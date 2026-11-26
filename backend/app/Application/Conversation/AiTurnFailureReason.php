<?php

namespace App\Application\Conversation;

enum AiTurnFailureReason: string
{
    case ProcessingFailed = 'processing_failed';
    case GenerationFailed = 'generation_failed';
    case ResponsePersistenceFailed = 'response_persistence_failed';
    case ClaimExpired = 'claim_expired';
    case FinalizationFailed = 'finalization_failed';
    case AuthorizationFailed = 'authorization_failed';
    case UnresolvedFailure = 'unresolved_failure';
}
