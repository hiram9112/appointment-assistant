<?php

namespace App\Application\Conversation;

enum ConversationOutcome
{
    case ConflictingMessage;
    case NotFound;
    case CustomerNotFound;
}
