<?php

namespace App\Application\Conversation;

enum AppointmentProposalState: string
{
    case Prepared = 'prepared';
    case Pending = 'pending';
    case Completed = 'completed';
    case Rejected = 'rejected';
}
