<?php

namespace Tests\Feature;

use App\Application\Availability\AppointmentOccupancy;
use App\Application\Availability\PostgresAppointmentOccupancy;
use App\Application\Availability\ReschedulingAppointmentOccupancy;
use App\Application\Availability\TimeInterval;
use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnOutcome;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\AppointmentProposalOperation;
use App\Application\Conversation\AppointmentProposalState;
use App\Application\Conversation\ConfirmAppointmentProposal;
use App\Application\Conversation\ConfirmAppointmentProposalTool;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Application\Conversation\PrepareAppointmentProposal;
use App\Application\Conversation\RegisterCustomerTool;
use App\Application\Customer\ResolveCustomer;
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
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ConfirmAppointmentProposalTest extends TestCase
{
    use DatabaseMigrations;

    private Business $business;

    private Customer $customer;

    private Service $service;

    private Professional $professional;

    private AiTurnCoordinator $turns;

    private AiTurnClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2030, 1, 1)->setTime(12, 0));
        $this->business = Business::factory()->create(['timezone' => 'Europe/Madrid']);
        $this->service = Service::factory()->for($this->business)->create([
            'name' => 'Consulta', 'active' => true, 'duration_minutes' => 60,
            'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20,
        ]);
        $this->professional = Professional::factory()->for($this->business)->create(['display_name' => 'Laura']);
        $this->professional->services()->attach($this->service);
        BusinessOperatingHour::factory()->for($this->business)->create(['weekday' => 'friday', 'opens_at' => '08:00', 'closes_at' => '19:00']);
        ProfessionalAvailabilityInterval::factory()->for($this->professional)->create(['weekday' => 'friday', 'starts_at' => '08:00', 'ends_at' => '19:00']);
        $this->customer = Customer::factory()->create();
        ChannelIdentity::factory()->for($this->customer)->create(['external_id' => 'sender']);
        $this->turns = app(AiTurnCoordinator::class);
        $this->turns->inbound($this->business, 'whatsapp', 'sender', '1', 'Quiero una cita');
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
    }

    #[DataProvider('operations')]
    public function test_executes_only_stored_parameters_and_recovers_repeated_calls_and_messages(string $operation): void
    {
        $proposal = $this->pending($operation);
        $confirmingTurn = $this->claim->turnId;
        $result = $this->invoke([
            'decision' => 'confirm', 'service_id' => -1, 'professional_id' => -1,
            'appointment_id' => -1, 'starts_at' => '2040-01-01', 'customer_id' => -1,
            'business_id' => -1, 'actor_type' => 'admin', 'actor_id' => 'spoof', 'source' => 'spoof',
        ]);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(['create' => 'created', 'reschedule' => 'rescheduled', 'cancel' => 'cancelled'][$operation], $result['outcome']);
        $appointment = Appointment::sole();
        $this->assertSame($appointment->id, $result['appointment']['id']);
        $this->assertSame($this->customer->id, $appointment->customer_id);
        $this->assertSame($this->professional->id, $appointment->professional_id);
        $this->assertSame('2030-02-01T11:00:17+01:00', $result['appointment']['starts_at']);
        $this->assertSame('Europe/Madrid', $result['appointment']['timezone']);
        $audit = AppointmentAudit::sole();
        $this->assertSame('customer', $audit->actor_type);
        $this->assertSame((string) $this->customer->id, $audit->actor_id);
        $this->assertSame('whatsapp', $audit->source);
        $this->assertSame($confirmingTurn, $proposal->fresh()->confirming_turn_id);
        $this->assertSame(AppointmentProposalState::Completed, $proposal->fresh()->state);
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertSame($result, $this->invoke(['decision' => 'confirm']));
        $this->assertSame($result, $this->invoke(['decision' => 'decline']));
        $this->nextTurn('Sí, otra vez');
        $this->assertNull(app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $this->assertSame(['status' => 'no_pending_proposal'], $this->invoke(['decision' => 'confirm']));
        $this->assertSame($confirmingTurn, $proposal->fresh()->confirming_turn_id);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public static function operations(): array
    {
        return ['create' => ['create'], 'reschedule' => ['reschedule'], 'cancel' => ['cancel']];
    }

    #[DataProvider('deliveryDecisions')]
    public function test_exact_duplicate_inbound_delivery_reuses_the_confirming_turn_before_and_after_recovery(bool $declined): void
    {
        $proposal = $this->prepare($declined ? 'cancel' : 'create');
        $this->turns->complete($this->business, $this->claim, '¿Confirmas la propuesta?');
        $content = $declined ? 'No, gracias' : 'Sí, confirmo';
        $turn = $this->turns->inbound($this->business, 'whatsapp', 'sender', 'confirmation-delivery', $content);
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $firstClaim = $this->claim;

        $duplicate = $this->turns->inbound($this->business, 'whatsapp', 'sender', 'confirmation-delivery', $content);
        $this->assertSame($turn->id, $duplicate->id);
        $this->assertSame($turn->inbound_message_id, $duplicate->inbound_message_id);
        $this->assertSame(AiTurnOutcome::Busy, $this->turns->claimNext($this->business, 'whatsapp', 'sender'));
        $result = $this->invoke(['decision' => $declined ? 'decline' : 'confirm']);
        $this->assertSame($declined ? 'declined' : 'created', $result['outcome']);
        $proposalBefore = $proposal->fresh()->getAttributes();

        $this->turns->fail($this->business, $this->claim);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $this->assertSame($turn->id, $this->turns->inbound($this->business, 'whatsapp', 'sender', 'confirmation-delivery', $content)->id);
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($firstClaim->turnId, $this->claim->turnId);
        $this->assertNotSame($firstClaim->token, $this->claim->token);
        $this->assertSame($result, app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $this->assertSame($result, $this->invoke(['decision' => 'confirm']));
        $this->assertSame($result, $this->invoke(['decision' => 'decline']));
        $this->turns->complete($this->business, $this->claim, 'El resultado guardado.');

        $duplicate = $this->turns->inbound($this->business, 'whatsapp', 'sender', 'confirmation-delivery', $content);
        $this->assertSame($turn->id, $duplicate->id);
        $this->assertSame(AiTurnState::Completed, $duplicate->state);
        ConversationAgent::fake()->preventStrayPrompts();
        $this->assertSame(AiTurnOutcome::Idle, app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender'));
        ConversationAgent::assertNeverPrompted();
        $this->assertSame($proposalBefore, $proposal->fresh()->getAttributes());
        $this->assertSame($turn->id, $proposal->fresh()->confirming_turn_id);
        $this->assertDatabaseCount('ai_turns', 2);
        $this->assertDatabaseCount('conversation_messages', 4);
        $this->assertDatabaseCount('appointment_proposals', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', $declined ? 0 : 1);
    }

    public static function deliveryDecisions(): array
    {
        return ['accepted' => [false], 'declined' => [true]];
    }

    #[DataProvider('operations')]
    public function test_channel_identity_reassignment_cannot_transfer_a_prepared_proposal(string $operation): void
    {
        $proposal = $this->pending($operation);
        $before = $proposal->fresh()->getAttributes();
        $appointmentsBefore = Appointment::query()->get()->map->getAttributes()->all();
        $other = Customer::factory()->create();
        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $other->id]);
        $this->assertSame($other->id, app(ResolveCustomer::class)->handle('whatsapp', 'sender')->id);

        $this->assertSame(['status' => 'identification_failed'], $this->invoke(['decision' => 'confirm']));
        $this->assertSame($this->customer->id, $proposal->conversation->fresh()->customer_id);
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertSame($appointmentsBefore, Appointment::query()->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('appointment_audits', 0);

        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $this->customer->id]);
        $result = $this->invoke(['decision' => 'confirm']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame($this->customer->id, $result['customer_id']);
        $this->assertSame($this->customer->id, Appointment::sole()->customer_id);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_registration_cannot_reassociate_a_conversation_to_transfer_an_existing_proposal(): void
    {
        $proposal = $this->pending('create');
        $before = $proposal->fresh()->getAttributes();
        $other = Customer::factory()->create();
        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $other->id]);
        $registration = app()->makeWith(RegisterCustomerTool::class, ['business' => $this->business, 'claim' => $this->claim]);
        $result = json_decode($registration->handle(new Request([])), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['status' => 'identification_failed'], $result);
        $this->assertSame($this->customer->id, $proposal->conversation->fresh()->customer_id);
        $this->assertSame(['status' => 'identification_failed'], $this->invoke(['decision' => 'confirm']));
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_lost_customer_association_is_not_reconstructed_from_a_reassigned_identity(): void
    {
        $proposal = $this->pending('create');
        $before = $proposal->fresh()->getAttributes();
        $proposal->conversation->update(['customer_id' => null]);
        $other = Customer::factory()->create();
        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $other->id]);
        $registration = app()->makeWith(RegisterCustomerTool::class, ['business' => $this->business, 'claim' => $this->claim]);
        $this->assertSame(['status' => 'identification_failed'], json_decode($registration->handle(new Request([])), true));
        $this->assertSame(['status' => 'identification_failed'], $this->invoke(['decision' => 'confirm']));
        $this->assertNull(app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $this->assertSame(['status' => 'identification_failed'], app(PrepareAppointmentProposal::class)->handle(
            $this->business, $this->claim, AppointmentProposalOperation::Create,
            ['service_id' => $this->service->id, 'professional_id' => $this->professional->id, 'starts_at' => '2030-02-01T11:00:17+01:00'],
        ));
        $this->assertNull($proposal->conversation->fresh()->customer_id);
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_registration_cannot_replace_a_missing_identity_after_proposal_preparation(): void
    {
        $proposal = $this->pending('create');
        ChannelIdentity::query()->where('external_id', 'sender')->delete();
        $registration = app()->makeWith(RegisterCustomerTool::class, ['business' => $this->business, 'claim' => $this->claim]);
        $result = json_decode($registration->handle(new Request(['first_name' => 'Different', 'last_name' => 'Customer'])), true);

        $this->assertSame(['status' => 'identification_failed'], $result);
        $this->assertSame($this->customer->id, $proposal->conversation->fresh()->customer_id);
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('channel_identities', 0);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_committed_result_recovery_remains_bound_to_the_original_customer(): void
    {
        $proposal = $this->pending('create');
        $result = $this->invoke(['decision' => 'confirm']);
        $before = $proposal->fresh()->getAttributes();
        $other = Customer::factory()->create();
        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $other->id]);
        $this->assertNull(app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $this->assertSame(['status' => 'identification_failed'], $this->invoke(['decision' => 'confirm']));
        $this->turns->fail($this->business, $this->claim);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $this->customer->id]);
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($result, app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $this->assertSame($result, $this->invoke(['decision' => 'confirm']));
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertSame($this->customer->id, Appointment::sole()->customer_id);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_new_registration_can_prepare_in_the_same_turn_and_execute_for_the_registered_customer(): void
    {
        ChannelIdentity::query()->where('external_id', 'sender')->delete();
        $registration = app()->makeWith(RegisterCustomerTool::class, ['business' => $this->business, 'claim' => $this->claim]);
        $registered = json_decode($registration->handle(new Request(['first_name' => 'Ana', 'last_name' => 'García'])), true);
        $this->assertSame('registered', $registered['status']);
        $this->customer = Customer::findOrFail($registered['customer']['id']);
        $proposal = $this->pending('create');
        $this->assertSame($this->customer->id, $proposal->conversation->customer_id);
        $result = $this->invoke(['decision' => 'confirm']);
        $this->assertSame('created', $result['outcome']);
        $this->assertSame($this->customer->id, $result['customer_id']);
        $this->assertSame($this->customer->id, Appointment::sole()->customer_id);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    #[DataProvider('operations')]
    public function test_unrelated_later_turns_do_not_receive_or_reexecute_a_previous_success(string $operation): void
    {
        $proposal = $this->pending($operation);
        $confirmingTurn = $this->claim->turnId;
        $result = $this->invoke(['decision' => 'confirm']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame($result, app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $proposalBefore = $proposal->fresh()->getAttributes();
        $appointmentBefore = Appointment::sole()->getAttributes();
        $auditBefore = AppointmentAudit::sole()->getAttributes();

        foreach (['¿Cuál es el horario?', 'Sí, confirmo otra vez'] as $content) {
            $this->nextTurn($content);
            $this->assertNotSame($confirmingTurn, $this->claim->turnId);
            $this->assertSame($proposal->conversation_id, $this->claim->inboundMessage->conversation_id);
            $this->assertNull(app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
            $this->assertSame(['status' => 'no_pending_proposal'], $this->invoke(['decision' => 'confirm']));
            $this->assertSame(['status' => 'no_pending_proposal'], $this->invoke(['decision' => 'decline']));
        }

        $this->assertSame($proposalBefore, $proposal->fresh()->getAttributes());
        $this->assertSame($appointmentBefore, Appointment::sole()->getAttributes());
        $this->assertSame($auditBefore, AppointmentAudit::sole()->getAttributes());
        $this->assertDatabaseCount('appointment_proposals', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public function test_unrelated_later_turns_do_not_receive_or_execute_a_previously_declined_proposal(): void
    {
        $proposal = $this->pending('cancel');
        $confirmingTurn = $this->claim->turnId;
        $result = $this->invoke(['decision' => 'decline']);
        $this->assertSame('declined', $result['outcome']);
        $this->assertSame($result, $this->invoke(['decision' => 'confirm']));
        $this->assertSame($result, app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $proposalBefore = $proposal->fresh()->getAttributes();
        $appointmentBefore = Appointment::sole()->getAttributes();

        foreach (['¿Cuál es el horario?', 'Sí, confirmo ahora'] as $content) {
            $this->nextTurn($content);
            $this->assertNotSame($confirmingTurn, $this->claim->turnId);
            $this->assertSame($proposal->conversation_id, $this->claim->inboundMessage->conversation_id);
            $this->assertNull(app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
            $this->assertSame(['status' => 'no_pending_proposal'], $this->invoke(['decision' => 'confirm']));
            $this->assertSame(['status' => 'no_pending_proposal'], $this->invoke(['decision' => 'decline']));
        }

        $this->assertSame($proposalBefore, $proposal->fresh()->getAttributes());
        $this->assertSame($appointmentBefore, Appointment::sole()->getAttributes());
        $this->assertDatabaseCount('appointment_proposals', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_decline_is_a_durable_rejection_without_mutation(): void
    {
        $proposal = $this->pending('cancel');
        $before = Appointment::sole()->getAttributes();
        $result = $this->invoke(['decision' => 'decline']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('declined', $result['outcome']);
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        $this->assertSame($result, app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $this->assertSame($result, $this->invoke(['decision' => 'confirm']));
        $this->assertSame($before, Appointment::sole()->getAttributes());
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_prepared_unpresented_proposal_and_preemptive_inbound_cannot_authorize_execution(): void
    {
        $proposal = $this->prepare('create');
        $this->assertSame('confirmation_required', $this->invoke(['decision' => 'confirm'])['status']);
        $this->turns->inbound($this->business, 'whatsapp', 'sender', 'early', 'Sí');
        $question = $this->turns->complete($this->business, $this->claim, '¿Confirmas esta cita?');
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->assertLessThan($question->id, $this->claim->inboundMessage->id);
        $this->assertSame('confirmation_required', $this->invoke(['decision' => 'confirm'])['status']);
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->result);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->nextTurn('Sí, confirmo');
        $this->assertSame('created', $this->invoke(['decision' => 'confirm'])['outcome']);
    }

    public function test_pending_requires_actual_accepted_originating_response_not_just_an_assistant_message(): void
    {
        $proposal = $this->prepare('create');
        $message = $proposal->conversation->messages()->create(['role' => 'assistant', 'content' => 'Unaccepted question']);
        $proposal->update(['state' => 'pending', 'confirmation_request_message_id' => $message->id]);
        $this->nextTurn('Sí');
        $this->assertNotSame($message->id, $proposal->originatingTurn->assistant_message_id);
        $this->assertSame('confirmation_required', $this->invoke(['decision' => 'confirm'])['status']);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_no_pending_proposal_and_invalid_decisions_cannot_write(): void
    {
        $this->assertSame('no_pending_proposal', $this->invoke(['decision' => 'confirm'])['status']);
        $this->pending('create');
        foreach ([[], ['decision' => 'yes but at eleven'], ['decision' => true], ['decision' => 'ambiguous']] as $arguments) {
            $this->assertSame('invalid_arguments', $this->invoke($arguments)['status']);
        }
        $this->assertSame(AppointmentProposalState::Pending, AppointmentProposal::sole()->state);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_tool_schema_exposes_no_identity_proposal_or_operation_parameters(): void
    {
        $tool = app()->makeWith(ConfirmAppointmentProposalTool::class, ['business' => $this->business, 'claim' => $this->claim]);
        $factory = new JsonSchemaTypeFactory;
        $schema = $factory->object($tool->schema($factory))->toArray();
        $this->assertSame(['decision'], array_keys($schema['properties']));
        $this->assertSame(['decision'], $schema['required']);
        $this->assertSame(['confirm', 'decline'], $schema['properties']['decision']['enum']);
        $this->assertStringContainsString('conditional, ambiguous, or changed preferences', $tool->description());
        $this->assertStringContainsString('no_pending_proposal does not negate earlier success', $tool->description());
        $this->assertStringContainsString('verify current state via GetCustomerAppointmentsTool', $tool->description());
    }

    public function test_stale_mismatched_completed_and_wrong_business_claims_cannot_execute(): void
    {
        $this->pending('create');
        $old = $this->claim;
        $this->travel(5)->minutes();
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $confirm = app(ConfirmAppointmentProposal::class);
        $this->assertSame('not_authorized', $confirm->handle($this->business, $old, true)['status']);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $confirm->recover($this->business, $old));
        $wrongInbound = new AiTurnClaim($this->claim->turnId, $this->claim->token, ConversationMessage::query()->oldest('id')->first());
        $this->assertSame('not_authorized', $confirm->handle($this->business, $wrongInbound, true)['status']);
        $this->assertSame('not_authorized', $confirm->handle(Business::factory()->create(), $this->claim, true)['status']);
        $this->turns->complete($this->business, $this->claim, 'No operation yet');
        $this->assertSame('not_authorized', $this->invoke(['decision' => 'confirm'])['status']);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_replaced_conversation_cannot_execute_or_recover_old_results(): void
    {
        $this->pending('create');
        $this->travel(13)->hours();
        $this->turns->inbound($this->business, 'whatsapp', 'sender', 'new', 'Sí');
        $this->assertSame('not_authorized', $this->invoke(['decision' => 'confirm'])['status']);
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->assertSame('no_pending_proposal', $this->invoke(['decision' => 'confirm'])['status']);
        $this->assertNull(app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_elapsed_time_during_processing_does_not_invalidate_an_accepted_inbound(): void
    {
        $this->pending('create');
        $this->travel(13)->hours();
        $this->assertSame('created', $this->invoke(['decision' => 'confirm'])['outcome']);
    }

    public function test_replacement_requires_its_own_later_confirmation_and_never_revives_old_proposal(): void
    {
        $old = $this->pending('create');
        $new = $this->prepare('create', ['starts_at' => '2030-02-01T14:00:17+01:00']);
        $this->assertSame(AppointmentProposalState::Rejected, $old->fresh()->state);
        $this->assertSame('confirmation_required', $this->invoke(['decision' => 'confirm'])['status']);
        $this->nextTurn('Confirmo la nueva');
        $result = $this->invoke(['decision' => 'confirm']);
        $this->assertSame($new->id, $result['proposal_id']);
        $this->assertSame('2030-02-01T14:00:17+01:00', $result['appointment']['starts_at']);
        $this->assertDatabaseCount('appointments', 1);
    }

    #[DataProvider('scopeChanges')]
    public function test_current_identity_and_business_scope_are_revalidated(string $operation, string $scope, bool $sameTime = false): void
    {
        $arguments = $sameTime ? ['appointment_id' => $this->appointment('2030-02-01 10:00:17 UTC')->id] : [];
        $proposal = $this->pending($operation, $arguments);
        if ($scope === 'service') {
            $this->service->business()->associate(Business::factory()->create())->save();
        } elseif ($scope === 'professional') {
            $this->professional->business()->associate(Business::factory()->create())->save();
        } else {
            Appointment::sole()->update(['customer_id' => Customer::factory()->create()->id]);
        }
        $before = Appointment::query()->get()->map->getAttributes()->all();
        $result = $this->invoke(['decision' => 'confirm']);
        $this->assertSame('rejected', $result['status']);
        $this->assertArrayNotHasKey('appointment', $result);
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        $this->assertSame($before, Appointment::query()->get()->map->getAttributes()->all());
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public static function scopeChanges(): array
    {
        return [
            'create foreign service' => ['create', 'service'],
            'create foreign Professional' => ['create', 'professional'],
            'reschedule other Customer' => ['reschedule', 'customer'],
            'reschedule foreign service' => ['reschedule', 'service'],
            'reschedule foreign Professional' => ['reschedule', 'professional'],
            'no-op reschedule other Customer' => ['reschedule', 'customer', true],
            'no-op reschedule foreign service' => ['reschedule', 'service', true],
            'no-op reschedule foreign Professional' => ['reschedule', 'professional', true],
            'cancel other Customer' => ['cancel', 'customer'],
            'cancel foreign service' => ['cancel', 'service'],
            'cancel foreign Professional' => ['cancel', 'professional'],
        ];
    }

    public function test_reschedule_executes_the_saved_explicit_professional_change(): void
    {
        $alternative = Professional::factory()->for($this->business)->create(['display_name' => 'Claudia']);
        $alternative->services()->attach($this->service);
        ProfessionalAvailabilityInterval::factory()->for($alternative)->create(['weekday' => 'friday', 'starts_at' => '08:00', 'ends_at' => '19:00']);
        $this->pending('reschedule', ['professional_id' => $alternative->id]);
        $result = $this->invoke(['decision' => 'confirm', 'professional_id' => $this->professional->id]);
        $this->assertSame('rescheduled', $result['outcome']);
        $this->assertSame($alternative->id, Appointment::sole()->professional_id);
        $this->assertSame('Claudia', $result['appointment']['professional_name']);
        $this->assertSame('Laura', $result['previous']['professional_name']);
        $this->assertSame($alternative->id, AppointmentAudit::sole()->after_state['professional_id']);
    }

    #[DataProvider('bookingOperations')]
    public function test_execution_revalidates_service_eligibility_instead_of_trusting_proposal_presentation(string $operation): void
    {
        $proposal = $this->pending($operation);
        $this->service->update(['active' => false]);
        $result = $this->invoke(['decision' => 'confirm']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    #[DataProvider('nonchangingResults')]
    public function test_rejected_and_unchanged_results_are_recovered_as_structured_context(string $outcome): void
    {
        if ($outcome === 'unchanged') {
            $appointment = $this->appointment('2030-02-01 10:00:17 UTC');
            $proposal = $this->pending('reschedule', ['appointment_id' => $appointment->id]);
        } else {
            $proposal = $this->pending('create');
            if ($outcome === 'slot_unavailable') {
                $this->appointment('2030-02-01 10:00:17 UTC');
            }
        }
        $result = $this->invoke(['decision' => $outcome === 'declined' ? 'decline' : 'confirm']);
        $this->assertSame($outcome, $result['outcome']);
        $this->claim->inboundMessage->update(['content' => 'Please explain the outcome in English.']);
        $this->turns->fail($this->business, $this->claim);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $generated = 'No appointment changes were made.';
        ConversationAgent::fake([$generated])->preventStrayPrompts();
        $message = app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($generated, $message->fresh()->content);
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($result): bool {
            preg_match('/<runtime_context>\n(.*)\n<\/runtime_context>$/s', $prompt->agent->instructions(), $matches);
            $this->assertSame($result, json_decode($matches[1], true)['appointment_operation_result']);
            $this->assertStringContainsString('natural, concise, professional Spanish', $prompt->agent->instructions());
            $this->assertStringContainsString('Report rejected outcomes accurately', $prompt->agent->instructions());
            $this->assertStringContainsString('describe unchanged rescheduling as no change', $prompt->agent->instructions());
            $this->assertSame('Please explain the outcome in English.', $prompt->prompt);

            return true;
        });
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public static function nonchangingResults(): array
    {
        return ['decline' => ['declined'], 'conflict' => ['slot_unavailable'], 'no-op' => ['unchanged']];
    }

    public function test_unidentified_and_mismatched_conversation_customers_cannot_write_or_read_saved_outcomes(): void
    {
        $proposal = $this->pending('create');
        $proposal->conversation->update(['customer_id' => Customer::factory()->create()->id]);
        $this->assertSame('identification_failed', $this->invoke(['decision' => 'confirm'])['status']);
        $proposal->conversation->update(['customer_id' => null]);
        ChannelIdentity::query()->delete();
        $this->assertSame('identification_failed', $this->invoke(['decision' => 'confirm'])['status']);
        $this->assertNull(app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
        $this->assertDatabaseCount('appointments', 0);
    }

    #[DataProvider('bookingOperations')]
    public function test_slot_taken_after_preparation_rejects_and_preserves_original_slot(string $operation): void
    {
        $proposal = $this->pending($operation);
        $before = Appointment::query()->first()?->getAttributes();
        $this->appointment('2030-02-01 10:00:17 UTC');
        $result = $this->invoke(['decision' => 'confirm']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame('slot_unavailable', $result['outcome']);
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        if ($before !== null) {
            $this->assertSame($before, Appointment::findOrFail($before['id'])->getAttributes());
        }
        $this->assertSame($result, $this->invoke(['decision' => 'confirm']));
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public static function bookingOperations(): array
    {
        return ['create' => ['create'], 'reschedule' => ['reschedule']];
    }

    public function test_noop_rescheduling_is_completed_as_unchanged_without_an_audit(): void
    {
        $appointment = $this->appointment('2030-02-01 10:00:17 UTC');
        $before = $appointment->fresh()->getAttributes();
        $originatingClaim = $this->claim;
        $proposal = $this->prepare('reschedule', ['appointment_id' => $appointment->id]);
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->state);
        $this->assertSame($appointment->id, $proposal->payload['appointment_id']);
        $this->assertSame('2030-02-01T10:00:17+00:00', $proposal->payload['starts_at']);
        $this->assertSame($this->professional->id, $proposal->payload['professional_id']);
        $this->assertSame($before, $appointment->fresh()->getAttributes());
        $this->assertSame('confirmation_required', $this->invoke(['decision' => 'confirm'])['status']);
        $this->turns->inbound($this->business, 'whatsapp', 'sender', 'early-noop', 'Sí, antes de la pregunta.');
        $question = $this->turns->complete($this->business, $this->claim, '¿Confirmas esta propuesta con los mismos datos?');
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertSame($question->id, $proposal->fresh()->confirmation_request_message_id);
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->assertSame('confirmation_required', $this->invoke(['decision' => 'confirm'])['status']);
        $this->assertNull($proposal->fresh()->result);
        $this->assertSame($before, $appointment->fresh()->getAttributes());
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->nextTurn('Sí, confirmo la propuesta presentada.');
        $confirmingTurn = $this->claim->turnId;
        $this->assertGreaterThan($question->id, $this->claim->inboundMessage->id);
        $confirm = app(ConfirmAppointmentProposal::class);
        $this->assertSame('not_authorized', $confirm->handle($this->business, $originatingClaim, true)['status']);
        $this->assertSame('not_authorized', $confirm->handle(Business::factory()->create(), $this->claim, true)['status']);
        $this->assertNull($proposal->fresh()->result);

        Appointment::saving(fn () => throw new RuntimeException('A no-op must not save the Appointment'));
        AppointmentAudit::creating(fn () => throw new RuntimeException('A no-op must not produce an audit'));
        try {
            $result = $this->invoke(['decision' => 'confirm']);
            $this->assertSame($result, $this->invoke(['decision' => 'confirm']));
            $this->assertSame($result, app(ConfirmAppointmentProposal::class)->recover($this->business, $this->claim));
            $this->nextTurn('Sí, otra vez.');
            $this->assertSame(['status' => 'no_pending_proposal'], $this->invoke(['decision' => 'confirm']));
        } finally {
            Appointment::flushEventListeners();
            AppointmentAudit::flushEventListeners();
        }
        $this->assertSame('completed', $result['status']);
        $this->assertSame('unchanged', $result['outcome']);
        $this->assertFalse($result['changed']);
        $this->assertSame($result['previous'], $result['appointment']);
        $this->assertSame('confirmed', $result['appointment']['status']);
        $this->assertSame(AppointmentProposalState::Completed, $proposal->fresh()->state);
        $this->assertSame($confirmingTurn, $proposal->fresh()->confirming_turn_id);
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertSame($before, $appointment->fresh()->getAttributes());
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_proposals', 1);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    #[DataProvider('changingOperations')]
    public function test_late_notice_audit_and_current_eligibility_remain_owned_by_capabilities(string $operation): void
    {
        $this->business->update(['cancellation_notice_minutes' => 240, 'rescheduling_notice_minutes' => 240]);
        $this->pending($operation);
        $this->travelTo(now()->setDate(2030, 2, 1)->setTime(7, 30));
        $result = $this->invoke(['decision' => 'confirm']);
        $this->assertSame('completed', $result['status']);
        $this->assertTrue(AppointmentAudit::sole()->late_notice);
    }

    public static function changingOperations(): array
    {
        return ['reschedule' => ['reschedule'], 'cancel' => ['cancel']];
    }

    #[DataProvider('changingOperations')]
    public function test_ineligible_appointment_is_rejected_without_mutation_or_audit(string $operation): void
    {
        $this->pending($operation);
        Appointment::sole()->update(['status' => 'cancelled']);
        $before = Appointment::sole()->getAttributes();
        $result = $this->invoke(['decision' => 'confirm']);
        $this->assertSame('not_eligible', $result['outcome']);
        $this->assertSame('rejected', $result['status']);
        $this->assertSame($before, Appointment::sole()->getAttributes());
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    #[DataProvider('rollbackFailures')]
    public function test_technical_errors_roll_back_domain_audit_and_result_and_leave_the_turn_retryable(string $operation, string $model, string $event): void
    {
        $proposal = $this->pending($operation);
        $before = Appointment::query()->get()->map->getAttributes()->all();
        $model::$event(fn () => throw new RuntimeException('Technical persistence failure'));
        try {
            $this->invoke(['decision' => 'confirm']);
            $this->fail('Execution must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Technical persistence failure', $exception->getMessage());
        } finally {
            $model::flushEventListeners();
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, Appointment::query()->get()->map->getAttributes()->all());
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->result);
        $this->assertNull($proposal->fresh()->confirming_turn_id);
        $this->assertSame(AiTurnState::Processing, AiTurn::findOrFail($this->claim->turnId)->state);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertSame('completed', $this->invoke(['decision' => 'confirm'])['status']);
        $this->assertDatabaseCount('appointment_audits', 1);
    }

    public static function rollbackFailures(): array
    {
        $cases = [];
        foreach (['create', 'reschedule', 'cancel'] as $operation) {
            foreach ([[Appointment::class, 'saved'], [AppointmentAudit::class, 'created'], [AppointmentProposal::class, 'updated']] as [$model, $event]) {
                $cases["$operation $model $event"] = [$operation, $model, $event];
            }
        }

        return $cases;
    }

    #[DataProvider('recoveryFailures')]
    public function test_committed_results_reach_processor_before_generation_after_provider_or_assistant_failure(string $operation, string $failure): void
    {
        $proposal = $this->pending($operation);
        $confirmation = 'Yes, please confirm that appointment operation.';
        $this->claim->inboundMessage->update(['content' => $confirmation]);
        $this->turns->fail($this->business, $this->claim);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $stored = null;
        ConversationAgent::fake(function () use (&$stored, $failure): string {
            $this->assertSame(0, DB::transactionLevel());
            $turn = AiTurn::query()->where('state', AiTurnState::Processing)->sole();
            $this->claim = new AiTurnClaim($turn->id, $turn->claim_token, $turn->inboundMessage);
            $stored = $this->invoke(['decision' => 'confirm']);
            if ($failure === 'provider') {
                throw new RuntimeException('Provider failed after commit');
            }
            ConversationMessage::creating(fn () => throw new RuntimeException('Assistant failed after commit'));

            return 'Generated but not accepted';
        })->preventStrayPrompts();
        try {
            app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
            $this->fail('Generation/completion must fail.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('failed after commit', $exception->getMessage());
        } finally {
            ConversationMessage::flushEventListeners();
        }
        $this->assertSame('completed', $stored['status']);
        $this->assertSame($stored, $proposal->fresh()->result);
        $this->assertSame(AiTurnState::Failed, AiTurn::findOrFail($this->claim->turnId)->state);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $this->service->update(['name' => 'Changed catalogue', 'active' => false]);
        Appointment::sole()->update(['status' => 'completed']);
        $this->app->instance(AppointmentOccupancy::class, new class implements ReschedulingAppointmentOccupancy
        {
            public function forProfessional(Professional $professional, TimeInterval $period): array
            {
                throw new RuntimeException('Recovery must not calculate availability');
            }

            public function forProfessionalExcludingAppointment(Professional $professional, TimeInterval $period, int $appointmentId): array
            {
                throw new RuntimeException('Recovery must not calculate availability');
            }
        });
        Appointment::saving(fn () => throw new RuntimeException('Recovery must not mutate'));
        AppointmentAudit::creating(fn () => throw new RuntimeException('Recovery must not audit'));
        $generated = 'Your appointment operation has been completed as confirmed.';
        ConversationAgent::fake(function () use ($stored, $generated): string {
            $this->assertSame(0, DB::transactionLevel());
            $turn = AiTurn::query()->where('state', AiTurnState::Processing)->sole();
            $this->claim = new AiTurnClaim($turn->id, $turn->claim_token, $turn->inboundMessage);
            $this->assertSame($stored, $this->invoke(['decision' => 'confirm']));

            return $generated;
        })->preventStrayPrompts();
        try {
            $message = app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
        } finally {
            Appointment::flushEventListeners();
            AppointmentAudit::flushEventListeners();
        }
        $this->assertSame($generated, $message->fresh()->content);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseCount('appointment_audits', 1);
        $this->assertSame($stored, $proposal->fresh()->result);
        ConversationAgent::assertPromptedTimes(2);
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($stored, $confirmation): bool {
            preg_match('/<runtime_context>\n(.*)\n<\/runtime_context>$/s', $prompt->agent->instructions(), $matches);
            $context = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            if (! isset($context['appointment_operation_result'])) {
                return false;
            }
            $this->assertSame($stored, $context['appointment_operation_result']);
            $this->assertArrayNotHasKey('prepared_proposal', $context);
            $this->assertStringContainsString('do not execute it again', $prompt->agent->instructions());
            $this->assertStringContainsString('natural, concise, professional Spanish', $prompt->agent->instructions());
            $this->assertSame($confirmation, $prompt->prompt);

            return true;
        });
    }

    public static function recoveryFailures(): array
    {
        $cases = [];
        foreach (['create', 'reschedule', 'cancel'] as $operation) {
            foreach (['provider', 'assistant'] as $failure) {
                $cases["$operation $failure"] = [$operation, $failure];
            }
        }

        return $cases;
    }

    #[DataProvider('bookingOperations')]
    public function test_real_postgresql_exclusion_violation_rolls_back_capability_savepoint_but_persists_rejection(string $operation): void
    {
        $proposal = $this->pending($operation);
        $before = Appointment::query()->first()?->getAttributes();
        config(['database.connections.confirm_competitor' => config('database.connections.'.DB::getDefaultConnection())]);
        $attributes = [
            'customer_id' => $this->customer->id, 'service_id' => $this->service->id, 'professional_id' => $this->professional->id,
            'starts_at' => '2030-02-01 10:00:17+00', 'ends_at' => '2030-02-01 11:00:17+00',
            'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20, 'status' => 'confirmed',
            'created_at' => now(), 'updated_at' => now(),
        ];
        $this->app->instance(AppointmentOccupancy::class, new class($attributes) implements ReschedulingAppointmentOccupancy
        {
            public function __construct(private array $attributes) {}

            public function forProfessional(Professional $professional, TimeInterval $period): array
            {
                $intervals = (new PostgresAppointmentOccupancy)->forProfessional($professional, $period);
                DB::connection('confirm_competitor')->table('appointments')->insert($this->attributes);

                return $intervals;
            }

            public function forProfessionalExcludingAppointment(Professional $professional, TimeInterval $period, int $appointmentId): array
            {
                $intervals = (new PostgresAppointmentOccupancy)->forProfessionalExcludingAppointment($professional, $period, $appointmentId);
                DB::connection('confirm_competitor')->table('appointments')->insert($this->attributes);

                return $intervals;
            }
        });
        $rollbacks = [];
        DB::connection()->setEventDispatcher(clone app('events'));
        DB::connection()->getEventDispatcher()->listen(TransactionRolledBack::class, function () use (&$rollbacks): void {
            $rollbacks[] = DB::transactionLevel();
        });
        try {
            $result = $this->invoke(['decision' => 'confirm']);
        } finally {
            DB::purge('confirm_competitor');
        }
        $this->assertSame('slot_unavailable', $result['outcome']);
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        $this->assertContains(1, $rollbacks, 'The nested capability must roll back to its savepoint, not abort the outer transaction.');
        $this->assertSame(0, DB::transactionLevel());
        if ($before !== null) {
            $this->assertSame($before, Appointment::findOrFail($before['id'])->getAttributes());
        }
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertSame($result, $proposal->fresh()->result);
    }

    #[DataProvider('concurrencyErrors')]
    public function test_nested_capability_concurrency_errors_escape_attempts_three_and_rollback_the_whole_execution(string $code): void
    {
        $proposal = $this->pending('create');
        $attempts = 0;
        AppointmentAudit::creating(function () use ($code, &$attempts): void {
            $attempts++;
            DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'deadlock detected or serialization failure' USING ERRCODE = '$code'; END $$");
        });
        try {
            $this->invoke(['decision' => 'confirm']);
            $this->fail('Nested concurrency error must escape to the outer transaction.');
        } catch (DeadlockException $exception) {
            $this->assertInstanceOf(QueryException::class, $exception->getPrevious());
            $this->assertSame($code, $exception->getPrevious()->errorInfo[0]);
        } finally {
            AppointmentAudit::flushEventListeners();
        }
        $this->assertSame(1, $attempts);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->result);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertSame('created', $this->invoke(['decision' => 'confirm'])['outcome']);
    }

    public static function concurrencyErrors(): array
    {
        return ['serialization failure' => ['40001'], 'deadlock' => ['40P01']];
    }

    public function test_exclusion_error_in_audit_is_technical_not_a_slot_rejection(): void
    {
        $proposal = $this->pending('create');
        AppointmentAudit::creating(fn () => DB::unprepared(<<<'SQL'
            DO $$ BEGIN RAISE EXCEPTION 'audit failure "appointments_confirmed_occupancy_exclusion"' USING ERRCODE = '23P01'; END $$
            SQL));
        try {
            $this->invoke(['decision' => 'confirm']);
            $this->fail('Audit failure must propagate.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->errorInfo[0]);
        } finally {
            AppointmentAudit::flushEventListeners();
        }
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->result);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    private function pending(string $operation, array $arguments = []): AppointmentProposal
    {
        $proposal = $this->prepare($operation, $arguments);
        $this->nextTurn('Sí, confirmo');

        return $proposal->fresh();
    }

    private function prepare(string $operation, array $arguments = []): AppointmentProposal
    {
        $defaults = ['service_id' => $this->service->id, 'professional_id' => $this->professional->id, 'starts_at' => '2030-02-01T11:00:17+01:00'];
        if ($operation !== 'create' && ! isset($arguments['appointment_id'])) {
            $defaults['appointment_id'] = $this->appointment($operation === 'cancel' ? '2030-02-01 10:00:17 UTC' : '2030-02-01 08:00:17 UTC')->id;
        }
        $result = app(PrepareAppointmentProposal::class)->handle(
            $this->business, $this->claim, AppointmentProposalOperation::from($operation), array_replace($defaults, $arguments),
        );
        $this->assertSame('prepared', $result['status']);

        return AppointmentProposal::findOrFail($result['proposal_id']);
    }

    private function nextTurn(string $content): void
    {
        $this->turns->complete($this->business, $this->claim, '¿Confirmas la propuesta?');
        $this->turns->inbound($this->business, 'whatsapp', 'sender', 'message-'.AiTurn::count(), $content);
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
    }

    private function appointment(string $start): Appointment
    {
        return Appointment::factory()->for($this->customer)->for($this->service)->for($this->professional)->create([
            'starts_at' => $start, 'ends_at' => (new \DateTimeImmutable($start))->modify('+1 hour'),
            'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20, 'status' => 'confirmed',
        ]);
    }

    private function invoke(array $arguments): array
    {
        $tool = app()->makeWith(ConfirmAppointmentProposalTool::class, ['business' => $this->business, 'claim' => $this->claim]);

        return json_decode($tool->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
    }
}
