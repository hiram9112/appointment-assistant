<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnOutcome;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\AppointmentProposalOperation;
use App\Application\Conversation\AppointmentProposalOutcome;
use App\Application\Conversation\AppointmentProposalPayload;
use App\Application\Conversation\AppointmentProposalPresentation;
use App\Application\Conversation\AppointmentProposals;
use App\Application\Conversation\AppointmentProposalState;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\Professional;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AppointmentProposalsTest extends TestCase
{
    use DatabaseMigrations;

    private Business $business;

    private Service $service;

    private Professional $professional;

    private AiTurnCoordinator $turns;

    private AppointmentProposals $proposals;

    private AiTurnClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-01-01 12:00:00 UTC');
        $this->business = Business::factory()->create(['timezone' => 'Europe/Madrid']);
        $this->service = Service::factory()->for($this->business)->create();
        $this->professional = Professional::factory()->for($this->business)->create(['display_name' => 'Dra. Laura']);
        $this->turns = app(AiTurnCoordinator::class);
        $this->proposals = app(AppointmentProposals::class);
        $this->turns->inbound($this->business, 'whatsapp', 'sender', '1', 'Book an Appointment');
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
    }

    public function test_preparation_persists_typed_canonical_parameters_and_recovers_without_appointment_writes(): void
    {
        $proposal = $this->prepare()->fresh();
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->state);
        $this->assertSame(AppointmentProposalOperation::Create, $proposal->operation);
        $this->assertEquals($this->payload(), $proposal->canonicalPayload());
        $this->assertEquals($this->presentation(), $proposal->canonicalPresentation());
        $this->assertSame($this->claim->turnId, $proposal->originatingTurn->id);
        $this->assertSame($this->claim->inboundMessage->conversation_id, $proposal->conversation->id);
        $this->assertNull($proposal->confirmationRequestMessage);
        $this->assertNull($proposal->confirmingTurn);
        $this->assertNull($proposal->result);
        $this->assertSame($proposal->id, $this->proposals->recover($this->business, $this->claim)->id);
        $this->assertSame($proposal->id, $this->proposals->active($this->business, $this->claim)->id);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_retry_reuses_canonical_instant_and_does_not_rewrite_parameters_or_presentation(): void
    {
        $first = $this->prepare();
        $before = $first->fresh()->getAttributes();
        $equivalent = AppointmentProposalPayload::create($this->service->id, $this->professional->id, CarbonImmutable::parse('2030-02-01 11:00:00 Europe/Madrid'));
        $this->assertSame($first->id, $this->proposals->prepare($this->business, $this->claim, $equivalent, $this->presentation())->id);
        $this->assertSame(AppointmentProposalOutcome::ParametersConflict, $this->proposals->prepare(
            $this->business, $this->claim, AppointmentProposalPayload::reschedule(1, $this->professional->id, $this->start()), $this->presentation(),
        ));
        $this->assertSame(AppointmentProposalOutcome::ParametersConflict, $this->proposals->prepare(
            $this->business, $this->claim, $this->payload(), new AppointmentProposalPresentation('Different Service', $this->professional->display_name, $this->start(), $this->start()->addHour(), $this->business->timezone),
        ));
        $this->assertSame($before, $first->fresh()->getAttributes());
        $this->assertDatabaseCount('appointment_proposals', 1);
    }

    #[DataProvider('activeStates')]
    public function test_new_turn_atomically_replaces_prepared_or_pending_proposal(string $state): void
    {
        $old = $this->prepare();
        $this->nextTurn($old, $state);
        $new = $this->prepare();

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(AppointmentProposalState::Rejected, $old->fresh()->state);
        $this->assertSame(AppointmentProposalState::Prepared, $new->state);
        $this->assertSame($new->id, $this->proposals->active($this->business, $this->claim)->id);
        $this->assertDatabaseCount('appointment_proposals', 2);
    }

    public static function activeStates(): array
    {
        return ['unpresented' => ['prepared'], 'presented' => ['pending']];
    }

    #[DataProvider('insertionFailures')]
    public function test_failed_insert_rolls_back_rejection_and_successful_insert_survives_provider_failure(string $event): void
    {
        $old = $this->prepare();
        $this->nextTurn($old, 'pending');
        AppointmentProposal::$event(function (): void {
            $this->assertSame(1, DB::transactionLevel());
            throw new RuntimeException('Insert failed');
        });
        try {
            $this->prepare();
            $this->fail('Replacement must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Insert failed', $exception->getMessage());
        } finally {
            AppointmentProposal::flushEventListeners();
        }
        $this->assertSame(AppointmentProposalState::Pending, $old->fresh()->state);
        $this->assertSame($old->id, $this->proposals->active($this->business, $this->claim)->id);
        $this->assertSame(AppointmentProposalOutcome::NotFound, $this->proposals->recover($this->business, $this->claim));
        $new = $this->prepare();
        $this->turns->fail($this->business, $this->claim);
        $this->travelTo(AiTurn::findOrFail($this->claim->turnId)->next_attempt_at);
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($new->id, $this->proposals->recover($this->business, $this->claim)->id);
        $this->assertSame($new->id, $this->prepare()->id);
        $this->assertSame(AppointmentProposalState::Rejected, $old->fresh()->state);
        $this->assertSame(AppointmentProposalState::Prepared, $new->fresh()->state);
    }

    public static function insertionFailures(): array
    {
        return ['before insertion' => ['creating'], 'after insertion' => ['created']];
    }

    public function test_rejection_is_idempotent_and_retry_never_resurrects_a_terminal_proposal(): void
    {
        $proposal = $this->prepare();
        $rejected = $this->proposals->reject($this->business, $this->claim, $proposal->id);
        $before = $rejected->fresh()->getAttributes();
        $this->assertSame(AppointmentProposalState::Rejected, $rejected->state);
        Carbon::setTestNow(now()->addMinute());
        $this->assertSame($before, $this->proposals->reject($this->business, $this->claim, $proposal->id)->fresh()->getAttributes());
        $this->assertSame($proposal->id, $this->prepare()->id);
        $this->assertSame(AppointmentProposalState::Rejected, $this->proposals->recover($this->business, $this->claim)->state);
        $this->assertSame(AppointmentProposalOutcome::NotFound, $this->proposals->active($this->business, $this->claim));
    }

    public function test_database_preserves_completed_result_and_guarded_rejection_does_not_overwrite_it(): void
    {
        $proposal = $this->prepare();
        $this->nextTurn($proposal, 'pending');
        $result = ['status' => 'created', 'appointment' => ['id' => 123]];
        $proposal->update(['state' => AppointmentProposalState::Completed, 'confirming_turn_id' => $this->claim->turnId, 'result' => $result]);
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertSame($this->claim->turnId, $proposal->fresh()->confirmingTurn->id);
        $this->assertSame(AppointmentProposalOutcome::InvalidTransition, $this->proposals->reject($this->business, $this->claim, $proposal->id));
        $new = $this->prepare();
        $this->assertNotSame($proposal->id, $new->id);
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertDatabaseCount('appointment_proposals', 2);
    }

    #[DataProvider('immutableChanges')]
    public function test_postgresql_blocks_rewriting_immutable_parameters_and_terminal_records(string $field, mixed $value, string $state): void
    {
        $proposal = $this->prepare();
        if ($state === 'rejected') {
            $this->proposals->reject($this->business, $this->claim, $proposal->id);
        } elseif ($state === 'completed') {
            $this->nextTurn($proposal, 'pending');
            $proposal->update(['state' => 'completed', 'confirming_turn_id' => $this->claim->turnId, 'result' => ['status' => 'created']]);
        }
        if (is_array($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }
        $this->assertDatabaseFailure(fn () => DB::table('appointment_proposals')->where('id', $proposal->id)->update([$field => $value]), '23514');
        $this->assertSame($proposal->id, $proposal->fresh()->id);
    }

    public static function immutableChanges(): array
    {
        return [
            'payload' => ['payload', ['service_id' => 99], 'prepared'],
            'presentation' => ['presentation', ['service_name' => 'Changed'], 'prepared'],
            'operation' => ['operation', 'cancel', 'prepared'],
            'Conversation' => ['conversation_id', 999, 'prepared'],
            'originating turn' => ['originating_turn_id', 999, 'prepared'],
            'created timestamp' => ['created_at', '2040-01-01 00:00:00', 'prepared'],
            'rejected resurrection' => ['state', 'prepared', 'rejected'],
            'completed resurrection' => ['state', 'pending', 'completed'],
            'completed outcome' => ['result', ['status' => 'failed'], 'completed'],
            'completed timestamp' => ['updated_at', '2040-01-01 00:00:00', 'completed'],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_database_rejects_invalid_transitions_and_incomplete_outcomes(string $from, array $changes): void
    {
        $proposal = $this->prepare();
        if ($from === 'pending') {
            $this->nextTurn($proposal, 'pending');
        }
        $this->assertDatabaseFailure(fn () => DB::table('appointment_proposals')->where('id', $proposal->id)->update($changes), '23514');
    }

    public static function invalidTransitions(): array
    {
        return [
            'prepared cannot complete' => ['prepared', ['state' => 'completed']],
            'prepared needs accepted prompt' => ['prepared', ['state' => 'pending']],
            'pending cannot return to prepared' => ['pending', ['state' => 'prepared', 'confirmation_request_message_id' => null]],
            'completed needs durable result' => ['pending', ['state' => 'completed']],
            'no fifth state' => ['prepared', ['state' => 'processing']],
            'no cancelled state' => ['prepared', ['state' => 'cancelled']],
            'no superseded state' => ['prepared', ['state' => 'superseded']],
        ];
    }

    public function test_postgresql_enforces_active_and_originating_turn_uniqueness_and_retains_history(): void
    {
        $proposal = $this->prepare();
        $other = $this->turns->inbound($this->business, 'whatsapp', 'sender', '2', 'Another option');
        $attributes = $proposal->only(['conversation_id', 'operation', 'payload', 'presentation']);
        $this->assertDatabaseFailure(fn () => AppointmentProposal::query()->create([...$attributes, 'originating_turn_id' => $other->id]), '23505');
        $this->proposals->reject($this->business, $this->claim, $proposal->id);
        $this->assertDatabaseFailure(fn () => AppointmentProposal::query()->create([...$attributes, 'originating_turn_id' => $this->claim->turnId]), '23505');
        $this->assertDatabaseFailure(fn () => $proposal->delete(), '23514');
        $this->assertDatabaseCount('appointment_proposals', 1);
    }

    public function test_cross_conversation_origin_prompt_and_confirming_turn_are_rejected(): void
    {
        $other = $this->turns->inbound($this->business, 'whatsapp', 'other', '1', 'Other Customer');
        $proposal = $this->prepare();
        $this->assertDatabaseFailure(fn () => AppointmentProposal::factory()->create([
            'conversation_id' => $other->inboundMessage->conversation_id,
            'originating_turn_id' => $this->claim->turnId,
        ]), '23514');
        $foreignPrompt = $other->inboundMessage->conversation->messages()->create(['role' => 'assistant', 'content' => 'Other question']);
        $this->assertDatabaseFailure(fn () => $proposal->update(['state' => 'pending', 'confirmation_request_message_id' => $foreignPrompt->id]), '23514');
        $proposal->refresh();
        $this->assertDatabaseFailure(fn () => $proposal->update(['state' => 'pending', 'confirmation_request_message_id' => $this->claim->inboundMessage->id]), '23514');
        $proposal->refresh();
        $this->nextTurn($proposal, 'pending');
        $this->assertDatabaseFailure(fn () => $proposal->update(['state' => 'completed', 'confirming_turn_id' => $other->id, 'result' => ['status' => 'created']]), '23514');
        $proposal->refresh();
        $this->assertDatabaseFailure(fn () => $proposal->update(['state' => 'completed', 'confirming_turn_id' => $proposal->originating_turn_id, 'result' => ['status' => 'created']]), '23514');
        $proposal->refresh();
        $this->assertSame(AppointmentProposalOutcome::NotFound, $this->proposals->reject($this->business, $this->claim, AppointmentProposal::factory()->create()->id));
    }

    public function test_expiry_alone_does_not_invalidate_but_actual_conversation_replacement_does(): void
    {
        $proposal = $this->prepare();
        $this->nextTurn($proposal, 'pending');
        $conversationId = $proposal->conversation_id;
        Carbon::setTestNow(now()->addHours(13));
        $this->assertSame(AppointmentProposalOutcome::ConversationStillCurrent, $this->proposals->invalidateReplacedConversation($this->business, $conversationId));
        $this->assertSame($proposal->id, $this->proposals->active($this->business, $this->claim)->id);
        $this->turns->inbound($this->business, 'whatsapp', 'sender', '3', 'New Conversation');
        $this->assertSame(AiTurnOutcome::Superseded, $this->proposals->active($this->business, $this->claim));
        $this->assertSame(1, $this->proposals->invalidateReplacedConversation($this->business, $conversationId));
        $this->assertSame(0, $this->proposals->invalidateReplacedConversation($this->business, $conversationId));
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->assertSame(AppointmentProposalOutcome::NotFound, $this->proposals->active($this->business, $this->claim));
        $this->assertSame(AppointmentProposalOutcome::NotFound, $this->proposals->reject($this->business, $this->claim, $proposal->id));
        $this->assertSame(AppointmentProposalOutcome::NotFound, $this->proposals->invalidateReplacedConversation(Business::factory()->create(), $conversationId));
    }

    public function test_stale_claims_and_wrong_business_cannot_prepare_recover_replace_or_reject(): void
    {
        $proposal = $this->prepare();
        Carbon::setTestNow(now()->addMinutes(5));
        $newClaim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->proposals->prepare($this->business, $this->claim, $this->payload(), $this->presentation()));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->proposals->recover($this->business, $this->claim));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->proposals->reject($this->business, $this->claim, $proposal->id));
        $this->assertSame(AiTurnOutcome::NotFound, $this->proposals->prepare(Business::factory()->create(), $newClaim, $this->payload(), $this->presentation()));
        $this->assertSame($proposal->id, $this->proposals->recover($this->business, $newClaim)->id);
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->fresh()->state);
        $this->assertDatabaseCount('appointment_proposals', 1);
    }

    public function test_invalid_replacement_scope_leaves_the_existing_proposal_untouched(): void
    {
        $old = $this->prepare();
        $this->nextTurn($old, 'pending');
        $foreign = Service::factory()->create();
        $payload = AppointmentProposalPayload::create($foreign->id, $this->professional->id, $this->start());
        $this->assertSame(AppointmentProposalOutcome::InvalidScope, $this->proposals->prepare($this->business, $this->claim, $payload, $this->presentation()));
        $this->assertSame(AppointmentProposalState::Pending, $old->fresh()->state);
        $this->assertSame($old->id, $this->proposals->active($this->business, $this->claim)->id);
        $this->assertDatabaseCount('appointment_proposals', 1);
    }

    public function test_invalidation_retains_completed_and_rejected_records_unchanged(): void
    {
        $completed = $this->prepare();
        $this->nextTurn($completed, 'pending');
        $completed->update(['state' => 'completed', 'confirming_turn_id' => $this->claim->turnId, 'result' => ['status' => 'created']]);
        $rejected = $this->prepare();
        $this->proposals->reject($this->business, $this->claim, $rejected->id);
        $before = [$completed->fresh()->getAttributes(), $rejected->fresh()->getAttributes()];
        Carbon::setTestNow(now()->addHours(12));
        $this->turns->inbound($this->business, 'whatsapp', 'sender', 'new', 'New Conversation');
        $this->assertSame(0, $this->proposals->invalidateReplacedConversation($this->business, $completed->conversation_id));
        $this->assertSame($before, [$completed->fresh()->getAttributes(), $rejected->fresh()->getAttributes()]);
        $this->assertDatabaseCount('appointment_proposals', 2);
    }

    public function test_create_reschedule_and_cancel_payloads_preserve_business_scope(): void
    {
        $foreignService = Service::factory()->create();
        $foreignProfessional = Professional::factory()->create();
        $foreignAppointment = Appointment::factory()->create();
        foreach ([
            AppointmentProposalPayload::create($foreignService->id, $this->professional->id, $this->start()),
            AppointmentProposalPayload::create($this->service->id, $foreignProfessional->id, $this->start()),
            AppointmentProposalPayload::cancel($foreignAppointment->id),
            AppointmentProposalPayload::reschedule($foreignAppointment->id, $this->professional->id, $this->start()),
        ] as $payload) {
            $this->assertSame(AppointmentProposalOutcome::InvalidScope, $this->proposals->prepare($this->business, $this->claim, $payload, $this->presentation()));
        }
        $this->assertDatabaseCount('appointment_proposals', 0);
        $appointment = Appointment::factory()->for($this->service)->for($this->professional)->create();
        $reschedule = AppointmentProposalPayload::reschedule($appointment->id, $this->professional->id, $this->start());
        $presentation = new AppointmentProposalPresentation($this->service->name, $this->professional->display_name, $this->start(), $this->start()->addHour(), $this->business->timezone, $this->presentation());
        $proposal = $this->proposals->prepare($this->business, $this->claim, $reschedule, $presentation);
        $this->assertEquals($reschedule, $proposal->fresh()->canonicalPayload());
        $this->assertEquals($presentation, $proposal->fresh()->canonicalPresentation());
        $this->nextTurn($proposal, 'prepared');
        $cancel = AppointmentProposalPayload::cancel($appointment->id);
        $proposal = $this->proposals->prepare($this->business, $this->claim, $cancel, $this->presentation());
        $this->assertEquals($cancel, $proposal->fresh()->canonicalPayload());
        $this->assertSame(['appointment_id' => $appointment->id], $proposal->payload);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_typed_payload_rejects_nonpositive_references(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AppointmentProposalPayload::cancel(0);
    }

    public function test_factory_builds_a_consistent_prepared_record(): void
    {
        $proposal = AppointmentProposal::factory()->create()->fresh();
        $this->assertSame($proposal->conversation_id, $proposal->originatingTurn->inboundMessage->conversation_id);
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->state);
        $this->assertSame($proposal->canonicalPayload()->startsAt->toIso8601String(), $proposal->canonicalPresentation()->startsAt->toIso8601String());
    }

    public function test_migrate_fresh_can_recreate_the_postgresql_guard_function(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
        $proposal = AppointmentProposal::factory()->create();
        $this->assertDatabaseFailure(fn () => $proposal->update(['operation' => 'cancel']), '23514');
        $this->assertDatabaseCount('appointment_proposals', 1);
    }

    private function start(): CarbonImmutable
    {
        return CarbonImmutable::parse('2030-02-01 10:00:00 UTC');
    }

    private function payload(): AppointmentProposalPayload
    {
        return AppointmentProposalPayload::create($this->service->id, $this->professional->id, $this->start());
    }

    private function presentation(): AppointmentProposalPresentation
    {
        return new AppointmentProposalPresentation($this->service->name, $this->professional->display_name, $this->start(), $this->start()->addHour(), $this->business->timezone);
    }

    private function prepare(): AppointmentProposal
    {
        $proposal = $this->proposals->prepare($this->business, $this->claim, $this->payload(), $this->presentation());
        $this->assertInstanceOf(AppointmentProposal::class, $proposal);

        return $proposal;
    }

    /** Task 3 replacement fixtures include an unpresented proposal; real completion now always presents it. */
    private function nextTurn(AppointmentProposal $proposal, string $state): void
    {
        if ($state === 'pending') {
            $generated = '¿Confirmas esta propuesta de cita?';
            $message = $this->turns->complete($this->business, $this->claim, $generated);
            $this->assertSame($generated, $message->fresh()->content);
            $this->assertSame($message->id, $proposal->fresh()->confirmation_request_message_id);
        } else {
            $message = $proposal->conversation->messages()->create(['role' => 'assistant', 'content' => 'Fixture response without presentation']);
            AiTurn::findOrFail($this->claim->turnId)->update([
                'state' => AiTurnState::Completed, 'assistant_message_id' => $message->id,
                'completed_at' => now(), 'claim_token' => null, 'claimed_until' => null,
            ]);
        }
        $this->turns->inbound($this->business, 'whatsapp', 'sender', 'next-'.AiTurn::query()->count(), 'A subsequent Customer response');
        $this->claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
    }

    private function assertDatabaseFailure(callable $operation, string $sqlState): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Expected PostgreSQL to reject the write.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }
}
