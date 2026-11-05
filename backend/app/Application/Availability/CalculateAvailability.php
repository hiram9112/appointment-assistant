<?php

namespace App\Application\Availability;

use App\Models\Business;
use App\Models\Professional;
use App\Models\Service;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CalculateAvailability
{
    public function __construct(private AppointmentOccupancy $occupancy) {}

    /**
     * Calculate starts in the inclusive requested range. Results are UTC instants;
     * availability is informational and does not reserve a time.
     *
     * @return list<AppointmentStartRange>
     */
    public function handle(Business $business, Service $service, Professional $professional, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException('The requested start range is reversed.');
        }

        if ($service->business_id !== $business->id || $professional->business_id !== $business->id || ! $service->active || ! $professional->services()->whereKey($service->id)->exists()) {
            return [];
        }

        $utc = new DateTimeZone('UTC');
        $from = $from->setTimezone($utc);
        $to = $to->setTimezone($utc);
        $earliestAllowedStart = DateTimeImmutable::createFromInterface(now())->setTimezone($utc)->modify('+2 minutes');
        if ($earliestAllowedStart->format('s.u') !== '00.000000') {
            $earliestAllowedStart = $earliestAllowedStart->setTime(
                (int) $earliestAllowedStart->format('H'),
                (int) $earliestAllowedStart->format('i'),
            )->modify('+1 minute');
        }
        $from = max($from, $earliestAllowedStart);
        if ($from > $to) {
            return [];
        }
        $before = $service->buffer_before_minutes;
        $after = $service->duration_minutes + $service->buffer_after_minutes;
        $period = new TimeInterval($from->modify("-{$before} minutes"), $to->modify("+{$after} minutes"));
        $zone = new DateTimeZone($business->timezone);
        $businessHours = $business->operatingHours()->get()->groupBy('weekday');
        $professionalHours = $professional->availabilityIntervals()->get()->groupBy('weekday');
        $businessAvailable = [];
        $professionalAvailable = [];
        $blocked = [];

        $day = $period->start->setTimezone($zone)->setTime(0, 0);
        $lastDay = $period->end->setTimezone($zone)->setTime(0, 0);
        while ($day <= $lastDay) {
            $weekday = strtolower($day->format('l'));
            foreach ($businessHours->get($weekday, []) as $hours) {
                $businessAvailable[] = $this->localInterval($day, $hours->opens_at, $hours->closes_at, $utc);
            }
            foreach ($professionalHours->get($weekday, []) as $hours) {
                $professionalAvailable[] = $this->localInterval($day, $hours->starts_at, $hours->ends_at, $utc);
            }
            $day = $day->modify('+1 day');
        }

        $exceptions = $business->scheduleExceptions()
            ->where('starts_at', '<', $period->end->format('Y-m-d H:i:s.u'))
            ->where('ends_at', '>', $period->start->format('Y-m-d H:i:s.u'))
            ->where(fn ($query) => $query->whereNull('professional_id')->orWhere('professional_id', $professional->id))
            ->get();
        foreach ($exceptions as $exception) {
            $interval = new TimeInterval(DateTimeImmutable::createFromInterface($exception->starts_at)->setTimezone($utc), DateTimeImmutable::createFromInterface($exception->ends_at)->setTimezone($utc));
            if ($exception->kind === 'blocked') {
                $blocked[] = $interval;
            } elseif ($exception->professional_id === null) {
                $businessAvailable[] = $interval;
            } else {
                $professionalAvailable[] = $interval;
            }
        }

        $effective = [];
        foreach ($this->merge($businessAvailable) as $businessInterval) {
            foreach ($this->merge($professionalAvailable) as $professionalInterval) {
                $start = max($businessInterval->start, $professionalInterval->start, $period->start);
                $end = min($businessInterval->end, $professionalInterval->end, $period->end);
                if ($start < $end) {
                    $effective[] = new TimeInterval($start, $end);
                }
            }
        }

        $effective = $this->subtract($this->merge($effective), $this->merge([...$blocked, ...$this->occupancy->forProfessional($professional, $period)]));
        $ranges = [];
        foreach ($effective as $interval) {
            $earliest = max($from, $interval->start->modify("+{$before} minutes"));
            $latest = min($to, $interval->end->modify("-{$after} minutes"));
            if ($earliest <= $latest) {
                $ranges[] = new AppointmentStartRange($earliest, $latest);
            }
        }

        return $ranges;
    }

    private function localInterval(DateTimeImmutable $day, string $start, string $end, DateTimeZone $utc): TimeInterval
    {
        return new TimeInterval(
            (new DateTimeImmutable($day->format('Y-m-d').' '.$start, $day->getTimezone()))->setTimezone($utc),
            (new DateTimeImmutable($day->format('Y-m-d').' '.$end, $day->getTimezone()))->setTimezone($utc),
        );
    }

    /** @param list<TimeInterval> $intervals
     * @return list<TimeInterval>
     */
    private function merge(array $intervals): array
    {
        usort($intervals, fn (TimeInterval $a, TimeInterval $b): int => $a->start <=> $b->start);
        $merged = [];
        foreach ($intervals as $interval) {
            $last = count($merged) - 1;
            if ($last >= 0 && $interval->start <= $merged[$last]->end) {
                $merged[$last] = new TimeInterval($merged[$last]->start, max($merged[$last]->end, $interval->end));
            } else {
                $merged[] = $interval;
            }
        }

        return $merged;
    }

    /** @param list<TimeInterval> $intervals
     * @param  list<TimeInterval>  $blocked
     * @return list<TimeInterval>
     */
    private function subtract(array $intervals, array $blocked): array
    {
        foreach ($blocked as $block) {
            $remaining = [];
            foreach ($intervals as $interval) {
                if ($block->end <= $interval->start || $block->start >= $interval->end) {
                    $remaining[] = $interval;

                    continue;
                }
                if ($block->start > $interval->start) {
                    $remaining[] = new TimeInterval($interval->start, $block->start);
                }
                if ($block->end < $interval->end) {
                    $remaining[] = new TimeInterval($block->end, $interval->end);
                }
            }
            $intervals = $remaining;
        }

        return $intervals;
    }
}
