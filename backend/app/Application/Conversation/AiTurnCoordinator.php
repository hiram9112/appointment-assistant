<?php

namespace App\Application\Conversation;

use App\Models\AiTurn;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use LogicException;
use Throwable;

final class AiTurnCoordinator
{
    public function __construct(
        private ConversationHandler $conversations,
        private ConversationParticipantLock $lock,
    ) {}

    /** Atomically persist an S10 inbound message and its one logical AI turn. */
    public function inbound(Business $business, mixed $channel, mixed $senderIdentity, mixed $messageId, mixed $content): AiTurn|ConversationOutcome
    {
        return DB::transaction(function () use ($business, $channel, $senderIdentity, $messageId, $content): AiTurn|ConversationOutcome {
            $message = $this->conversations->inbound($business, $channel, $senderIdentity, $messageId, $content);
            if ($message instanceof ConversationOutcome) {
                return $message;
            }

            $this->lock->acquire($business->id, $channel, $senderIdentity);

            return AiTurn::query()->firstOrCreate(['inbound_message_id' => $message->id]);
        });
    }

    /**
     * Internal re-entry: also recover S10 messages persisted without turn registration.
     * Inbound IDs reflect insertion order under S10's participant lock, unlike timestamps.
     */
    public function claimNext(Business $business, string $channel, string $senderIdentity, int $leaseSeconds = 300): AiTurnClaim|AiTurnOutcome
    {
        return $this->claim($business, $channel, $senderIdentity, $leaseSeconds, false);
    }

    /** Finalization can accept a response or terminate work, but cannot authorize AI tools. */
    public function claimFinalizationNext(Business $business, string $channel, string $senderIdentity, int $leaseSeconds = 300): AiTurnClaim|AiTurnOutcome
    {
        return $this->claim($business, $channel, $senderIdentity, $leaseSeconds, true);
    }

    private function claim(Business $business, string $channel, string $senderIdentity, int $leaseSeconds, bool $finalization): AiTurnClaim|AiTurnOutcome
    {
        if ($leaseSeconds < 1) {
            throw new InvalidArgumentException('The processing lease must be positive.');
        }

        return DB::transaction(function () use ($business, $channel, $senderIdentity, $leaseSeconds, $finalization): AiTurnClaim|AiTurnOutcome {
            $this->lock->acquire($business->id, $channel, $senderIdentity);
            $conversations = $this->participant($business, $channel, $senderIdentity);
            $current = (clone $conversations)->orderByDesc('last_activity_at')->orderByDesc('id')->first();
            if (! $current) {
                return AiTurnOutcome::Idle;
            }

            $messages = ConversationMessage::query()->whereIn('conversation_id', (clone $conversations)->select('id'))
                ->where('role', 'user')->whereNotIn('id', AiTurn::query()->select('inbound_message_id'))->orderBy('id')->get();
            foreach ($messages as $message) {
                AiTurn::query()->create(['inbound_message_id' => $message->id]);
            }

            $turns = AiTurn::query()->whereHas('inboundMessage', fn (Builder $query) => $query
                ->whereIn('conversation_id', (clone $conversations)->select('id')))
                ->whereIn('state', [AiTurnState::Pending, AiTurnState::Processing, AiTurnState::Failed,
                    AiTurnState::FailureResolutionPending, AiTurnState::ResolvingFailure]);

            (clone $turns)->whereHas('inboundMessage', fn (Builder $query) => $query->where('conversation_id', '!=', $current->id))
                ->update(['state' => AiTurnState::Superseded, 'claim_token' => null, 'claimed_until' => null, 'next_attempt_at' => null]);

            $turn = $turns->orderBy('inbound_message_id')->lockForUpdate()->first();
            if (! $turn) {
                return AiTurnOutcome::Idle;
            }
            if (in_array($turn->state, [AiTurnState::Processing, AiTurnState::ResolvingFailure], true) && $turn->claimed_until->isFuture()) {
                return AiTurnOutcome::Busy;
            }
            if ($turn->next_attempt_at?->isFuture()) {
                return AiTurnOutcome::NotDue;
            }

            $exhausted = in_array($turn->state, [AiTurnState::FailureResolutionPending, AiTurnState::ResolvingFailure], true)
                || $turn->attempt_count >= max(1, (int) config('conversation.processing.max_attempts'));
            if ($turn->state === AiTurnState::Processing && ! $exhausted) {
                $claimedAt = $turn->diagnostics['attempts'][$turn->attempt_count]['claimed_at'] ?? $turn->updated_at;
                if (CarbonImmutable::parse($claimedAt)->addSeconds($this->retryDelay($turn->attempt_count))->isFuture()) {
                    return AiTurnOutcome::NotDue;
                }
            }
            if ($turn->state === AiTurnState::Processing) {
                $this->recordFailure($turn, AiTurnFailure::Recoverable, AiTurnFailureReason::ClaimExpired);
            }
            if ($turn->state === AiTurnState::ResolvingFailure) {
                $this->recordFinalizationFailure($turn, AiTurnFailure::Recoverable, AiTurnFailureReason::ClaimExpired);
            }
            if ($exhausted) {
                $turn->fill(['state' => AiTurnState::FailureResolutionPending, 'claim_token' => null, 'claimed_until' => null, 'next_attempt_at' => null]);
                if (! $finalization) {
                    $turn->save();

                    return AiTurnOutcome::FailureResolutionPending;
                }
            } elseif ($finalization) {
                return AiTurnOutcome::NotEligible;
            }

            $token = (string) Str::uuid();
            $diagnostics = $turn->diagnostics;
            if ($finalization) {
                $diagnostics['finalization']['claim_count'] = ($diagnostics['finalization']['claim_count'] ?? 0) + 1;
                $diagnostics['finalization']['last_claimed_at'] = now()->toAtomString();
            } else {
                $turn->attempt_count++;
                $diagnostics['attempts'][$turn->attempt_count] = ['claimed_at' => now()->toAtomString()];
            }
            $turn->update([
                'state' => $finalization ? AiTurnState::ResolvingFailure : AiTurnState::Processing,
                'claim_token' => $token, 'claimed_until' => now()->addSeconds($leaseSeconds),
                'next_attempt_at' => null, 'diagnostics' => $diagnostics,
            ]);

            return new AiTurnClaim($turn->id, $token, $turn->inboundMessage);
        });
    }

