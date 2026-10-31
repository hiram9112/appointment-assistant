<?php

namespace App\Application\Service;

final readonly class ProfessionalSummary
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public string $displayName,
    ) {}
}
