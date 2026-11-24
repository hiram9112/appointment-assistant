<?php

namespace Tests\Feature;

use App\Application\Availability\AppointmentOccupancy;
use App\Application\Availability\ReschedulingAppointmentOccupancy;
use App\Application\Availability\TimeInterval;
use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnOutcome;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\AppointmentProposalState;
use App\Application\Conversation\CheckAvailabilityTool;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Application\Conversation\PrepareCancelAppointmentTool;
use App\Application\Conversation\PrepareCreateAppointmentTool;
use App\Application\Conversation\PrepareRescheduleAppointmentTool;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\Service;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PrepareAppointmentProposalTest extends TestCase
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
            'name' => 'Consultation', 'active' => true, 'duration_minutes' => 60,
            'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20,
        ]);
        $this->professional = $this->professional('Laura');
        BusinessOperatingHour::factory()->for($this->business)->create([
            'weekday' => 'friday', 'opens_at' => '08:00', 'closes_at' => '19:00',
        ]);
        $this->customer = Customer::factory()->create();
        ChannelIdentity::factory()->for($this->customer)->create(['external_id' => 'sender']);
        $this->turns = app(AiTurnCoordinator::class);
        $this->turns->inbound($this->business, 'whatsapp', 'sender', '1', 'Book with Laura');
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
    }

    public function test_create_prepares_exact_canonical_details_without_writing_an_appointment(): void
    {
        $result = $this->create();
        $proposal = AppointmentProposal::sole();

        $this->assertSame('prepared', $result['status']);
        $this->assertSame($this->customer->id, $proposal->conversation->customer_id);
        $this->assertSame('create', $result['operation']);
        $this->assertSame($this->presentation(), $result['presentation']);
        $this->assertProposalDetails($result, $proposal);
        $this->assertSame([
            'service_id' => $this->service->id, 'professional_id' => $this->professional->id,
            'starts_at' => '2030-02-01T10:00:17+00:00',
        ], $proposal->canonicalPayload()->toArray());
        $this->assertSame('2030-02-01T11:00:17+00:00', $proposal->presentation['ends_at']);
        $this->assertNull($proposal->confirmation_request_message_id);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertDatabaseCount('conversation_messages', 1);
    }

    public function test_preparation_retry_cannot_rebind_a_proposal_after_channel_identity_reassignment(): void
    {
        $prepared = $this->create();
        $proposal = AppointmentProposal::sole();
        $before = $proposal->getAttributes();
        $other = Customer::factory()->create();
        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $other->id]);

        $this->assertSame(['status' => 'identification_failed'], $this->create());
        $this->assertSame($this->customer->id, $proposal->conversation->fresh()->customer_id);
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertDatabaseCount('appointments', 0);
        ChannelIdentity::query()->where('external_id', 'sender')->update(['customer_id' => $this->customer->id]);
        $this->turns->fail($this->business, $this->claim);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->preventAvailabilityRecalculation();
        $this->assertSame($prepared, $this->create());
        $this->assertSame($before, $proposal->fresh()->getAttributes());
    }

    #[DataProvider('associationFailures')]
    public function test_customer_association_and_proposal_persistence_roll_back_together(string $model): void
    {
        $conversation = $this->claim->inboundMessage->conversation;
        $before = $conversation->getAttributes();
        $model::saved(function () use ($conversation): void {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->assertSame($this->customer->id, $conversation->fresh()->customer_id);
            throw new RuntimeException('Association or proposal persistence failed');
        });
        try {
            $this->create();
            $this->fail('The association and proposal must roll back together.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Association or proposal persistence failed', $exception->getMessage());
        } finally {
            $model::flushEventListeners();
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $conversation->fresh()->getAttributes());
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertSame('prepared', $this->create()['status']);
        $this->assertSame($this->customer->id, $conversation->fresh()->customer_id);
    }

    public static function associationFailures(): array
    {
        return ['association save' => [Conversation::class], 'proposal save' => [AppointmentProposal::class]];
    }

    public function test_stale_preparation_cannot_establish_a_customer_association(): void
    {
        $stale = $this->claim;
        $this->travel(5)->minutes();
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $tool = app()->makeWith(PrepareCreateAppointmentTool::class, ['business' => $this->business, 'claim' => $stale]);
        $this->assertSame('not_authorized', json_decode($tool->handle(new Request($this->createArguments())), true)['status']);
        $this->assertNull($this->claim->inboundMessage->conversation->customer_id);
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertSame('prepared', $this->create()['status']);
        $this->assertSame($this->customer->id, $this->claim->inboundMessage->conversation->fresh()->customer_id);
    }

    #[DataProvider('missingOrAmbiguousReferences')]
    public function test_missing_and_ambiguous_references_require_clarification(string $tool, array $arguments): void
    {
        $this->assertSame('clarification_required', $this->invokeTool($tool, $arguments)['status']);
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointments', 0);
    }

    public static function missingOrAmbiguousReferences(): array
    {
        return [
            'no Professional selection' => [PrepareCreateAppointmentTool::class, ['service_id' => 1, 'starts_at' => '2030-02-01T11:00:00+01:00']],
            'name instead of ID' => [PrepareCreateAppointmentTool::class, ['service_id' => 'Consultation', 'professional_id' => 'Laura', 'starts_at' => '2030-02-01T11:00:00+01:00']],
            'multiple Professionals' => [PrepareCreateAppointmentTool::class, ['service_id' => 1, 'professional_id' => [1, 2], 'starts_at' => '2030-02-01T11:00:00+01:00']],
            'no cancel target' => [PrepareCancelAppointmentTool::class, []],
            'multiple cancel targets' => [PrepareCancelAppointmentTool::class, ['appointment_id' => [1, 2]]],
            'no reschedule target' => [PrepareRescheduleAppointmentTool::class, ['starts_at' => '2030-02-01T11:00:00+01:00']],
            'numeric string ID' => [PrepareCancelAppointmentTool::class, ['appointment_id' => '1']],
        ];
    }

    public function test_reschedule_keeps_professional_and_excludes_its_own_occupancy(): void
    {
        $appointment = $this->appointment();
        $before = $appointment->fresh()->getAttributes();
        $result = $this->invokeTool(PrepareRescheduleAppointmentTool::class, [
            'appointment_id' => $appointment->id, 'starts_at' => '2030-02-01T11:15:17+01:00',
        ]);
        $proposal = AppointmentProposal::sole();

        $this->assertSame('prepared', $result['status']);
        $this->assertSame($this->professional->id, $proposal->payload['professional_id']);
        $this->assertSame($appointment->id, $proposal->payload['appointment_id']);
        $this->assertSame('2030-02-01T10:15:17+00:00', $proposal->payload['starts_at']);
        $this->assertSame('reschedule', $result['operation']);
        $this->assertSame($this->presentation(
            startsAt: '2030-02-01T11:15:17+01:00', endsAt: '2030-02-01T12:15:17+01:00',
            previous: $this->presentation(),
        ), $result['presentation']);
        $this->assertProposalDetails($result, $proposal);
        $this->assertSame($before, $appointment->fresh()->getAttributes());
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_reschedule_changes_professional_only_when_explicitly_selected(): void
    {
        $appointment = $this->appointment();
        $alternative = $this->professional('Claudia');
        $result = $this->invokeTool(PrepareRescheduleAppointmentTool::class, [
            'appointment_id' => $appointment->id, 'professional_id' => $alternative->id,
            'starts_at' => '2030-02-01T11:00:17+01:00',
        ]);
        $proposal = AppointmentProposal::sole();

        $this->assertSame('prepared', $result['status']);
        $this->assertSame($alternative->id, $proposal->payload['professional_id']);
        $this->assertSame('Laura', $proposal->presentation['previous']['professional_name']);
        $this->assertSame('Claudia', $proposal->presentation['professional_name']);
        $this->assertSame($this->presentation(professional: 'Claudia', previous: $this->presentation()), $result['presentation']);
        $this->assertProposalDetails($result, $proposal);
        $this->assertSame($this->professional->id, $appointment->fresh()->professional_id);
        $text = '¿Confirmas el cambio de Laura a Claudia para la consulta del 1 de febrero a las once?';
        $this->assertSame($text, $this->turns->complete($this->business, $this->claim, $text)->content);
    }

    public function test_unavailable_selected_professional_is_not_silently_replaced(): void
    {
        $this->appointment();
        $this->professional('Available alternative');
        $this->assertSame('slot_unavailable', $this->create()['status']);
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_rescheduling_excludes_only_the_owned_appointment_not_other_bookings(): void
    {
        $appointment = $this->appointment();
        Appointment::factory()->for($this->service)->for($this->professional)->create([
            'starts_at' => '2030-02-01 12:00:00 UTC', 'ends_at' => '2030-02-01 13:00:00 UTC',
            'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20,
        ]);
        $result = $this->invokeTool(PrepareRescheduleAppointmentTool::class, [
            'appointment_id' => $appointment->id, 'starts_at' => '2030-02-01T13:00:00+01:00',
        ]);
        $this->assertSame('slot_unavailable', $result['status']);
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_cancel_presents_persisted_details_without_cancelling_or_requiring_active_service(): void
    {
        $appointment = $this->appointment();
        $this->service->update(['active' => false]);
        $result = $this->invokeTool(PrepareCancelAppointmentTool::class, ['appointment_id' => $appointment->id]);
        $this->assertSame('prepared', $result['status']);
        $this->assertSame('cancel', $result['operation']);
        $this->assertSame($this->presentation(), $result['presentation']);
        $this->assertProposalDetails($result, AppointmentProposal::sole());
        $this->assertSame(['appointment_id' => $appointment->id], AppointmentProposal::sole()->payload);
        $this->assertSame('confirmed', $appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    #[DataProvider('appointmentTools')]
    public function test_preparation_rejects_foreign_or_ineligible_appointments(string $tool): void
    {
        $foreignCustomer = $this->appointment(['customer_id' => Customer::factory()->create()->id]);
        $foreignBusiness = Appointment::factory()->for($this->customer)->create();
        $cancelled = $this->appointment(['status' => 'cancelled']);
        $past = $this->appointment(['starts_at' => '2029-02-01 10:00 UTC', 'ends_at' => '2029-02-01 11:00 UTC']);
        foreach ([
            [$foreignCustomer, 'invalid_reference'], [$foreignBusiness, 'invalid_reference'],
            [$cancelled, 'not_eligible'], [$past, 'not_eligible'],
        ] as [$appointment, $status]) {
            $this->assertSame($status, $this->invokeTool($tool, [
                'appointment_id' => $appointment->id, 'starts_at' => '2030-02-01T11:00:17+01:00',
                'customer_id' => $appointment->customer_id, 'business_id' => $foreignBusiness->service->business_id,
            ])['status']);
        }
        $this->assertDatabaseCount('appointment_proposals', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public static function appointmentTools(): array
    {
        return ['reschedule' => [PrepareRescheduleAppointmentTool::class], 'cancel' => [PrepareCancelAppointmentTool::class]];
    }

    public function test_unidentified_and_unsupported_channel_cannot_use_model_supplied_identity(): void
    {
        ChannelIdentity::query()->delete();
        $this->assertSame('identification_failed', $this->create(['customer_id' => $this->customer->id, 'sender_identity' => 'other'])['status']);
        $this->turns->inbound($this->business, 'web', 'web-sender', '1', 'Book');
        $this->claim = $this->turns->claimNext($this->business, 'web', 'web-sender');
        $this->assertSame('identification_failed', $this->create()['status']);
        $this->assertDatabaseCount('appointment_proposals', 0);
    }

    public function test_create_enforces_current_business_active_service_and_professional_eligibility(): void
    {
        $foreignService = Service::factory()->create();
        $foreignProfessional = Professional::factory()->create();
        $ineligible = Professional::factory()->for($this->business)->create();
        foreach ([
            ['service_id' => $foreignService->id], ['professional_id' => $foreignProfessional->id],
            ['professional_id' => $ineligible->id],
        ] as $arguments) {
            $this->assertSame('invalid_reference', $this->create($arguments)['status']);
        }
        $this->service->update(['active' => false]);
        $this->assertSame('invalid_reference', $this->create()['status']);
        $this->assertDatabaseCount('appointment_proposals', 0);
    }

    #[DataProvider('invalidTimestamps')]
    public function test_invalid_or_ambiguous_local_timestamps_are_not_normalized_silently(string $start): void
    {
        $this->assertSame('clarification_required', $this->create(['starts_at' => $start])['status']);
        $this->assertDatabaseCount('appointment_proposals', 0);
    }

    public static function invalidTimestamps(): array
    {
        return [
            'no offset' => ['2030-02-01T11:00:00'], 'fractional seconds' => ['2030-02-01T11:00:00.1+01:00'],
            'wrong local offset' => ['2030-02-01T11:00:00+00:00'], 'invalid date' => ['2030-02-30T11:00:00+01:00'],
            'nonexistent spring time' => ['2030-03-31T02:30:00+01:00'], 'ambiguous autumn without offset' => ['2030-10-27T02:30:00'],
        ];
    }

    #[DataProvider('dstStarts')]
    public function test_dst_offsets_identify_exact_instants_in_structured_payload_and_presentation(string $start, string $utc, string $end): void
    {
        BusinessOperatingHour::factory()->for($this->business)->create(['weekday' => 'sunday', 'opens_at' => '00:00', 'closes_at' => '06:00']);
        ProfessionalAvailabilityInterval::factory()->for($this->professional)->create(['weekday' => 'sunday', 'starts_at' => '00:00', 'ends_at' => '06:00']);
        $result = $this->create(['starts_at' => $start]);
        $this->assertSame('prepared', $result['status']);
        $this->assertSame($utc, AppointmentProposal::sole()->payload['starts_at']);
        $this->assertSame(3600, AppointmentProposal::sole()->canonicalPresentation()->endsAt->getTimestamp() - AppointmentProposal::sole()->canonicalPresentation()->startsAt->getTimestamp());
        $this->assertSame($start, $result['presentation']['starts_at']);
        $this->assertSame($end, $result['presentation']['ends_at']);
        $this->assertProposalDetails($result, AppointmentProposal::sole());
    }

    public static function dstStarts(): array
    {
        return [
            'before spring jump' => ['2030-03-31T01:30:17+01:00', '2030-03-31T00:30:17+00:00', '2030-03-31T03:30:17+02:00'],
            'after spring jump' => ['2030-03-31T03:30:17+02:00', '2030-03-31T01:30:17+00:00', '2030-03-31T04:30:17+02:00'],
            'first autumn occurrence' => ['2030-10-27T02:30:17+02:00', '2030-10-27T00:30:17+00:00', '2030-10-27T02:30:17+01:00'],
            'second autumn occurrence' => ['2030-10-27T02:30:17+01:00', '2030-10-27T01:30:17+00:00', '2030-10-27T03:30:17+01:00'],
        ];
    }

    public function test_completion_preserves_model_prose_and_atomically_accepts_the_actual_response(): void
    {
        $this->create();
        $generated = '¿Te gustaría reservar la consulta con Laura el 1 de febrero a las once?';
        $proposal = AppointmentProposal::sole();
        AppointmentProposal::updating(function (AppointmentProposal $updating) use ($generated): void {
            if ($updating->state === AppointmentProposalState::Pending) {
                $this->assertGreaterThan(0, DB::transactionLevel());
                $turn = AiTurn::findOrFail($this->claim->turnId);
                $this->assertSame(AiTurnState::Completed, $turn->state);
                $this->assertSame($turn->assistant_message_id, $updating->confirmation_request_message_id);
                $this->assertSame($generated, $turn->assistantMessage->content);
            }
        });
        try {
            $message = $this->turns->complete($this->business, $this->claim, $generated);
        } finally {
            AppointmentProposal::flushEventListeners();
        }
        $this->assertSame($generated, $message->fresh()->content);
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertSame($message->id, $proposal->fresh()->confirmation_request_message_id);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->complete($this->business, $this->claim, 'Duplicate question'));
        $this->assertDatabaseCount('conversation_messages', 2);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    #[DataProvider('completionFailures')]
    public function test_completion_failure_rolls_back_message_turn_and_promotion_then_retry_recovers(string $model, string $event): void
    {
        $this->create();
        $model::$event(fn () => throw new RuntimeException('Persistence failed'));
        try {
            $this->turns->complete($this->business, $this->claim, 'Any generated text');
            $this->fail('Completion must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Persistence failed', $exception->getMessage());
        } finally {
            $model::flushEventListeners();
        }
        $proposal = AppointmentProposal::sole();
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->state);
        $this->assertNull($proposal->confirmation_request_message_id);
        $this->assertSame(AiTurnState::Processing, AiTurn::findOrFail($this->claim->turnId)->state);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->turns->fail($this->business, $this->claim);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $generated = '¿Confirmas la consulta con Laura para el viernes 1 de febrero a las once?';
        ConversationAgent::fake([$generated])->preventStrayPrompts();
        $message = app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($generated, $message->fresh()->content);
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertSame($message->id, $proposal->fresh()->confirmation_request_message_id);
        ConversationAgent::assertPromptedTimes(1);
        $this->assertRecoveryContext($proposal);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public static function completionFailures(): array
    {
        return [
            'assistant insertion' => [ConversationMessage::class, 'creating'],
            'after assistant insertion' => [ConversationMessage::class, 'created'],
            'turn completion' => [AiTurn::class, 'updated'],
            'proposal promotion' => [AppointmentProposal::class, 'updated'],
        ];
    }

    #[DataProvider('recoveryOperations')]
    public function test_provider_interruption_recovers_the_original_structured_proposal_through_model_generation(string $toolClass): void
    {
        $arguments = $toolClass === PrepareCreateAppointmentTool::class ? $this->createArguments() : [
            'appointment_id' => $this->appointment()->id,
            'starts_at' => '2030-02-01T11:15:17+01:00',
            'professional_id' => $this->professional('Claudia')->id,
        ];
        $this->turns->fail($this->business, $this->claim);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $tool = null;
        try {
            $this->turns->processNext($this->business, 'whatsapp', 'sender', function (AiTurnClaim $claim) use (&$tool, $toolClass, $arguments): string {
                $this->assertSame(0, DB::transactionLevel());
                $this->claim = $claim;
                $tool = $this->invokeTool($toolClass, $arguments);
                throw new RuntimeException('Provider interrupted after preparation');
            });
            $this->fail('Provider must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider interrupted after preparation', $exception->getMessage());
        }
        $proposal = AppointmentProposal::sole();
        $before = $proposal->getAttributes();
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->state);
        $this->assertProposalDetails($tool, $proposal);
        if ($toolClass === PrepareCreateAppointmentTool::class) {
            $this->appointment();
        }
        $appointmentBefore = Appointment::sole()->getAttributes();
        $this->service->update(['name' => 'Renamed', 'active' => false]);
        $this->professional->update(['display_name' => 'Renamed']);
        $this->preventAvailabilityRecalculation();
        $generated = match ($toolClass) {
            PrepareCreateAppointmentTool::class => 'La consulta con Laura está propuesta para el viernes 1 de febrero a las once. ¿Confirmas la reserva?',
            PrepareRescheduleAppointmentTool::class => '¿Confirmas el cambio de tu consulta con Laura a una consulta con Claudia el 1 de febrero a las once y cuarto?',
            PrepareCancelAppointmentTool::class => '¿Confirmas la cancelación de tu consulta con Laura del 1 de febrero a las once?',
        };
        ConversationAgent::fake(function () use ($generated): string {
            $this->assertSame(0, DB::transactionLevel());

            return $generated;
        })->preventStrayPrompts();
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $message = app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($generated, $message->fresh()->content);
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertSame($message->id, $proposal->fresh()->confirmation_request_message_id);
        $this->assertSame($before['payload'], $proposal->fresh()->getAttributes()['payload']);
        $this->assertSame($before['presentation'], $proposal->fresh()->getAttributes()['presentation']);
        $this->assertDatabaseCount('appointment_proposals', 1);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame($appointmentBefore, Appointment::sole()->getAttributes());
        $this->assertDatabaseCount('appointment_audits', 0);
        ConversationAgent::assertPromptedTimes(1);
        $this->assertRecoveryContext($proposal);
    }

    public static function recoveryOperations(): array
    {
        return [
            'create' => [PrepareCreateAppointmentTool::class],
            'reschedule with Professional change' => [PrepareRescheduleAppointmentTool::class],
            'cancel' => [PrepareCancelAppointmentTool::class],
        ];
    }

    public function test_failed_recovery_generation_and_unaccepted_wording_leave_the_original_proposal_recoverable(): void
    {
        /** This regression exercises four authorized attempts, using a configured bounded budget. */
        config(['conversation.processing.max_attempts' => 4]);
        $this->create();
        $proposal = AppointmentProposal::sole();
        $before = $proposal->getAttributes();
        $this->turns->fail($this->business, $this->claim);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $this->preventAvailabilityRecalculation();
        ConversationAgent::fake(function (): string {
            $this->assertSame(0, DB::transactionLevel());
            throw new RuntimeException('Recovery provider failed');
        })->preventStrayPrompts();
        try {
            app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
            $this->fail('Recovery generation must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Recovery provider failed', $exception->getMessage());
        }
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertRecoveryContext($proposal);
        ConversationAgent::assertPromptedTimes(1);

        ConversationAgent::fake(['¿Te gustaría confirmar esta consulta con Laura?'])->preventStrayPrompts();
        ConversationMessage::creating(fn () => throw new RuntimeException('Recovery insertion failed'));
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        try {
            app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
            $this->fail('Unaccepted wording must not promote the proposal.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Recovery insertion failed', $exception->getMessage());
        } finally {
            ConversationMessage::flushEventListeners();
        }
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertRecoveryContext($proposal);
        ConversationAgent::assertPromptedTimes(2);
        $this->assertDatabaseCount('conversation_messages', 1);

        $accepted = '¿Confirmas la reserva con Laura el viernes 1 de febrero a las once?';
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        ConversationAgent::fake([$accepted])->preventStrayPrompts();
        $message = app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($accepted, $message->fresh()->content);
        $this->assertSame($message->id, $proposal->fresh()->confirmation_request_message_id);
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $this->assertSame($before['payload'], $proposal->fresh()->getAttributes()['payload']);
        $this->assertSame($before['presentation'], $proposal->fresh()->getAttributes()['presentation']);
        $this->assertRecoveryContext($proposal);
        ConversationAgent::assertPromptedTimes(3);
        $this->assertDatabaseCount('appointment_proposals', 1);
        $this->assertDatabaseCount('conversation_messages', 2);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_prepared_proposal_does_not_bypass_existing_assistant_response_validation(): void
    {
        $this->create();
        try {
            $this->turns->complete($this->business, $this->claim, '   ');
            $this->fail('Empty assistant content must fail S11 validation.');
        } catch (ValidationException) {
            $this->assertSame(AppointmentProposalState::Prepared, AppointmentProposal::sole()->state);
            $this->assertNull(AppointmentProposal::sole()->confirmation_request_message_id);
            $this->assertSame(AiTurnState::Processing, AiTurn::findOrFail($this->claim->turnId)->state);
            $this->assertDatabaseCount('conversation_messages', 1);
        }
    }

    public function test_repeated_tool_calls_reuse_immutable_details_and_conflicting_parameters_cannot_replace_them(): void
    {
        $first = $this->create();
        $this->service->update(['name' => 'Changed']);
        $this->appointment();
        $this->assertSame($first, $this->create());
        $this->assertSame('parameters_conflict', $this->create(['starts_at' => '2030-02-01T14:00:17+01:00'])['status']);
        $this->assertSame('parameters_conflict', $this->invokeTool(PrepareCancelAppointmentTool::class, ['appointment_id' => 1])['status']);
        $this->assertDatabaseCount('appointment_proposals', 1);
    }

    public function test_new_preparation_rejects_old_pending_proposal_even_when_provider_generation_then_fails(): void
    {
        $this->create();
        $old = AppointmentProposal::sole();
        $this->turns->complete($this->business, $this->claim, 'Confirm?');
        $this->turns->inbound($this->business, 'whatsapp', 'sender', '2', 'Instead at 14:00');
        try {
            $this->turns->processNext($this->business, 'whatsapp', 'sender', function (AiTurnClaim $claim): string {
                $this->claim = $claim;
                $this->assertSame('prepared', $this->create(['starts_at' => '2030-02-01T14:00:17+01:00'])['status']);
                throw new RuntimeException('Provider failed');
            });
            $this->fail('Generation must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider failed', $exception->getMessage());
        }
        $new = AppointmentProposal::query()->where('id', '!=', $old->id)->sole();
        $this->assertSame(AppointmentProposalState::Rejected, $old->fresh()->state);
        $this->assertSame(AppointmentProposalState::Prepared, $new->state);
        $this->assertNull($new->confirmation_request_message_id);
        $generated = '¿Confirmas la nueva propuesta con Laura para el viernes 1 de febrero a las dos de la tarde?';
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        ConversationAgent::fake([$generated])->preventStrayPrompts();
        $message = app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($generated, $message->fresh()->content);
        $this->assertSame(AppointmentProposalState::Pending, $new->fresh()->state);
        $this->assertSame($message->id, $new->fresh()->confirmation_request_message_id);
        $this->assertSame(AppointmentProposalState::Rejected, $old->fresh()->state);
        $this->assertDatabaseCount('appointments', 0);
        ConversationAgent::assertPromptedTimes(1);
        $this->assertRecoveryContext($new);
    }

    #[DataProvider('modelConfirmations')]
    public function test_sdk_generated_wording_and_mixed_intent_content_are_persisted_unchanged(string $generated): void
    {
        $this->service->update(['price_amount' => '45.00', 'currency_code' => 'EUR']);
        $this->claim->inboundMessage->update(['content' => '¿Cuánto cuesta la consulta? Quiero reservar con Laura el 1 de febrero a las once.']);
        $this->turns->fail($this->business, $this->claim);
        Carbon::setTestNow(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        ConversationAgent::fake(function () use ($generated): string {
            $this->assertSame(0, DB::transactionLevel());
            $turn = AiTurn::sole();
            $this->claim = new AiTurnClaim($turn->id, $turn->claim_token, $turn->inboundMessage);
            $this->assertSame('prepared', $this->create()['status']);

            return $generated;
        })->preventStrayPrompts();
        $message = app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($generated, $message->fresh()->content);
        $this->assertSame(AppointmentProposalState::Pending, AppointmentProposal::sole()->state);
        $this->assertSame($message->id, AppointmentProposal::sole()->confirmation_request_message_id);
        ConversationAgent::assertPromptedTimes(1);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public static function modelConfirmations(): array
    {
        return [
            'concise question' => ['¿Confirmas la consulta con Laura el 1 de febrero a las once?'],
            'different wording' => ['Te propongo una consulta con Laura el viernes 1 de febrero a las once. ¿Te viene bien y confirmas la reserva?'],
            'mixed intent and whitespace' => ["  La consulta cuesta 45 €.\n\n¿Quieres que reserve tu cita con Laura el 1 de febrero a las once?  "],
        ];
    }

    public function test_stale_attempt_cannot_prepare_replace_or_promote_and_current_attempt_reuses_proposal(): void
    {
        $prepared = $this->create();
        $stale = $this->claim;
        $this->travel(5)->minutes();
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $tool = app()->makeWith(PrepareCreateAppointmentTool::class, ['business' => $this->business, 'claim' => $stale]);
        $this->assertSame('not_authorized', json_decode($tool->handle(new Request($this->createArguments())), true)['status']);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->complete($this->business, $stale, 'Old generation'));
        $this->assertSame(AppointmentProposalState::Prepared, AppointmentProposal::sole()->state);
        $this->assertSame($prepared, $this->create());
        $generated = '¿Confirmas tu consulta con Laura el viernes a las once?';
        $this->assertSame($generated, $this->turns->complete($this->business, $this->claim, $generated)->content);
        $this->assertDatabaseCount('appointment_proposals', 1);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_superseded_conversation_cannot_promote_and_new_conversation_does_not_inherit_proposal(): void
    {
        $this->create();
        $this->travel(13)->hours();
        $this->turns->inbound($this->business, 'whatsapp', 'sender', '2', 'New interaction');
        $this->assertSame(AiTurnOutcome::Superseded, $this->turns->complete($this->business, $this->claim, 'Old question'));
        $this->assertSame(AppointmentProposalState::Prepared, AppointmentProposal::sole()->state);
        ConversationAgent::fake(['Fresh answer'])->preventStrayPrompts();
        $message = app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
        $this->assertSame('Fresh answer', $message->content);
        $this->assertNotSame(AppointmentProposal::sole()->conversation_id, $message->conversation_id);
    }

    public function test_elapsed_time_without_actual_conversation_replacement_does_not_block_acceptance(): void
    {
        $this->create();
        $this->travel(13)->hours();
        $generated = '¿Confirmas la consulta con Laura para el 1 de febrero a las once?';
        $this->assertSame($generated, $this->turns->complete($this->business, $this->claim, $generated)->content);
        $this->assertSame(AppointmentProposalState::Pending, AppointmentProposal::sole()->state);
    }

    #[DataProvider('toolSchemas')]
    public function test_sdk_tool_schemas_expose_only_operation_arguments(string $toolClass, array $properties, array $required): void
    {
        $tool = app()->makeWith($toolClass, ['business' => $this->business, 'claim' => $this->claim]);
        $factory = new JsonSchemaTypeFactory;
        $schema = $factory->object($tool->schema($factory))->toArray();
        $this->assertInstanceOf(Tool::class, $tool);
        $this->assertNotEmpty($tool->description());
        $this->assertStringContainsString('authoritative persisted data', $tool->description());
        $this->assertStringContainsString('explicit confirmation', $tool->description());
        $this->assertStringContainsString('natural, concise, professional Spanish', $tool->description());
        $this->assertStringContainsString('Do not claim the Appointment has already been', $tool->description());
        $this->assertSame($properties, array_keys($schema['properties']));
        $this->assertSame($required, $schema['required']);
    }

    public static function toolSchemas(): array
    {
        return [
            'create' => [PrepareCreateAppointmentTool::class, ['service_id', 'professional_id', 'starts_at'], ['service_id', 'professional_id', 'starts_at']],
            'reschedule' => [PrepareRescheduleAppointmentTool::class, ['appointment_id', 'professional_id', 'starts_at'], ['appointment_id', 'starts_at']],
            'cancel' => [PrepareCancelAppointmentTool::class, ['appointment_id'], ['appointment_id']],
        ];
    }

    /** @return array<string, mixed> */
    private function presentation(
        string $startsAt = '2030-02-01T11:00:17+01:00',
        string $endsAt = '2030-02-01T12:00:17+01:00',
        string $professional = 'Laura',
        ?array $previous = null,
    ): array {
        return [
            'service_name' => 'Consultation', 'professional_name' => $professional,
            'starts_at' => $startsAt, 'ends_at' => $endsAt, 'timezone' => 'Europe/Madrid', 'previous' => $previous,
        ];
    }

    /** @param array<string, mixed> $result */
    private function assertProposalDetails(array $result, AppointmentProposal $proposal): void
    {
        $this->assertSame(['status', 'proposal_id', 'operation', 'payload', 'presentation'], array_keys($result));
        $this->assertSame($proposal->id, $result['proposal_id']);
        $this->assertSame($proposal->state->value, $result['status']);
        $this->assertSame($proposal->operation->value, $result['operation']);
        $this->assertEquals($proposal->payload, $result['payload']);
        $presentation = $proposal->presentation;
        $zone = new \DateTimeZone($presentation['timezone']);
        $this->assertSame((new DateTimeImmutable($presentation['starts_at']))->setTimezone($zone)->format(DATE_ATOM), $result['presentation']['starts_at']);
        $this->assertSame((new DateTimeImmutable($presentation['ends_at']))->setTimezone($zone)->format(DATE_ATOM), $result['presentation']['ends_at']);
        $this->assertSame($proposal->canonicalPresentation()->toLocalArray(), $result['presentation']);
    }

    private function assertRecoveryContext(AppointmentProposal $proposal): void
    {
        ConversationAgent::assertPrompted(function (AgentPrompt $prompt) use ($proposal): bool {
            $this->assertSame(1, preg_match('/<runtime_context>\n(.*)\n<\/runtime_context>$/s', $prompt->agent->instructions(), $matches));
            $context = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
            $expected = $proposal->fresh()->structuredDetails();
            $expected['status'] = 'prepared';
            $this->assertSame($expected, $context['prepared_proposal']);
            $this->assertStringContainsString('Compose a new explicit confirmation question', $prompt->agent->instructions());
            $this->assertStringContainsString('natural, concise, professional Spanish', $prompt->agent->instructions());
            $this->assertStringContainsString('Do not prepare a replacement or recheck availability', $prompt->agent->instructions());
            $this->assertContains(CheckAvailabilityTool::class, array_map(fn ($tool): string => $tool::class, [...$prompt->agent->tools()]));
            $this->assertContains(PrepareCreateAppointmentTool::class, array_map(fn ($tool): string => $tool::class, [...$prompt->agent->tools()]));

            return true;
        });
    }

    private function preventAvailabilityRecalculation(): void
    {
        $this->app->instance(AppointmentOccupancy::class, new class implements ReschedulingAppointmentOccupancy
        {
            public function forProfessional(Professional $professional, TimeInterval $period): array
            {
                throw new RuntimeException('Recovery must not calculate availability.');
            }

            public function forProfessionalExcludingAppointment(Professional $professional, TimeInterval $period, int $appointmentId): array
            {
                throw new RuntimeException('Recovery must not calculate rescheduling availability.');
            }
        });
    }

    /** @return array<string, mixed> */
    private function createArguments(): array
    {
        return ['service_id' => $this->service->id, 'professional_id' => $this->professional->id, 'starts_at' => '2030-02-01T11:00:17+01:00'];
    }

    /** @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function create(array $arguments = []): array
    {
        return $this->invokeTool(PrepareCreateAppointmentTool::class, array_replace($this->createArguments(), $arguments));
    }

    /** @param class-string<Tool> $toolClass
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function invokeTool(string $toolClass, array $arguments): array
    {
        $tool = app()->makeWith($toolClass, ['business' => $this->business, 'claim' => $this->claim]);

        return json_decode($tool->handle(new Request($arguments)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $attributes */
    private function appointment(array $attributes = []): Appointment
    {
        return Appointment::factory()->for($this->customer)->for($this->service)->for($this->professional)->create(array_replace([
            'starts_at' => '2030-02-01 10:00:17 UTC', 'ends_at' => '2030-02-01 11:00:17 UTC',
            'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20, 'status' => 'confirmed',
        ], $attributes));
    }

    private function professional(string $name): Professional
    {
        $professional = Professional::factory()->for($this->business)->create(['display_name' => $name]);
        $professional->services()->attach($this->service);
        ProfessionalAvailabilityInterval::factory()->for($professional)->create([
            'weekday' => 'friday', 'starts_at' => '08:00', 'ends_at' => '19:00',
        ]);

        return $professional;
    }
}
