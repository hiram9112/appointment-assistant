<?php

namespace App\Application\Conversation;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

final class ConversationAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are a conversational assistant.';
    }
}
