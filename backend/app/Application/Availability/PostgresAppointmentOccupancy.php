<?php

namespace App\Application\Availability;

use App\Models\Appointment;
use App\Models\Professional;
use DateTimeImmutable;
use DateTimeZone;

final class PostgresAppointmentOccupancy implements AppointmentOccupancy
{
    public function forProfessional(Professional $professional, TimeInterval $period): array
    {
        $utc = new DateTimeZone('UTC');

        return Appointment::query()
            ->where('professional_id', $professional->id)
            ->where('status', 'confirmed')
            ->whereRaw("(starts_at AT TIME ZONE 'UTC') - buffer_before_minutes * interval '1 minute' < ?::timestamp", [$period->end->setTimezone($utc)->format('Y-m-d H:i:s.u')])
            ->whereRaw("(ends_at AT TIME ZONE 'UTC') + buffer_after_minutes * interval '1 minute' > ?::timestamp", [$period->start->setTimezone($utc)->format('Y-m-d H:i:s.u')])
            ->get()
            ->map(fn (Appointment $appointment): TimeInterval => new TimeInterval(
                DateTimeImmutable::createFromInterface($appointment->starts_at)->setTimezone($utc)->modify("-{$appointment->buffer_before_minutes} minutes"),
                DateTimeImmutable::createFromInterface($appointment->ends_at)->setTimezone($utc)->modify("+{$appointment->buffer_after_minutes} minutes"),
            ))
            ->all();
    }
}
