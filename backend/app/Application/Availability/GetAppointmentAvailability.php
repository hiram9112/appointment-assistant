<?php

namespace App\Application\Availability;

use App\Models\Business;
use App\Models\Professional;
use App\Models\Service;
use DateTimeImmutable;
use InvalidArgumentException;

final class GetAppointmentAvailability
{
    public function __construct(private CalculateAvailability $calculator) {}

    /** Returns null when the service or requested professional is not valid for the business. */
    public function handle(Business $business, int $serviceId, DateTimeImmutable $from, DateTimeImmutable $to, ?int $professionalId = null): ?AppointmentAvailability
    {
        if ($from > $to) {
            throw new InvalidArgumentException('The requested start range is reversed.');
        }

        $service = $business->services()->where('active', true)->find($serviceId);
        if ($service === null) {
            return null;
        }

        $requested = $professionalId === null ? null : $business->professionals()->find($professionalId);
        if ($professionalId !== null && $requested === null) {
            return null;
        }

        $professionals = $service->professionals()
            ->where('professionals.business_id', $business->id)
            ->orderBy('first_name')->orderBy('last_name')->orderBy('professionals.id')
            ->get();

        $requestedAvailability = null;
        if ($requested !== null) {
            $eligible = $professionals->contains('id', $requested->id);
            $requestedAvailability = $this->forProfessional($business, $service, $requested, $from, $to, $eligible);
        }

        $available = [];
        if ($requestedAvailability === null || $requestedAvailability->ranges === []) {
            foreach ($professionals as $professional) {
                if ($professional->id === $professionalId) {
                    continue;
                }
                $result = $this->forProfessional($business, $service, $professional, $from, $to, true);
                if ($result->ranges !== []) {
                    $available[] = $result;
                }
            }
        }

        return new AppointmentAvailability($requestedAvailability, $available);
    }

    private function forProfessional(Business $business, Service $service, Professional $professional, DateTimeImmutable $from, DateTimeImmutable $to, bool $eligible): ProfessionalAvailability
    {
        return new ProfessionalAvailability(
            $professional->id,
            $professional->first_name,
            $professional->last_name,
            $professional->display_name ?? $professional->first_name.' '.$professional->last_name,
            $eligible,
            $eligible ? $this->calculator->handle($business, $service, $professional, $from, $to) : [],
        );
    }
}
