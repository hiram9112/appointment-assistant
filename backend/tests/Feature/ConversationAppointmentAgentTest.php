<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnOutcome;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\AppointmentProposalState;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentAudit;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\ChannelIdentity;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ConversationAppointmentAgentTest extends TestCase
{
    use DatabaseMigrations;

    private Business $business;

    private Service $service;

    private Professional $professional;

    private AiTurnCoordinator $turns;

    /** @var list<AgentResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 1)->setTime(12, 0));
        config(['ai.default' => 'openai', 'ai.providers.openai.models.text.default' => 'test-model']);
        $this->app->bind(ConversationStore::class, fn () => throw new RuntimeException('SDK storage must not be used.'));
        $this->business = Business::factory()->create(['timezone' => 'Europe/Madrid']);
        $this->service = Service::factory()->for($this->business)->create([
            'name' => 'Consulta', 'duration_minutes' => 60, 'price_amount' => '25.00', 'currency_code' => 'EUR',
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
        ]);
        $this->professional = $this->professional('Laura');
        BusinessOperatingHour::factory()->for($this->business)->create(['weekday' => 'friday', 'opens_at' => '08:00', 'closes_at' => '19:00']);
        $this->turns = app(AiTurnCoordinator::class);
        Event::listen(AgentPrompted::class, function (AgentPrompted $event): void {
            $this->responses[] = $event->response;
        });
    }

    #[DataProvider('registrationStates')]
    public function test_complete_create_view_reschedule_and_cancel_flows_share_one_conversation(bool $registered): void
    {
        $customer = $registered ? $this->identify() : null;
        $request = '¿Cuánto cuesta la consulta? Quiero reservar con Laura el 1 de febrero a las once.';
        if (! $registered) {
            $this->inbound($request);
            ConversationAgent::fake(['Para continuar, ¿cuál es tu nombre y apellido?'])->preventStrayPrompts();
            $this->process();
        }
        $origin = $this->inbound($registered ? $request : 'Ana García. Prefiero no dar mi teléfono.');
        $question = 'La consulta cuesta 25 €. ¿Confirmas la reserva con Laura el 1 de febrero a las once?';
        ConversationAgent::fake([
            ...($registered ? [] : [$this->toolCall('RegisterCustomerTool', ['first_name' => 'Ana', 'last_name' => 'García'])]),
            $this->toolCall('GetCustomerAppointmentsTool'),
            $this->toolCall('PrepareCreateAppointmentTool', $this->createArguments()),
            $question,
        ])->preventStrayPrompts();

        $message = $this->process();

        $customer ??= Customer::sole();
        $proposal = AppointmentProposal::sole();
        $this->assertSame($question, $message->fresh()->content);
        $this->assertSame($origin->id, $proposal->originating_turn_id);
        $this->assertSame($message->id, $proposal->confirmation_request_message_id);
        $this->assertSame(AppointmentProposalState::Pending, $proposal->state);
        $this->assertSame($customer->id, $message->conversation->customer_id);
        $this->assertSame('no_appointments', $this->toolResult('GetCustomerAppointmentsTool')['status']);
        $prepared = $this->toolResult('PrepareCreateAppointmentTool');
        $this->assertSame('prepared', $prepared['status']);
        $this->assertSame('Consulta', $prepared['presentation']['service_name']);
        $this->assertSame('Laura', $prepared['presentation']['professional_name']);
        $this->assertSame('2030-02-01T11:00:00+01:00', $prepared['presentation']['starts_at']);
        $this->assertArrayNotHasKey('confirmation_request', $prepared);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
        if (! $registered) {
            $this->assertNull($customer->phone);
            ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($request): bool {
                if ($prompt->prompt !== 'Ana García. Prefiero no dar mi teléfono.') {
                    return false;
                }
                $this->assertSame(['status' => 'unregistered'], $this->context($prompt)['customer']);
                $this->assertSame($request, $prompt->messages[0]->content);

                return true;
            });
        }

        $confirming = $this->inbound('Sí, confirmo la reserva.');
        ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), 'Tu cita está reservada.'])->preventStrayPrompts();
        $this->process();
        $appointment = Appointment::sole();
        $this->assertSame('created', $this->toolResult('ConfirmAppointmentProposalTool')['outcome']);
        $this->assertSame($confirming->id, $proposal->fresh()->confirming_turn_id);
        $this->assertSame($customer->id, $appointment->customer_id);
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($customer, $proposal, $origin, $question): bool {
            if ($prompt->prompt !== 'Sí, confirmo la reserva.') {
                return false;
            }
            $context = $this->context($prompt);
            $this->assertSame($customer->id, $context['customer']['id']);
            $this->assertSame($proposal->id, $context['active_proposal']['proposal_id']);
            $this->assertTrue($context['active_proposal']['confirmation_response_is_later']);
            $this->assertSame([
                ['user', $origin->inboundMessage->content], ['assistant', $question],
            ], array_map(fn (Message $message): array => [$message->role->value, $message->content], array_slice($prompt->messages, -2)));

            return true;
        });
        $this->inbound('¿Qué citas tengo?');
        ConversationAgent::fake([$this->toolCall('GetCustomerAppointmentsTool'), 'Tienes una consulta con Laura el 1 de febrero a las once.'])->preventStrayPrompts();
        $this->process();
        $this->assertSame([$appointment->id], array_column($this->toolResult('GetCustomerAppointmentsTool')['appointments'], 'id'));

        foreach ([null, $this->professional('Claudia')] as $alternative) {
            $this->inbound($alternative ? 'Mejor a las doce con Claudia.' : 'Muévela a las once y cuarto con la misma profesional.');
            $arguments = [
                'appointment_id' => $appointment->id,
                'starts_at' => $alternative ? '2030-02-01T12:00:00+01:00' : '2030-02-01T11:15:00+01:00',
                ...($alternative ? ['professional_id' => $alternative->id] : []),
            ];
            $question = $alternative
                ? '¿Confirmas mover la consulta de las once y cuarto con Laura a las doce con Claudia?'
                : '¿Confirmas mover la consulta con Laura de las once a las once y cuarto?';
            ConversationAgent::fake([$this->toolCall('PrepareRescheduleAppointmentTool', $arguments), $question])->preventStrayPrompts();
            $this->assertSame($question, $this->process()->content);
            $details = $this->toolResult('PrepareRescheduleAppointmentTool');
            $this->assertSame('Laura', $details['presentation']['previous']['professional_name']);
            $this->assertSame($alternative ? 'Claudia' : 'Laura', $details['presentation']['professional_name']);
            $this->assertSame($alternative?->id ?? $this->professional->id, $details['payload']['professional_id']);
            $this->inbound('Sí, confirmo ese cambio.');
            ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), 'El cambio está guardado.'])->preventStrayPrompts();
            $this->process();
            $this->assertSame('rescheduled', $this->toolResult('ConfirmAppointmentProposalTool')['outcome']);
        }

        $this->inbound('Quiero cancelar mi consulta.');
        ConversationAgent::fake([$this->toolCall('PrepareCancelAppointmentTool', ['appointment_id' => $appointment->id]), '¿Confirmas cancelar la consulta con Claudia del 1 de febrero a las doce?'])->preventStrayPrompts();
        $this->process();
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->inbound('Sí, cancélala.');
        ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), 'Tu cita está cancelada.'])->preventStrayPrompts();
        $this->process();
        $this->assertSame('cancelled', $this->toolResult('ConfirmAppointmentProposalTool')['outcome']);
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 4);
        $this->assertSame(4, AppointmentProposal::query()->where('state', 'completed')->count());
    }

    public static function registrationStates(): array
    {
        return ['registered' => [true], 'registration without phone' => [false]];
    }

    #[DataProvider('replies')]
    public function test_decline_ambiguity_and_changed_selections_do_not_execute_the_old_proposal(string $reply, string $branch): void
    {
        $this->identify();
        $old = $this->prepare();
        $this->inbound($reply);
        $calls = match ($branch) {
            'decline' => [$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'decline'])],
            'replace' => [$this->toolCall('PrepareCreateAppointmentTool', $this->createArguments('2030-02-01T12:00:00+01:00'))],
            default => [],
        };
        ConversationAgent::fake([...$calls, 'Respuesta del modelo con aclaración o nueva pregunta de confirmación.'])->preventStrayPrompts();
        $this->process();
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertSame($branch === 'ambiguous' ? AppointmentProposalState::Pending : AppointmentProposalState::Rejected, $old->fresh()->state);
        if ($branch === 'decline') {
            $this->assertSame('declined', $old->fresh()->result['outcome']);
        }
        if ($branch === 'replace') {
            $new = AppointmentProposal::query()->where('state', 'pending')->sole();
            $this->assertNotSame($old->id, $new->id);
            $this->assertSame('2030-02-01T11:00:00+00:00', $new->payload['starts_at']);
            $this->inbound('Sí, confirmo la nueva propuesta.');
            ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), 'Reserva confirmada.'])->preventStrayPrompts();
            $this->process();
            $this->assertSame($new->id, $this->toolResult('ConfirmAppointmentProposalTool')['proposal_id']);
            $this->assertSame('2030-02-01T11:00:00+00:00', Appointment::sole()->starts_at->toIso8601String());
            $this->assertNull($old->fresh()->result);
        }
    }

    public static function replies(): array
    {
        return [
            'negative' => ['No, gracias.', 'decline'],
            'ambiguous' => ['Puede ser, ¿qué opinas?', 'ambiguous'],
            'conditional' => ['Sí, pero a las doce.', 'replace'],
            'changed selection' => ['Mejor a las doce.', 'replace'],
        ];
    }

    public function test_same_turn_and_premature_confirmation_are_rejected_even_if_the_fake_requests_execution(): void
    {
        $this->identify();
        $this->inbound('Reserva a las once, sí, confirmo ya.');
        ConversationAgent::fake([
            $this->toolCall('PrepareCreateAppointmentTool', $this->createArguments()),
            $this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']),
            function (): string {
                $this->assertSame(0, DB::transactionLevel());
                $this->inbound('Sí, antes de recibir la pregunta.');

                return '¿Confirmas la consulta con Laura el 1 de febrero a las once?';
            },
        ])->preventStrayPrompts();
        $question = $this->process();
        $this->assertSame('confirmation_required', $this->toolResult('ConfirmAppointmentProposalTool')['status']);
        ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), 'Necesito que confirmes después de la pregunta.'])->preventStrayPrompts();
        $this->process();
        $this->assertSame('confirmation_required', $this->toolResult('ConfirmAppointmentProposalTool')['status']);
        ConversationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'Sí, antes de recibir la pregunta.'
            && $this->context($prompt)['active_proposal']['confirmation_response_is_later'] === false);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertSame($question->id, AppointmentProposal::sole()->confirmation_request_message_id);
        $this->inbound('Ahora sí, confirmo.');
        ConversationAgent::fake([
            $this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']),
            $this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']),
            'Tu cita está guardada.',
        ])->preventStrayPrompts();
        $this->process();
        $this->inbound('Sí, otra vez.');
        ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), 'La operación anterior ya terminó.'])->preventStrayPrompts();
        $this->process();
        $this->assertSame('no_pending_proposal', $this->toolResult('ConfirmAppointmentProposalTool')['status']);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_appointment_selection_and_reads_are_customer_and_business_scoped(): void
    {
        $customer = $this->identify();
        $first = $this->appointment($customer);
        $second = $this->appointment($customer, '2030-02-01 12:00 UTC');
        $foreignBusiness = Appointment::factory()->for($customer)->create();
        $otherCustomer = $this->appointment(Customer::factory()->create(), '2030-02-01 14:00 UTC');
        $this->inbound('Cancela mi cita.');
        ConversationAgent::fake([$this->toolCall('GetCustomerAppointmentsTool'), '¿Quieres cancelar la consulta de las once o la de la una?'])->preventStrayPrompts();
        $this->process();
        $this->assertSame([$second->id, $first->id], array_column($this->toolResult('GetCustomerAppointmentsTool')['appointments'], 'id'));
        $this->assertDatabaseCount('appointment_proposals', 0);
        foreach ([$foreignBusiness, $otherCustomer] as $foreign) {
            $this->inbound('Intenta usar una referencia ajena.');
            ConversationAgent::fake([$this->toolCall('PrepareCancelAppointmentTool', [
                'appointment_id' => $foreign->id, 'customer_id' => $foreign->customer_id, 'business_id' => $foreign->service->business_id,
            ]), 'No puedo acceder a esa cita.'])->preventStrayPrompts();
            $this->process();
            $this->assertSame('invalid_reference', $this->toolResult('PrepareCancelAppointmentTool')['status']);
        }
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    #[DataProvider('executionOutcomes')]
    public function test_authoritative_conflict_ineligibility_and_noop_results_reach_the_agent(string $case, string $outcome): void
    {
        $customer = $this->identify();
        if ($case === 'noop') {
            $appointment = $this->appointment($customer);
            $this->inbound('Mantén mi cita en la misma hora con Laura.');
            ConversationAgent::fake([$this->toolCall('PrepareRescheduleAppointmentTool', [
                'appointment_id' => $appointment->id, 'starts_at' => '2030-02-01T11:00:00+01:00',
            ]), '¿Confirmas mantener los mismos datos?'])->preventStrayPrompts();
            $this->process();
            $proposal = AppointmentProposal::sole();
        } else {
            $proposal = $this->prepare();
            if ($case === 'conflict') {
                $this->appointment(Customer::factory()->create());
            } else {
                $this->service->update(['active' => false]);
            }
        }
        $this->inbound('Sí, confirmo.');
        $answer = 'Respuesta del modelo basada en el resultado, sin inventar cambios.';
        ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), $answer])->preventStrayPrompts();
        $this->assertSame($answer, $this->process()->content);
        $result = $this->toolResult('ConfirmAppointmentProposalTool');
        $this->assertSame($outcome, $result['outcome']);
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertSame($case === 'noop' ? 'completed' : 'rejected', $result['status']);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public static function executionOutcomes(): array
    {
        return ['occupied' => ['conflict', 'slot_unavailable'], 'ineligible' => ['ineligible', 'invalid_request'], 'no change' => ['noop', 'unchanged']];
    }

    public function test_explicit_request_to_keep_an_appointment_can_be_acknowledged_with_only_a_scoped_read(): void
    {
        $customer = $this->identify();
        $appointment = $this->appointment($customer);
        $before = $appointment->fresh()->getAttributes();
        $this->inbound('Mantén mi cita con Laura el 1 de febrero a las once, sin cambiar ningún dato.');
        $answer = 'Tu consulta con Laura el 1 de febrero a las once sigue confirmada sin cambios.';
        ConversationAgent::fake([$this->toolCall('GetCustomerAppointmentsTool'), $answer])->preventStrayPrompts();

        $this->assertSame($answer, $this->process()->fresh()->content);
        $listing = $this->toolResult('GetCustomerAppointmentsTool');
        $this->assertSame('ok', $listing['status']);
        $this->assertSame([$appointment->id], array_column($listing['appointments'], 'id'));
        $this->assertSame('confirmed', $listing['appointments'][0]['status']);
        $this->assertSame($before, $appointment->fresh()->getAttributes());
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    #[DataProvider('interruptions')]
    public function test_sdk_tool_writes_recover_before_generation_without_repeating_them(string $phase, string $failure): void
    {
        $this->identify();
        if ($phase === 'completed') {
            $this->prepare();
        }
        $turn = $this->inbound($phase === 'prepared' ? 'Reserva con Laura a las once.' : 'Sí, confirmo.');
        $tool = $phase === 'prepared' ? 'PrepareCreateAppointmentTool' : 'ConfirmAppointmentProposalTool';
        ConversationAgent::fake([
            $this->toolCall($tool, $phase === 'prepared' ? $this->createArguments() : ['decision' => 'confirm']),
            function () use ($failure): string {
                $this->assertSame(0, DB::transactionLevel());
                if ($failure === 'provider') {
                    throw new RuntimeException('Interrupted after durable write');
                }
                ConversationMessage::creating(fn () => throw new RuntimeException('Interrupted after durable write'));

                return 'Unaccepted model response';
            },
        ])->preventStrayPrompts();
        try {
            $this->process();
            $this->fail('An interruption must propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interrupted after durable write', $exception->getMessage());
        } finally {
            ConversationMessage::flushEventListeners();
        }
        $proposal = AppointmentProposal::sole();
        $payload = $proposal->payload;
        $result = $proposal->result;
        $this->assertSame(AiTurnState::Failed, $turn->fresh()->state);
        $this->travelTo($turn->fresh()->next_attempt_at);
        $this->assertSame($phase, $proposal->state->value);
        $this->assertSame($phase === 'prepared' ? 0 : 1, Appointment::count());
        $this->service->update(['active' => false, 'name' => 'Changed catalogue']);
        AppointmentProposal::creating(fn () => throw new RuntimeException('Must not recreate proposal'));
        Appointment::saving(fn () => throw new RuntimeException('Must not repeat operation'));
        $generated = $phase === 'prepared' ? '¿Confirmas la consulta con Laura el 1 de febrero a las once?' : 'Tu consulta con Laura está reservada.';
        ConversationAgent::fake(function () use ($generated): string {
            $this->assertSame(0, DB::transactionLevel());

            return $generated;
        })->preventStrayPrompts();
        try {
            $this->assertSame($generated, $this->process()->fresh()->content);
        } finally {
            AppointmentProposal::flushEventListeners();
            Appointment::flushEventListeners();
        }
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($phase, $proposal, $result): bool {
            $context = $this->context($prompt);
            $key = $phase === 'prepared' ? 'prepared_proposal' : 'appointment_operation_result';
            if (! isset($context[$key])) {
                return false;
            }
            $expected = $phase === 'prepared' ? [...$proposal->structuredDetails(), 'status' => 'prepared'] : $result;
            $this->assertSame($expected, $context[$key]);
            $this->assertSame('Consulta', $context[$key][$phase === 'prepared' ? 'presentation' : 'appointment']['service_name']);
            $this->assertNotContains('Unaccepted model response', array_map(fn (Message $message): string => $message->content, $prompt->messages));

            return true;
        });
        $this->assertSame($payload, $proposal->fresh()->payload);
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertDatabaseCount('appointment_proposals', 1);
        $this->assertDatabaseCount('appointment_audits', $phase === 'prepared' ? 0 : 1);
        if ($phase === 'completed') {
            $proposalBefore = $proposal->fresh()->getAttributes();
            $appointmentBefore = Appointment::sole()->getAttributes();
            $auditBefore = AppointmentAudit::sole()->getAttributes();
            $this->inbound('Sí, otra vez.');
            $answer = 'Tu consulta con Laura ya quedó reservada. ¿A qué te refieres con «otra vez»?';
            ConversationAgent::fake([$answer])->preventStrayPrompts();
            $this->assertSame($answer, $this->process()->fresh()->content);
            ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($generated): bool {
                if ($prompt->prompt !== 'Sí, otra vez.') {
                    return false;
                }
                $context = $this->context($prompt);
                $this->assertNull($context['active_proposal']);
                $this->assertArrayNotHasKey('prepared_proposal', $context);
                $this->assertArrayNotHasKey('appointment_operation_result', $context);
                $this->assertSame([
                    ['user', 'Sí, confirmo.'], ['assistant', $generated],
                ], array_map(fn (Message $message): array => [$message->role->value, $message->content], array_slice($prompt->messages, -2)));
                $this->assertNotContains('Unaccepted model response', array_map(fn (Message $message): string => $message->content, $prompt->messages));
                $this->assertStringContainsString('Preserve success communicated in completed history, including recovered success', $prompt->agent->instructions());
                $this->assertStringContainsString('"Sí, otra vez." needs acknowledgement and clarification, never repetition or reactivation', $prompt->agent->instructions());

                return true;
            });
            $this->assertCount(0, $this->responses[array_key_last($this->responses)]->toolResults);

            $this->inbound('Comprueba el estado actual de esa cita.');
            ConversationAgent::fake([$this->toolCall('GetCustomerAppointmentsTool'), 'La cita sigue confirmada.'])->preventStrayPrompts();
            $this->process();
            $this->assertSame([$appointmentBefore['id']], array_column($this->toolResult('GetCustomerAppointmentsTool')['appointments'], 'id'));
            $this->assertSame('confirmed', $this->toolResult('GetCustomerAppointmentsTool')['appointments'][0]['status']);
            $this->assertSame($proposalBefore, $proposal->fresh()->getAttributes());
            $this->assertSame($appointmentBefore, Appointment::sole()->getAttributes());
            $this->assertSame($auditBefore, AppointmentAudit::sole()->getAttributes());
            $this->assertSame(AppointmentProposalState::Completed, $proposal->fresh()->state);
            $this->assertDatabaseCount('appointment_proposals', 1);
            $this->assertDatabaseCount('appointments', 1);
            $this->assertDatabaseCount('appointment_audits', 1);
        }
    }

    public static function interruptions(): array
    {
        return [
            'prepared provider' => ['prepared', 'provider'], 'prepared assistant' => ['prepared', 'assistant'],
            'completed provider' => ['completed', 'provider'], 'completed assistant' => ['completed', 'assistant'],
        ];
    }

    #[DataProvider('registrationInterruptions')]
    public function test_interrupted_registration_is_resolved_fresh_and_booking_continues_on_retry(string $failure): void
    {
        $turn = $this->inbound('Soy Ana García. Sin teléfono. Reserva la consulta con Laura el 1 de febrero a las once.');
        ConversationAgent::fake([
            $this->toolCall('RegisterCustomerTool', ['first_name' => 'Ana', 'last_name' => 'García', 'phone' => null]),
            function () use ($failure): string {
                if ($failure === 'provider') {
                    throw new RuntimeException('Registration response interrupted');
                }
                ConversationMessage::creating(fn () => throw new RuntimeException('Registration response interrupted'));

                return 'Unaccepted registration response';
            },
        ])->preventStrayPrompts();
        try {
            $this->process();
            $this->fail('The registration response must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Registration response interrupted', $exception->getMessage());
        } finally {
            ConversationMessage::flushEventListeners();
        }
        $customer = Customer::sole();
        $this->assertSame($customer->id, $turn->inboundMessage->conversation->customer_id);
        $this->assertSame(AiTurnState::Failed, $turn->fresh()->state);
        $this->travelTo($turn->fresh()->next_attempt_at);
        ConversationAgent::fake([
            $this->toolCall('GetCustomerAppointmentsTool'),
            $this->toolCall('PrepareCreateAppointmentTool', $this->createArguments()),
            '¿Confirmas la consulta con Laura el 1 de febrero a las once?',
        ])->preventStrayPrompts();
        $this->process();
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($customer): bool {
            $context = $this->context($prompt);
            if ($context['customer']['status'] !== 'identified') {
                return false;
            }
            $this->assertSame($customer->id, $context['customer']['id']);
            $this->assertSame('Ana', $context['customer']['first_name']);
            $this->assertSame([], $prompt->messages);

            return true;
        });
        $this->assertSame('no_appointments', $this->toolResult('GetCustomerAppointmentsTool')['status']);
        $this->assertSame($turn->id, AppointmentProposal::sole()->originating_turn_id);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('appointments', 0);
    }

    public static function registrationInterruptions(): array
    {
        return ['provider' => ['provider'], 'assistant persistence' => ['assistant']];
    }

    public function test_proposal_context_is_not_exposed_to_a_reassigned_channel_identity(): void
    {
        $customer = $this->identify();
        $proposal = $this->prepare();
        $other = Customer::factory()->create();
        ChannelIdentity::query()->where('customer_id', $customer->id)->update(['customer_id' => $other->id]);
        $turn = $this->inbound('Sí, confirmo.');
        ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), 'No puedo verificar tu identidad para esa propuesta.'])->preventStrayPrompts();
        try {
            $this->process();
            $this->fail('Mismatched identity must stop before supplying context to the agent.');
        } catch (LogicException $exception) {
            $this->assertSame('Customer identity could not be verified for the Conversation.', $exception->getMessage());
        }
        ConversationAgent::assertNotPrompted('Sí, confirmo.');
        ConversationAgent::assertPromptedTimes(1);
        $this->assertCount(1, $this->responses);
        $this->assertSame(AiTurnState::Failed, $turn->fresh()->state);
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);

        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $customer->id]);
        $this->travelTo($turn->fresh()->next_attempt_at);
        ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), 'Tu cita está reservada.'])->preventStrayPrompts();
        $this->process();
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($customer, $proposal): bool {
            if ($prompt->prompt !== 'Sí, confirmo.') {
                return false;
            }
            $context = $this->context($prompt);
            $this->assertSame($customer->id, $context['customer']['id']);
            $this->assertSame($proposal->id, $context['active_proposal']['proposal_id']);
            $this->assertSame([
                ['user', 'Reserva con Laura el 1 de febrero a las once.'],
                ['assistant', '¿Confirmas la consulta con Laura el 1 de febrero a las once?'],
            ], array_map(fn (Message $message): array => [$message->role->value, $message->content], $prompt->messages));

            return true;
        });
        $this->assertSame(AiTurnState::Completed, $turn->fresh()->state);
        $this->assertSame(AppointmentProposalState::Completed, $proposal->fresh()->state);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    #[DataProvider('failedIdentities')]
    public function test_failed_identity_cannot_receive_private_completed_history_even_without_a_proposal(string $failure): void
    {
        $customer = $this->identify();
        $appointment = $this->appointment($customer);
        $origin = $this->inbound('¿Qué citas tengo?');
        $conversation = $origin->inboundMessage->conversation;
        $private = 'Tu consulta privada con Laura es el 1 de febrero a las once.';
        ConversationAgent::fake([$this->toolCall('GetCustomerAppointmentsTool'), $private])->preventStrayPrompts();
        $this->process();
        $this->assertSame($customer->id, $conversation->fresh()->customer_id);
        $this->assertSame([$appointment->id], array_column($this->toolResult('GetCustomerAppointmentsTool')['appointments'], 'id'));
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->inbound('¿Puedes repetir la hora?');
        ConversationAgent::fake([$private])->preventStrayPrompts();
        $this->process();
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($customer, $private): bool {
            if ($prompt->prompt !== '¿Puedes repetir la hora?') {
                return false;
            }
            $this->assertSame($customer->id, $this->context($prompt)['customer']['id']);
            $this->assertSame([
                ['user', '¿Qué citas tengo?'], ['assistant', $private],
            ], array_map(fn (Message $message): array => [$message->role->value, $message->content], $prompt->messages));

            return true;
        });

        $channel = 'whatsapp';
        if ($failure === 'reassigned') {
            ChannelIdentity::query()->where('customer_id', $customer->id)->update(['customer_id' => Customer::factory()->create()->id]);
        } elseif ($failure === 'missing') {
            ChannelIdentity::query()->where('customer_id', $customer->id)->delete();
        } else {
            $channel = 'web';
            $conversation->update(['channel' => $channel]);
        }
        $turn = $this->turns->inbound($this->business, $channel, 'sender', 'next', 'Repite los detalles privados de mi cita.');
        ConversationAgent::fake([$this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']), 'No puedo verificar tu identidad.'])->preventStrayPrompts();
        try {
            app(ConversationTurnProcessor::class)->processNext($this->business, $channel, 'sender');
            $this->fail('Failed identity must not expose private history to the agent.');
        } catch (LogicException $exception) {
            $this->assertSame('Customer identity could not be verified for the Conversation.', $exception->getMessage());
        }
        ConversationAgent::assertNotPrompted('Repite los detalles privados de mi cita.');
        ConversationAgent::assertPromptedTimes(2);
        $this->assertCount(2, $this->responses);
        $this->assertSame(AiTurnState::Failed, $turn->fresh()->state);
        $this->assertSame($customer->id, $conversation->fresh()->customer_id);
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public static function failedIdentities(): array
    {
        return ['reassigned identity' => ['reassigned'], 'missing identity' => ['missing'], 'unsupported channel' => ['unsupported']];
    }

    public function test_unassociated_completed_history_cannot_be_claimed_by_a_resolved_customer(): void
    {
        $customer = $this->identify();
        $origin = $this->inbound('¿Qué citas tengo?');
        $claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $private = 'Tu consulta privada con Laura es el 1 de febrero a las once.';
        $this->turns->complete($this->business, $claim, $private);
        $this->assertNull($origin->inboundMessage->conversation->customer_id);
        ChannelIdentity::query()->where('customer_id', $customer->id)->update(['customer_id' => Customer::factory()->create()->id]);
        $turn = $this->inbound('Repite mis citas.');
        ConversationAgent::fake()->preventStrayPrompts();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->process();
                $this->fail('Existing unassociated history is not proof of Customer ownership.');
            } catch (LogicException $exception) {
                $this->assertSame('Customer identity could not be verified for the Conversation history.', $exception->getMessage());
            }
            $this->assertSame(AiTurnState::Failed, $turn->fresh()->state);
            $this->assertNull($origin->inboundMessage->conversation->fresh()->customer_id);
            $this->travelTo($turn->fresh()->next_attempt_at);
        }
        ConversationAgent::assertNeverPrompted();
        $this->assertSame($private, $origin->fresh()->assistantMessage->content);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    #[DataProvider('failedRecoveryIdentities')]
    public function test_failed_identity_cannot_receive_history_or_durable_context_when_a_turn_is_retried(string $phase, bool $missing): void
    {
        $customer = $this->identify();
        $this->prepare();
        $turn = $this->inbound($phase === 'prepared' ? 'Mejor a las doce.' : 'Sí, confirmo.');
        ConversationAgent::fake([
            $this->toolCall(
                $phase === 'prepared' ? 'PrepareCreateAppointmentTool' : 'ConfirmAppointmentProposalTool',
                $phase === 'prepared' ? $this->createArguments('2030-02-01T12:00:00+01:00') : ['decision' => 'confirm'],
            ),
            fn () => throw new RuntimeException('Interrupted after durable write'),
        ])->preventStrayPrompts();
        try {
            $this->process();
            $this->fail('The interrupted turn must remain retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Interrupted after durable write', $exception->getMessage());
        }
        $proposal = AppointmentProposal::query()->latest('id')->firstOrFail();
        $before = $proposal->getAttributes();
        $this->assertSame($phase, $proposal->state->value);
        if ($missing) {
            ChannelIdentity::query()->where('customer_id', $customer->id)->delete();
        } else {
            ChannelIdentity::query()->where('customer_id', $customer->id)->update(['customer_id' => Customer::factory()->create()->id]);
        }
        $responsesBeforeRetry = count($this->responses);
        $this->travelTo($turn->fresh()->next_attempt_at);
        ConversationAgent::fake([
            $this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']),
            $this->toolCall('PrepareCreateAppointmentTool', $this->createArguments()),
            'No puedo verificar tu identidad.',
        ])->preventStrayPrompts();
        try {
            $this->process();
            $this->fail('Failed identity must not expose history or durable recovery context to the agent.');
        } catch (LogicException $exception) {
            $this->assertSame('Customer identity could not be verified for the Conversation.', $exception->getMessage());
        }
        ConversationAgent::assertPromptedTimes(2);
        $this->assertCount($responsesBeforeRetry, $this->responses);
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertDatabaseCount('appointments', $phase === 'completed' ? 1 : 0);
        $this->assertDatabaseCount('appointment_audits', $phase === 'completed' ? 1 : 0);
        $this->assertSame($customer->id, $proposal->conversation->fresh()->customer_id);

        if ($missing) {
            ChannelIdentity::factory()->for($customer)->create(['external_id' => 'sender']);
        } else {
            ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $customer->id]);
        }
        ConversationAgent::fake(['Respuesta recuperada para el cliente correcto.'])->preventStrayPrompts();
        $this->travelTo($turn->fresh()->next_attempt_at);
        $this->process();
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($phase, $customer, $proposal): bool {
            $context = $this->context($prompt);
            $key = $phase === 'prepared' ? 'prepared_proposal' : 'appointment_operation_result';
            if (! isset($context[$key])) {
                return false;
            }
            $this->assertSame($customer->id, $context['customer']['id']);
            $this->assertSame($proposal->id, $context[$key]['proposal_id']);
            $this->assertSame([
                ['user', 'Reserva con Laura el 1 de febrero a las once.'],
                ['assistant', '¿Confirmas la consulta con Laura el 1 de febrero a las once?'],
            ], array_map(fn (Message $message): array => [$message->role->value, $message->content], $prompt->messages));

            return true;
        });
        $this->assertSame($phase === 'prepared' ? AppointmentProposalState::Pending : AppointmentProposalState::Completed, $proposal->fresh()->state);
        $this->assertSame($before['payload'], $proposal->fresh()->getRawOriginal('payload'));
        $this->assertSame($before['result'], $proposal->fresh()->getRawOriginal('result'));
        $this->assertDatabaseCount('appointment_audits', $phase === 'completed' ? 1 : 0);
    }

    public static function failedRecoveryIdentities(): array
    {
        return [
            'prepared reassigned' => ['prepared', false], 'prepared missing' => ['prepared', true],
            'completed reassigned' => ['completed', false], 'completed missing' => ['completed', true],
        ];
    }

    public function test_expiration_discards_old_proposal_and_history_but_keeps_customer_and_appointments(): void
    {
        $customer = $this->identify();
        $appointment = $this->appointment($customer, '2030-02-01 14:00 UTC');
        $old = $this->prepare();
        $this->travel(12)->hours();
        $new = $this->inbound('Sí. ¿Qué citas tengo?');
        ConversationAgent::fake([
            $this->toolCall('GetCustomerAppointmentsTool'), $this->toolCall('ConfirmAppointmentProposalTool', ['decision' => 'confirm']),
            'Tienes una consulta; no hay una propuesta pendiente en esta conversación.',
        ])->preventStrayPrompts();
        $this->process();
        $this->assertNotSame($old->conversation_id, $new->inboundMessage->conversation_id);
        $this->assertSame([$appointment->id], array_column($this->toolResult('GetCustomerAppointmentsTool')['appointments'], 'id'));
        $this->assertSame('no_pending_proposal', $this->toolResult('ConfirmAppointmentProposalTool')['status']);
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($customer): bool {
            if ($prompt->prompt !== 'Sí. ¿Qué citas tengo?') {
                return false;
            }
            $context = $this->context($prompt);
            $this->assertSame($customer->id, $context['customer']['id']);
            $this->assertNull($context['active_proposal']);
            $this->assertArrayNotHasKey('prepared_proposal', $context);
            $this->assertArrayNotHasKey('appointment_operation_result', $context);
            $this->assertSame([], $prompt->messages);

            return true;
        });
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_stale_agent_tools_cannot_register_or_prepare_after_takeover(): void
    {
        $turn = $this->inbound('Soy Ana García, quiero reservar.');
        ConversationAgent::fake([
            function (): ToolCall {
                $this->travel(301)->seconds();
                $claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
                $this->turns->complete($this->business, $claim, 'Respuesta aceptada del nuevo intento.');

                return $this->toolCall('RegisterCustomerTool', ['first_name' => 'Ana', 'last_name' => 'García']);
            },
            $this->toolCall('PrepareCreateAppointmentTool', $this->createArguments()),
            'Respuesta obsoleta',
        ])->preventStrayPrompts();
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->process());
        $this->assertSame('not_authorized', $this->toolResult('RegisterCustomerTool')['status']);
        $this->assertSame('not_authorized', $this->toolResult('PrepareCreateAppointmentTool')['status']);
        $this->assertSame('Respuesta aceptada del nuevo intento.', $turn->fresh()->assistantMessage->content);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('appointment_proposals', 0);
    }

    public function test_instructions_cover_confirmation_branching_spanish_and_mixed_intent_without_backend_templates(): void
    {
        $this->identify();
        $this->inbound('Información, por favor.');
        ConversationAgent::fake(['Respuesta informativa.'])->preventStrayPrompts();
        $this->process();
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            $instructions = $prompt->agent->instructions();
            foreach ([
                'natural, concise, professional Spanish', 'Express dates naturally',
                'subsequent, unconditional, explicit affirmative response', 'decision decline',
                'conditional acceptance such as "sí, pero a las once"', 'never confirm the old proposal',
                'Clarify missing dates, times, or ambiguous references first',
                'only the latest can be confirmed', 'only current proposal',
                'Phone is optional', 'tool result supersedes the pre-registration runtime snapshot',
                'original and proposed details, including any Professional change',
                'Prepared and pending are not completed', 'unchanged rescheduling as no change',
                'Technical errors are not rejection, success', 'one coherent response',
                'customer-supplied IDs', 'Rescheduling candidates must use PrepareRescheduleAppointmentTool',
                'execute the prepared booking, rescheduling, or cancellation, not prepare it again',
                '¿Confirmas esta reserva?',
                'after success, report completion',
                'Only announce a new Appointment-changing success from an authoritative completed tool result',
                'Preserve success communicated in completed history, including recovered success',
                'no_pending_proposal does not undo it',
                'History supports continuity, not execution or authorization',
                'After success with no active proposal',
                '"Sí, otra vez." needs acknowledgement and clarification, never repetition or reactivation',
                'use scoped GetCustomerAppointmentsTool, not availability',
                'New operations need fresh proposals and later confirmation',
            ] as $rule) {
                $this->assertStringContainsString($rule, $instructions);
            }
            $this->assertStringNotContainsString('Only read capabilities are available', $instructions);
            $this->assertStringNotContainsString("in the customer's language", $instructions);

            return true;
        });
    }

    private function identify(): Customer
    {
        $customer = Customer::factory()->create(['first_name' => 'Ana', 'last_name' => 'García']);
        ChannelIdentity::factory()->for($customer)->create(['external_id' => 'sender']);

        return $customer;
    }

    private function professional(string $name): Professional
    {
        $professional = Professional::factory()->for($this->business)->create(['display_name' => $name]);
        $professional->services()->attach($this->service);
        ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => 'friday', 'starts_at' => '08:00', 'ends_at' => '19:00']);

        return $professional;
    }

    private function appointment(Customer $customer, string $start = '2030-02-01 10:00 UTC'): Appointment
    {
        return Appointment::factory()->for($customer)->for($this->service)->for($this->professional)->create([
            'starts_at' => $start, 'ends_at' => (new \DateTimeImmutable($start))->modify('+1 hour'),
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'status' => 'confirmed',
        ]);
    }

    private function prepare(): AppointmentProposal
    {
        $this->inbound('Reserva con Laura el 1 de febrero a las once.');
        ConversationAgent::fake([$this->toolCall('PrepareCreateAppointmentTool', $this->createArguments()), '¿Confirmas la consulta con Laura el 1 de febrero a las once?'])->preventStrayPrompts();
        $this->process();

        return AppointmentProposal::sole();
    }

    /** @return array<string, mixed> */
    private function createArguments(string $start = '2030-02-01T11:00:00+01:00'): array
    {
        return ['service_id' => $this->service->id, 'professional_id' => $this->professional->id, 'starts_at' => $start];
    }

    /** @param array<string, mixed> $arguments */
    private function toolCall(string $tool, array $arguments = []): ToolCall
    {
        return new ToolCall(fake()->uuid(), $tool, $arguments);
    }

    private function inbound(string $content): AiTurn
    {
        return $this->turns->inbound($this->business, 'whatsapp', 'sender', (string) (AiTurn::count() + 1), $content);
    }

    private function process(): ConversationMessage|AiTurnOutcome
    {
        return app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
    }

    /** @return array<string, mixed> */
    private function toolResult(string $name): array
    {
        $response = $this->responses[array_key_last($this->responses)];
        $result = $response->toolResults->last(fn ($result): bool => $result->name === $name);
        $this->assertNotNull($result, "Missing result for $name");

        return json_decode($result->result, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function context(AgentPrompt $prompt): array
    {
        $this->assertSame(1, preg_match('/<runtime_context>\n(.*)\n<\/runtime_context>$/s', $prompt->agent->instructions(), $matches));

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
