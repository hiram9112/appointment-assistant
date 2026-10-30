<?php

namespace App\Application\Business;

final readonly class BusinessInformation
{
    /**
     * @param  list<BusinessOperatingInterval>  $operatingHours  Days without intervals are closed.
     * @param  array<array-key, mixed>|null  $paymentMethods
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public string $timezone,
        public ?string $addressLine,
        public ?string $postalCode,
        public ?string $city,
        public ?string $countryCode,
        public ?string $phone,
        public ?string $email,
        public array $operatingHours,
        public ?array $paymentMethods,
        public ?bool $appointmentsRecommended,
        public ?int $arrivalLeadMinutes,
        public ?int $cancellationNoticeMinutes,
        public ?int $reschedulingNoticeMinutes,
    ) {}
}
