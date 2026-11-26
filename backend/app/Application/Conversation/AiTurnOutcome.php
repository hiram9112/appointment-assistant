<?php

namespace App\Application\Conversation;

enum AiTurnOutcome
{
    case Idle;
    case Busy;
    case NotDue;
    case NotEligible;
    case FailureResolutionPending;
    case StaleAttempt;
    case Superseded;
    case NotFound;
}
