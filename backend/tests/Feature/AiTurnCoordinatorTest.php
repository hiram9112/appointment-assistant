<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnOutcome;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\ConversationHandler;
use App\Application\Conversation\ConversationOutcome;
use App\Models\AiTurn;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AiTurnCoordinatorTest extends TestCase
{
    use DatabaseMigrations;

    private Business $business;

    private AiTurnCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2030-01-01 12:00:00 UTC');
        $this->business = Business::factory()->create();
        $this->coordinator = app(AiTurnCoordinator::class);
    }

    public function test_duplicate_deliveries_resolve_to_one_turn_without_renewing_activity(): void
    {
        $turn = $this->inbound('1');
        $claim = $this->claim();
        $answer = $this->coordinator->complete($this->business, $claim, 'answer');
        Carbon::setTestNow(now()->addDay());
        $duplicate = $this->inbound('1');

        $this->assertSame($turn->id, $duplicate->id);
        $this->assertSame($answer->id, $duplicate->assistant_message_id);
        $this->assertSame(ConversationOutcome::ConflictingMessage, $this->coordinator->inbound($this->business, 'web', 'sender', '1', 'changed'));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->coordinator->complete($this->business, $claim, 'duplicate'));
        $this->assertSame(AiTurnOutcome::Idle, $this->claim());
        $this->assertDatabaseCount('ai_turns', 1);
        $this->assertDatabaseCount('conversation_messages', 2);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertSame('2030-01-01 12:00:00', Conversation::firstOrFail()->last_activity_at->format('Y-m-d H:i:s'));
    }

    public function test_inbound_and_turn_registration_are_atomic(): void
    {
        AiTurn::creating(fn () => throw new RuntimeException('registration failed'));
        try {
            $this->inbound('1');
            $this->fail('Expected registration failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('registration failed', $exception->getMessage());
        } finally {
            AiTurn::flushEventListeners();
        }
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertDatabaseCount('ai_turns', 0);
        $this->assertSame(AiTurnState::Pending, $this->inbound('1')->fresh()->state);
    }

    public function test_reentry_recovers_unregistered_s10_messages_and_preserves_fifo(): void
    {
        $first = app(ConversationHandler::class)->inbound($this->business, 'web', 'sender', '1', 'first');
        $second = $this->inbound('2');
        $claim = $this->claim();
        $this->assertSame($first->id, $claim->inboundMessage->id);
        $this->assertSame(AiTurnOutcome::Busy, $this->claim());
        $this->coordinator->fail($this->business, $claim);
        $retry = $this->claim();
        $this->assertSame($claim->turnId, $retry->turnId);
        $this->assertNotSame($claim->token, $retry->token);
        $this->coordinator->complete($this->business, $retry, 'first answer');
        $this->assertSame($second->id, $this->claim()->turnId);
    }

    public function test_failed_work_blocks_later_turns_until_successful_retry(): void
    {
        $first = $this->inbound('1');
        $second = $this->inbound('2');
        $claim = $this->claim();
        $this->assertSame(AiTurnState::Failed, $this->coordinator->fail($this->business, $claim)->state);
        $this->assertDatabaseCount('conversation_messages', 2);
        $retry = $this->claim();
        $this->assertSame($first->id, $retry->turnId);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->coordinator->fail($this->business, $claim));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->coordinator->complete($this->business, $claim, 'stale'));
        $this->coordinator->complete($this->business, $retry, 'answer');
        $this->assertSame($second->id, $this->claim()->turnId);
    }

    public function test_interrupted_claim_is_recovered_at_lease_boundary_and_old_owner_is_fenced(): void
    {
        $turn = $this->inbound('1');
        $old = $this->coordinator->claimNext($this->business, 'web', 'sender', 60);
        Carbon::setTestNow(now()->addSeconds(59));
        $this->assertSame(AiTurnOutcome::Busy, $this->claim());
        Carbon::setTestNow(now()->addSecond());
        $new = $this->claim();
        $this->assertSame($old->turnId, $new->turnId);
        $this->assertNotSame($old->token, $new->token);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->coordinator->complete($this->business, $old, 'late'));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->coordinator->fail($this->business, $old));
        $answer = $this->coordinator->complete($this->business, $new, 'accepted');
        $this->assertSame($answer->id, $turn->fresh()->assistant_message_id);
        $this->assertSame(AiTurnState::Completed, $turn->fresh()->state);
        $this->assertNotNull($turn->fresh()->completed_at);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_inactivity_and_lease_expiry_alone_do_not_invalidate_current_response(): void
    {
        $turn = $this->inbound('1');
        $claim = $this->claim();
        Carbon::setTestNow(now()->addDay());
        $answer = $this->coordinator->complete($this->business, $claim, 'long running answer');
        $this->assertInstanceOf(ConversationMessage::class, $answer);
        $this->assertTrue(Conversation::firstOrFail()->last_activity_at->equalTo(now()));
        $next = $this->inbound('2');
        $this->assertSame($turn->inboundMessage->conversation_id, $next->inboundMessage->conversation_id);
    }

    public function test_replacement_prevents_late_response_and_pending_old_work_is_superseded(): void
    {
        $first = $this->inbound('1');
        $claim = $this->claim();
        $pending = $this->inbound('2');
        $activity = $first->inboundMessage->conversation->last_activity_at;
        Carbon::setTestNow(now()->addHours(12));
        $new = $this->inbound('3');

        $this->assertSame(AiTurnOutcome::Superseded, $this->coordinator->complete($this->business, $claim, 'too late'));
        $this->assertSame(AiTurnState::Superseded, $first->fresh()->state);
        $this->assertSame($new->id, $this->claim()->turnId);
        $this->assertSame(AiTurnState::Superseded, $pending->fresh()->state);
        $this->assertTrue($first->inboundMessage->conversation->fresh()->last_activity_at->equalTo($activity));
        $this->assertCount(0, $this->coordinator->completedBefore($this->business, $new->inbound_message_id));
        $this->assertDatabaseCount('conversation_messages', 3);
    }

    public function test_claiming_new_conversation_fences_old_processing_without_waiting_for_provider(): void
    {
        $this->inbound('1');
        $old = $this->claim();
        Carbon::setTestNow(now()->addHours(12));
        $new = $this->inbound('2');
        $this->assertSame($new->id, $this->claim()->turnId);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->coordinator->complete($this->business, $old, 'late'));
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_failed_assistant_insert_rolls_back_completion_and_activity(): void
    {
        $turn = $this->inbound('1');
        $claim = $this->claim();
        Carbon::setTestNow(now()->addMinute());
        ConversationMessage::creating(fn () => throw new RuntimeException('insert failed'));
        try {
            $this->coordinator->complete($this->business, $claim, 'answer');
            $this->fail('Expected insertion failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('insert failed', $exception->getMessage());
        } finally {
            ConversationMessage::flushEventListeners();
        }
        $this->assertSame(AiTurnState::Processing, $turn->fresh()->state);
        $this->assertNull($turn->fresh()->assistant_message_id);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertSame('2030-01-01 12:00:00', Conversation::firstOrFail()->last_activity_at->format('Y-m-d H:i:s'));
        $this->assertInstanceOf(ConversationMessage::class, $this->coordinator->complete($this->business, $claim, 'retry persistence'));
    }

    public function test_failed_turn_completion_rolls_back_successful_s10_insertion(): void
    {
        $turn = $this->inbound('1');
        $claim = $this->claim();
        Carbon::setTestNow(now()->addMinute());
        AiTurn::updating(function (AiTurn $turn): void {
            if ($turn->state === AiTurnState::Completed) {
                throw new RuntimeException('completion failed');
            }
        });
        try {
            $this->coordinator->complete($this->business, $claim, 'answer');
            $this->fail('Expected completion failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('completion failed', $exception->getMessage());
        } finally {
            AiTurn::flushEventListeners();
        }
        $this->assertSame(AiTurnState::Processing, $turn->fresh()->state);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertSame('2030-01-01 12:00:00', Conversation::firstOrFail()->last_activity_at->format('Y-m-d H:i:s'));
    }

    public function test_completed_associations_preserve_logical_turn_order_and_exclude_current_and_later(): void
    {
        $first = $this->inbound('1');
        $second = $this->inbound('2');
        $third = $this->inbound('3');
        $firstAnswer = $this->coordinator->complete($this->business, $this->claim(), 'first answer');
        $this->coordinator->complete($this->business, $this->claim(), 'second answer');
        $history = $this->coordinator->completedBefore($this->business, $second->inbound_message_id);
        $this->assertSame([$first->id], $history->modelKeys());
        $this->assertSame($first->inbound_message_id, $history[0]->inboundMessage->id);
        $this->assertSame($firstAnswer->id, $history[0]->assistantMessage->id);
        $this->assertSame([$first->id, $second->id], $this->coordinator->completedBefore($this->business, $third->inbound_message_id)->modelKeys());
        $this->assertSame(AiTurnOutcome::NotFound, $this->coordinator->completedBefore($this->business, $firstAnswer->id));
        Carbon::setTestNow(now()->addHours(12));
        $new = $this->inbound('4');
        $this->assertCount(0, $this->coordinator->completedBefore($this->business, $new->inbound_message_id));
    }

    public function test_participant_channel_and_business_scopes_are_independent(): void
    {
        $first = $this->inbound('1');
        $claim = $this->claim();
        $other = Business::factory()->create();
        foreach ([[$this->business, 'web', 'other'], [$this->business, 'other', 'sender'], [$other, 'web', 'sender']] as [$business, $channel, $sender]) {
            $turn = $this->coordinator->inbound($business, $channel, $sender, '1', 'hello');
            $independent = $this->coordinator->claimNext($business, $channel, $sender);
            $this->assertSame($turn->id, $independent->turnId);
            $this->assertInstanceOf(ConversationMessage::class, $this->coordinator->complete($business, $independent, 'answer'));
            $this->assertCount(0, $this->coordinator->completedBefore($business, $turn->inbound_message_id));
        }
        $this->assertSame(AiTurnOutcome::NotFound, $this->coordinator->complete($other, $claim, 'hidden'));
        $this->assertSame(AiTurnOutcome::NotFound, $this->coordinator->fail($other, $claim));
        $this->assertSame(AiTurnOutcome::NotFound, $this->coordinator->completedBefore($other, $first->inbound_message_id));
        $this->assertSame(AiTurnOutcome::Busy, $this->claim());
    }

    public function test_provider_failure_is_recoverable_and_generation_has_no_transaction(): void
    {
        $turn = $this->inbound('1');
        try {
            $this->coordinator->processNext($this->business, 'web', 'sender', function (AiTurnClaim $claim) use ($turn): string {
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame($turn->id, $claim->turnId);
                throw new RuntimeException('provider failed');
            });
            $this->fail('Expected provider failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider failed', $exception->getMessage());
        }
        $this->assertSame(AiTurnState::Failed, $turn->fresh()->state);
        $this->assertDatabaseCount('conversation_messages', 1);
        $answer = $this->coordinator->processNext($this->business, 'web', 'sender', function (): string {
            $this->assertSame(0, DB::transactionLevel());

            return 'recovered';
        });
        $this->assertSame('recovered', $answer->content);
        $this->assertSame(AiTurnOutcome::Idle, $this->coordinator->processNext($this->business, 'web', 'sender', fn () => throw new RuntimeException('must not execute')));
    }

    public function test_process_next_refuses_generation_inside_an_outer_transaction(): void
    {
        $this->inbound('1');
        $this->expectException(LogicException::class);
        DB::transaction(fn () => $this->coordinator->processNext($this->business, 'web', 'sender', fn () => 'must not run'));
    }

    public function test_authorized_callback_receives_persisted_context_and_does_not_complete_the_turn(): void
    {
        $turn = $this->inbound('1');
        $claim = $this->claim();
        Carbon::setTestNow(now()->addDay());
        $claim->inboundMessage->setRelation('conversation', new Conversation(['sender_identity' => 'untrusted snapshot']));

        $result = $this->coordinator->withOwnership($this->business, $claim, function (AiTurn $authorizedTurn, Conversation $conversation) use ($turn): array {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame($turn->id, $authorizedTurn->id);
            $this->assertSame($turn->inboundMessage->conversation_id, $conversation->id);
            $this->assertSame($this->business->id, $conversation->business_id);
            $this->assertSame('sender', $conversation->sender_identity);
            $this->assertSame('web', $conversation->channel);
            $customer = Customer::factory()->create();
            $conversation->update(['customer_id' => $customer->id]);

            return ['customer' => $customer->id];
        });

        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($result['customer'], Conversation::firstOrFail()->customer_id);
        $this->assertSame(AiTurnState::Processing, $turn->fresh()->state);
        $this->assertSame($claim->token, $turn->fresh()->claim_token);
        $this->assertNull($turn->fresh()->assistant_message_id);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertInstanceOf(ConversationMessage::class, $this->coordinator->complete($this->business, $claim, 'answer'));
    }

    #[DataProvider('unauthorizedClaims')]
    public function test_unauthorized_claims_never_invoke_a_write_callback(string $scenario, AiTurnOutcome $expected): void
    {
        $turn = $this->inbound('1');
        $claim = $this->claim();
        $business = $this->business;
        switch ($scenario) {
            case 'pending':
                $turn->refresh()->update(['state' => AiTurnState::Pending, 'claim_token' => null, 'claimed_until' => null]);
                break;
            case 'failed':
                $this->coordinator->fail($business, $claim);
                break;
            case 'completed':
                $this->coordinator->complete($business, $claim, 'answer');
                break;
            case 'taken over':
                Carbon::setTestNow(now()->addMinutes(5));
                $this->claim();
                break;
            case 'superseded':
            case 'replacement':
                Carbon::setTestNow(now()->addHours(12));
                $this->inbound('2');
                if ($scenario === 'superseded') {
                    $this->claim();
                }
                break;
            case 'wrong token':
                $claim = new AiTurnClaim($claim->turnId, 'wrong', $claim->inboundMessage);
                break;
            case 'wrong message':
                $other = $this->coordinator->inbound($business, 'web', 'other', '1', 'other');
                $claim = new AiTurnClaim($claim->turnId, $claim->token, $other->inboundMessage);
                break;
            case 'wrong turn':
                $claim = new AiTurnClaim(99999999, $claim->token, $claim->inboundMessage);
                break;
            case 'wrong business':
                $business = Business::factory()->create();
                break;
        }

        $this->assertSame($expected, $this->coordinator->withOwnership($business, $claim, function (): void {
            Customer::factory()->create();
            $this->fail('An unauthorized callback must not run.');
        }));
        $this->assertDatabaseCount('customers', 0);
        $this->assertNull(Conversation::findOrFail($turn->inboundMessage->conversation_id)->customer_id);
    }

    public static function unauthorizedClaims(): array
    {
        return [
            'pending' => ['pending', AiTurnOutcome::StaleAttempt],
            'failed' => ['failed', AiTurnOutcome::StaleAttempt],
            'completed' => ['completed', AiTurnOutcome::StaleAttempt],
            'taken over' => ['taken over', AiTurnOutcome::StaleAttempt],
            'superseded' => ['superseded', AiTurnOutcome::StaleAttempt],
            'replaced before next claim' => ['replacement', AiTurnOutcome::Superseded],
            'wrong token' => ['wrong token', AiTurnOutcome::StaleAttempt],
            'wrong message' => ['wrong message', AiTurnOutcome::StaleAttempt],
            'wrong turn' => ['wrong turn', AiTurnOutcome::NotFound],
            'wrong business' => ['wrong business', AiTurnOutcome::NotFound],
        ];
    }

    public function test_callback_failure_rolls_back_writes_and_preserves_recovery_and_stale_response_protection(): void
    {
        $turn = $this->inbound('1');
        $claim = $this->claim();
        try {
            $this->coordinator->withOwnership($this->business, $claim, function (AiTurn $turn, Conversation $conversation): void {
                $customer = Customer::factory()->create();
                $conversation->update(['customer_id' => $customer->id]);
                throw new RuntimeException('write failed');
            });
            $this->fail('Expected callback failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('write failed', $exception->getMessage());
        }

        $this->assertSame(0, DB::transactionLevel());
        $this->assertDatabaseCount('customers', 0);
        $this->assertNull(Conversation::firstOrFail()->customer_id);
        $this->assertSame(AiTurnState::Processing, $turn->fresh()->state);
        $this->assertSame($claim->token, $turn->fresh()->claim_token);
        $this->assertNull($turn->fresh()->assistant_message_id);
        $this->assertDatabaseCount('conversation_messages', 1);
        Carbon::setTestNow(now()->addMinutes(5));
        $retry = $this->claim();
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->coordinator->complete($this->business, $claim, 'stale'));
        $customer = $this->coordinator->withOwnership($this->business, $retry, fn () => Customer::factory()->create());
        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertInstanceOf(ConversationMessage::class, $this->coordinator->complete($this->business, $retry, 'recovered'));
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_generation_can_enter_and_leave_a_short_authorized_write_transaction(): void
    {
        $this->inbound('1');
        $answer = $this->coordinator->processNext($this->business, 'web', 'sender', function (AiTurnClaim $claim): string {
            $this->assertSame(0, DB::transactionLevel());
            $this->coordinator->withOwnership($this->business, $claim, function (): void {
                $this->assertSame(1, DB::transactionLevel());
                Customer::factory()->create();
            });
            $this->assertSame(0, DB::transactionLevel());

            return 'answer after write';
        });

        $this->assertSame('answer after write', $answer->content);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_invalid_generated_response_is_a_recoverable_failure_not_a_success(): void
    {
        $turn = $this->inbound('1');
        try {
            $this->coordinator->processNext($this->business, 'web', 'sender', fn () => '');
            $this->fail('Expected response validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content', $exception->errors());
        }
        $this->assertSame(AiTurnState::Failed, $turn->fresh()->state);
        $this->assertNull($turn->fresh()->assistant_message_id);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertSame($turn->id, $this->claim()->turnId);
    }

    public function test_non_positive_lease_is_rejected_without_claiming_pending_work(): void
    {
        $turn = $this->inbound('1');
        $this->assertSame(AiTurnState::Pending, $turn->state);
        try {
            $this->coordinator->claimNext($this->business, 'web', 'sender', 0);
            $this->fail('Expected lease validation failure.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('The processing lease must be positive.', $exception->getMessage());
        }
        $this->assertSame(AiTurnState::Pending, $turn->fresh()->state);
    }

    public function test_database_enforces_one_turn_per_inbound_message(): void
    {
        $turn = $this->inbound('1');
        $this->expectException(QueryException::class);
        AiTurn::query()->create(['inbound_message_id' => $turn->inbound_message_id]);
    }

    public function test_database_enforces_unique_accepted_assistant_association(): void
    {
        $first = $this->inbound('1');
        $second = $this->inbound('2');
        $answer = $this->coordinator->complete($this->business, $this->claim(), 'answer');
        $this->assertSame($answer->id, $first->fresh()->assistant_message_id);
        $this->expectException(QueryException::class);
        $second->update(['state' => AiTurnState::Completed, 'assistant_message_id' => $answer->id, 'completed_at' => now()]);
    }

    private function inbound(string $messageId): AiTurn
    {
        return $this->coordinator->inbound($this->business, 'web', 'sender', $messageId, 'hello');
    }

    private function claim(): AiTurnClaim|AiTurnOutcome
    {
        return $this->coordinator->claimNext($this->business, 'web', 'sender');
    }
}
