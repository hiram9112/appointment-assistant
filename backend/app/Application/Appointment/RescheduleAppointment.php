<?php

namespace App\Application\Appointment;

use App\Application\Availability\CalculateAvailability;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class RescheduleAppointment
{
    public function __construct(private CalculateAvailability $availability, private AppendAppointmentAudit $audit) {}

    /** Customer identity, actor and source must come from a trusted application caller. */
    public function handle(Business $business, ?int $customerId, ?int $appointmentId, DateTimeImmutable $startsAt, ?int $professionalId, string $actorType, string $actorId, string $source): Appointment|RescheduleAppointmentOutcome
    {
        $updatingAppointment = false;

        try {
            return DB::transaction(function () use ($business, $customerId, $appointmentId, $startsAt, $professionalId, $actorType, $actorId, $source, &$updatingAppointment): Appointment|RescheduleAppointmentOutcome {
                $updatingAppointment = false;
                if ($customerId === null || ! Customer::query()->whereHas('channelIdentities')->whereKey($customerId)->exists()) {
                    return RescheduleAppointmentOutcome::IdentificationFailed;
                }
                $business = $business->exists ? Business::query()->find($business->getKey()) : null;
                if ($business === null || trim($actorType) === '' || trim($actorId) === '' || trim($source) === '') {
                    return RescheduleAppointmentOutcome::InvalidRequest;
                }
                $appointment = Appointment::query()->where('customer_id', $customerId)
                    ->whereHas('service', fn ($query) => $query->where('business_id', $business->id))
                    ->whereKey($appointmentId)->lockForUpdate()->first();
                if ($appointment === null) {
                    return RescheduleAppointmentOutcome::InvalidAppointment;
                }

                $operationTime = DateTimeImmutable::createFromInterface(now());
                if ($appointment->status !== 'confirmed' || $appointment->starts_at <= $operationTime) {
                    return RescheduleAppointmentOutcome::NotEligible;
                }
                $start = $startsAt->setTimezone(new DateTimeZone('UTC'));
                $start = $start->setTime((int) $start->format('H'), (int) $start->format('i'), (int) $start->format('s'));
                if ($start <= $operationTime) {
                    return RescheduleAppointmentOutcome::InvalidStart;
                }

                $service = $business->services()->find($appointment->service_id);
                $professional = $business->professionals()->find($professionalId ?? $appointment->professional_id);
                if ($service === null || ! $service->active) {
                    return RescheduleAppointmentOutcome::NotEligible;
                }
                if ($professional === null || ! $professional->services()->whereKey($service->id)->exists()) {
                    return RescheduleAppointmentOutcome::InvalidProfessional;
                }
                if ($this->availability->forRescheduling($business, $service, $professional, $start, $start, $appointment->id) === []) {
                    return RescheduleAppointmentOutcome::SlotUnavailable;
                }

                $before = $this->snapshot($appointment);
                $appointment->fill([
                    'professional_id' => $professional->id,
                    'starts_at' => $start,
                    'ends_at' => $start->modify("+{$service->duration_minutes} minutes"),
                    'buffer_before_minutes' => $service->buffer_before_minutes,
                    'buffer_after_minutes' => $service->buffer_after_minutes,
                ]);
                if (! $appointment->isDirty()) {
                    return $appointment;
                }
                $lateNotice = $business->rescheduling_notice_minutes !== null
                    && $operationTime > $appointment->getOriginal('starts_at')->subMinutes($business->rescheduling_notice_minutes);
                $updatingAppointment = true;
                $appointment->save();
                $updatingAppointment = false;
                $appointment->refresh();
                $this->audit->handle($appointment, 'rescheduled', $actorType, $actorId, $source, $before, $this->snapshot($appointment), $lateNotice, $operationTime);

                return $appointment;
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ($updatingAppointment && ($exception->errorInfo[0] ?? null) === '23P01'
                && str_contains($exception->errorInfo[2] ?? '', '"appointments_confirmed_occupancy_exclusion"')) {
                return RescheduleAppointmentOutcome::SlotUnavailable;
            }

            throw $exception;
        }
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
