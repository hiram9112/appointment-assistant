<?php

namespace App\Application\Conversation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class AppointmentProposalPayload
{
    private function __construct(
        public AppointmentProposalOperation $operation,
        public ?int $appointmentId,
        public ?int $serviceId,
        public ?int $professionalId,
        public ?CarbonImmutable $startsAt,
    ) {
        foreach ([$appointmentId, $serviceId, $professionalId] as $id) {
            if ($id !== null && $id < 1) {
                throw new InvalidArgumentException('Proposal references must be positive IDs.');
            }
        }
        if ($startsAt !== null && $startsAt->microsecond !== 0) {
            throw new InvalidArgumentException('Proposal times must use whole seconds.');
        }
    }

    public static function create(int $serviceId, int $professionalId, CarbonImmutable $startsAt): self
    {
        return new self(AppointmentProposalOperation::Create, null, $serviceId, $professionalId, $startsAt->utc());
    }

    public static function reschedule(int $appointmentId, int $professionalId, CarbonImmutable $startsAt): self
    {
        return new self(AppointmentProposalOperation::Reschedule, $appointmentId, null, $professionalId, $startsAt->utc());
    }

    public static function cancel(int $appointmentId): self
    {
        return new self(AppointmentProposalOperation::Cancel, $appointmentId, null, null, null);
    }

    /** @return array{appointment_id?: int, service_id?: int, professional_id?: int, starts_at?: string} */
    public function toArray(): array
    {
        return array_filter([
            'appointment_id' => $this->appointmentId,
            'service_id' => $this->serviceId,
            'professional_id' => $this->professionalId,
            'starts_at' => $this->startsAt?->toIso8601String(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @param array{appointment_id?: int, service_id?: int, professional_id?: int, starts_at?: string} $payload */
    public static function fromArray(AppointmentProposalOperation $operation, array $payload): self
    {
        return match ($operation) {
            AppointmentProposalOperation::Create => self::create($payload['service_id'], $payload['professional_id'], CarbonImmutable::parse($payload['starts_at'])),
            AppointmentProposalOperation::Reschedule => self::reschedule($payload['appointment_id'], $payload['professional_id'], CarbonImmutable::parse($payload['starts_at'])),
            AppointmentProposalOperation::Cancel => self::cancel($payload['appointment_id']),
        };
    }
}
