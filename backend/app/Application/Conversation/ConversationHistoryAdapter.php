<?php

namespace App\Application\Conversation;

use App\Models\AiTurn;
use App\Models\Business;
use App\Models\ConversationMessage;
use Laravel\Ai\Messages\Message;

final class ConversationHistoryAdapter
{
    public function __construct(private ConversationHandler $conversations) {}

    /**
     * Map coordinator-selected completed pairs, not raw message insertion order.
     *
     * @param  iterable<AiTurn>  $turns
     * @return list<Message>
     */
    public function completedTurns(iterable $turns): array
    {
        $messages = [];
        foreach ($turns as $turn) {
            $messages[] = new Message($turn->inboundMessage->role, $turn->inboundMessage->content);
            $messages[] = new Message($turn->assistantMessage->role, $turn->assistantMessage->content);
        }

        return $messages;
    }

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
