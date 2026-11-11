<?php

namespace App\Application\Conversation;

use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ConversationHandler
{
    /** Identity values must come from a trusted channel integration and already be normalized. */
    public function inbound(Business $business, mixed $channel, mixed $senderIdentity, mixed $messageId, mixed $content): ConversationMessage|ConversationOutcome
    {
        Validator::make(compact('channel', 'senderIdentity', 'messageId', 'content'), [
            'channel' => ['required', 'string', 'max:255'],
            'senderIdentity' => ['required', 'string', 'max:255'],
            'messageId' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ])->validate();

        $participant = json_encode([(string) $business->getKey(), $channel, $senderIdentity], JSON_THROW_ON_ERROR);
        $key = hash('sha256', json_encode([$participant, $messageId], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($business, $channel, $senderIdentity, $content, $participant, $key): ConversationMessage|ConversationOutcome {
            $existing = ConversationMessage::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->content === $content ? $existing : ConversationOutcome::ConflictingMessage;
            }

            DB::select('select pg_advisory_xact_lock(?)', [unpack('J', substr(hash('sha256', $participant, true), 0, 8))[1]]);

            $existing = ConversationMessage::query()->where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing->content === $content ? $existing : ConversationOutcome::ConflictingMessage;
            }

            $conversation = Conversation::query()->where('business_id', $business->getKey())
                ->where('channel', $channel)->where('sender_identity', $senderIdentity)
                ->orderByDesc('last_activity_at')->orderByDesc('id')->lockForUpdate()->first();
            $now = now();
            if (! $conversation || $conversation->last_activity_at->lte($now->copy()->subSeconds((int) config('conversation.inactivity_seconds')))) {
                $conversation = Conversation::query()->create([
                    'business_id' => $business->getKey(), 'channel' => $channel,
                    'sender_identity' => $senderIdentity, 'last_activity_at' => $now,
                ]);
            }

            $message = $conversation->messages()->create(['role' => 'user', 'content' => $content, 'idempotency_key' => $key]);
            $conversation->update(['last_activity_at' => $now]);

            return $message;
        });
    }

    public function assistant(Business $business, int $conversationId, mixed $content): ConversationMessage|ConversationOutcome
    {
        Validator::make(['content' => $content], ['content' => ['required', 'string']])->validate();

        return DB::transaction(function () use ($business, $conversationId, $content): ConversationMessage|ConversationOutcome {
            $conversation = $this->scoped($business, $conversationId)->lockForUpdate()->first();
            if (! $conversation) {
                return ConversationOutcome::NotFound;
            }

            $message = $conversation->messages()->create(['role' => 'assistant', 'content' => $content]);
            $conversation->update(['last_activity_at' => now()]);

            return $message;
        });
    }

    /** @return Collection<int, ConversationMessage>|ConversationOutcome */
    public function history(Business $business, int $conversationId): Collection|ConversationOutcome
    {
        $conversation = $this->scoped($business, $conversationId)->first();

        return $conversation ? $conversation->messages()->get() : ConversationOutcome::NotFound;
    }

    /** The customer must have been resolved by a trusted application caller. */
    public function associateCustomer(Business $business, int $conversationId, Customer $customer): Conversation|ConversationOutcome
    {
        $conversation = $this->scoped($business, $conversationId)->first();
        if (! $conversation) {
            return ConversationOutcome::NotFound;
        }
        if (! $customer->exists || ! Customer::query()->whereKey($customer->getKey())->exists()) {
            return ConversationOutcome::CustomerNotFound;
        }
        $conversation->customer()->associate($customer);
        $conversation->save();

        return $conversation;
    }

    private function scoped(Business $business, int $conversationId): Builder
    {
        return Conversation::query()->where('business_id', $business->getKey())->whereKey($conversationId);
    }
}