    /**
     * Expiry permits takeover, but does not by itself revoke an unchanged owner.
     * Only one token can accept a response, even if provider requests overlap.
     */
    public function complete(Business $business, AiTurnClaim $claim, string $content): ConversationMessage|AiTurnOutcome
    {
        return $this->withClaimOwnership($business, $claim, function (AiTurn $turn, Conversation $conversation) use ($business, $content): ConversationMessage {
            $fallback = $turn->state === AiTurnState::ResolvingFailure;
            $proposal = AppointmentProposal::query()->where('conversation_id', $conversation->id)
                ->where('originating_turn_id', $turn->id)->lockForUpdate()->first();
            $message = $this->conversations->assistant($business, $conversation->id, $content);
            if (! $message instanceof ConversationMessage) {
                throw new LogicException('The claimed Conversation disappeared during completion.');
            }
            $turn->update([
                'state' => AiTurnState::Completed, 'assistant_message_id' => $message->id,
                'completed_at' => now(), 'claim_token' => null, 'claimed_until' => null,
                'next_attempt_at' => null, 'fallback_used' => $fallback,
            ]);
            if ($fallback) {
                $this->abandonProposals($conversation);
            } elseif ($proposal?->state === AppointmentProposalState::Prepared) {
                $proposal->update([
                    'state' => AppointmentProposalState::Pending,
                    'confirmation_request_message_id' => $message->id,
                ]);
            }

            return $message;
        }, true);
    }

