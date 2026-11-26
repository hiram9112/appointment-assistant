<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnFailureReason;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\AppointmentProposalState;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
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
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;
use RedisException;
use RuntimeException;
use Tests\TestCase;

class ConversationReconciliationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_scheduler_recovers_missing_and_lost_jobs_through_a_real_redis_worker(): void
    {
        $this->travelTo('2030-01-01 12:00:00 UTC');
        $business = Business::factory()->create();
        $queueName = 'conversation-reconciliation-test-'.Str::uuid();
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => $queueName,
            'cache.default' => 'redis',
            'cache.prefix' => $queueName.':',
        ]);
        $redis = Queue::connection('redis');
        $rejectPush = true;
        Event::listen(JobQueueing::class, function () use (&$rejectPush): void {
            if ($rejectPush) {
                throw new RedisException('Simulated Redis dispatch failure');
            }
        });
        ConversationAgent::fake(fn (string $prompt): string => 'Answer to '.$prompt)->preventStrayPrompts();

        try {
            $missing = $this->inbound($business, 'Dispatch never reached Redis');
            $this->assertSame(0, $redis->size($queueName));
            $rejectPush = false;
            $lost = $this->inbound($business, 'Redis lost this job', sender: 'other');
            $this->assertSame(1, $redis->size($queueName));
            $redis->clear($queueName);
            $this->assertSame(0, $redis->size($queueName));

            $this->tick();
            $this->assertSame(2, $redis->size($queueName));
            $this->travel(1)->minute();
            $this->tick();
            $this->assertSame(4, $redis->size($queueName));
            $this->assertSame(0, $missing->fresh()->attempt_count);
            $this->assertSame(0, $lost->fresh()->attempt_count);
            $this->workRedisQueue();

            $this->assertSame(0, $redis->size($queueName));
            foreach ([$missing->refresh(), $lost->refresh()] as $turn) {
                $this->assertSame(AiTurnState::Completed, $turn->state);
                $this->assertSame(1, $turn->attempt_count);
                $this->assertSame('Answer to '.$turn->inboundMessage->content, $turn->assistantMessage->content);
            }
            $this->assertDatabaseCount('conversation_messages', 4);
            $this->assertDatabaseCount('failed_jobs', 0);
            ConversationAgent::assertPromptedTimes(2);
        } finally {
            $redis->clear($queueName);
        }
    }

    public function test_scheduler_exhausts_failed_generation_and_recovers_fifo_without_authorizing_an_unaccepted_proposal(): void
    {
        $this->travelTo('2030-01-01 12:00:00 UTC');
        $queueName = 'conversation-exhaustion-test-'.Str::uuid();
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => $queueName,
            'conversation.processing.max_attempts' => 3,
            'conversation.processing.retry_delays_seconds' => [30, 120],
        ]);
        $redis = Queue::connection('redis');
        $business = Business::factory()->create();
        $customer = Customer::factory()->create();
        ChannelIdentity::factory()->for($customer)->create(['external_id' => 'sender']);
        $appointment = Appointment::factory()->for($customer)
            ->for(Service::factory()->for($business))->for(Professional::factory()->for($business))->create([
                'status' => 'confirmed', 'starts_at' => '2030-02-01 10:00 UTC', 'ends_at' => '2030-02-01 11:00 UTC',
            ]);
        $prepareCancellation = new ToolCall('prepare', 'PrepareCancelAppointmentTool', ['appointment_id' => $appointment->id]);
        Exceptions::fake();
        $rejectProposal = true;
        AppointmentProposal::creating(function () use (&$rejectProposal): void {
            if ($rejectProposal) {
                throw new RuntimeException('Simulated proposal persistence failure');
            }
        });
        ConversationMessage::created(function (ConversationMessage $message): void {
            if ($message->content === '¿Confirmas la cancelación?') {
                throw new RuntimeException('Simulated assistant persistence failure');
            }
        });

        try {
            ConversationAgent::fake(fn () => throw new ConnectionException('Simulated provider timeout'))->preventStrayPrompts();
            $turn = $this->inbound($business, 'Cancela mi cita', channel: 'whatsapp');
            $this->workRedisQueue();
            $this->assertSame(AiTurnState::Failed, $turn->refresh()->state);
            $this->assertSame('12:00:30', $turn->next_attempt_at->format('H:i:s'));
            $this->assertSame(ConnectionException::class, $turn->diagnostics['attempts'][1]['failure']['exception_class']);

            $affirmative = $this->inbound($business, 'Sí', channel: 'whatsapp');
            ConversationAgent::fake([])->preventStrayPrompts();
            $this->travel(29)->seconds();
            $this->tick();
            $this->workRedisQueue();
            $this->assertSame(0, $affirmative->fresh()->attempt_count);
            ConversationAgent::assertPromptedTimes(1);

            ConversationAgent::fake([
                $prepareCancellation,
                fn () => throw new ConnectionException('Simulated disconnect after proposal failure'),
            ])->preventStrayPrompts();
            $this->travel(1)->second();
            $this->tick();
            $this->workRedisQueue();
            $this->assertDatabaseCount('appointment_proposals', 0);
            $this->assertSame('12:02:30', $turn->refresh()->next_attempt_at->format('H:i:s'));
            Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'Simulated proposal persistence failure');

            $rejectProposal = false;
            ConversationAgent::fake([$prepareCancellation, '¿Confirmas la cancelación?'])->preventStrayPrompts();
            $this->travel(2)->minutes();
            $this->tick();
            $this->workRedisQueue();
            $proposal = AppointmentProposal::sole();
            $this->assertSame(AppointmentProposalState::Prepared, $proposal->state);
            $this->assertSame(AiTurnState::FailureResolutionPending, $turn->refresh()->state);
            $this->assertSame(AiTurnFailureReason::ResponsePersistenceFailed, $turn->failure_reason);
            $this->assertDatabaseCount('conversation_messages', 2);
            $attempts = $turn->diagnostics['attempts'];

            ConversationAgent::fake([])->preventStrayPrompts();
            $this->travel(1)->minute();
            $this->tick();
            $this->workRedisQueue(['--max-jobs' => 1]);
            $this->assertSame('No pudimos completar tu solicitud. Puedes volver a intentarlo.', $turn->refresh()->assistantMessage->content);
            $this->assertTrue($turn->fallback_used);
            $this->assertSame(3, $turn->attempt_count);
            $this->assertSame($attempts, $turn->diagnostics['attempts']);
            $this->assertSame(AppointmentProposalState::Rejected, $proposal->refresh()->state);
            $this->assertNull($proposal->confirmation_request_message_id);
            $this->assertSame(0, $affirmative->fresh()->attempt_count);
            ConversationAgent::assertPromptedTimes(3);

            ConversationAgent::fake([
                new ToolCall('confirm', 'ConfirmAppointmentProposalTool', ['decision' => 'confirm']),
                'No hay una propuesta pendiente.',
            ])->preventStrayPrompts();
            $this->workRedisQueue();

            $this->assertSame('No hay una propuesta pendiente.', $affirmative->refresh()->assistantMessage->content);
            $this->assertSame(1, $affirmative->attempt_count);
            $this->assertNull($proposal->fresh()->confirming_turn_id);
            $this->assertSame('confirmed', $appointment->fresh()->status);
            $this->assertDatabaseCount('appointment_audits', 0);
            $this->assertDatabaseCount('conversation_messages', 4);
            $this->assertSame(0, $redis->size($queueName));
            ConversationAgent::assertPromptedTimes(4);
        } finally {
            $redis->clear($queueName);
        }
    }

    public function test_scheduler_excludes_superseded_conversations_and_batches_fifo_heads_by_participant(): void
    {
        $this->travelTo('2030-01-01 12:00:00 UTC');
        config(['conversation.inactivity_seconds' => 60, 'conversation.processing.reconciliation_batch_size' => 3]);
        $business = Business::factory()->create();
        $old = $this->inbound($business, 'old');
        $this->travel(1)->minute();
        $replacement = $this->inbound($business, 'replacement');
        $this->inbound($business, 'replacement backlog');
        $old->inboundMessage->conversation->update(['last_activity_at' => now()]);
        $otherBusiness = $this->inbound(Business::factory()->create(), 'same sender, other business');
        $otherChannel = $this->inbound($business, 'same sender, other channel', channel: 'whatsapp');
        $otherSender = $this->inbound($business, 'different sender', sender: 'other');
        Queue::fake([ProcessConversationTurn::class]);
        ConversationAgent::fake(['New answer', 'Second answer', 'Third answer'])->preventStrayPrompts();

        $this->tick();

        $this->assertQueuedConversations([$replacement, $otherBusiness, $otherChannel]);
        foreach (Queue::pushed(ProcessConversationTurn::class) as $job) {
            $job->handle(app(ConversationTurnProcessor::class));
        }
        $this->assertSame(AiTurnState::Superseded, $old->fresh()->state);
        $this->assertSame(0, $otherSender->fresh()->attempt_count);
        ConversationAgent::assertPromptedTimes(3);

        Queue::fake([ProcessConversationTurn::class]);
        $this->travel(1)->minute();
        $this->tick();
        $this->assertQueuedConversations([$otherSender]);
        Queue::fake([ProcessConversationTurn::class]);
        $this->travel(1)->minute();
        $this->tick();
        $this->assertQueuedConversations([$replacement, $otherSender]);
    }

    public function test_scheduler_waits_for_retry_backoff_and_an_expired_lease_before_requeueing(): void
    {
        $this->travelTo('2030-01-01 12:00:00 UTC');
        config(['conversation.processing.retry_delays_seconds' => [60, 120]]);
        $business = Business::factory()->create();
        $turns = app(AiTurnCoordinator::class);
        $failed = $this->inbound($business, 'failed', sender: 'retry');
        $turns->fail($business, $turns->claimNext($business, 'web', 'retry'));
        $interrupted = $this->inbound($business, 'expired but subject to backoff');
        $turns->claimNext($business, 'web', 'sender', leaseSeconds: 30);
        $this->inbound($business, 'arrived behind an interrupted claim');
        $active = $this->inbound($business, 'live lease', sender: 'active');
        $turns->claimNext($business, 'web', 'active', leaseSeconds: 120);
        Queue::fake([ProcessConversationTurn::class]);

        $this->travel(59)->seconds();
        $this->tick();
        Queue::assertNothingPushed();
        $this->travel(1)->second();
        $this->tick();

        $this->assertQueuedConversations([$failed, $interrupted]);
        $this->assertSame(1, $interrupted->fresh()->attempt_count);
        $this->assertSame(AiTurnState::Processing, $active->fresh()->state);
    }

    public function test_scheduler_discovers_exhausted_work_but_never_grants_more_model_attempts(): void
    {
        $this->travelTo('2030-01-01 12:00:00 UTC');
        config(['conversation.processing.max_attempts' => 1, 'conversation.processing.retry_delays_seconds' => [120]]);
        $business = Business::factory()->create();
        $turns = app(AiTurnCoordinator::class);
        $lastAttempt = $this->inbound($business, 'interrupted last attempt');
        $turns->claimNext($business, 'web', 'sender', leaseSeconds: 60);
        $backlog = $this->inbound($business, 'waiting behind exhaustion');
        $pending = $this->inbound($business, 'exhausted', sender: 'pending');
        $turns->fail($business, $turns->claimNext($business, 'web', 'pending'));
        $expired = $this->inbound($business, 'expired finalization', sender: 'expired');
        $this->claimFinalization($business, 'expired', 60);
        $active = $this->inbound($business, 'active finalization', sender: 'active');
        $this->claimFinalization($business, 'active', 120);
        $backoff = $this->inbound($business, 'finalization backoff', sender: 'backoff');
        $turns->fail($business, $this->claimFinalization($business, 'backoff', 60));
        $terminal = $this->inbound($business, 'closed without reply', sender: 'closed');
        $turns->terminate($business, $this->claimFinalization($business, 'closed', 60));
        $afterTerminal = $this->inbound($business, 'can proceed after closure', sender: 'closed');
        Queue::fake([ProcessConversationTurn::class]);
        ConversationAgent::fake(['Answer after closure'])->preventStrayPrompts();

        $this->travel(1)->minute();
        $this->tick();
        $this->assertQueuedConversations([$lastAttempt, $pending, $expired, $afterTerminal]);
        foreach (Queue::pushed(ProcessConversationTurn::class) as $job) {
            $job->handle(app(ConversationTurnProcessor::class));
        }

        foreach ([$lastAttempt->refresh(), $pending->refresh(), $expired->refresh()] as $turn) {
            $this->assertSame(1, $turn->attempt_count);
            $this->assertSame(AiTurnState::Completed, $turn->state);
            $this->assertNotNull($turn->assistant_message_id);
            $this->assertTrue($turn->fallback_used);
        }
        $this->assertSame(AiTurnState::ResolvingFailure, $active->fresh()->state);
        $this->assertSame(AiTurnState::FailureResolutionPending, $backoff->fresh()->state);
        $this->assertSame(AiTurnState::ClosedWithoutResponse, $terminal->fresh()->state);
        $this->assertSame('Answer after closure', $afterTerminal->fresh()->assistantMessage->content);
        ConversationAgent::assertPromptedTimes(1);

        Queue::fake([ProcessConversationTurn::class]);
        $this->tick();
        $this->assertQueuedConversations([$backlog]);
    }

    public function test_scheduler_skips_reconciliation_while_another_scan_holds_the_lock(): void
    {
        $business = Business::factory()->create();
        $turn = $this->inbound($business, 'pending');
        Queue::fake([ProcessConversationTurn::class]);
        $event = collect(app(Schedule::class)->events())->firstWhere('description', 'conversation:reconcile');
        $mutex = app(CacheEventMutex::class);
        $this->assertTrue($mutex->create($event));

        try {
            $this->tick();
            Queue::assertNothingPushed();
        } finally {
            $mutex->forget($event);
        }

        $this->tick();
        $this->assertQueuedConversations([$turn]);
    }

    public function test_a_full_batch_of_work_awaiting_finalization_does_not_starve_other_conversations(): void
    {
        $this->travelTo('2030-01-01 12:00:00 UTC');
        config(['conversation.processing.max_attempts' => 1, 'conversation.processing.reconciliation_batch_size' => 1]);
        $business = Business::factory()->create();
        $turns = app(AiTurnCoordinator::class);
        $exhausted = $this->inbound($business, 'awaiting finalization');
        $turns->fail($business, $turns->claimNext($business, 'web', 'sender'));
        $pending = $this->inbound($business, 'pending', sender: 'other');
        Queue::fake([ProcessConversationTurn::class]);
        ConversationAgent::fake(['Answer'])->preventStrayPrompts();

        $this->tick();
        $this->assertQueuedConversations([$exhausted]);
        $this->travel(1)->minute();
        $this->tick();
        $this->assertQueuedConversations([$exhausted, $pending]);
        Queue::pushed(ProcessConversationTurn::class)->last()->handle(app(ConversationTurnProcessor::class));
        $this->travel(1)->minute();
        $this->tick();

        $this->assertQueuedConversations([$exhausted, $pending, $exhausted]);
        $this->assertSame(AiTurnState::FailureResolutionPending, $exhausted->fresh()->state);
        $this->assertSame('Answer', $pending->fresh()->assistantMessage->content);

        Queue::pushed(ProcessConversationTurn::class)->last()->handle(app(ConversationTurnProcessor::class));
        $this->assertSame(AiTurnState::Completed, $exhausted->refresh()->state);
        $this->assertTrue($exhausted->fallback_used);
        ConversationAgent::assertPromptedTimes(1);
    }

    public function test_scheduler_rediscovers_an_older_retry_despite_continuous_full_batches(): void
    {
        $this->travelTo('2030-01-01 12:00:00 UTC');
        config([
            'conversation.processing.reconciliation_batch_size' => 1,
            'conversation.processing.retry_delays_seconds' => [60],
        ]);
        $business = Business::factory()->create();
        $turns = app(AiTurnCoordinator::class);
        $older = $this->inbound($business, 'Older turn awaiting retry');
        $turns->fail($business, $turns->claimNext($business, 'web', 'sender'));
        $first = $this->inbound($business, 'First newer turn', sender: 'first');
        $second = $this->inbound($business, 'Second newer turn', sender: 'second');
        Queue::fake([ProcessConversationTurn::class]);
        ConversationAgent::fake(['Recovered answer'])->preventStrayPrompts();

        $this->tick();
        $this->assertQueuedConversations([$first]);
        $this->travel(1)->minute();
        $this->inbound($business, 'Arrived after the first scan', sender: 'third');
        Queue::fake([ProcessConversationTurn::class]);
        $this->tick();
        $this->assertQueuedConversations([$second]);

        $this->travel(1)->minute();
        $this->inbound($business, 'Arrived after the second scan', sender: 'fourth');
        Queue::fake([ProcessConversationTurn::class]);
        $this->tick();
        $this->assertQueuedConversations([$older]);
        Queue::pushed(ProcessConversationTurn::class)->sole()->handle(app(ConversationTurnProcessor::class));

        $this->assertSame(AiTurnState::Completed, $older->refresh()->state);
        $this->assertSame(2, $older->attempt_count);
        $this->assertSame('Recovered answer', $older->assistantMessage->content);
        ConversationAgent::assertPromptedTimes(1);
    }

    private function tick(): void
    {
        $this->artisan('schedule:run')->assertSuccessful();
    }

    /** @param array<string, mixed> $options */
    private function workRedisQueue(array $options = []): void
    {
        $this->artisan('queue:work', [
            'connection' => 'redis', '--stop-when-empty' => true, '--sleep' => 0, ...$options,
        ])->assertSuccessful();
    }

    private function inbound(Business $business, string $content, string $sender = 'sender', string $channel = 'web'): AiTurn
    {
        return app(AiTurnCoordinator::class)->inbound($business, $channel, $sender, $content, $content);
    }

    private function claimFinalization(Business $business, string $sender, int $seconds): AiTurnClaim
    {
        $turns = app(AiTurnCoordinator::class);
        $turns->fail($business, $turns->claimNext($business, 'web', $sender));

        return $turns->claimFinalizationNext($business, 'web', $sender, $seconds);
    }

    /** @param list<AiTurn> $turns */
    private function assertQueuedConversations(array $turns): void
    {
        $this->assertSame(
            array_map(fn (AiTurn $turn): int => $turn->inboundMessage->conversation_id, $turns),
            Queue::pushed(ProcessConversationTurn::class)->map->conversationId->all(),
        );
    }
}
