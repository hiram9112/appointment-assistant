<?php

namespace App\Application\Availability;

use App\Models\Professional;

interface ReschedulingAppointmentOccupancy extends AppointmentOccupancy
{
    /** @return list<TimeInterval> */
    public function forProfessionalExcludingAppointment(Professional $professional, TimeInterval $period, int $appointmentId): array;
}
