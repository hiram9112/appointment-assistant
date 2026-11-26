<?php

namespace Tests\Feature;

use App\Application\Conversation\AiTurnClaim;
use App\Application\Conversation\AiTurnCoordinator;
use App\Application\Conversation\AiTurnFailure;
use App\Application\Conversation\AiTurnFailureReason;
use App\Application\Conversation\AiTurnOutcome;
use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\AppointmentProposalOperation;
use App\Application\Conversation\AppointmentProposalOutcome;
use App\Application\Conversation\AppointmentProposalPayload;
use App\Application\Conversation\AppointmentProposalPresentation;
use App\Application\Conversation\AppointmentProposals;
use App\Application\Conversation\AppointmentProposalState;
use App\Application\Conversation\ConfirmAppointmentProposal;
use App\Application\Conversation\ConversationAgent;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Application\Conversation\PrepareAppointmentProposal;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\ChannelIdentity;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AiTurnRetryLifecycleTest extends TestCase
{
    use DatabaseMigrations;

    private Business $business;

    private AiTurnCoordinator $turns;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2030-01-01 12:00:00 UTC'));
        $this->business = Business::factory()->create();
        $this->turns = app(AiTurnCoordinator::class);
    }

    public function test_only_authorized_claims_consume_durable_attempts_and_duplicates_respect_retry_boundaries(): void
    {
        $turn = $this->inbound('1');
        $later = $this->inbound('2');
        $this->assertSame(0, $turn->attempt_count);
        $this->assertSame(AiTurnOutcome::NotEligible, $this->finalization());
        $first = $this->claim();
        $this->assertSame(1, $turn->fresh()->attempt_count);
        $this->assertSame(AiTurnOutcome::Busy, $this->claim());
        $this->assertSame(AiTurnOutcome::Busy, $this->finalization());
        $this->assertSame($turn->id, $this->inbound('1')->id);

        $this->turns->fail($this->business, $first, exception: new RuntimeException('sensitive provider payload'));
        $this->assertSame(30, (int) now()->diffInSeconds($turn->fresh()->next_attempt_at));
        $snapshot = $turn->fresh()->getAttributes();
        $this->turns = app(AiTurnCoordinator::class);
        DB::disconnect();
        $this->travel(29)->seconds();
        $this->assertSame(AiTurnOutcome::NotDue, $this->claim());
        $this->assertSame(AiTurnOutcome::NotDue, $this->finalization());
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->fail($this->business, $first));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->complete($this->business, $first, 'late'));
        $this->assertSame($snapshot, $turn->fresh()->getAttributes());
        $this->travel(1)->seconds();
        $retry = $this->claim();
        $this->assertSame($turn->id, $retry->turnId);
        $this->assertSame(2, $turn->fresh()->attempt_count);
        $this->assertSame(0, $later->fresh()->attempt_count);
        $this->turns->complete($this->business, $retry, 'accepted');
        $this->assertSame(AiTurnFailureReason::ProcessingFailed, $turn->fresh()->failure_reason);
        $this->assertSame(RuntimeException::class, $turn->fresh()->diagnostics['attempts'][1]['failure']['exception_class']);
        $this->assertStringNotContainsString('sensitive', json_encode($turn->fresh()->diagnostics));
        $this->assertSame($later->id, $this->claim()->turnId);
    }

    public function test_progressive_backoff_and_default_budget_cannot_be_bypassed_by_direct_processor_reentry(): void
    {
        $turn = $this->inbound('1');
        ConversationAgent::fake(fn () => throw new RuntimeException('provider failed'))->preventStrayPrompts();
        $processor = app(ConversationTurnProcessor::class);
        foreach ([30, 120, null] as $index => $delay) {
            try {
                $processor->processNext($this->business, 'web', 'sender');
                $this->fail('Provider failure must propagate.');
            } catch (RuntimeException $exception) {
                $this->assertSame('provider failed', $exception->getMessage());
            }
            $this->assertSame($index + 1, $turn->fresh()->attempt_count);
            if ($delay !== null) {
                $this->assertSame($delay, (int) now()->diffInSeconds($turn->fresh()->next_attempt_at));
                $this->assertSame(AiTurnOutcome::NotDue, $processor->processNext($this->business, 'web', 'sender'));
                $this->travelTo($turn->fresh()->next_attempt_at);
            }
        }
        $this->assertSame(AiTurnState::FailureResolutionPending, $turn->fresh()->state);
        $this->assertSame(AiTurnOutcome::FailureResolutionPending, $this->claim());
        $this->assertNull($turn->fresh()->assistant_message_id);

        $response = $processor->processNext($this->business, 'web', 'sender');
        $this->assertInstanceOf(ConversationMessage::class, $response);
        $this->assertSame($response->id, $turn->refresh()->assistant_message_id);
        $this->assertTrue($turn->fallback_used);
        $this->assertSame(3, $turn->attempt_count);
        $this->assertCount(3, $turn->diagnostics['attempts']);
        $this->travel(1)->hours();
        $this->assertSame(AiTurnOutcome::Idle, $this->claim());
        ConversationAgent::assertPromptedTimes(3);
    }

    public function test_process_interruptions_count_and_expired_last_claim_requires_finalization_without_more_generation(): void
    {
        $turn = $this->inbound('1');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $old = $this->claim();
            $this->assertSame($attempt, $turn->fresh()->attempt_count);
            $this->travel(299)->seconds();
            $this->assertSame(AiTurnOutcome::Busy, $this->claim());
            $this->travel(1)->seconds();
        }
        $this->assertSame(AiTurnOutcome::FailureResolutionPending, $this->claim());
        $this->assertSame(AiTurnFailureReason::ClaimExpired, $turn->fresh()->failure_reason);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->complete($this->business, $old, 'obsolete'));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->fail($this->business, $old));
        $this->assertSame(3, $turn->fresh()->attempt_count);
        $this->assertCount(3, $turn->fresh()->diagnostics['attempts']);
        $finalizer = $this->finalization();
        $this->assertInstanceOf(AiTurnClaim::class, $finalizer);
        $this->assertSame(AiTurnState::ResolvingFailure, $turn->fresh()->state);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->withOwnership($this->business, $finalizer, fn () => $this->fail('Tools must not run')));
    }

    #[DataProvider('failureClassifications')]
    public function test_failure_classification_controls_retry_or_finalization(AiTurnFailure $classification, AiTurnState $state): void
    {
        $turn = $this->inbound('1');
        $this->turns->fail($this->business, $this->claim(), $classification, AiTurnFailureReason::AuthorizationFailed);
        $this->assertSame($classification, $turn->fresh()->failure_classification);
        $this->assertSame($state, $turn->fresh()->state);
        $this->assertSame(1, $turn->fresh()->attempt_count);
        if ($state === AiTurnState::FailureResolutionPending) {
            config(['conversation.processing.max_attempts' => 10]);
            $this->assertSame(AiTurnOutcome::FailureResolutionPending, $this->claim());
        } else {
            $this->assertSame(AiTurnOutcome::NotDue, $this->claim());
            $this->travelTo($turn->fresh()->next_attempt_at);
            $this->assertInstanceOf(AiTurnClaim::class, $this->claim());
        }
    }

    public static function failureClassifications(): array
    {
        return [
            'recoverable' => [AiTurnFailure::Recoverable, AiTurnState::Failed],
            'non-recoverable' => [AiTurnFailure::NonRecoverable, AiTurnState::FailureResolutionPending],
            'persistence unavailable' => [AiTurnFailure::PersistenceUnavailable, AiTurnState::Failed],
        ];
    }

    public function test_configured_budget_and_backoff_are_enforced(): void
    {
        config(['conversation.processing.max_attempts' => 2, 'conversation.processing.retry_delays_seconds' => [17]]);
        $turn = $this->inbound('1');
        $this->turns->fail($this->business, $this->claim());
        $this->assertSame(17, (int) now()->diffInSeconds($turn->fresh()->next_attempt_at));
        $this->travelTo($turn->fresh()->next_attempt_at);
        $this->turns->fail($this->business, $this->claim());
        $this->assertSame(AiTurnOutcome::FailureResolutionPending, $this->claim());
        $this->assertSame(2, $turn->fresh()->attempt_count);
    }

    #[DataProvider('expiredOwnerOperations')]
    public function test_lease_expiry_alone_does_not_revoke_the_unchanged_owner_even_on_the_last_attempt(string $operation): void
    {
        config(['conversation.processing.max_attempts' => 1]);
        $turn = $this->inbound('1');
        $claim = $this->claim(1);
        $this->travel(2)->seconds();
        $this->assertSame($turn->id, $this->turns->withOwnership($this->business, $claim, fn (AiTurn $authorized): int => $authorized->id));
        if ($operation === 'complete') {
            $this->assertInstanceOf(ConversationMessage::class, $this->turns->complete($this->business, $claim, 'late but still owned'));
            $this->assertSame(AiTurnState::Completed, $turn->fresh()->state);
        } else {
            $this->turns->fail($this->business, $claim);
            $this->assertSame(AiTurnState::FailureResolutionPending, $turn->fresh()->state);
            $this->assertSame(AiTurnFailureReason::ProcessingFailed, $turn->fresh()->failure_reason);
        }
        $this->assertSame(1, $turn->fresh()->attempt_count);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->withOwnership($this->business, $claim, fn () => $this->fail('Released ownership must be fenced')));
    }

    public static function expiredOwnerOperations(): array
    {
        return ['late completion' => ['complete'], 'late failure' => ['fail']];
    }

    #[DataProvider('expiredFinalizerOperations')]
    public function test_expired_finalizer_retains_ownership_until_release_or_takeover(string $operation): void
    {
        config(['conversation.processing.max_attempts' => 1]);
        $turn = $this->inbound('1');
        $this->turns->fail($this->business, $this->claim());
        $claim = $this->turns->claimFinalizationNext($this->business, 'web', 'sender', 1);
        $this->travel(2)->seconds();
        match ($operation) {
            'complete' => $this->turns->complete($this->business, $claim, 'accepted late fallback'),
            'terminate' => $this->turns->terminate($this->business, $claim),
            'fail' => $this->turns->fail($this->business, $claim, reason: AiTurnFailureReason::FinalizationFailed),
        };
        $expected = match ($operation) {
            'complete' => AiTurnState::Completed,
            'terminate' => AiTurnState::ClosedWithoutResponse,
            'fail' => AiTurnState::FailureResolutionPending,
        };
        $this->assertSame($expected, $turn->fresh()->state);
        $this->assertSame(1, $turn->fresh()->attempt_count);
        $this->assertSame(AiTurnFailureReason::ProcessingFailed, $turn->fresh()->failure_reason);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->complete($this->business, $claim, 'released'));
    }

    public static function expiredFinalizerOperations(): array
    {
        return ['completion' => ['complete'], 'terminal disposition' => ['terminate'], 'failure' => ['fail']];
    }

    #[DataProvider('expiredProcessingBackoff')]
    public function test_expired_processing_claims_respect_progressive_minimum_spacing_without_restarting_the_delay(int $lease, array $delays): void
    {
        config(['conversation.processing.retry_delays_seconds' => $delays]);
        $turn = $this->inbound('1');
        $later = $this->inbound('2');
        $claim = $this->claim($lease);
        foreach ($delays as $index => $delay) {
            $claimedAt = now()->toImmutable();
            $this->travelTo($claimedAt->addSeconds(max($lease, $delay) - 1));
            $expected = $lease >= $delay ? AiTurnOutcome::Busy : AiTurnOutcome::NotDue;
            $snapshot = $turn->fresh()->getAttributes();
            $this->assertSame($expected, $this->claim($lease));
            $this->assertSame($expected, $this->claim($lease));
            $this->assertSame($expected, $this->finalization());
            $this->assertSame($snapshot, $turn->fresh()->getAttributes());
            $this->assertSame($index + 1, $turn->fresh()->attempt_count);
            $this->assertSame(0, $later->fresh()->attempt_count);
            $this->assertSame($turn->id, $this->turns->withOwnership($this->business, $claim, fn (AiTurn $owned): int => $owned->id));
            $this->travel(1)->seconds();
            $next = $this->claim($lease);
            $this->assertInstanceOf(AiTurnClaim::class, $next);
            $this->assertSame($turn->id, $next->turnId);
            $this->assertSame($index + 2, $turn->fresh()->attempt_count);
            $this->assertSame(AiTurnFailureReason::ClaimExpired, $turn->fresh()->failure_reason);
            $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->complete($this->business, $claim, 'obsolete'));
            $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->fail($this->business, $claim));
            $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->withOwnership($this->business, $claim, fn () => $this->fail('Old owner must not write')));
            $claim = $next;
        }
        $this->travel($lease)->seconds();
        $this->assertSame(AiTurnOutcome::FailureResolutionPending, $this->claim());
        $this->assertSame(3, $turn->fresh()->attempt_count);
    }

    public static function expiredProcessingBackoff(): array
    {
        return [
            'short lease' => [5, [30, 120]],
            'default lease already exceeds backoff' => [300, [30, 120]],
            'configured backoff exceeds default lease' => [300, [600, 900]],
        ];
    }

    #[DataProvider('invalidRetryDelays')]
    public function test_invalid_retry_delays_do_not_prevent_durable_failure_recording(mixed $delays): void
    {
        config(['conversation.processing.retry_delays_seconds' => $delays]);
        $turn = $this->inbound('1');
        foreach ([30, 120] as $delay) {
            $claim = $this->claim();
            $this->turns->fail($this->business, $claim, exception: new RuntimeException('original failure'));
            $this->assertSame(AiTurnState::Failed, $turn->fresh()->state);
            $this->assertSame($delay, (int) now()->diffInSeconds($turn->fresh()->next_attempt_at));
            $this->assertSame(AiTurnOutcome::NotDue, $this->claim());
            $this->travelTo($turn->fresh()->next_attempt_at);
        }
        $this->turns->fail($this->business, $this->claim());
        $this->assertSame(AiTurnOutcome::FailureResolutionPending, $this->claim());
        $this->assertSame(3, $turn->fresh()->attempt_count);
        $this->assertSame(RuntimeException::class, $turn->fresh()->diagnostics['attempts'][1]['failure']['exception_class']);
    }

    public static function invalidRetryDelays(): array
    {
        return [
            'empty' => [[]], 'null' => [null], 'scalar' => ['30,120'], 'boolean' => [false],
            'associative' => [['first' => 30]], 'zero' => [[0, 120]], 'negative' => [[30, -1]],
            'numeric string' => [['7', 17]], 'fraction' => [[0.5, 120]], 'nested' => [[[30], 120]],
        ];
    }

    #[DataProvider('processingExceptions')]
    public function test_current_provider_errors_are_classified_without_treating_all_processing_exceptions_as_permanent(string $kind, AiTurnFailure $classification): void
    {
        $exception = match ($kind) {
            'credits' => InsufficientCreditsException::forProvider('openai'),
            '401', '403', '500' => new RequestException(new Response(new \GuzzleHttp\Psr7\Response((int) $kind))),
            'rate' => RateLimitedException::forProvider('openai'),
            'connection' => ProviderConnectionException::forProvider('openai'),
            'overload' => ProviderOverloadedException::forProvider('openai'),
            'logic' => new LogicException('State may be repaired before retry'),
        };
        $turn = $this->inbound('1');
        ConversationAgent::fake(fn () => throw $exception)->preventStrayPrompts();
        try {
            app(ConversationTurnProcessor::class)->processNext($this->business, 'web', 'sender');
            $this->fail('The original processing exception must propagate.');
        } catch (\Throwable $observed) {
            $this->assertSame($exception, $observed);
        }
        $this->assertSame($classification, $turn->fresh()->failure_classification);
        $this->assertSame($exception::class, $turn->fresh()->diagnostics['attempts'][1]['failure']['exception_class']);
        $expected = $classification === AiTurnFailure::NonRecoverable ? AiTurnOutcome::FailureResolutionPending : AiTurnOutcome::NotDue;
        $this->assertSame($expected, $this->claim());
        $this->assertSame(1, $turn->fresh()->attempt_count);
        $this->assertNull($turn->fresh()->assistant_message_id);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
        ConversationAgent::assertPromptedTimes(1);
    }

    public static function processingExceptions(): array
    {
        return [
            'insufficient credits' => ['credits', AiTurnFailure::NonRecoverable],
            'provider authentication' => ['401', AiTurnFailure::NonRecoverable],
            'provider permission' => ['403', AiTurnFailure::NonRecoverable],
            'server failure' => ['500', AiTurnFailure::Recoverable],
            'rate limit' => ['rate', AiTurnFailure::Recoverable],
            'connection' => ['connection', AiTurnFailure::Recoverable],
            'overload' => ['overload', AiTurnFailure::Recoverable],
            'no blanket LogicException classification' => ['logic', AiTurnFailure::Recoverable],
        ];
    }

    public function test_failed_and_interrupted_finalization_remain_recoverable_without_resetting_attempts_or_original_failure(): void
    {
        $turn = $this->inbound('1');
        $later = $this->inbound('2');
        $this->exhaust();
        $original = $turn->fresh()->diagnostics['attempts'];
        $finalizer = $this->finalization();
        $this->assertSame(AiTurnOutcome::Busy, $this->claim());
        $this->turns->fail($this->business, $finalizer, AiTurnFailure::PersistenceUnavailable, AiTurnFailureReason::FinalizationFailed, new RuntimeException('db'));
        $this->assertSame(AiTurnState::FailureResolutionPending, $turn->fresh()->state);
        $this->assertSame(AiTurnFailureReason::ProcessingFailed, $turn->fresh()->failure_reason);
        $this->assertSame(120, (int) now()->diffInSeconds($turn->fresh()->next_attempt_at));
        $this->assertSame(AiTurnOutcome::NotDue, $this->finalization());
        $this->assertSame(AiTurnOutcome::NotDue, $this->claim());
        $this->travelTo($turn->fresh()->next_attempt_at);
        $interrupted = $this->finalization();
        $this->travel(300)->seconds();
        $current = $this->finalization();
        $this->assertNotSame($interrupted->token, $current->token);
        $snapshot = $turn->fresh()->getAttributes();
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->terminate($this->business, $interrupted));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->fail($this->business, $interrupted));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->complete($this->business, $interrupted, 'stale'));
        $this->assertSame($snapshot, $turn->fresh()->getAttributes());
        $message = $this->turns->complete($this->business, $current, 'Deterministic text supplied by trusted code');
        $this->assertInstanceOf(ConversationMessage::class, $message);
        $this->assertTrue($turn->fresh()->fallback_used);
        $this->assertSame(3, $turn->fresh()->attempt_count);
        $this->assertSame($original, $turn->fresh()->diagnostics['attempts']);
        $this->assertSame(2, $turn->fresh()->diagnostics['finalization']['failure_count']);
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->complete($this->business, $current, 'duplicate'));
        $this->assertSame($later->id, $this->claim()->turnId);
        $this->assertDatabaseCount('conversation_messages', 3);
    }

    public function test_unavailable_persistence_does_not_fabricate_failure_or_completion(): void
    {
        $turn = $this->inbound('1');
        $this->exhaust();
        $finalizer = $this->finalization();
        ConversationMessage::creating(fn () => throw new RuntimeException('database unavailable'));
        AiTurn::updating(fn () => throw new RuntimeException('database unavailable'));
        try {
            foreach ([
                fn () => $this->turns->complete($this->business, $finalizer, 'unsaved'),
                fn () => $this->turns->fail($this->business, $finalizer),
                fn () => $this->turns->terminate($this->business, $finalizer),
            ] as $operation) {
                try {
                    $operation();
                    $this->fail('Persistence failure must propagate.');
                } catch (RuntimeException $exception) {
                    $this->assertSame('database unavailable', $exception->getMessage());
                }
            }
        } finally {
            ConversationMessage::flushEventListeners();
            AiTurn::flushEventListeners();
        }
        $this->assertSame(AiTurnState::ResolvingFailure, $turn->fresh()->state);
        $this->assertNull($turn->fresh()->assistant_message_id);
        $this->assertNull($turn->fresh()->terminal_at);
        $this->assertFalse($turn->fresh()->fallback_used);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->travel(300)->seconds();
        $this->assertInstanceOf(AiTurnClaim::class, $this->finalization());
    }

    #[DataProvider('dispositions')]
    public function test_terminal_or_fallback_disposition_invalidates_prepared_proposals_before_fifo_advances(bool $fallback): void
    {
        $turn = $this->inbound('1');
        $claim = $this->claim();
        $proposal = $this->prepare($claim);
        $later = $this->inbound('2');
        $this->assertSame(AiTurnOutcome::NotEligible, $this->turns->terminate($this->business, $claim));
        $this->exhaust($claim);
        $this->assertSame(AiTurnOutcome::FailureResolutionPending, $this->claim());
        $finalizer = $this->finalization();
        if ($fallback) {
            $this->turns->complete($this->business, $finalizer, 'No operation was performed.');
            $this->assertTrue($turn->fresh()->fallback_used);
        } else {
            $this->turns->terminate($this->business, $finalizer);
            $this->assertSame(AiTurnState::ClosedWithoutResponse, $turn->fresh()->state);
            $this->assertNotNull($turn->fresh()->terminal_at);
            $this->assertSame(AiTurnFailureReason::UnresolvedFailure, $turn->fresh()->terminal_reason);
            $this->assertNull($turn->fresh()->assistant_message_id);
        }
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        $this->assertNull($proposal->fresh()->confirmation_request_message_id);
        $next = $this->claim();
        $this->assertSame($later->id, $next->turnId);
        $this->assertSame(AppointmentProposalOutcome::NotFound, app(AppointmentProposals::class)->active($this->business, $next));
        $this->assertSame(AiTurnOutcome::StaleAttempt, $this->turns->withOwnership($this->business, $finalizer, fn () => $this->fail('must not execute')));
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('appointment_audits', 0);
    }

    public static function dispositions(): array
    {
        return ['durable terminal disposition' => [false], 'accepted fallback' => [true]];
    }

    public function test_terminal_disposition_rolls_back_proposal_invalidation_when_turn_persistence_fails(): void
    {
        $turn = $this->inbound('1');
        $claim = $this->claim();
        $proposal = $this->prepare($claim);
        $this->exhaust($claim);
        $finalizer = $this->finalization();
        AiTurn::updating(fn () => throw new RuntimeException('terminal write failed'));
        try {
            $this->turns->terminate($this->business, $finalizer);
            $this->fail('Terminal persistence must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('terminal write failed', $exception->getMessage());
        } finally {
            AiTurn::flushEventListeners();
        }
        $this->assertSame(AppointmentProposalState::Prepared, $proposal->fresh()->state);
        $this->assertSame(AiTurnState::ResolvingFailure, $turn->fresh()->state);
        $this->assertNull($turn->fresh()->terminal_at);
        $this->assertSame(AiTurnOutcome::Busy, $this->claim());
    }

    #[DataProvider('dispositions')]
    public function test_conversation_replacement_supersedes_work_awaiting_or_undergoing_failure_resolution(bool $claimed): void
    {
        $turn = $this->inbound('1');
        $this->exhaust();
        $claim = $claimed ? $this->finalization() : null;
        $this->travel(12)->hours();
        $replacement = $this->inbound('2');
        if ($claim !== null) {
            $this->assertSame(AiTurnOutcome::Superseded, $this->turns->complete($this->business, $claim, 'obsolete'));
        }
        $this->assertSame($replacement->id, $this->claim()->turnId);
        $this->assertSame(AiTurnState::Superseded, $turn->fresh()->state);
        $this->assertSame(3, $turn->fresh()->attempt_count);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_terminal_disposition_preserves_committed_operation_result(): void
    {
        $customer = Customer::factory()->create();
        ChannelIdentity::factory()->for($customer)->create(['channel' => 'whatsapp', 'external_id' => 'sender']);
        $service = Service::factory()->for($this->business)->create();
        $professional = Professional::factory()->for($this->business)->create(['display_name' => 'Laura']);
        $appointment = Appointment::factory()->for($service)->for($professional)->for($customer)->create([
            'status' => 'confirmed', 'starts_at' => '2030-02-01 10:00 UTC', 'ends_at' => '2030-02-01 11:00 UTC',
        ]);
        $this->turns->inbound($this->business, 'whatsapp', 'sender', '1', 'Cancel');
        $claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $prepared = app(PrepareAppointmentProposal::class)->handle($this->business, $claim, AppointmentProposalOperation::Cancel, ['appointment_id' => $appointment->id]);
        $this->turns->complete($this->business, $claim, 'Confirm cancellation?');
        $confirming = $this->turns->inbound($this->business, 'whatsapp', 'sender', '2', 'Yes');
        $claim = $this->turns->claimNext($this->business, 'whatsapp', 'sender');
        $result = app(ConfirmAppointmentProposal::class)->handle($this->business, $claim, true);
        $this->assertSame('cancelled', $result['outcome']);
        $proposal = AppointmentProposal::findOrFail($prepared['proposal_id']);
        $before = $proposal->getAttributes();
        $this->turns->fail($this->business, $claim, AiTurnFailure::NonRecoverable);
        $finalizer = $this->turns->claimFinalizationNext($this->business, 'whatsapp', 'sender');
        $this->turns->terminate($this->business, $finalizer);
        $this->assertSame($before, $proposal->fresh()->getAttributes());
        $this->assertSame($result, $proposal->fresh()->result);
        $this->assertSame($confirming->id, $proposal->confirming_turn_id);
        $this->assertSame('cancelled', $appointment->fresh()->status);
        $this->assertDatabaseCount('appointment_audits', 1);
        $this->assertSame(AiTurnOutcome::Idle, $this->turns->claimNext($this->business, 'whatsapp', 'sender'));
    }

    public function test_terminal_disposition_revokes_an_earlier_pending_proposal_before_a_later_affirmative_can_advance(): void
    {
        $this->inbound('1');
        $claim = $this->claim();
        $proposal = $this->prepare($claim);
        $this->turns->complete($this->business, $claim, 'Confirm?');
        $this->assertSame(AppointmentProposalState::Pending, $proposal->fresh()->state);
        $failed = $this->inbound('2');
        $later = $this->inbound('3');
        $this->exhaust();
        $this->turns->terminate($this->business, $this->finalization());
        $this->assertSame(AiTurnState::ClosedWithoutResponse, $failed->fresh()->state);
        $this->assertSame(AppointmentProposalState::Rejected, $proposal->fresh()->state);
        $next = $this->claim();
        $this->assertSame($later->id, $next->turnId);
        $this->assertSame(AppointmentProposalOutcome::NotFound, app(AppointmentProposals::class)->active($this->business, $next));
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_cross_business_finalization_cannot_accept_fail_or_terminate_another_business_turn(): void
    {
        $turn = $this->inbound('1');
        $this->exhaust();
        $claim = $this->finalization();
        $other = Business::factory()->create();
        $snapshot = $turn->fresh()->getAttributes();
        $this->assertSame(AiTurnOutcome::NotFound, $this->turns->complete($other, $claim, 'hidden'));
        $this->assertSame(AiTurnOutcome::NotFound, $this->turns->fail($other, $claim));
        $this->assertSame(AiTurnOutcome::NotFound, $this->turns->terminate($other, $claim));
        $this->assertSame($snapshot, $turn->fresh()->getAttributes());
        $this->assertSame(AiTurnOutcome::Idle, $this->turns->claimFinalizationNext($other, 'web', 'sender'));
    }

    public function test_additive_migration_preserves_legacy_turns_and_counts_the_attempt_evidenced_by_state(): void
    {
        $completed = $this->inbound('1');
        $this->turns->complete($this->business, $this->claim(), 'answer');
        $failed = $this->inbound('2');
        $this->turns->fail($this->business, $this->claim());
        $pending = $this->inbound('3');
        $processing = $this->turns->inbound($this->business, 'web', 'other', '1', 'hello');
        $this->turns->claimNext($this->business, 'web', 'other');

        $rollbackStates = [];
        foreach (['awaiting-resolution', 'resolving', 'closed'] as $sender) {
            $turn = $this->turns->inbound($this->business, 'web', $sender, '1', 'hello');
            $claim = $this->turns->claimNext($this->business, 'web', $sender);
            $this->turns->fail($this->business, $claim, AiTurnFailure::NonRecoverable);
            if ($sender !== 'awaiting-resolution') {
                $claim = $this->turns->claimFinalizationNext($this->business, 'web', $sender);
                if ($sender === 'closed') {
                    $this->turns->terminate($this->business, $claim);
                }
            }
            $rollbackStates[$turn->id] = $sender === 'closed' ? AiTurnState::Superseded : AiTurnState::Failed;
        }

        $migration = require database_path('migrations/2026_11_23_171125_add_retry_lifecycle_to_ai_turns_table.php');
        DB::transaction(function () use ($migration, $rollbackStates): void {
            $migration->down();
            foreach ($rollbackStates as $id => $state) {
                $this->assertDatabaseHas('ai_turns', [
                    'id' => $id, 'state' => $state->value, 'claim_token' => null, 'claimed_until' => null,
                ]);
            }
            $migration->up();
        });
        foreach ([$completed, $failed, $processing] as $turn) {
            $this->assertSame(1, $turn->fresh()->attempt_count);
        }
        $this->assertSame(0, $pending->fresh()->attempt_count);
        $this->assertSame(AiTurnState::Completed, $completed->fresh()->state);
        $this->assertNotNull($completed->fresh()->assistant_message_id);
        $this->assertSame(AiTurnState::Failed, $failed->fresh()->state);
        $this->assertSame(AiTurnState::Processing, $processing->fresh()->state);
        $this->assertSame(AiTurnOutcome::Busy, $this->turns->claimNext($this->business, 'web', 'other'));
    }

    private function inbound(string $id): AiTurn
    {
        return $this->turns->inbound($this->business, 'web', 'sender', $id, 'hello');
    }

    private function claim(int $leaseSeconds = 300): AiTurnClaim|AiTurnOutcome
    {
        return $this->turns->claimNext($this->business, 'web', 'sender', $leaseSeconds);
    }

    private function finalization(): AiTurnClaim|AiTurnOutcome
    {
        return $this->turns->claimFinalizationNext($this->business, 'web', 'sender');
    }

    private function exhaust(?AiTurnClaim $claim = null): void
    {
        while (true) {
            $claim ??= $this->claim();
            $turn = $this->turns->fail($this->business, $claim);
            if ($turn->state === AiTurnState::FailureResolutionPending) {
                return;
            }
            $this->travelTo($turn->next_attempt_at);
            $claim = null;
        }
    }

    private function prepare(AiTurnClaim $claim): AppointmentProposal
    {
        $service = Service::factory()->for($this->business)->create();
        $professional = Professional::factory()->for($this->business)->create(['display_name' => 'Laura']);
        $start = CarbonImmutable::parse('2030-02-01 10:00 UTC');

        return app(AppointmentProposals::class)->prepare($this->business, $claim,
            AppointmentProposalPayload::create($service->id, $professional->id, $start),
            new AppointmentProposalPresentation($service->name, $professional->display_name, $start, $start->addHour(), $this->business->timezone));
    }
}
