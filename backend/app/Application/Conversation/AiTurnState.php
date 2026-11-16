<?php

namespace App\Application\Conversation;

enum AiTurnState: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Superseded = 'superseded';
}
