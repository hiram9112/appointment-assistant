<?php

namespace App\Application\Conversation;

use App\Models\Business;
use App\Models\ConversationMessage;
use Laravel\Ai\Messages\Message;

final class ConversationHistoryAdapter
{
    public function __construct(private ConversationHandler $conversations) {}

    /** @return list<Message>|ConversationOutcome */
    public function beforeInbound(Business $business, int $conversationId, int $inboundMessageId): array|ConversationOutcome
    {
        $history = $this->conversations->history($business, $conversationId);

        if ($history instanceof ConversationOutcome) {
            return $history;
        }

        $current = $history->search(fn (ConversationMessage $message): bool => $message->getKey() === $inboundMessageId && $message->role === 'user');

        if ($current === false) {
            return ConversationOutcome::NotFound;
        }

        return $history->take($current)
            ->map(fn (ConversationMessage $message): Message => new Message($message->role, $message->content))
            ->values()->all();
    }
}
