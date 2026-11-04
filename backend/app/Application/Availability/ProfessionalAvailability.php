<?php

namespace App\Application\Availability;

final readonly class ProfessionalAvailability
{
    /** @param list<AppointmentStartRange> $ranges */
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public string $displayName,
        public bool $eligible,
        public array $ranges,
    ) {}
}
