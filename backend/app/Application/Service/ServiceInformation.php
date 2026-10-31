<?php

namespace App\Application\Service;

use App\Models\Professional;
use App\Models\Service;

final readonly class ServiceInformation
{
    /** @param list<ProfessionalSummary> $eligibleProfessionals */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public int $durationMinutes,
        public ?string $priceAmount,
        public ?string $currencyCode,
        public array $eligibleProfessionals,
    ) {}

    public static function fromService(Service $service): self
    {
        return new self(
            id: $service->id,
            name: $service->name,
            description: $service->description,
            durationMinutes: $service->duration_minutes,
            priceAmount: $service->price_amount,
            currencyCode: $service->currency_code,
            eligibleProfessionals: $service->professionals->map(
                fn (Professional $professional): ProfessionalSummary => new ProfessionalSummary(
                    id: $professional->id,
                    firstName: $professional->first_name,
                    lastName: $professional->last_name,
                    displayName: $professional->display_name ?? $professional->first_name.' '.$professional->last_name,
                ),
            )->all(),
        );
    }
}
