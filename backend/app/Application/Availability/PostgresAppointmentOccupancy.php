<?php

namespace App\Application\Availability;

use App\Models\Appointment;
use App\Models\Professional;
use DateTimeImmutable;
use DateTimeZone;

final class PostgresAppointmentOccupancy implements ReschedulingAppointmentOccupancy
{
    public function forProfessional(Professional $professional, TimeInterval $period): array
    {
        return $this->queryOccupancy($professional, $period, null);
    }

    public function forProfessionalExcludingAppointment(Professional $professional, TimeInterval $period, int $appointmentId): array
    {
        return $this->queryOccupancy($professional, $period, $appointmentId);
    }

    private function queryOccupancy(Professional $professional, TimeInterval $period, ?int $appointmentId): array
    {
        $utc = new DateTimeZone('UTC');

        return Appointment::query()
            ->where('professional_id', $professional->id)
            ->where('status', 'confirmed')
            ->when($appointmentId !== null, fn ($query) => $query->where('id', '<>', $appointmentId))
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
