<?php

namespace App\Application\Conversation;

use App\Application\Availability\GetAppointmentAvailability;
use App\Application\Availability\ProfessionalAvailability;
use App\Models\Business;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class CheckAvailabilityTool implements Tool
{
    private const int MaxRangeDays = 31;

    public function __construct(
        private Business $business,
        private GetAppointmentAvailability $availability,
    ) {}

    public function description(): string
    {
        return 'Check authoritative Appointment start ranges for a canonical Service ID and optional Professional ID. '
            .'Use current Business context to select IDs; clarify ambiguous references instead of guessing. '
            .'from and to are inclusive Business-local RFC 3339 timestamps with seconds and an explicit UTC offset valid in the Business timezone. '
            .'The range may span at most 31 local calendar days; from equal to to checks one exact start. '
            .'Results are informational, do not reserve an Appointment, and preserve requested Professionals separately from alternatives.';
    }

    public function handle(Request $request): string
    {
        try {
            $references = $request->validate([
                'service_id' => ['required', 'integer:strict', 'min:1', 'max:'.PHP_INT_MAX],
                'professional_id' => ['nullable', 'integer:strict', 'min:1', 'max:'.PHP_INT_MAX],
            ]);
        } catch (ValidationException $exception) {
            return $this->encode(['status' => 'invalid_reference', 'errors' => $exception->errors()]);
        }

        $business = Business::query()->findOrFail($this->business->id);
        $serviceId = (int) $references['service_id'];
        $professionalId = isset($references['professional_id']) ? (int) $references['professional_id'] : null;

        if (! $business->services()->where('active', true)->whereKey($serviceId)->exists()) {
            return $this->encode(['status' => 'invalid_reference', 'errors' => ['service_id' => ['Unknown or inactive Service for this Business.']]]);
        }
        if ($professionalId !== null && ! $business->professionals()->whereKey($professionalId)->exists()) {
            return $this->encode(['status' => 'invalid_reference', 'errors' => ['professional_id' => ['Unknown Professional for this Business.']]]);
        }

        try {
            $arguments = $request->validate([
                'from' => ['required', 'string', 'max:25'],
                'to' => ['required', 'string', 'max:25'],
            ]);
            $zone = new DateTimeZone($business->timezone);
            $from = $this->timestamp($arguments['from'], $zone, 'from');
            $to = $this->timestamp($arguments['to'], $zone, 'to');
            if ($from > $to || $to > $from->modify('+'.self::MaxRangeDays.' days')) {
                throw ValidationException::withMessages(['to' => 'The inclusive start range must be ordered and span at most 31 local calendar days.']);
            }
        } catch (ValidationException $exception) {
            return $this->encode(['status' => 'invalid_temporal_arguments', 'errors' => $exception->errors()]);
        }

        $result = $this->availability->handle($business, $serviceId, $from, $to, $professionalId);
        if ($result === null) {
            return $this->encode(['status' => 'invalid_reference', 'errors' => ['references' => ['Service or Professional is no longer valid for this Business.']]]);
        }

        $requested = $result->requestedProfessional === null ? null : $this->professional($result->requestedProfessional, $zone);
        if ($requested !== null && $requested['ranges'] === [] && $result->requestedProfessional->ranges !== []) {
            // S03 omits alternatives when the requested Professional has any raw ranges.
            $result = $this->availability->handle($business, $serviceId, $from, $to);
            if ($result === null) {
                return $this->encode(['status' => 'invalid_reference', 'errors' => ['references' => ['Service or Professional is no longer valid for this Business.']]]);
            }
        }

        $available = [];
        foreach ($result->availableProfessionals as $professional) {
            if ($professional->id === $professionalId) {
                continue;
            }
            $normalized = $this->professional($professional, $zone);
            if ($normalized['ranges'] !== []) {
                $available[] = $normalized;
            }
        }

        $status = match (true) {
            $requested !== null && ! $requested['eligible'] => 'requested_professional_ineligible',
            $requested !== null && $requested['ranges'] === [] => 'requested_professional_unavailable',
            $requested !== null || $available !== [] => 'ok',
            default => 'unavailable',
        };

        return $this->encode([
            'status' => $status,
            'service_id' => $serviceId,
            'timezone' => $business->timezone,
            'from' => $from->format(DATE_ATOM),
            'to' => $to->format(DATE_ATOM),
            'requested_professional' => $requested,
            'available_professionals' => $available,
        ]);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service_id' => $schema->integer()->min(1)->max(PHP_INT_MAX)->description('Canonical active Service ID from current Business context.')->required(),
            'professional_id' => $schema->integer()->min(1)->max(PHP_INT_MAX)->nullable()->description('Canonical explicitly requested Professional ID, or null when no preference is given.'),
            'from' => $schema->string()->format('date-time')->max(25)->description('Inclusive earliest start, YYYY-MM-DDTHH:MM:SS±HH:MM in the Business timezone (Z allowed for UTC).')->required(),
            'to' => $schema->string()->format('date-time')->max(25)->description('Inclusive latest start in the same format; at or after from and no more than 31 local calendar days later. Equal to from for an exact start.')->required(),
        ];
    }

    private function timestamp(string $value, DateTimeZone $zone, string $field): DateTimeImmutable
    {
        $value = preg_replace('/Z$/', '+00:00', $value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value)) {
            throw ValidationException::withMessages([$field => 'Use YYYY-MM-DDTHH:MM:SS with an explicit UTC offset in the Business timezone.']);
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($instant === false || $errors !== false || $instant->format(DATE_ATOM) !== $value || $instant->setTimezone($zone)->format(DATE_ATOM) !== $value) {
            throw ValidationException::withMessages([$field => 'Use a valid Business-local timestamp with seconds and an explicit UTC offset matching the Business timezone.']);
        }

        return $instant->setTimezone($zone);
    }

    /** @return array{id: int, display_name: string, eligible: bool, ranges: list<array{earliest: string, latest: string}>} */
    private function professional(ProfessionalAvailability $professional, DateTimeZone $zone): array
    {
        $ranges = [];
        foreach ($professional->ranges as $range) {
            $earliest = $range->earliest->getTimestamp() + ($range->earliest->format('u') === '000000' ? 0 : 1);
            $latest = $range->latest->getTimestamp();
            if ($earliest <= $latest) {
                $ranges[] = [
                    'earliest' => $range->earliest->setTimestamp($earliest)->setTimezone($zone)->format(DATE_ATOM),
                    'latest' => $range->latest->setTimestamp($latest)->setTimezone($zone)->format(DATE_ATOM),
                ];
            }
        }

        return [
            'id' => $professional->id,
            'display_name' => $professional->displayName,
            'eligible' => $professional->eligible,
            'ranges' => $ranges,
        ];
    }

    /** @param array<string, mixed> $result */
    private function encode(array $result): string
    {
        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
