<?php

namespace App\Application\Conversation;

use App\Models\AiTurn;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
        if ($leaseSeconds < 1) {
            throw new InvalidArgumentException('The processing lease must be positive.');
        }

        return DB::transaction(function () use ($business, $channel, $senderIdentity, $leaseSeconds): AiTurnClaim|AiTurnOutcome {
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
                ->whereIn('state', [AiTurnState::Pending, AiTurnState::Processing, AiTurnState::Failed]);

            (clone $turns)->whereHas('inboundMessage', fn (Builder $query) => $query->where('conversation_id', '!=', $current->id))
                ->update(['state' => AiTurnState::Superseded, 'claim_token' => null, 'claimed_until' => null]);

            $turn = $turns->orderBy('inbound_message_id')->lockForUpdate()->first();
            if (! $turn) {
                return AiTurnOutcome::Idle;
            }
            if ($turn->state === AiTurnState::Processing && $turn->claimed_until->isFuture()) {
                return AiTurnOutcome::Busy;
            }

            $token = (string) Str::uuid();
            $turn->update(['state' => AiTurnState::Processing, 'claim_token' => $token, 'claimed_until' => now()->addSeconds($leaseSeconds)]);

            return new AiTurnClaim($turn->id, $token, $turn->inboundMessage);
        });
    }

    /**
     * Expiry permits takeover, but does not by itself revoke an unchanged owner.
     * Only one token can accept a response, even if provider requests overlap.
     */
    public function complete(Business $business, AiTurnClaim $claim, string $content): ConversationMessage|AiTurnOutcome
    {
        return $this->withOwnership($business, $claim, function (AiTurn $turn, Conversation $conversation) use ($business, $content): ConversationMessage {
            $message = $this->conversations->assistant($business, $conversation->id, $content);
            if (! $message instanceof ConversationMessage) {
                throw new LogicException('The claimed Conversation disappeared during completion.');
            }
            $turn->update([
                'state' => AiTurnState::Completed, 'assistant_message_id' => $message->id,
                'completed_at' => now(), 'claim_token' => null, 'claimed_until' => null,
            ]);

            return $message;
        });
    }

    public function fail(Business $business, AiTurnClaim $claim): AiTurn|AiTurnOutcome
    {
        return $this->withOwnership($business, $claim, function (AiTurn $turn): AiTurn {
            $turn->update(['state' => AiTurnState::Failed, 'claim_token' => null, 'claimed_until' => null]);

            return $turn;
        });
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

        try {
            return $this->complete($business, $claim, $generate($claim));
        } catch (Throwable $exception) {
            $this->fail($business, $claim);

            throw $exception;
        }
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
        return DB::transaction(function () use ($business, $claim, $operation): mixed {
            $turn = AiTurn::query()->whereKey($claim->turnId)
                ->whereHas('inboundMessage.conversation', fn (Builder $query) => $query->where('business_id', $business->id))->first();
            if (! $turn) {
                return AiTurnOutcome::NotFound;
            }
            $conversation = $turn->inboundMessage->conversation;
            $this->lock->acquire($business->id, $conversation->channel, $conversation->sender_identity);
            $turn = AiTurn::query()->lockForUpdate()->findOrFail($turn->id);
            if ($turn->state !== AiTurnState::Processing || $turn->claim_token !== $claim->token
                || $turn->inbound_message_id !== $claim->inboundMessage->id) {
                return AiTurnOutcome::StaleAttempt;
            }
            $current = $this->participant($business, $conversation->channel, $conversation->sender_identity)
                ->orderByDesc('last_activity_at')->orderByDesc('id')->first();
            if ($current->id !== $conversation->id) {
                $turn->update(['state' => AiTurnState::Superseded, 'claim_token' => null, 'claimed_until' => null]);

                return AiTurnOutcome::Superseded;
            }

            return $operation($turn, $current);
        });
    }

    private function participant(Business $business, string $channel, string $senderIdentity): Builder
    {
        return Conversation::query()->where('business_id', $business->id)
            ->where('channel', $channel)->where('sender_identity', $senderIdentity);
    }
}
