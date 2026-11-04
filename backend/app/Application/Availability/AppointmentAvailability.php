<?php

namespace App\Application\Availability;

final readonly class AppointmentAvailability
{
    /**
     * Without a preference, availableProfessionals contains all available choices.
     * With a preference, it contains alternatives only when the requested professional has no availability.
     *
     * @param  list<ProfessionalAvailability>  $availableProfessionals
     */
    public function __construct(
        public ?ProfessionalAvailability $requestedProfessional,
        public array $availableProfessionals,
    ) {}
}
