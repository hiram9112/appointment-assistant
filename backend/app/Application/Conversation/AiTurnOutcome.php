<?php

namespace App\Application\Conversation;

enum AiTurnOutcome
{
    case Idle;
    case Busy;
    case StaleAttempt;
    case Superseded;
    case NotFound;
}
