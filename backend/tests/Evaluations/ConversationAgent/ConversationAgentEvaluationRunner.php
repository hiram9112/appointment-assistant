<?php

namespace Tests\Evaluations\ConversationAgent;

use App\Application\Availability\GetAppointmentAvailability;
use App\Application\Availability\ProfessionalAvailability;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\CheckAvailabilityTool;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Database\Seeders\BusinessSeeder;
use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Tools\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Local ConversationAgent evaluation support, outside PHPUnit's test-suite directories.
 * Only the opt-in CLI may use a live provider; ordinary tests fake the HTTP boundary.
 */
final class ConversationAgentEvaluationRunner
{
    public const string Database = 'appointment_assistant_s11_smoke';

    public const array Limits = [
        'http_requests' => 48, 'requests_per_turn' => 3, 'output_tokens_per_request' => 512,
        'luna_output_tokens_per_request' => 2048,
        'request_bytes' => 24000, 'total_request_bytes' => 750000, 'reported_tokens' => 160000,
        'timeout_seconds' => 60,
    ];

    private array $report;

    private array $current = [];

    private array $fingerprints = [];

    private int $requests = 0;

    private int $bytes = 0;

    private int $tokens = 0;

    private ?int $scenarioId = null;

    public function __construct(private Business $business, private string $output)
    {
        $this->report = [
            'run_id' => (string) Str::uuid(), 'executed_at' => gmdate(DATE_ATOM),
            'operation_time' => '2026-11-09T00:30:00+01:00',
            'provider' => 'openai', 'model' => config('ai.providers.openai.models.text.default'),
            'limits' => self::Limits, 'status' => 'prepared', 'developer_review' => 'pending',
            'fixture' => 'BusinessSeeder + reference demo Appointments; scenario-local overlays are recorded.',
            'scenarios' => [],
        ];
    }