    public function fail(
        Business $business,
        AiTurnClaim $claim,
        AiTurnFailure $classification = AiTurnFailure::Recoverable,
        AiTurnFailureReason $reason = AiTurnFailureReason::ProcessingFailed,
        ?Throwable $exception = null,
    ): AiTurn|AiTurnOutcome {
        return $this->withClaimOwnership($business, $claim, function (AiTurn $turn) use ($classification, $reason, $exception): AiTurn {
            if ($turn->state === AiTurnState::ResolvingFailure) {
                $this->recordFinalizationFailure($turn, $classification, $reason, $exception);
                $turn->fill([
                    'state' => AiTurnState::FailureResolutionPending,
                    'next_attempt_at' => now()->addSeconds(max(1, (int) config('conversation.processing.finalization_retry_seconds'))),
                ]);
            } else {
                $this->recordFailure($turn, $classification, $reason, $exception);
                $exhausted = $classification === AiTurnFailure::NonRecoverable
                    || $turn->attempt_count >= max(1, (int) config('conversation.processing.max_attempts'));
                $turn->fill([
                    'state' => $exhausted ? AiTurnState::FailureResolutionPending : AiTurnState::Failed,
                    'next_attempt_at' => $exhausted ? null : now()->addSeconds($this->retryDelay($turn->attempt_count)),
                ]);
            }
            $turn->fill(['claim_token' => null, 'claimed_until' => null])->save();

            return $turn;
        }, true);
    }

    /** Release FIFO only with a durable disposition and no reusable pending authorization. */
    public function terminate(Business $business, AiTurnClaim $claim, AiTurnFailureReason $reason = AiTurnFailureReason::UnresolvedFailure): AiTurn|AiTurnOutcome
    {
        return $this->withClaimOwnership($business, $claim, function (AiTurn $turn, Conversation $conversation) use ($reason): AiTurn|AiTurnOutcome {
            if ($turn->state !== AiTurnState::ResolvingFailure) {
                return AiTurnOutcome::NotEligible;
            }
            $this->abandonProposals($conversation);
            $turn->update([
                'state' => AiTurnState::ClosedWithoutResponse, 'terminal_at' => now(), 'terminal_reason' => $reason,
                'claim_token' => null, 'claimed_until' => null, 'next_attempt_at' => null,
            ]);

            return $turn;
        }, true);
    }

