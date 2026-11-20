<?php

namespace Tests\Evaluations\ConversationAgent;

use App\Application\Appointment\CreateAppointment;
use App\Application\Appointment\GetCustomerAppointments;
use App\Application\Availability\CalculateAvailability;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\AppointmentProposalPresentation;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentAudit;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Messages\Message;
use RuntimeException;
use Throwable;

/** Developer-only live orchestration; PHPUnit exercises this same path with fake provider HTTP. */
final class AppointmentOperationsEvaluationRunner
{
    public const string Database = 'appointment_assistant_appointment_evaluation';

    public const array Limits = [
        'http_requests' => 64, 'requests_per_turn' => 8, 'output_tokens_per_request' => 1024,
        'luna_output_tokens_per_request' => 4096, 'request_bytes' => 64000,
        'total_request_bytes' => 2000000, 'reported_tokens' => 200000, 'timeout_seconds' => 60,
    ];

    private EvaluationProviderGuard $provider;

    private array $report = [];

    private array $current = [];

    private array $specification = [];

    private ?AiTurn $queued = null;

    private ?AiTurn $lastTurn = null;

    private bool $injected = false;

    public function __construct(private Business $business, private string $output)
    {
        $this->provider = new EvaluationProviderGuard(self::Limits);
    }

    /** @param array<string, mixed> $scenario */
    public function run(array $scenario): array
    {
        $previousTime = Carbon::getTestNow();
        Carbon::setTestNow(CarbonImmutable::parse($scenario['operation_time'])->utc());
        $this->report = [
            'run_id' => (string) Str::uuid(), 'executed_at' => gmdate(DATE_ATOM),
            'suite' => 'appointment-operations', 'scenario' => $scenario['name'],
            'operation_time' => $scenario['operation_time'], 'fixture' => $scenario['fixture'],
            'provider' => 'openai', 'model' => config('ai.providers.openai.models.text.default'),
            'limits' => self::Limits, 'status' => 'running', 'developer_review' => 'pending',
            'review_rubric' => $scenario['review_rubric'],
            'planned_turns' => $scenario['turns'], 'turns' => [],
        ];
        $this->observe($scenario['sender']);
        $this->save();
        try {
            $this->preflight($scenario);
            $this->report['fixture_preflight'] = 'passed';
            foreach ($scenario['turns'] as $index => $specification) {
                $this->executeTurn($index, $specification, $scenario['sender']);
                if ($this->current['contract_status'] !== 'pass') {
                    $this->report['status'] = 'contract_failures';
                    break;
                }
            }
            if ($this->report['status'] === 'running') {
                $this->report['status'] = $scenario['turns'] === [] ? 'contract_failures' : 'completed_needs_review';
            }
        } catch (Throwable $exception) {
            $this->report['status'] = 'stopped';
            $this->report['failure'] = ['exception_class' => $exception::class,
                'category' => in_array($exception->getMessage(), [
                    'smoke_endpoint_guard', 'smoke_budget_exceeded', 'smoke_retry_blocked',
                    'evaluation_fixture_mismatch', 'evaluation_sdk_storage_used', 'evaluation_unexpected_agent',
                ], true) ? $exception->getMessage() : 'technical_or_provider_failure'];
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof RequestException) {
                    $this->report['failure']['http_status'] = $cause->response->status();
                    $key = config('ai.providers.openai.key');
                    foreach (['type', 'code'] as $field) {
                        $value = $cause->response->json('error.'.$field, flags: 0);
                        if (is_string($value) && preg_match('/^[a-zA-Z0-9_.-]{1,80}$/D', $value)
                            && (! is_string($key) || $key === '' || ! str_contains($value, $key))) {
                            $this->report['failure']['provider_error'][$field] = $value;
                        }
                    }
                    break;
                }
            }
            if ($this->current !== []) {
                $this->current['contract_status'] = 'fail';
                $this->current['after'] = $this->snapshot();
            }
        } finally {
            $this->save();
            Carbon::setTestNow($previousTime);
        }

        return $this->report;
    }

    private function executeTurn(int $index, array $specification, string $sender): void
    {
        $this->specification = $specification = $this->resolveReferences($specification);
        $this->injected = false;
        if (isset($specification['advance_hours'])) {
            Carbon::setTestNow(now()->addHours($specification['advance_hours']));
        }
        $this->current = ['index' => $index, 'prompt' => $specification['prompt'],
            'expected' => $specification, 'contract_status' => 'running', 'developer_status' => 'unreviewed',
            'tools' => [], 'steps' => [], 'tool_failures' => [], 'history' => [], 'runtime_context' => null];
        if ($specification['occupy'] ?? false) {
            $proposal = AppointmentProposal::query()->latest('id')->firstOrFail();
            $competitor = Customer::factory()->create(['first_name' => 'Otra', 'last_name' => 'Prueba', 'phone' => null]);
            ChannelIdentity::factory()->for($competitor)->create(['external_id' => 'competing-synthetic-identity']);
            $p = $proposal->payload;
            $appointment = app(CreateAppointment::class)->handle($this->business, $competitor->id, $p['service_id'],
                $p['professional_id'], new DateTimeImmutable($p['starts_at']), 'system', 'evaluation', 'evaluation');
            if (! $appointment instanceof Appointment) {
                throw new RuntimeException('evaluation_fixture_mismatch');
            }
            $this->current['fixture_overlay'] = ['competing_appointment_id' => $appointment->id, 'proposal_id' => $proposal->id];
        }
        $this->current['before'] = $this->snapshot();
        $turns = app(AiTurnCoordinator::class);
        $turn = ($specification['resume'] ?? false) ? $this->lastTurn
            : (($specification['queued'] ?? false) ? $this->queued
                : $turns->inbound($this->business, 'whatsapp', $sender, (string) $index, $specification['prompt']));
        if (! $turn instanceof AiTurn) {
            throw new RuntimeException('evaluation_fixture_mismatch');
        }
        $this->lastTurn = $turn;
        $this->current['turn_id'] = $turn->id;
        $this->provider->beginAttempt();
        $this->save();
        $answer = null;
        try {
            $answer = app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', $sender);
        } catch (Throwable $exception) {
            if (! $this->injected || $exception->getMessage() !== 'evaluation_injected_interruption') {
                throw $exception;
            }
            $this->current['injected_interruption'] = $specification['interrupt'];
        }
        $this->current['after'] = $this->snapshot();
        $this->current['checks'] = $this->checks($turn->fresh(), $answer, $specification);
        $this->current['contract_status'] = in_array(false, $this->current['checks'], true) ? 'fail' : 'pass';
        $this->save();
    }

    /** Structural contracts only: model wording is always left for Developer review. */
    private function checks(AiTurn $turn, mixed $answer, array $expected): array
    {
        $before = $this->current['before'];
        $after = $this->current['after'];
        $interrupted = isset($expected['interrupt']);
        $calls = $this->current['tools'];
        $writeNames = ['RegisterCustomerTool', 'PrepareCreateAppointmentTool', 'PrepareRescheduleAppointmentTool', 'PrepareCancelAppointmentTool', 'ConfirmAppointmentProposalTool'];
        $actualWrites = array_values(array_filter($calls, fn (array $call): bool => in_array($call['name'], $writeNames, true)
            && ! ($call['name'] === 'ConfirmAppointmentProposalTool'
                && in_array($call['result']['status'] ?? null, ['confirmation_required', 'no_pending_proposal'], true))));
        $expectedWrites = array_values(array_filter($expected['calls'], fn (array $call): bool => in_array($call['name'], $writeNames, true)));
        $normalize = function (array $arguments, string $tool): array {
            $arguments = array_filter($arguments, fn (mixed $value): bool => $value !== null);
            if ($tool === 'PrepareRescheduleAppointmentTool' && ! isset($arguments['professional_id'])) {
                $original = collect($this->current['before']['appointments'])->firstWhere('id', $arguments['appointment_id'] ?? null);
                $arguments['professional_id'] = $original['professional_id'] ?? null;
            }
            ksort($arguments);

            return $arguments;
        };
        $matches = count($actualWrites) === count($expectedWrites);
        foreach ($expectedWrites as $index => $call) {
            $actual = $actualWrites[$index] ?? [];
            $matches = $matches && ($actual['name'] ?? null) === $call['name']
                && $normalize($actual['arguments'] ?? [], $call['name']) === $normalize($call['arguments'], $call['name']);
        }
        $requiredReads = true;
        foreach ($expected['calls'] as $call) {
            if (! in_array($call['name'], $writeNames, true)) {
                $requiredReads = $requiredReads && collect($calls)->contains(fn (array $actual): bool => $actual['name'] === $call['name']
                    && $normalize($actual['arguments'], $call['name']) === $normalize($call['arguments'], $call['name']));
            }
        }
        $checks = [
            'expected_write_contract' => $matches, 'required_reads' => $requiredReads,
            'no_tool_failures' => $this->current['tool_failures'] === [],
            'not_truncated' => ! in_array('length', array_column($this->current['steps'], 'finish_reason'), true),
            'provider_outside_transactions' => ($this->current['provider_transaction_level'] ?? -1) === 0,
            'accepted_model_response' => $interrupted
                ? $this->injected && $turn->state === AiTurnState::Failed && $turn->assistant_message_id === null
                : $answer instanceof ConversationMessage && $turn->state === AiTurnState::Completed
                    && $turn->assistant_message_id === $answer->id && $answer->content === ($this->current['response'] ?? null),
        ];
        $proposal = AppointmentProposal::query()->where('originating_turn_id', $turn->id)->first();
        if ($expected['effect'] === 'prepared') {
            $payload = $expected['arguments'];
            if (isset($payload['starts_at'])) {
                $payload['starts_at'] = CarbonImmutable::parse($payload['starts_at'])->utc()->toIso8601String();
            }
            if ($expected['operation'] === 'reschedule' && ! isset($payload['professional_id'])) {
                $original = collect($before['appointments'])->firstWhere('id', $payload['appointment_id']);
                $payload['professional_id'] = $original['professional_id'] ?? null;
            }
            $checks['canonical_proposal'] = $proposal !== null && $proposal->operation->value === $expected['operation'] && $proposal->payload == $payload;
            $checks['preparation_has_no_domain_mutation'] = $before['appointments'] === $after['appointments'] && $before['audits'] === $after['audits'];
            $checks['atomic_confirmation_association'] = $proposal !== null && ($interrupted
                ? $proposal->state->value === 'prepared' && $proposal->confirmation_request_message_id === null
                : $proposal->state->value === 'pending' && $proposal->confirmation_request_message_id === $turn->assistant_message_id);
            $checks['single_active_proposal'] = AppointmentProposal::query()->where('conversation_id', $turn->inboundMessage->conversation_id)
                ->whereIn('state', ['prepared', 'pending'])->count() === 1;
            if (! ($expected['resume'] ?? false)) {
                $tool = collect($calls)->first(fn (array $call): bool => str_starts_with($call['name'], 'Prepare'));
                $checks['structured_from_persistence'] = $tool !== null && ($tool['result']['status'] ?? null) === 'prepared'
                    && $tool['result'] === $tool['persisted_proposal'] && ! isset($tool['result']['confirmation_request']);
                $checks['replacement_invalidates_previous'] = collect($before['proposals'])->every(function (array $old) use ($after): bool {
                    return ! in_array($old['state'], ['prepared', 'pending'], true)
                        || collect($after['proposals'])->firstWhere('id', $old['id'])['state'] === 'rejected';
                });
            }
        } elseif (in_array($expected['effect'], ['completed', 'rejected'], true)) {
            $executed = AppointmentProposal::query()->where('confirming_turn_id', $turn->id)->first();
            $result = $executed?->result;
            $checks['durable_outcome'] = $executed !== null && $executed->state->value === $expected['effect']
                && ($result['outcome'] ?? null) === $expected['outcome'];
            $checks['subsequent_persisted_confirmation'] = $executed !== null && $executed->confirmation_request_message_id !== null
                && $turn->inbound_message_id > $executed->confirmation_request_message_id
                && $executed->originatingTurn->assistant_message_id === $executed->confirmation_request_message_id;
            $mutated = $expected['effect'] === 'completed' && $expected['outcome'] !== 'unchanged' && ! ($expected['resume'] ?? false);
            $checks['exactly_one_or_zero_audits'] = count($after['audits']) - count($before['audits']) === ($mutated ? 1 : 0);
            if (! $mutated) {
                $checks['no_duplicate_or_rejected_mutation'] = $before['appointments'] === $after['appointments'];
            } else {
                $id = $result['appointment']['id'] ?? null;
                $stored = collect($after['appointments'])->firstWhere('id', $id);
                $others = fn (array $rows): array => array_values(array_filter($rows, fn (array $row): bool => $row['id'] !== $id));
                $checks['only_authoritative_target_changed'] = $stored !== null && $others($before['appointments']) === $others($after['appointments']);
                $checks['result_matches_appointment'] = $stored !== null
                    && $stored['status'] === $result['appointment']['status']
                    && $stored['service_id'] === $result['appointment']['service_id']
                    && $stored['professional_id'] === $result['appointment']['professional_id']
                    && CarbonImmutable::parse($stored['starts_at'])->equalTo($result['appointment']['starts_at'])
                    && CarbonImmutable::parse($stored['ends_at'])->equalTo($result['appointment']['ends_at']);
                $old = collect($before['appointments'])->firstWhere('id', $id);
                $checks['expected_domain_transition'] = $stored !== null && match ($expected['outcome']) {
                    'created' => $old === null && $stored['customer_id'] === $result['customer_id']
                        && $stored['service_id'] === $executed->payload['service_id']
                        && $stored['professional_id'] === $executed->payload['professional_id']
                        && CarbonImmutable::parse($stored['starts_at'])->equalTo($executed->payload['starts_at']),
                    'rescheduled' => $old !== null && $old !== $stored && $id === $executed->payload['appointment_id']
                        && $stored['professional_id'] === $executed->payload['professional_id']
                        && CarbonImmutable::parse($stored['starts_at'])->equalTo($executed->payload['starts_at']),
                    'cancelled' => $old !== null && $old['status'] === 'confirmed' && $stored['status'] === 'cancelled'
                        && $id === $executed->payload['appointment_id'],
                    default => false,
                };
                $audit = $after['audits'][array_key_last($after['audits'])] ?? [];
                $checks['trusted_audit_context'] = ($audit['appointment_id'] ?? null) === $id && ($audit['actor_type'] ?? null) === 'customer'
                    && $audit['actor_id'] === (string) $result['customer_id'] && $audit['source'] === 'whatsapp';
            }
        } else {
            $checks['no_domain_mutation'] = $before['appointments'] === $after['appointments'] && $before['audits'] === $after['audits'];
            $checks['no_proposal_change'] = ($expected['new_conversation'] ?? false) || $before['proposals'] === $after['proposals'];
        }
        if ($expected['registration'] ?? false) {
            $customer = Customer::query()->whereHas('channelIdentities', fn ($query) => $query->where('external_id', $turn->inboundMessage->conversation->sender_identity))->sole();
            $checks['optional_phone_and_association'] = $customer->phone === null
                && $turn->inboundMessage->conversation->customer_id === $customer->id
                && count($after['customers']) - count($before['customers']) === 1
                && count($after['conversations']) === 1;
        }
        if ($expected['listing'] ?? false) {
            $customerId = $turn->inboundMessage->conversation->customer_id;
            $authoritative = app(GetCustomerAppointments::class)->handle($this->business, $customerId);
            $listing = collect($calls)->firstWhere('name', 'GetCustomerAppointmentsTool')['result'] ?? [];
            $checks['scoped_listing'] = is_array($authoritative)
                && array_column($listing['appointments'] ?? [], 'id') === array_map(fn ($appointment): int => $appointment->id, $authoritative);
        }
        if ($expected['resume'] ?? false) {
            $key = $expected['effect'] === 'prepared' ? 'prepared_proposal' : 'appointment_operation_result';
            $saved = collect($before['proposals'])->firstWhere($expected['effect'] === 'prepared' ? 'originating_turn_id' : 'confirming_turn_id', $turn->id);
            $context = $this->current['runtime_context'][$key] ?? null;
            $checks['authoritative_recovery_context'] = $saved !== null && $context !== null
                && ($expected['effect'] === 'prepared'
                    ? $context['proposal_id'] === $saved['id'] && $context['payload'] == $saved['payload']
                        && $context['presentation'] === AppointmentProposalPresentation::fromArray($saved['presentation'])->toLocalArray()
                    : $context === $saved['result']);
            $recovered = $saved === null ? null : collect($after['proposals'])->firstWhere('id', $saved['id']);
            $checks['immutable_recovery_data'] = $saved !== null && $recovered !== null
                && $saved['payload'] === $recovered['payload'] && $saved['presentation'] === $recovered['presentation']
                && $saved['result'] === $recovered['result'];
            $checks['no_recreated_proposal_or_availability'] = count($before['proposals']) === count($after['proposals'])
                && ! collect($calls)->contains(fn (array $call): bool => $call['name'] === 'CheckAvailabilityTool' || str_starts_with($call['name'], 'Prepare'));
        }
        if ($expected['new_conversation'] ?? false) {
            $checks['fresh_conversation_context'] = count($after['conversations']) === count($before['conversations']) + 1
                && $this->current['history'] === [] && $this->current['runtime_context']['active_proposal'] === null;
        }

        return $checks;
    }

    /** Check fixture availability without preparing proposals or invoking an AI. */
    private function preflight(array $scenario): void
    {
        foreach ($scenario['turns'] as $turn) {
            if (($turn['effect'] ?? null) !== 'prepared' || $turn['operation'] === 'cancel' || ($turn['resume'] ?? false)) {
                continue;
            }
            $a = $turn['arguments'];
            if (($a['appointment_id'] ?? null) === '@created') {
                continue;
            }
            $appointment = isset($a['appointment_id']) ? Appointment::query()->findOrFail($a['appointment_id']) : null;
            $service = $this->business->services()->findOrFail($a['service_id'] ?? $appointment->service_id);
            $professional = $this->business->professionals()->findOrFail($a['professional_id'] ?? $appointment->professional_id);
            $start = new DateTimeImmutable($a['starts_at']);
            $ranges = $appointment === null
                ? app(CalculateAvailability::class)->handle($this->business, $service, $professional, $start, $start)
                : app(CalculateAvailability::class)->forRescheduling($this->business, $service, $professional, $start, $start, $appointment->id);
            if ($ranges === []) {
                throw new RuntimeException('evaluation_fixture_mismatch');
            }
        }
    }

    private function observe(string $sender): void
    {
        config(['ai.default' => 'openai', 'ai.providers.openai.store' => false, 'logging.default' => 'null']);
        app()->bind(ConversationStore::class, fn () => throw new RuntimeException('evaluation_sdk_storage_used'));
        $this->provider->install(fn () => $this->save());
        Event::listen(PromptingAgent::class, function (PromptingAgent $event): void {
            if (! $event->prompt->agent instanceof ConversationAgent
                || preg_match('/<runtime_context>\n(.*)\n<\/runtime_context>$/s', $event->prompt->agent->instructions(), $matches) !== 1) {
                throw new RuntimeException('evaluation_unexpected_agent');
            }
            $this->current['provider_transaction_level'] = DB::transactionLevel();
            $this->current['runtime_context'] = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            $this->current['history'] = array_map(fn (Message $message): array => ['role' => $message->role->value, 'content' => $message->content], $event->prompt->messages ?? []);
            $this->save();
        });
        Event::listen(StepCompleted::class, function (StepCompleted $event): void {
            $this->provider->recordTokens($event->response->usage->inputTokens + $event->response->usage->outputTokens);
            $this->current['steps'][] = ['model' => $event->response->meta->model, 'text' => $event->response->text,
                'finish_reason' => $event->response->finishReason->value, 'usage' => $event->response->usage->toArray()];
            $this->save();
        });
        Event::listen(AgentPrompted::class, function (AgentPrompted $event): void {
            $this->current['response'] = $event->response->text;
            $this->save();
        });
        Event::listen(ToolFailed::class, function (ToolFailed $event): void {
            $this->current['tool_failures'][] = ['name' => class_basename($event->tool), 'exception_class' => $event->exception::class];
            $this->save();
        });
        Event::listen(ToolInvoked::class, function (ToolInvoked $event) use ($sender): void {
            $result = json_decode($event->result, true, flags: JSON_THROW_ON_ERROR);
            $proposal = isset($result['proposal_id']) ? AppointmentProposal::query()->find($result['proposal_id']) : null;
            $this->current['tools'][] = ['name' => class_basename($event->tool), 'arguments' => $event->arguments,
                'result' => $result, 'persisted_proposal' => $proposal?->structuredDetails()];
            $this->save();
            if (($this->specification['queue_premature'] ?? false) && $this->queued === null && ($result['status'] ?? null) === 'prepared') {
                $this->queued = app(AiTurnCoordinator::class)->inbound($this->business, 'whatsapp', $sender, 'premature', 'Sí, confirmo antes de recibir la pregunta.');
            }
            $fault = $this->specification['interrupt'] ?? null;
            if (($fault === 'after_preparation' && ($result['status'] ?? null) === 'prepared')
                || ($fault === 'after_execution' && ($result['status'] ?? null) === 'completed')) {
                $this->interrupt();
            }
        });
        Event::listen('eloquent.creating: '.ConversationMessage::class, function (ConversationMessage $message): void {
            if (($this->specification['interrupt'] ?? null) === 'assistant' && $message->role === 'assistant') {
                $this->interrupt();
            }
        });
    }

    private function interrupt(): never
    {
        $this->injected = true;
        throw new RuntimeException('evaluation_injected_interruption');
    }

    private function resolveReferences(array $specification): array
    {
        $resolve = function (array $arguments): array {
            if (($arguments['appointment_id'] ?? null) === '@created') {
                $proposal = AppointmentProposal::query()->where('operation', 'create')->where('state', 'completed')->latest('id')->firstOrFail();
                $arguments['appointment_id'] = $proposal->result['appointment']['id'];
            }

            return $arguments;
        };
        if (isset($specification['arguments'])) {
            $specification['arguments'] = $resolve($specification['arguments']);
        }
        foreach ($specification['calls'] as &$call) {
            $call['arguments'] = $resolve($call['arguments']);
        }
        unset($call);

        return $specification;
    }

    /** Authoritative evidence, including complete audits; never persist claim tokens or provider headers. */
    private function snapshot(): array
    {
        return [
            'customers' => Customer::query()->orderBy('id')->get()->toArray(),
            'identities' => ChannelIdentity::query()->orderBy('id')->get()->toArray(),
            'conversations' => Conversation::query()->orderBy('id')->get()->toArray(),
            'proposals' => AppointmentProposal::query()->orderBy('id')->get()->toArray(),
            'appointments' => Appointment::query()->orderBy('id')->get()->toArray(),
            'audits' => AppointmentAudit::query()->orderBy('id')->get()->toArray(),
            'turns' => AiTurn::query()->orderBy('id')->get()->makeHidden(['claim_token'])->toArray(),
            'messages' => ConversationMessage::query()->orderBy('id')->get()->toArray(),
        ];
    }

    private function save(): void
    {
        if ($this->current !== []) {
            $this->report['turns'][$this->current['index']] = $this->current;
        }
        $this->report['usage'] = $this->provider->usage();
        $json = json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $key = config('ai.providers.openai.key');
        if (is_string($key) && $key !== '') {
            $json = str_replace($key, '[REDACTED]', $json);
        }
        if (file_put_contents($this->output.'.tmp', $json."\n", LOCK_EX) === false) {
            throw new RuntimeException('evaluation_evidence_write_failed');
        }
        chmod($this->output.'.tmp', 0600);
        if (! rename($this->output.'.tmp', $this->output)) {
            throw new RuntimeException('evaluation_evidence_write_failed');
        }
    }
}
