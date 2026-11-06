<?php

namespace App\Application\Appointment;

enum CreateAppointmentOutcome
{
    case InvalidRequest;
    case SlotUnavailable;
}
