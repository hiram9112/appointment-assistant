<?php

namespace App\Application\Availability;

use App\Models\Professional;

interface AppointmentOccupancy
{
    /**
     * Return active Appointment occupancy overlapping the supplied period, including
     * any existing Appointment buffers. Intervals use half-open boundaries.
     *
     * @return list<TimeInterval>
     */
    public function forProfessional(Professional $professional, TimeInterval $period): array;
}
