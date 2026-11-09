<?php

namespace App\Application\Appointment;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final class CancelAppointment
{
    public function __construct(private AppendAppointmentAudit $audit) {}

    /** Customer identity, actor and source must come from a trusted application caller. */
    public function handle(Business $business, ?int $customerId, ?int $appointmentId, string $actorType, string $actorId, string $source): Appointment|CancelAppointmentOutcome
    {
        return DB::transaction(function () use ($business, $customerId, $appointmentId, $actorType, $actorId, $source): Appointment|CancelAppointmentOutcome {
            if ($customerId === null || ! Customer::query()->whereHas('channelIdentities')->whereKey($customerId)->exists()) {
                return CancelAppointmentOutcome::IdentificationFailed;
            }
            $business = $business->exists ? Business::query()->find($business->getKey()) : null;
            if ($business === null || trim($actorType) === '' || trim($actorId) === '' || trim($source) === '') {
                return CancelAppointmentOutcome::InvalidRequest;
            }
            $appointment = Appointment::query()->where('customer_id', $customerId)
                ->whereHas('service', fn ($query) => $query->where('business_id', $business->id))
                ->whereKey($appointmentId)->lockForUpdate()->first();
            if ($appointment === null) {
                return CancelAppointmentOutcome::InvalidAppointment;
            }

            $operationTime = DateTimeImmutable::createFromInterface(now());
            if ($appointment->status !== 'confirmed' || $appointment->starts_at <= $operationTime) {
                return CancelAppointmentOutcome::NotEligible;
            }
            $lateNotice = $business->cancellation_notice_minutes !== null
                && $operationTime > $appointment->starts_at->subMinutes($business->cancellation_notice_minutes);
            $before = $this->snapshot($appointment);
            $appointment->status = 'cancelled';
            $appointment->save();
            $appointment->refresh();
            $this->audit->handle($appointment, 'cancelled', $actorType, $actorId, $source, $before, $this->snapshot($appointment), $lateNotice, $operationTime);

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
