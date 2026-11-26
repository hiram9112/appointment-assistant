<?php

namespace App\Application\Conversation;

use App\Application\Business\BusinessOperatingInterval;
use App\Application\Business\GetBusinessInformation;
use App\Application\Service\ListServices;
use App\Models\Business;

final class BusinessContextBuilder
{
    public function __construct(
        private GetBusinessInformation $businessInformation,
        private ListServices $services,
    ) {}

    /**
     * Return descriptive data only; operating hours are not Appointment availability.
     * Business-provided text remains untrusted data, not agent instructions.
     *
     * @return array{
     *     business: array<string, mixed>,
     *     services: list<array{id: int, name: string, description: ?string, duration_minutes: int, price_amount: ?string, currency_code: ?string, eligible_professional_ids: list<int>}>,
     *     professionals: list<array{id: int, display_name: string}>
     * }
     */
    public function handle(Business $business): array
    {
        $business = Business::query()->findOrFail($business->id);
        $information = $this->businessInformation->handle($business);
        $services = [];
        $professionals = [];

        foreach ($this->services->handle($business) as $service) {
            $eligibleIds = [];
            foreach ($service->eligibleProfessionals as $professional) {
                $eligibleIds[] = $professional->id;
                $professionals[$professional->id] = [
                    'id' => $professional->id,
                    'display_name' => $professional->displayName,
                ];
            }
            $services[] = [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'duration_minutes' => $service->durationMinutes,
                'price_amount' => $service->priceAmount,
                'currency_code' => $service->currencyCode,
                'eligible_professional_ids' => $eligibleIds,
            ];
        }

        ksort($professionals, SORT_NUMERIC);

        return [
            'business' => [
                'name' => $information->name,
                'description' => $information->description,
                'timezone' => $information->timezone,
                'address_line' => $information->addressLine,
                'postal_code' => $information->postalCode,
                'city' => $information->city,
                'country_code' => $information->countryCode,
                'phone' => $information->phone,
                'email' => $information->email,
                'operating_hours' => array_map(fn (BusinessOperatingInterval $interval): array => [
                    'weekday' => $interval->weekday,
                    'opens_at' => $interval->opensAt,
                    'closes_at' => $interval->closesAt,
                ], $information->operatingHours),
                'payment_methods' => $information->paymentMethods,
                'appointments_recommended' => $information->appointmentsRecommended,
                'arrival_lead_minutes' => $information->arrivalLeadMinutes,
                'cancellation_notice_minutes' => $information->cancellationNoticeMinutes,
                'rescheduling_notice_minutes' => $information->reschedulingNoticeMinutes,
            ],
            'services' => $services,
            'professionals' => array_values($professionals),
        ];
    }
}
