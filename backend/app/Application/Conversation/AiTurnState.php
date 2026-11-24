<?php

namespace App\Application\Conversation;

enum AiTurnState: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case FailureResolutionPending = 'failure_resolution_pending';
    case ResolvingFailure = 'resolving_failure';
    case ClosedWithoutResponse = 'closed_without_response';
    case Superseded = 'superseded';
}
