<?php

namespace App\Application\Appointment;

use DateTimeImmutable;

final readonly class CustomerAppointment
{
    public function __construct(
        public int $id,
        public string $service_name,
        public string $professional_name,
        public DateTimeImmutable $starts_at,
        public DateTimeImmutable $ends_at,
        public string $status,
    ) {}
}
