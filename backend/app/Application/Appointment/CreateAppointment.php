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

final class CreateAppointment
{
    public function __construct(private CalculateAvailability $availability, private AppendAppointmentAudit $audit) {}

    /** Actor and source must come from a trusted application caller. */
    public function handle(Business $business, int $customerId, int $serviceId, ?int $professionalId, DateTimeImmutable $startsAt, string $actorType, string $actorId, string $source): Appointment|CreateAppointmentOutcome
    {
        $insertingAppointment = false;

        try {
            return DB::transaction(function () use ($business, $customerId, $serviceId, $professionalId, $startsAt, $actorType, $actorId, $source, &$insertingAppointment): Appointment|CreateAppointmentOutcome {
                $insertingAppointment = false;
                $business = $business->exists ? Business::query()->find($business->getKey()) : null;
                $customer = Customer::query()->whereHas('channelIdentities')->find($customerId);
                if ($business === null || $customer === null || $professionalId === null || trim($actorType) === '' || trim($actorId) === '' || trim($source) === '') {
                    return CreateAppointmentOutcome::InvalidRequest;
                }

                $service = $business->services()->where('active', true)->find($serviceId);
                $professional = $business->professionals()->find($professionalId);
                if ($service === null || $professional === null || ! $professional->services()->whereKey($service->id)->exists()) {
                    return CreateAppointmentOutcome::InvalidRequest;
                }

                $start = $startsAt->setTimezone(new DateTimeZone('UTC'));
                $start = $start->setTime((int) $start->format('H'), (int) $start->format('i'), (int) $start->format('s'));
                if ($this->availability->handle($business, $service, $professional, $start, $start) === []) {
                    return CreateAppointmentOutcome::SlotUnavailable;
                }

                $insertingAppointment = true;
                $appointment = Appointment::query()->create([
                    'customer_id' => $customer->id,
                    'service_id' => $service->id,
                    'professional_id' => $professional->id,
                    'starts_at' => $start,
                    'ends_at' => $start->modify("+{$service->duration_minutes} minutes"),
                    'buffer_before_minutes' => $service->buffer_before_minutes,
                    'buffer_after_minutes' => $service->buffer_after_minutes,
                    'status' => 'confirmed',
                ]);
                $insertingAppointment = false;

                $this->audit->created($appointment, $actorType, $actorId, $source);

                return $appointment;
            }, attempts: 3);
        } catch (QueryException $exception) {
            if ($insertingAppointment && ($exception->errorInfo[0] ?? null) === '23P01'
                && str_contains($exception->errorInfo[2] ?? '', '"appointments_confirmed_occupancy_exclusion"')) {
                return CreateAppointmentOutcome::SlotUnavailable;
            }

            throw $exception;
        }
    }
}
