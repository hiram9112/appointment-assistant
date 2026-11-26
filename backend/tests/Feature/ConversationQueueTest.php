<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationOutcome;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Jobs\ProcessConversationTurn;
use App\Models\AiTurn;
use App\Models\Business;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use RedisException;
use Tests\TestCase;

class ConversationQueueTest extends TestCase
{
    use DatabaseMigrations;

    private Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        $this->business = Business::factory()->create();
    }

    public function test_inbound_jobs_wait_for_outer_commit_and_rollbacks_discard_them(): void
    {
        Queue::fake();

        DB::beginTransaction();
        $this->inbound('rolled back');
        Queue::assertNothingPushed();
        DB::rollBack();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('conversation_messages', 0);
        $this->assertDatabaseCount('ai_turns', 0);

        DB::transaction(function (): void {
            $this->inbound('committed');
            Queue::assertNothingPushed();
        });

        $turn = AiTurn::sole();
        Queue::assertPushedOnce(ProcessConversationTurn::class);
        Queue::assertPushed(ProcessConversationTurn::class, ['conversationId' => $turn->inboundMessage->conversation_id]);
        $this->assertSame(AiTurnState::Pending, $turn->state);
    }

    public function test_duplicate_and_conflicting_inbounds_do_not_enqueue_again(): void
    {
        Queue::fake();
        $turns = app(AiTurnCoordinator::class);

        $first = $this->inbound('original');
        $duplicate = $this->inbound('original');
        $conflict = $turns->inbound($this->business, 'web', 'sender', 'original', 'changed');

        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(ConversationOutcome::ConflictingMessage, $conflict);
        Queue::assertPushedOnce(ProcessConversationTurn::class);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertDatabaseCount('ai_turns', 1);
    }

    public function test_redis_push_failure_is_logged_after_commit_and_leaves_pending_work(): void
    {
        config(['queue.default' => 'redis']);
        Log::spy();
        Queue::shouldReceive('push')->once()->andReturnUsing(function (ProcessConversationTurn $job): never {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertSame($job->conversationId, AiTurn::sole()->inboundMessage->conversation_id);

            throw new RedisException('Connection refused');
        });

        $turn = DB::transaction(fn (): AiTurn => $this->inbound('committed'));

        $this->assertDatabaseHas('ai_turns', ['id' => $turn->id, 'state' => 'pending', 'attempt_count' => 0]);
        $this->assertDatabaseHas('conversation_messages', ['id' => $turn->inbound_message_id, 'content' => 'committed']);
        Log::shouldHaveReceived('warning')->once()->with('Conversation processing job could not be queued.', [
            'conversation_id' => $turn->inboundMessage->conversation_id,
            'exception_class' => RedisException::class,
        ]);
    }

    public function test_redis_worker_accepts_one_response_even_with_duplicate_jobs(): void
    {
        $queueName = 'conversation-test-'.Str::uuid();
        config(['queue.default' => 'redis', 'queue.connections.redis.queue' => $queueName]);
        $redis = Queue::connection('redis');
        ConversationAgent::fake(['Respuesta'])->preventStrayPrompts();

        try {
            $turn = $this->inbound('Pregunta');
            $conversationId = $turn->inboundMessage->conversation_id;
            $this->assertSame(1, $redis->size($queueName));
            $redis->push(new ProcessConversationTurn($conversationId));
            $this->assertSame(2, $redis->size($queueName));

            $this->artisan('queue:work', ['connection' => 'redis', '--max-jobs' => 2, '--stop-when-empty' => true])
                ->assertSuccessful();

            $this->assertSame(0, $redis->size($queueName));
            $this->assertSame(AiTurnState::Completed, $turn->refresh()->state);
            $this->assertSame(1, $turn->attempt_count);
            $this->assertSame('Respuesta', $turn->assistantMessage->content);
            $this->assertDatabaseCount('conversation_messages', 2);
            $this->assertDatabaseCount('failed_jobs', 0);
            ConversationAgent::assertPromptedTimes(1);
        } finally {
            $redis->clear($queueName);
        }
    }

    public function test_busy_jobs_acknowledge_and_success_continues_backlog_one_turn_at_a_time(): void
    {
        Queue::fake();
        $first = $this->inbound('first');
        $processor = app(ConversationTurnProcessor::class);
        $second = $third = null;
        ConversationAgent::fake(function (string $prompt) use ($first, $processor, &$second, &$third): string {
            if ($prompt === 'first') {
                $second = $this->inbound('second');
                $third = $this->inbound('third');
                foreach (Queue::pushed(ProcessConversationTurn::class)->skip(1) as $job) {
                    $job->handle($processor);
                }
                Queue::assertPushedTimes(ProcessConversationTurn::class, 3);
                $this->assertSame(AiTurnState::Processing, $first->fresh()->state);
                $this->assertSame(0, $second->fresh()->attempt_count);
                $this->assertSame(0, $third->fresh()->attempt_count);
            }

            return 'Answer to '.$prompt;
        })->preventStrayPrompts();

        Queue::pushed(ProcessConversationTurn::class)->first()->handle($processor);
        Queue::assertPushedTimes(ProcessConversationTurn::class, 4);
        $this->assertSame(AiTurnState::Pending, $second->fresh()->state);

        Queue::pushed(ProcessConversationTurn::class)->last()->handle($processor);
        Queue::assertPushedTimes(ProcessConversationTurn::class, 5);
        $this->assertSame('Answer to second', $second->fresh()->assistantMessage->content);
        $this->assertSame(AiTurnState::Pending, $third->fresh()->state);

        Queue::pushed(ProcessConversationTurn::class)->last()->handle($processor);
        Queue::assertPushedTimes(ProcessConversationTurn::class, 5);
        $this->assertSame('Answer to third', $third->fresh()->assistantMessage->content);
        ConversationAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === 'third'
            && array_column($prompt->messages, 'content') === ['first', 'Answer to first', 'second', 'Answer to second']);
        ConversationAgent::assertPromptedTimes(3);
    }

    public function test_superseded_job_cannot_process_the_replacement_or_other_participants(): void
    {
        Queue::fake();
        config(['conversation.inactivity_seconds' => 60]);
        $old = $this->inbound('old');
        $this->travel(61)->seconds();
        $replacement = $this->inbound('replacement');
        $otherSender = $this->inbound('other customer', sender: 'other sender');
        $otherBusiness = $this->inbound('other business', business: Business::factory()->create());
        $processor = app(ConversationTurnProcessor::class);
        ConversationAgent::fake(['New answer'])->preventStrayPrompts();
        [$oldJob, $replacementJob] = Queue::pushed(ProcessConversationTurn::class)->all();

        $oldJob->handle($processor);
        $this->assertSame(0, $replacement->fresh()->attempt_count);
        ConversationAgent::assertNeverPrompted();

        $replacementJob->handle($processor);
        $oldJob->handle($processor);

        $this->assertSame(AiTurnState::Superseded, $old->refresh()->state);
        $this->assertSame(0, $old->attempt_count);
        $this->assertSame('New answer', $replacement->fresh()->assistantMessage->content);
        $this->assertSame(AiTurnState::Pending, $otherSender->fresh()->state);
        $this->assertSame(AiTurnState::Pending, $otherBusiness->fresh()->state);
        Queue::assertPushedTimes(ProcessConversationTurn::class, 4);
        ConversationAgent::assertPromptedTimes(1);
    }

    public function test_not_due_and_exhausted_work_does_not_generate_and_fallback_queues_backlog(): void
    {
        Queue::fake();
        config(['conversation.processing.max_attempts' => 2]);
        $turns = app(AiTurnCoordinator::class);
        $first = $this->inbound('first');
        $second = $this->inbound('second');
        $turns->fail($this->business, $turns->claimNext($this->business, 'web', 'sender'));

        $processor = app(ConversationTurnProcessor::class);
        $job = Queue::pushed(ProcessConversationTurn::class)->last();
        ConversationAgent::fake([])->preventStrayPrompts();

        $job->handle($processor);
        $this->assertSame(1, $first->fresh()->attempt_count);
        $this->assertSame(0, $second->fresh()->attempt_count);
        $this->travelTo($first->fresh()->next_attempt_at);
        $turns->fail($this->business, $turns->claimNext($this->business, 'web', 'sender'));

        $job->handle($processor);
        $this->assertSame(AiTurnState::Completed, $first->refresh()->state);
        $this->assertTrue($first->fallback_used);
        $this->assertSame(2, $first->attempt_count);
        $this->assertSame(0, $second->fresh()->attempt_count);
        Queue::assertPushedTimes(ProcessConversationTurn::class, 3);
        ConversationAgent::assertNeverPrompted();

        ConversationAgent::fake(['Second answer'])->preventStrayPrompts();

        Queue::pushed(ProcessConversationTurn::class)->last()->handle($processor);
        $this->assertSame('Second answer', $second->fresh()->assistantMessage->content);
        Queue::assertPushedTimes(ProcessConversationTurn::class, 3);
        ConversationAgent::assertPromptedTimes(1);
    }

    private function inbound(string $content, string $sender = 'sender', ?Business $business = null): AiTurn
    {
        return app(AiTurnCoordinator::class)->inbound($business ?? $this->business, 'web', $sender, $content, $content);
    }
}