    /** Run or resume one turn using a supplied generator, never inside a caller's transaction. */
    public function processNext(Business $business, string $channel, string $senderIdentity, Closure $generate, int $leaseSeconds = 300): ConversationMessage|AiTurnOutcome
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('AI generation must run outside database transactions.');
        }
        $claim = $this->claimNext($business, $channel, $senderIdentity, $leaseSeconds);
        if ($claim instanceof AiTurnOutcome) {
            return $claim;
        }

        $reason = AiTurnFailureReason::GenerationFailed;
        try {
            $content = $generate($claim);
            $reason = AiTurnFailureReason::ResponsePersistenceFailed;

            return $this->complete($business, $claim, $content);
        } catch (Throwable $exception) {
            $nonRecoverable = $exception instanceof InsufficientCreditsException
                || ($exception instanceof RequestException && in_array($exception->response->status(), [401, 403], true));
            $this->fail($business, $claim, $nonRecoverable ? AiTurnFailure::NonRecoverable : AiTurnFailure::Recoverable,
                reason: $reason, exception: $exception);

            throw $exception;
        }
    }

    /** Invalid configuration must not prevent recording the original processing failure. */
    private function retryDelay(int $attempt): int
    {
        $delays = config('conversation.processing.retry_delays_seconds');
        if (! is_array($delays) || $delays === [] || ! array_is_list($delays)
            || array_filter($delays, fn (mixed $delay): bool => ! is_int($delay) || $delay < 1) !== []) {
            $delays = [30, 120];
        }

        return $delays[min(max(0, $attempt - 1), count($delays) - 1)];
    }

    /** @return Collection<int, AiTurn>|AiTurnOutcome Completed pairs, not raw message insertion order. */
    public function completedBefore(Business $business, int $inboundMessageId): Collection|AiTurnOutcome
    {
        $inbound = ConversationMessage::query()->whereKey($inboundMessageId)->where('role', 'user')
            ->whereHas('conversation', fn (Builder $query) => $query->where('business_id', $business->id))->first();
        if (! $inbound) {
            return AiTurnOutcome::NotFound;
        }

        return AiTurn::query()->where('state', AiTurnState::Completed)
            ->where('inbound_message_id', '<', $inbound->id)
            ->whereHas('inboundMessage', fn (Builder $query) => $query->where('conversation_id', $inbound->conversation_id))
            ->with(['inboundMessage', 'assistantMessage'])->orderBy('inbound_message_id')->get();
    }

    /**
     * Execute a bounded database operation under current claim ownership.
     * The callback receives persisted context; provider calls must remain outside it.
     * Lease expiry permits takeover, but only a new token or Conversation revokes ownership.
     *
     * @template TResult
     *
     * @param  Closure(AiTurn, Conversation): TResult  $operation
     * @return TResult|AiTurnOutcome
     */
    public function withOwnership(Business $business, AiTurnClaim $claim, Closure $operation): mixed
    {
        return $this->withClaimOwnership($business, $claim, $operation, false);
    }

    /** @see withOwnership */
    private function withClaimOwnership(Business $business, AiTurnClaim $claim, Closure $operation, bool $allowFinalization): mixed
    {
        return DB::transaction(function () use ($business, $claim, $operation, $allowFinalization): mixed {
            $turn = AiTurn::query()->whereKey($claim->turnId)
                ->whereHas('inboundMessage.conversation', fn (Builder $query) => $query->where('business_id', $business->id))->first();
            if (! $turn) {
                return AiTurnOutcome::NotFound;
            }
            $conversation = $turn->inboundMessage->conversation;
            $this->lock->acquire($business->id, $conversation->channel, $conversation->sender_identity);
            $turn = AiTurn::query()->lockForUpdate()->findOrFail($turn->id);
            if (! in_array($turn->state, $allowFinalization ? [AiTurnState::Processing, AiTurnState::ResolvingFailure] : [AiTurnState::Processing], true) || $turn->claim_token !== $claim->token
                || $turn->inbound_message_id !== $claim->inboundMessage->id) {
                return AiTurnOutcome::StaleAttempt;
            }
            $current = $this->participant($business, $conversation->channel, $conversation->sender_identity)
                ->orderByDesc('last_activity_at')->orderByDesc('id')->first();
            if ($current->id !== $conversation->id) {
                $turn->update(['state' => AiTurnState::Superseded, 'claim_token' => null, 'claimed_until' => null, 'next_attempt_at' => null]);

                return AiTurnOutcome::Superseded;
            }

            return $operation($turn, $current);
        });
    }

    private function recordFailure(AiTurn $turn, AiTurnFailure $classification, AiTurnFailureReason $reason, ?Throwable $exception = null): void
    {
        $diagnostics = $turn->diagnostics;
        $diagnostics['attempts'][$turn->attempt_count]['failure'] = [
            'at' => now()->toAtomString(), 'classification' => $classification->value, 'reason' => $reason->value,
            'exception_class' => $exception === null ? null : $exception::class,
        ];
        $turn->fill(['failure_classification' => $classification, 'failure_reason' => $reason, 'diagnostics' => $diagnostics]);
    }

    /** Keep finalization failures separate, retaining the original generation incident. */
    private function recordFinalizationFailure(AiTurn $turn, AiTurnFailure $classification, AiTurnFailureReason $reason, ?Throwable $exception = null): void
    {
        $diagnostics = $turn->diagnostics;
        $diagnostics['finalization']['failure_count'] = ($diagnostics['finalization']['failure_count'] ?? 0) + 1;
        $diagnostics['finalization']['last_failure'] = [
            'at' => now()->toAtomString(), 'classification' => $classification->value, 'reason' => $reason->value,
            'exception_class' => $exception === null ? null : $exception::class,
        ];
        $turn->diagnostics = $diagnostics;
    }

    private function abandonProposals(Conversation $conversation): void
    {
        $proposals = AppointmentProposal::query()->where('conversation_id', $conversation->id)
            ->whereIn('state', [AppointmentProposalState::Prepared, AppointmentProposalState::Pending])->lockForUpdate()->get();
        foreach ($proposals as $proposal) {
            $proposal->update(['state' => AppointmentProposalState::Rejected]);
        }
    }

    private function participant(Business $business, string $channel, string $senderIdentity): Builder
    {
        return Conversation::query()->where('business_id', $business->id)
            ->where('channel', $channel)->where('sender_identity', $senderIdentity);
    }
}
