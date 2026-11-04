<?php

namespace App\Application\Availability;

use DateTimeImmutable;
use InvalidArgumentException;

/** A half-open interval of occupied or available time: [start, end). */
final readonly class TimeInterval
{
    public function __construct(public DateTimeImmutable $start, public DateTimeImmutable $end)
    {
        if ($start >= $end) {
            throw new InvalidArgumentException('An interval must have a positive duration.');
        }
    }
}
