<?php

namespace App\Application\Business;

final readonly class BusinessOperatingInterval
{
    public function __construct(
        public string $weekday,
        public string $opensAt,
        public string $closesAt,
    ) {}
}
