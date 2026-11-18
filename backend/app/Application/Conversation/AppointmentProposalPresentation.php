<?php

namespace App\Application\Conversation;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AppointmentProposalPresentation
{
    public function __construct(
        public string $serviceName,
        public string $professionalName,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
        public ?self $previous = null,
    ) {
        new DateTimeZone($timezone);
        if (trim($serviceName) === '' || trim($professionalName) === '' || $endsAt->lte($startsAt)
            || $startsAt->microsecond !== 0 || $endsAt->microsecond !== 0 || $previous?->previous !== null) {
            throw new InvalidArgumentException('A proposal needs complete, non-nested Appointment presentation details.');
        }
    }

    /** @return array{service_name: string, professional_name: string, starts_at: string, ends_at: string, timezone: string, previous: ?array} */
    public function toArray(): array
    {
        return [
            'service_name' => $this->serviceName,
            'professional_name' => $this->professionalName,
            'starts_at' => $this->startsAt->utc()->toIso8601String(),
            'ends_at' => $this->endsAt->utc()->toIso8601String(),
            'timezone' => $this->timezone,
            'previous' => $this->previous?->toArray(),
        ];
    }

    /** @param array{service_name: string, professional_name: string, starts_at: string, ends_at: string, timezone: string, previous: ?array} $details */
    public static function fromArray(array $details): self
    {
        return new self(
            $details['service_name'], $details['professional_name'],
            CarbonImmutable::parse($details['starts_at']), CarbonImmutable::parse($details['ends_at']),
            $details['timezone'], $details['previous'] === null ? null : self::fromArray($details['previous']),
        );
    }
}
