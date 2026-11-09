<?php

namespace App\Application\Appointment;

enum RecordAppointmentAttendanceOutcome
{
    case InvalidRequest;
    case InvalidAppointment;
    case NotEligible;
}
