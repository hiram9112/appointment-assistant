<?php

namespace App\Application\Conversation;

enum AppointmentProposalOperation: string
{
    case Create = 'create';
    case Reschedule = 'reschedule';
    case Cancel = 'cancel';
}