    /** Refuse remote, URL-overridden, non-PostgreSQL or non-local reset targets. */
    public static function configureIsolatedDatabase(): void
    {
        $connection = config('database.connections.pgsql');
        if (! app()->environment('local') || app()->configurationIsCached()
            || ! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql'
            || isset($connection['read']) || isset($connection['write'])
            || ! in_array($connection['host'] ?? null, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('smoke_database_guard');
        }
        config([
            'database.default' => 'conversation_agent_evaluation',
            'database.connections.conversation_agent_evaluation' => [
                ...$connection, 'url' => null, 'database' => self::Database, 'search_path' => 'public',
            ],
        ]);
        if (DB::selectOne('select current_database() as name')->name !== self::Database) {
            throw new RuntimeException('smoke_database_guard');
        }
    }

    /** Seed only after the CLI has guarded and migrated the dedicated database. */
    public static function seed(): Business
    {
        app(BusinessSeeder::class)->run();
        $business = Business::query()->where('name', 'Lúmina Estética Avanzada')->sole();
        $customer = Customer::factory()->create(['first_name' => 'Synthetic', 'last_name' => 'Smoke', 'phone' => null]);
        foreach ([
            ['2026-11-09 10:00', 'Deep facial cleansing', 'Laura', 60],
            ['2026-11-09 16:00', 'Firming body treatment', 'Sara', 60],
            ['2026-11-10 12:00', 'Laser hair removal — full legs', 'Marta', 45],
            ['2026-11-10 17:00', 'Wellness massage', 'Claudia', 60],
            ['2026-11-11 13:00', 'Laser hair removal — underarms', 'Sara', 20],
            ['2026-11-12 09:30', 'Premium facial treatment', 'Laura', 90],
            ['2026-11-13 10:00', 'Hydrating facial treatment', 'Sara', 60],
            ['2026-11-14 10:30', 'Firming body treatment', 'Claudia', 60],
        ] as [$start, $serviceName, $professionalName, $duration]) {
            $service = $business->services()->where('name', $serviceName)->sole();
            $professional = $business->professionals()->where('first_name', $professionalName)->sole();
            $startsAt = CarbonImmutable::parse($start, $business->timezone);
            Appointment::factory()->for($service)->for($professional)->for($customer)->create([
                'starts_at' => $startsAt->utc(), 'ends_at' => $startsAt->addMinutes($duration)->utc(),
                'buffer_before_minutes' => $service->buffer_before_minutes,
                'buffer_after_minutes' => $service->buffer_after_minutes,
            ]);
        }

        return $business;
    }

    /** Synthetic prompts and explicit review rubrics; no LLM-as-judge or semantic substring grading. */
    public static function scenarios(Business $business): array
    {
        $service = fn (string $name): int => $business->services()->where('name', $name)->sole()->id;
        $professional = fn (string $name): int => $business->professionals()->where('first_name', $name)->sole()->id;
        $descriptive = fn (string $text): array => ['prompt' => $text, 'arguments' => null];
        $availability = fn (string $text, string $name, ?string $person, string $start, string $status): array => [
            'prompt' => $text, 'status' => $status,
            'arguments' => ['service_id' => $service($name), 'professional_id' => $person === null ? null : $professional($person), 'from' => $start, 'to' => $start],
        ];
        $deep = 'Deep facial cleansing';
        $laser = 'Laser hair removal — underarms';

        return [
            1 => ['name' => 'Colloquial and alternative Service names', 'review' => 'Interpret sobacos as underarms; use the canonical Service and Marta IDs, not full legs.', 'turns' => [
                $availability('¿Hay hueco para quitarme el vello de los sobacos con Marta hoy a las 12:00?', $laser, 'Marta', '2026-11-09T12:00:00+01:00', 'ok'),
            ]],
            2 => ['name' => 'Ambiguous Services and duplicate Professional names', 'review' => 'Clarify facial type, then distinguish the two Alex professionals without guessing; the Premium-eligible Alex is Laura.', 'turns' => [
                $descriptive('¿Hay hueco para un facial hoy a las 15:30?'),
                $descriptive('Deep facial cleansing con Alex hoy a las 15:30.'),
                $availability('Alex, la que también ofrece Premium facial treatment.', $deep, 'Laura', '2026-11-09T15:30:00+01:00', 'ok'),
            ]],
            3 => ['name' => 'Descriptive enquiry without lookup tools', 'review' => 'Give the fictional Zaragoza address, card/cash, free 30-minute initial consultation; email is temporarily unconfigured, not invented.', 'turns' => [
                $descriptive('¿Dónde estáis, cómo puedo pagar, cuánto cuesta y dura la consulta inicial y cuál es vuestro correo?'),
            ]],
            4 => ['name' => 'Actual availability invokes S03', 'review' => 'Check availability without a Professional preference; Sara is available, Laura is not at 12:30.', 'turns' => [
                $availability('Deep facial cleansing hoy a las 12:30, sin preferencia de profesional. ¿Hay hueco?', $deep, null, '2026-11-09T12:30:00+01:00', 'ok'),
            ]],
            5 => ['name' => 'Relative dates and Business-local time', 'review' => 'At the frozen operation time UTC is Sunday but Madrid is Monday; mañana means Tuesday 10 November.', 'turns' => [
                $availability('Mañana a las 12:15, Deep facial cleansing con Laura Martín. ¿Hay disponibilidad?', $deep, 'Laura', '2026-11-10T12:15:00+01:00', 'ok'),
            ]],
            6 => ['name' => 'Exact-start request', 'review' => 'Use from == to at exactly 15:30, not a fixed-slot grid or a broad day range.', 'turns' => [
                $availability('Comprueba Deep facial cleansing con Laura el 9 de noviembre de 2026 exactamente a las 15:30, no una franja.', $deep, 'Laura', '2026-11-09T15:30:00+01:00', 'ok'),
            ]],
            7 => ['name' => 'The second Service', 'review' => 'Preserve the requested list order; el segundo refers to Deep facial cleansing, not catalogue insertion/alphabetic order.', 'turns' => [
                $descriptive('Enumera en este orden: Initial beauty consultation, Deep facial cleansing, Wellness massage. Indica precio y duración.'),
                $availability('El segundo con Laura hoy a las 15:30, ¿está disponible?', $deep, 'Laura', '2026-11-09T15:30:00+01:00', 'ok'),
            ]],
            8 => ['name' => 'Explicit preferences and alternatives', 'review' => 'Explain Laura unavailability and Marta ineligibility first; offer Sara separately, never silently substitute.', 'turns' => [
                $availability('Deep facial cleansing hoy a las 12:30 con Laura. ¿Hay hueco?', $deep, 'Laura', '2026-11-09T12:30:00+01:00', 'requested_professional_unavailable'),
                $availability('¿Y si prefiero a Marta Ruiz para el mismo servicio y la misma hora?', $deep, 'Marta', '2026-11-09T12:30:00+01:00', 'requested_professional_ineligible'),
            ]],
            9 => ['name' => 'Changed preferences and topic', 'review' => 'Replace Service, Professional and date as requested, then answer the cash question without an availability call.', 'turns' => [
                $availability('Deep facial cleansing con Laura hoy a las 15:30. ¿Hay hueco?', $deep, 'Laura', '2026-11-09T15:30:00+01:00', 'ok'),
                $availability('Mejor Laser hair removal — underarms con Marta mañana a las 13:00.', $laser, 'Marta', '2026-11-10T13:00:00+01:00', 'ok'),
                $availability('En vez de mañana, el jueves 12 de noviembre a la misma hora con Sara Gómez.', $laser, 'Sara', '2026-11-12T13:00:00+01:00', 'ok'),
                $descriptive('Dejemos las citas. ¿Aceptáis efectivo?'),
            ]],
            10 => ['name' => 'Multi-turn continuity', 'review' => 'Ask only for the missing date/time; retain massage and Claudia; media hora antes means 15:00, which is unavailable because of the pre-buffer.', 'turns' => [
                $descriptive('Quiero consultar huecos para Wellness massage con Claudia Pérez.'),
                $availability('El martes 10 de noviembre a las 15:30.', 'Wellness massage', 'Claudia', '2026-11-10T15:30:00+01:00', 'ok'),
                $availability('¿Y media hora antes, manteniendo todo lo demás?', 'Wellness massage', 'Claudia', '2026-11-10T15:00:00+01:00', 'requested_professional_unavailable'),
            ]],
            11 => ['name' => 'Configuration changes between turns', 'review' => 'First answer 60 min / EUR 55 / original phone; after the recorded mutation answer 50 min / EUR 59.90 / +34 600 000 111. Current facts override history.', 'turns' => [
                $descriptive('¿Cuánto dura y cuesta Deep facial cleansing y qué teléfono tenéis?'),
                $descriptive('¿Y ahora, cuánto dura, cuánto cuesta y cuál es vuestro teléfono?'),
            ]],
            12 => ['name' => 'No fabricated availability from opening hours', 'review' => 'Business opening at 09:00 does not establish availability. Claudia has no Monday schedule; report unavailable, do not promise a booking.', 'turns' => [
                $availability('Si abrís a las 9, ¿puedo hacerme un Wellness massage hoy lunes 9 de noviembre a las 9 con Claudia Pérez?', 'Wellness massage', 'Claudia', '2026-11-09T09:00:00+01:00', 'requested_professional_unavailable'),
            ]],
        ];
    }

    /** @param array<int, array<string, mixed>>|null $scenarios */
    public function run(?array $scenarios = null): array
    {
        $previousTime = Carbon::getTestNow();
        Carbon::setTestNow('2026-11-08 23:30:00 UTC');
        $this->observe();
        $scenarios ??= self::scenarios($this->business);
        foreach ($scenarios as $id => $scenario) {
            $this->report['scenarios'][$id] = [
                'name' => $scenario['name'], 'review_rubric' => $scenario['review'],
                'contract_status' => 'not_run', 'developer_status' => 'unreviewed', 'turns' => [],
            ];
        }
        $this->report['status'] = 'running';
        $this->save();

        try {
            foreach ($scenarios as $id => $scenario) {
                $this->scenarioId = $id;
                foreach ($scenario['turns'] as $turn) {
                    if ($turn['arguments'] !== null) {
                        $this->expectedResult($turn);
                    }
                }
            }
            $this->report['fixture_preflight'] = 'passed';
            foreach ([...array_intersect([3, 4], array_keys($scenarios)), ...array_diff(array_keys($scenarios), [3, 4])] as $id) {
                $this->scenarioId = $id;
                $businessBefore = $this->business->fresh()->getAttributes();
                $professionalsBefore = $this->business->professionals()->get()->keyBy('id');
                $service = $this->business->services()->where('name', 'Deep facial cleansing')->sole();
                $serviceBefore = $service->getAttributes();
                try {
                    if ($id === 3) {
                        $this->business->update(['email' => null]);
                    }
                    if ($id === 2) {
                        $this->business->professionals()->whereIn('first_name', ['Laura', 'Sara'])->update(['display_name' => 'Alex']);
                    }
                    foreach ($scenarios[$id]['turns'] as $index => $turn) {
                        if ($id === 11 && $index === 1) {
                            $service->update(['duration_minutes' => 50, 'price_amount' => '59.90']);
                            $this->business->update(['phone' => '+34 600 000 111']);
                        }
                        $this->executeTurn($id, $index, $turn);
                    }
                    $passed = $scenarios[$id]['turns'] !== [] && array_diff(array_column($this->report['scenarios'][$id]['turns'], 'contract_status'), ['pass']) === [];
                    $this->report['scenarios'][$id]['contract_status'] = $passed ? 'pass' : 'fail';
                    if (in_array($id, [3, 4], true) && ! $passed) {
                        $this->report['status'] = 'stopped_at_integration_gate';
                        break;
                    }
                } finally {
                    $this->current = [];
                    $this->business->setRawAttributes($businessBefore)->save();
                    $service->setRawAttributes($serviceBefore)->save();
                    if ($id === 2) {
                        foreach ($professionalsBefore as $professional) {
                            DB::table('professionals')->where('id', $professional->id)->update(['display_name' => $professional->getRawOriginal('display_name')]);
                        }
                    }
                }
                $this->save();
            }
            if ($this->report['status'] === 'running') {
                $this->report['status'] = isset($scenarios[3], $scenarios[4])
                    && array_diff(array_column($this->report['scenarios'], 'contract_status'), ['pass']) === []
                    ? 'completed_needs_review' : 'contract_failures';
            }
        } catch (Throwable $exception) {
            $this->report['status'] = 'stopped';
            $this->report['failure'] = [
                'scenario' => $this->scenarioId, 'exception_class' => $exception::class,
                'category' => in_array($exception->getMessage(), [
                    'smoke_fixture_status_mismatch', 'smoke_budget_exceeded', 'smoke_retry_blocked',
                    'smoke_endpoint_guard', 'smoke_sdk_storage_used', 'smoke_unexpected_agent',
                ], true) ? $exception->getMessage() : 'technical_or_provider_failure',
            ];
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof RequestException) {
                    $this->report['failure']['http_status'] = $cause->response->status();
                    foreach (['type', 'code', 'param'] as $field) {
                        $value = $cause->response->json('error.'.$field);
                        if (is_string($value) && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $value)) {
                            $this->report['failure']['provider_error'][$field] = $value;
                        }
                    }
                    break;
                }
            }
            $this->report['scenarios'][$this->scenarioId]['contract_status'] = 'fail';
            foreach ($this->report['scenarios'][$this->scenarioId]['turns'] as &$evidence) {
                if ($evidence['contract_status'] === 'running') {
                    $evidence['contract_status'] = 'fail';
                }
            }
            unset($evidence);
            if ($this->current !== []) {
                $this->current['contract_status'] = 'fail';
                $this->report['scenarios'][$this->scenarioId]['contract_status'] = 'fail';
            }
        } finally {
            $this->save();
            Carbon::setTestNow($previousTime);
        }

        return $this->report;
    }

    private function executeTurn(int $id, int $index, array $turn): void
    {
        $this->fingerprints = [];
        $this->current = [
            'index' => $index, 'prompt' => $turn['prompt'], 'expected_arguments' => $turn['arguments'],
            'expected_status' => $turn['status'] ?? null, 'contract_status' => 'running',
            'runtime_context' => null,
            'steps' => [], 'tools' => [], 'tool_failures' => [], 'writes' => [],
        ];
        if ($turn['arguments'] !== null) {
            $this->current['fixture_expected_result'] = $this->expectedResult($turn);
        }
        $this->save();
        $appointments = Appointment::query()->orderBy('id')->get()->toArray();
        $sender = $this->report['run_id'].'-'.$id;
        $inbound = app(AiTurnCoordinator::class)->inbound($this->business, 'smoke', $sender, (string) $index, $turn['prompt']);
        $answer = app(ConversationTurnProcessor::class)->processNext($this->business, 'smoke', $sender);
        $calls = $this->current['tools'];
        $actualArguments = array_replace(['professional_id' => null], $calls[0]['arguments'] ?? []);
        $expectedArguments = $turn['arguments'] ?? [];
        ksort($actualArguments);
        ksort($expectedArguments);
        $matching = $turn['arguments'] === null ? $calls === [] : count($calls) === 1
            && $actualArguments === $expectedArguments
            && $calls[0]['result'] === $this->current['fixture_expected_result']
            && ($calls[0]['matches_s03'] ?? false);
        $this->current['checks'] = [
            'tool_contract' => $matching,
            'accepted_response' => $answer instanceof ConversationMessage && trim($answer->content) !== ''
                && $inbound->fresh()->state === AiTurnState::Completed
                && $inbound->fresh()->assistant_message_id === $answer->id,
            'read_only' => array_diff($this->current['writes'], ['ai_turns', 'conversations', 'conversation_messages']) === []
                && $appointments === Appointment::query()->orderBy('id')->get()->toArray(),
            'not_truncated' => ! in_array('length', array_column($this->current['steps'], 'finish_reason'), true),
        ];
        $this->current['contract_status'] = in_array(false, $this->current['checks'], true) ? 'fail' : 'pass';
        $this->save();
        $this->current = [];
    }

    private function expectedResult(array $turn): array
    {
        $result = json_decode(app()->makeWith(CheckAvailabilityTool::class, ['business' => $this->business])->handle(new Request($turn['arguments'])), true, flags: JSON_THROW_ON_ERROR);
        if ($result['status'] !== $turn['status']) {
            throw new RuntimeException('smoke_fixture_status_mismatch');
        }

        return $result;
    }

    /** Observe the unchanged SDK/agent/tool path; never record request headers or exception messages. */
    private function observe(): void
    {
        config(['ai.default' => 'openai', 'ai.providers.openai.store' => false, 'logging.default' => 'null']);
        app()->bind(ConversationStore::class, fn () => throw new RuntimeException('smoke_sdk_storage_used'));
        Http::globalMiddleware(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                if ((string) $request->getUri() !== 'https://api.openai.com/v1/responses' || $request->getMethod() !== 'POST') {
                    throw new RuntimeException('smoke_endpoint_guard');
                }
                $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
                $body['max_output_tokens'] = $body['model'] === 'gpt-6-luna'
                    ? self::Limits['luna_output_tokens_per_request']
                    : self::Limits['output_tokens_per_request'];
                $body['store'] = false;
                $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $fingerprint = hash('sha256', $json);
                if (isset($this->fingerprints[$fingerprint])) {
                    throw new RuntimeException('smoke_retry_blocked');
                }
                if ($this->requests >= self::Limits['http_requests']
                    || count($this->fingerprints) >= self::Limits['requests_per_turn']
                    || strlen($json) > self::Limits['request_bytes']
                    || $this->bytes + strlen($json) > self::Limits['total_request_bytes']
                    || $this->tokens >= self::Limits['reported_tokens']) {
                    throw new RuntimeException('smoke_budget_exceeded');
                }
                $this->fingerprints[$fingerprint] = true;
                $this->requests++;
                $this->bytes += strlen($json);
                $this->save();
                $request = $request->withBody(Utils::streamFor($json))->withoutHeader('Content-Length');
                $options['timeout'] = self::Limits['timeout_seconds'];
                $options['connect_timeout'] = 10;
                $options['allow_redirects'] = false;

                // SDK 1.4 retries short Retry-After responses. Strip it only in this local process;
                // the request fingerprint guard also fences identical retransmissions.
                return $handler($request, $options)->then(fn (ResponseInterface $response): ResponseInterface => $response->withoutHeader('Retry-After'));
            };
        });
        Event::listen(PromptingAgent::class, function (PromptingAgent $event): void {
            if (! $event->prompt->agent instanceof ConversationAgent) {
                throw new RuntimeException('smoke_unexpected_agent');
            }
            if (preg_match('/<runtime_context>\n(.*)\n<\/runtime_context>$/s', $event->prompt->agent->instructions(), $matches) !== 1) {
                throw new RuntimeException('smoke_unexpected_agent');
            }
            $this->current['runtime_context'] = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            $this->current['requested_model'] = $event->prompt->model;
            $this->current['history'] = array_map(fn (Message $message): array => ['role' => $message->role->value, 'content' => $message->content], $event->prompt->messages ?? []);
            $this->save();
        });
        Event::listen(StepCompleted::class, function (StepCompleted $event): void {
            $this->tokens += $event->response->usage->inputTokens + $event->response->usage->outputTokens;
            $this->current['steps'][] = [
                'model' => $event->response->meta->model, 'text' => $event->response->text,
                'finish_reason' => $event->response->finishReason->value, 'usage' => $event->response->usage->toArray(),
                'tool_calls' => array_map(fn ($call): array => ['name' => $call->name, 'arguments' => $call->arguments], $event->response->toolCalls),
            ];
            $this->save();
        });
        Event::listen(ToolInvoked::class, function (ToolInvoked $event): void {
            $result = json_decode($event->result, true, flags: JSON_THROW_ON_ERROR);
            $s03 = null;
            if (isset($result['service_id'])) {
                $a = $event->arguments;
                $s03 = app(GetAppointmentAvailability::class)->handle($this->business->fresh(), $a['service_id'], new DateTimeImmutable($a['from']), new DateTimeImmutable($a['to']), $a['professional_id'] ?? null);
                $zone = new DateTimeZone($this->business->timezone);
                $map = fn (ProfessionalAvailability $p): array => [
                    'id' => $p->id, 'display_name' => $p->displayName, 'eligible' => $p->eligible,
                    'ranges' => array_map(fn ($range): array => ['earliest' => $range->earliest->setTimezone($zone)->format(DATE_ATOM), 'latest' => $range->latest->setTimezone($zone)->format(DATE_ATOM)], $p->ranges),
                ];
                $s03 = $s03 === null ? null : [
                    'requested_professional' => $s03->requestedProfessional === null ? null : $map($s03->requestedProfessional),
                    'available_professionals' => array_map($map, $s03->availableProfessionals),
                ];
            }
            $this->current['tools'][] = [
                'name' => class_basename($event->tool), 'arguments' => $event->arguments, 'result' => $result, 's03' => $s03,
                'matches_s03' => $s03 !== null && $s03['requested_professional'] === $result['requested_professional']
                    && $s03['available_professionals'] === $result['available_professionals'],
            ];
            $this->save();
        });
        Event::listen(AgentPrompted::class, function (AgentPrompted $event): void {
            $this->current['response'] = $event->response->text;
            $this->save();
        });
        Event::listen(ToolFailed::class, function (ToolFailed $event): void {
            $this->current['tool_failures'][] = [
                'name' => class_basename($event->tool), 'arguments' => $event->arguments,
                'exception_class' => $event->exception::class,
            ];
            $this->save();
        });
        DB::listen(function (QueryExecuted $query): void {
            if ($this->current !== [] && preg_match('/^\s*(?:insert into|update|delete from)\s+"?(\w+)/i', $query->sql, $matches)) {
                $this->current['writes'][] = $matches[1];
            }
        });
    }

    private function save(): void
    {
        if ($this->current !== [] && $this->scenarioId !== null) {
            $this->report['scenarios'][$this->scenarioId]['turns'][$this->current['index']] = $this->current;
        }
        $this->report['usage'] = ['http_requests' => $this->requests, 'request_bytes' => $this->bytes, 'reported_tokens' => $this->tokens];
        $json = json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $key = config('ai.providers.openai.key');
        if (is_string($key) && $key !== '') {
            $json = str_replace($key, '[REDACTED]', $json);
        }
        if (file_put_contents($this->output.'.tmp', $json."\n", LOCK_EX) === false) {
            throw new RuntimeException('smoke_evidence_write_failed');
        }
        chmod($this->output.'.tmp', 0600);
        if (! rename($this->output.'.tmp', $this->output)) {
            throw new RuntimeException('smoke_evidence_write_failed');
        }
    }
}
