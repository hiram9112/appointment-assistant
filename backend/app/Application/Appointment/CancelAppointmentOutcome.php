<?php

namespace App\Application\Appointment;

enum CancelAppointmentOutcome
{
    case IdentificationFailed;
    case InvalidAppointment;
    case NotEligible;
    case InvalidRequest;
}
