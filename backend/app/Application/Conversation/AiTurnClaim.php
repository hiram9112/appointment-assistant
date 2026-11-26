<?php

namespace App\Application\Conversation;

use App\Models\ConversationMessage;

final readonly class AiTurnClaim
{
    public function __construct(
        public int $turnId,
        public string $token,
        public ConversationMessage $inboundMessage,
    ) {}
}
