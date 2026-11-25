<?php

namespace App\Jobs;

use App\Application\Conversation\AiTurnState;
use App\Application\Conversation\ConversationTurnProcessor;
use App\Models\AiTurn;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

final class ProcessConversationTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $conversationId) {}

    /** Dispatch only committed work. A failed push leaves PostgreSQL work intact. */
    public static function enqueue(int $conversationId): void
    {
        try {
            Queue::push(new self($conversationId));
        } catch (Throwable $exception) {
            Log::warning('Conversation processing job could not be queued.', [
                'conversation_id' => $conversationId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function handle(ConversationTurnProcessor $processor): void
    {
        $conversation = Conversation::query()->with('business')->find($this->conversationId);
        if ($conversation?->business === null) {
            return;
        }

        $result = $processor->processNext(
            $conversation->business,
            $conversation->channel,
            $conversation->sender_identity,
            expectedConversationId: $conversation->id,
        );

        if ($result instanceof ConversationMessage && AiTurn::query()
            ->where('state', AiTurnState::Pending)
            ->whereRelation('inboundMessage', 'conversation_id', $conversation->id)
            ->exists()) {
            self::enqueue($conversation->id);
        }
    }
}
