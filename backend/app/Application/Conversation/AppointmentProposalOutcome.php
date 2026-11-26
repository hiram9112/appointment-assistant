<?php

namespace App\Application\Conversation;

enum AppointmentProposalOutcome
{
    case NotFound;
    case InvalidScope;
    case ParametersConflict;
    case InvalidTransition;
    case ConversationStillCurrent;
}
