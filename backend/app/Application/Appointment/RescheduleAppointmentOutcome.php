<?php

namespace App\Application\Appointment;

enum RescheduleAppointmentOutcome
{
    case IdentificationFailed;
    case InvalidAppointment;
    case NotEligible;
    case InvalidProfessional;
    case InvalidStart;
    case InvalidRequest;
    case SlotUnavailable;
}
