<?php

namespace App\Application\Conversation;

enum AiTurnFailure: string
{
    case Recoverable = 'recoverable';
    case NonRecoverable = 'non_recoverable';
    case PersistenceUnavailable = 'persistence_unavailable';
}
