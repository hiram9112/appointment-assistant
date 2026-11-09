<?php

namespace App\Application\Appointment;

use App\Models\Appointment;
use App\Models\Business;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class RecordAppointmentAttendance
{
    public function __construct(private AppendAppointmentAudit $audit) {}

    /** Actor and source identify a trusted application caller; they do not authorize it. */
    public function handle(Business $business, ?int $appointmentId, string $outcome, string $actorType, string $actorId, string $source): Appointment|RecordAppointmentAttendanceOutcome
    {
        return DB::transaction(function () use ($business, $appointmentId, $outcome, $actorType, $actorId, $source): Appointment|RecordAppointmentAttendanceOutcome {
            $business = $business->exists ? Business::query()->find($business->getKey()) : null;
            if ($business === null || ! in_array($outcome, ['completed', 'no_show'], true) || trim($actorType) === '' || trim($actorId) === '' || trim($source) === '') {
                return RecordAppointmentAttendanceOutcome::InvalidRequest;
            }

            $appointment = Appointment::query()
                ->whereHas('service', fn ($query) => $query->where('business_id', $business->id))
                ->whereKey($appointmentId)->lockForUpdate()->first();
            if ($appointment === null) {
                return RecordAppointmentAttendanceOutcome::InvalidAppointment;
            }

            $operationTime = DateTimeImmutable::createFromInterface(now());
            if ($appointment->status !== 'confirmed' || $appointment->starts_at > $operationTime) {
                return RecordAppointmentAttendanceOutcome::NotEligible;
            }

            $before = $this->snapshot($appointment);
            $appointment->status = $outcome;
            $appointment->save();
            $appointment->refresh();
            $this->audit->handle($appointment, $outcome, $actorType, $actorId, $source, $before, $this->snapshot($appointment), false, $operationTime);

            return $appointment;
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function snapshot(Appointment $appointment): array
    {
        return [
            'id' => $appointment->id,
            'customer_id' => $appointment->customer_id,
            'service_id' => $appointment->service_id,
            'professional_id' => $appointment->professional_id,
            'starts_at' => $appointment->starts_at->utc()->toJSON(),
            'ends_at' => $appointment->ends_at->utc()->toJSON(),
            'buffer_before_minutes' => $appointment->buffer_before_minutes,
            'buffer_after_minutes' => $appointment->buffer_after_minutes,
            'status' => $appointment->status,
            'created_at' => $appointment->created_at->utc()->toJSON(),
            'updated_at' => $appointment->updated_at->utc()->toJSON(),
        ];
    }
}
