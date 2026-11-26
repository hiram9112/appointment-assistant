<?php

namespace App\Application\Appointment;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

final class GetCustomerAppointments
{
    /**
     * Business scope and Customer identity must come from a trusted application caller.
     *
     * @return list<CustomerAppointment>|GetCustomerAppointmentsOutcome
     */
    public function handle(Business $business, ?int $customerId): array|GetCustomerAppointmentsOutcome
    {
        if ($customerId === null || ! Customer::query()->whereHas('channelIdentities')->whereKey($customerId)->exists()) {
            return GetCustomerAppointmentsOutcome::IdentificationFailed;
        }

        return Appointment::query()
            ->where('customer_id', $customerId)
            ->whereHas('service', fn (Builder $query) => $query->where('business_id', $business->id))
            ->with(['service.business', 'professional'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (Appointment $appointment): CustomerAppointment {
                $timezone = new DateTimeZone($appointment->service->business->timezone);
                $professional = $appointment->professional;

                return new CustomerAppointment(
                    id: $appointment->id,
                    service_name: $appointment->service->name,
                    professional_name: $professional->display_name ?? $professional->first_name.' '.$professional->last_name,
                    starts_at: $appointment->starts_at->setTimezone($timezone),
                    ends_at: $appointment->ends_at->setTimezone($timezone),
                    status: $appointment->status,
                );
            })->all();
    }
}
