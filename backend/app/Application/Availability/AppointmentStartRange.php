<?php

namespace App\Application\Availability;

use DateTimeImmutable;

/** Both endpoints are valid Appointment start instants, including when they are equal. */
final readonly class AppointmentStartRange
{
    public function __construct(public DateTimeImmutable $earliest, public DateTimeImmutable $latest) {}
}
