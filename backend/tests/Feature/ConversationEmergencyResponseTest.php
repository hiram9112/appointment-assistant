<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnFailure;
use App\Application\Conversation\AiTurnFailureReason;
use App\Application\Conversation\AiTurnOutcome;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\AppointmentProposalOperation;
use App\Application\Conversation\AppointmentProposalState;
use App\Application\Conversation\ConfirmAppointmentProposal;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Application\Conversation\PrepareAppointmentProposal;
use App\Jobs\ProcessConversationTurn;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\ChannelIdentity;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationEmergencyResponseTest extends TestCase
{
    use DatabaseMigrations;

    private const NeutralResponse = 'No pudimos completar tu solicitud. Puedes volver a intentarlo.';

    private Business $business;

    private AiTurnCoordinator $turns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2030-01-01 12:00:00 UTC');
        config(['conversation.processing.max_attempts' => 1]);
        $this->business = Business::factory()->create();
        $this->turns = app(AiTurnCoordinator::class);
    }

    public function test_emergency_response_cannot_authorize_a_prepared_proposal_before_or_after_acceptance(): void
    {
        [$turn, $proposal] = $this->preparedAndExhausted();
        $premature = $this->inbound('premature');
        $this->assertSame(AiTurnOutcome::FailureResolutionPending, $this->turns->claimNext($this->business, 'whatsapp', 'sender'));
        ConversationAgent::fake([])->preventStrayPrompts();

        (new ProcessConversationTurn($turn->inboundMessage->conversation_id))->handle(app(ConversationTurnProcessor::class));

        $this->assertSame(self::NeutralResponse, $turn->fresh()->assistantMessage->content);
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->refresh()->state);
        $this->assertNull($proposal->confirmation_request_message_id);
        $this->assertNull($proposal->confirming_turn_id);
        ConversationAgent::assertNeverPrompted();
        $later = $this->inbound('later');

        foreach ([$premature, $later] as $affirmative) {
            $claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
            $this->assertSame($affirmative->id, $claim->turnId);
            $this->assertSame(['status' => 'no_pending_proposal'], app(ConfirmAppointmentProposal::class)->handle($this->business, $claim, true));
            $this->turns->complete($this->business, $claim, 'No hay una propuesta pendiente.');
        }
        $this->assertSame('confirmed', Appointment::sole()->status);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public function test_interruption_before_customer_context_does_not_block_the_registered_customers_backlog(): void
    {
        $customer = Customer::factory()->create();
        ChannelIdentity::factory()->for($customer)->create(['external_id' => 'sender']);
        $first = $this->inbound('first');
        $this->turns->claimNext($this->business, 'whatsapp', 'sender', leaseSeconds: 1);
        $later = $this->inbound('later');
        $this->assertNull($first->inboundMessage->conversation->customer_id);
        $this->travel(1)->second();
        ConversationAgent::fake(['Respuesta siguiente'])->preventStrayPrompts();

        $this->assertSame(self::NeutralResponse, $this->process()->content);
        $this->assertSame($customer->id, $first->inboundMessage->conversation->fresh()->customer_id);
        $this->assertSame(AiTurnState::Pending, $later->fresh()->state);
        $this->assertSame('Respuesta siguiente', $this->process()->content);
        $this->assertSame(AiTurnState::Completed, $later->fresh()->state);
        ConversationAgent::assertPromptedTimes(1);
    }

    #[DataProvider('persistenceFailures')]
    public function test_failed_atomic_acceptance_leaves_finalization_recoverable(bool $diagnosticsAvailable): void
    {
        [$turn, $proposal] = $this->preparedAndExhausted();
        $attempts = $turn->fresh()->diagnostics['attempts'];
        ConversationAgent::fake([])->preventStrayPrompts();
        AppointmentProposal::updating(function (): void {
            $this->assertSame(1, ConversationMessage::query()->where('role', 'assistant')->count());
            DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'simulated fallback persistence failure'; END $$");
        });
        if (! $diagnosticsAvailable) {
            AiTurn::updating(function (AiTurn $turn): void {
                if ($turn->getOriginal('state') === AiTurnState::ResolvingFailure && $turn->state === AiTurnState::FailureResolutionPending) {
                    DB::unprepared("DO $$ BEGIN RAISE EXCEPTION 'simulated diagnostics persistence failure'; END $$");
                }
            });
        }

        try {
            $this->process();
            $this->fail('The affected transaction must report the persistence error.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString($diagnosticsAvailable ? 'simulated fallback' : 'simulated diagnostics', $exception->getMessage());
        } finally {
            AiTurn::flushEventListeners();
            AppointmentProposal::flushEventListeners();
        }
        $turn->refresh();
        $this->assertFalse($turn->fallback_used);
        $this->assertNull($turn->assistant_message_id);
        $this->assertNull($turn->completed_at);
        $this->assertNull($turn->terminal_at);
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->fresh()->state);
        $this->assertDatabaseCount('conversation_messages', 1);
        if ($diagnosticsAvailable) {
            $this->assertSame(AiTurnState::FailureResolutionPending, $turn->state);
            $this->assertSame(AiTurnFailure::PersistenceUnavailable->value, $turn->diagnostics['finalization']['last_failure']['classification']);
            $this->assertSame(AiTurnFailureReason::FinalizationFailed->value, $turn->diagnostics['finalization']['last_failure']['reason']);
            $this->assertSame(AiTurnOutcome::NotDue, $this->process());
            $this->travelTo($turn->next_attempt_at);
        } else {
            $this->assertSame(AiTurnState::ResolvingFailure, $turn->state);
            $this->assertSame(AiTurnOutcome::Busy, $this->process());
            $this->travelTo($turn->claimed_until);
        }

        $response = $this->process();

        $this->assertSame(self::NeutralResponse, $response->content);
        $this->assertSame($response->id, $turn->refresh()->assistant_message_id);
        $this->assertTrue($turn->fallback_used);
        $this->assertSame(1, $turn->attempt_count);
        $this->assertSame($attempts, $turn->diagnostics['attempts']);
        $this->assertSame(2, $turn->diagnostics['finalization']['claim_count']);
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        $this->assertDatabaseCount('conversation_messages', 2);
        $this->assertDatabaseCount('appointment_audits', 0);
        ConversationAgent::assertNeverPrompted();
    }

    public static function persistenceFailures(): array
    {
        return ['can still record retry' => [true], 'must recover after lease expiry' => [false]];
    }

    public function test_rejected_turn_update_cannot_leave_an_orphaned_assistant_response(): void
    {
        [$turn, $proposal] = $this->preparedAndExhausted();
        ConversationAgent::fake([])->preventStrayPrompts();
        AiTurn::updating(fn (AiTurn $turn): bool => $turn->state !== AiTurnState::Completed);
        try {
            $this->process();
            $this->fail('An assistant message alone must not report success.');
        } catch (LogicException $exception) {
            $this->assertSame('The assistant response could not be accepted.', $exception->getMessage());
        } finally {
            AiTurn::flushEventListeners();
        }

        $this->assertSame(AiTurnState::FailureResolutionPending, $turn->fresh()->state);
        $this->assertNull($turn->fresh()->assistant_message_id);
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->fresh()->state);
        $this->assertDatabaseCount('conversation_messages', 1);
        ConversationAgent::assertNeverPrompted();
    }

    public function test_superseded_and_cross_business_claims_cannot_build_or_accept_emergency_responses(): void
    {
        [$turn, $proposal] = $this->preparedAndExhausted();
        $claim = $this->turns->claimFinalizationNext($this->business, 'whatsapp', 'sender');
        $unexpectedResponse = fn () => $this->fail('An unauthorized claim must not load outcome data.');
        $this->assertSame(AiTurnOutcome::NotFound, $this->turns->finalize(Business::factory()->create(), $claim, $unexpectedResponse));
        $this->travel(12)->hours();
        $replacement = $this->inbound('replacement');
        $this->assertNotSame($turn->inboundMessage->conversation_id, $replacement->inboundMessage->conversation_id);
        ConversationAgent::fake([])->preventStrayPrompts();
        (new ProcessConversationTurn($turn->inboundMessage->conversation_id))->handle(app(ConversationTurnProcessor::class));

        $this->assertSame(AiTurnOutcome::Superseded, $this->turns->finalize($this->business, $claim, $unexpectedResponse));
        $this->assertSame(AiTurnState::Superseded, $turn->fresh()->state);
        $this->assertNull($turn->fresh()->assistant_message_id);
        $this->assertSame(AiTurnState::Pending, $replacement->fresh()->state);
        $replacementClaim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $this->assertSame($replacement->id, $replacementClaim->turnId);
        $this->assertSame(['status' => 'no_pending_proposal'], app(ConfirmAppointmentProposal::class)->handle($this->business, $replacementClaim, true));
        $this->assertNull($proposal->fresh()->confirmation_request_message_id);
        $this->assertDatabaseCount('conversation_messages', 2);
        $this->assertDatabaseCount('appointment_audits', 0);
        ConversationAgent::assertNeverPrompted();
    }

    private function inbound(string $messageId): AiTurn
    {
        return $this->turns->inbound($this->business, 'whatsapp', 'sender', $messageId, 'Sí');
    }

    private function process(): ConversationMessage|AiTurnOutcome
    {
        return app(ConversationTurnProcessor::class)->processNext($this->business, 'whatsapp', 'sender');
    }

    /** @return array{AiTurn, AppointmentProposal} */
    private function preparedAndExhausted(): array
    {
        $customer = Customer::factory()->create();
        ChannelIdentity::factory()->for($customer)->create(['external_id' => 'sender']);
        $appointment = Appointment::factory()->for($customer)
            ->for(Service::factory()->for($this->business))->for(Professional::factory()->for($this->business))->create([
                'status' => 'confirmed', 'starts_at' => '2030-02-01 10:00 UTC', 'ends_at' => '2030-02-01 11:00 UTC',
            ]);
        $turn = $this->inbound('prepare');
        $claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $prepared = app(PrepareAppointmentProposal::class)->handle(
            $this->business, $claim, AppointmentProposalOperation::Cancel, ['appointment_id' => $appointment->id],
        );
        $this->assertSame('prepared', $prepared['status']);
        $this->turns->fail($this->business, $claim, reason: AiTurnFailureReason::GenerationFailed);

        return [$turn, AppointmentProposal::findOrFail($prepared['proposal_id'])];
    }
}
