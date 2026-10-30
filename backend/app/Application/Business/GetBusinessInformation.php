<?php

namespace App\Application\Business;

use App\Models\Business;
use App\Models\BusinessOperatingHour;

final class GetBusinessInformation
{
    public function handle(Business $business): BusinessInformation
    {
        $operatingHours = $business->operatingHours()
            ->orderByRaw("CASE weekday
                WHEN 'monday' THEN 1
                WHEN 'tuesday' THEN 2
                WHEN 'wednesday' THEN 3
                WHEN 'thursday' THEN 4
                WHEN 'friday' THEN 5
                WHEN 'saturday' THEN 6
                WHEN 'sunday' THEN 7
                ELSE 8
            END")
            ->orderBy('weekday')
            ->orderBy('opens_at')
            ->orderBy('closes_at')
            ->get()
            ->map(fn (BusinessOperatingHour $hour): BusinessOperatingInterval => new BusinessOperatingInterval(
                weekday: $hour->weekday,
                opensAt: $hour->opens_at,
                closesAt: $hour->closes_at,
            ))
            ->all();

        return new BusinessInformation(
            name: $business->name,
            description: $business->description,
            timezone: $business->timezone,
            addressLine: $business->address_line,
            postalCode: $business->postal_code,
            city: $business->city,
            countryCode: $business->country_code,
            phone: $business->phone,
            email: $business->email,
            operatingHours: $operatingHours,
            paymentMethods: $business->payment_methods,
            appointmentsRecommended: $business->appointments_recommended,
            arrivalLeadMinutes: $business->arrival_lead_minutes,
            cancellationNoticeMinutes: $business->cancellation_notice_minutes,
            reschedulingNoticeMinutes: $business->rescheduling_notice_minutes,
        );
    }
}
