<?php

namespace Tests\Feature;

use App\Application\Availability\AppointmentOccupancy;
use App\Application\Availability\CalculateAvailability;
use App\Application\Availability\TimeInterval;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\ScheduleException;
use App\Models\Service;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CalculateAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_duration_and_both_buffers_produce_inclusive_start_ranges(): void
    {
        [$business, $service, $professional] = $this->schedule(['duration_minutes' => 60, 'buffer_before_minutes' => 10, 'buffer_after_minutes' => 20]);

        $this->assertSame([['09:10', '15:40']], $this->calculate($business, $service, $professional));
        $service->update(['duration_minutes' => 450]);
        $this->assertSame([['09:10', '09:10']], $this->calculate($business, $service, $professional));
        $service->update(['duration_minutes' => 451]);
        $this->assertSame([], $this->calculate($business, $service, $professional));
    }

    public function test_exact_buffer_boundaries_include_endpoints_and_exclude_starts_just_outside(): void
    {
        [$business, $service, $professional] = $this->schedule(['duration_minutes' => 60, 'buffer_before_minutes' => 5, 'buffer_after_minutes' => 10]);
        $business->operatingHours()->update(['closes_at' => '11:00']);
        $professional->availabilityIntervals()->update(['ends_at' => '11:00']);

        $this->assertSame([['09:05', '09:50']], $this->calculate($business, $service, $professional));
        $this->assertSame([['09:05', '09:05']], $this->calculate($business, $service, $professional, [], '09:05:00', '09:05:00'));
        $this->assertSame([['09:50', '09:50']], $this->calculate($business, $service, $professional, [], '09:50:00', '09:50:00'));
        $this->assertSame([], $this->calculate($business, $service, $professional, [], '09:04:59', '09:04:59'));
        $this->assertSame([], $this->calculate($business, $service, $professional, [], '09:50:01', '09:50:01'));
    }

    public function test_multi_day_range_uses_each_days_business_and_professional_schedules(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $business->operatingHours()->update(['opens_at' => '10:00', 'closes_at' => '16:00']);
        $professional->availabilityIntervals()->update(['starts_at' => '09:00', 'ends_at' => '15:00']);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'tuesday', 'opens_at' => '12:00', 'closes_at' => '18:00']);
        ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => 'tuesday', 'starts_at' => '13:00', 'ends_at' => '19:00']);
        $source = new class implements AppointmentOccupancy
        {
            public function forProfessional(Professional $professional, TimeInterval $period): array
            {
                return [];
            }
        };

        $ranges = (new CalculateAvailability($source))->handle($business, $service, $professional, new DateTimeImmutable('2026-11-09 00:00 UTC'), new DateTimeImmutable('2026-11-10 23:59 UTC'));

        $this->assertSame([
            ['2026-11-09 10:00:00', '2026-11-09 14:00:00'],
            ['2026-11-10 13:00:00', '2026-11-10 17:00:00'],
        ], array_map(fn ($range) => [$range->earliest->format('Y-m-d H:i:s'), $range->latest->format('Y-m-d H:i:s')], $ranges));
    }

    public function test_business_and_professional_hours_and_recurring_breaks_constrain_results(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $business->operatingHours()->update(['opens_at' => '10:00', 'closes_at' => '16:00']);
        $professional->availabilityIntervals()->delete();
        $this->professionalHours($professional, '09:00', '12:00');
        $this->professionalHours($professional, '13:00', '15:00');

        $this->assertSame([['10:00', '11:00'], ['13:00', '14:00']], $this->calculate($business, $service, $professional));
    }

    public function test_blocked_business_and_professional_exceptions_override_positive_availability(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $this->exception($business, null, 'available', '08:00', '18:00');
        $this->exception($business, $professional, 'available', '08:00', '18:00');
        $this->exception($business, null, 'blocked', '10:00', '11:00');
        $this->exception($business, $professional, 'blocked', '14:00', '15:00');

        $this->assertSame([['08:00', '09:00'], ['11:00', '13:00'], ['15:00', '17:00']], $this->calculate($business, $service, $professional));
    }

    public function test_full_day_block_removes_availability(): void
    {
        [$business, $service, $professional] = $this->schedule();
        ScheduleException::factory()->for($business)->create(['professional_id' => $professional->id, 'kind' => 'blocked', 'starts_at' => '2026-11-09 00:00:00', 'ends_at' => '2026-11-10 00:00:00']);

        $this->assertSame([], $this->calculate($business, $service, $professional));
    }

    public function test_absent_recurring_hours_and_other_professionals_exceptions_do_not_create_availability(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $professional->availabilityIntervals()->delete();
        $other = Professional::factory()->for($business)->create();
        $this->exception($business, $other, 'available', '09:00', '17:00');

        $this->assertSame([], $this->calculate($business, $service, $professional));
    }

    public function test_business_full_day_block_overrides_both_kinds_of_availability(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $this->exception($business, null, 'available', '08:00', '19:00');
        $this->exception($business, $professional, 'available', '08:00', '19:00');
        ScheduleException::factory()->for($business)->create(['professional_id' => null, 'kind' => 'blocked', 'starts_at' => '2026-11-09 00:00:00', 'ends_at' => '2026-11-10 00:00:00']);

        $this->assertSame([], $this->calculate($business, $service, $professional));
    }

    public function test_reversed_requested_range_is_rejected(): void
    {
        [$business, $service, $professional] = $this->schedule();

        $this->expectException(InvalidArgumentException::class);
        $this->calculate($business, $service, $professional, [], '11:00', '10:00');
    }

    public function test_professional_extra_hours_require_business_extra_hours(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $this->exception($business, $professional, 'available', '17:00', '19:00');
        $this->assertSame([['09:00', '16:00']], $this->calculate($business, $service, $professional));
        $this->exception($business, null, 'available', '17:00', '19:00');

        $this->assertSame([['09:00', '18:00']], $this->calculate($business, $service, $professional));
    }

    public function test_occupancy_splits_availability_and_adjacent_intervals_do_not_conflict(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $occupied = [$this->interval('08:00', '09:00'), $this->interval('11:00', '12:00'), $this->interval('17:00', '18:00')];

        $this->assertSame([['09:00', '10:00'], ['12:00', '16:00']], $this->calculate($business, $service, $professional, $occupied));
    }

    public function test_requested_start_range_includes_occupancy_outside_the_requested_range(): void
    {
        [$business, $service, $professional] = $this->schedule(['buffer_before_minutes' => 15, 'buffer_after_minutes' => 15]);
        $occupied = [$this->interval('09:30', '09:50')];

        $this->assertSame([['10:05', '10:30']], $this->calculate($business, $service, $professional, $occupied, '10:00', '10:30'));
    }

    public function test_occupancy_source_receives_professional_and_complete_requested_occupancy_period(): void
    {
        [$business, $service, $professional] = $this->schedule(['buffer_before_minutes' => 15, 'buffer_after_minutes' => 20]);
        $source = new class implements AppointmentOccupancy
        {
            public ?Professional $professional = null;

            public ?TimeInterval $period = null;

            public function forProfessional(Professional $professional, TimeInterval $period): array
            {
                $this->professional = $professional;
                $this->period = $period;

                return [];
            }
        };

        (new CalculateAvailability($source))->handle($business, $service, $professional, new DateTimeImmutable('2026-11-09 10:00 UTC'), new DateTimeImmutable('2026-11-09 11:00 UTC'));

        $this->assertTrue($professional->is($source->professional));
        $this->assertSame('2026-11-09 09:45', $source->period->start->format('Y-m-d H:i'));
        $this->assertSame('2026-11-09 12:20', $source->period->end->format('Y-m-d H:i'));
    }

    public function test_ineligible_inactive_or_other_business_records_return_no_availability(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $professional->services()->detach($service);
        $this->assertSame([], $this->calculate($business, $service, $professional));
        $professional->services()->attach($service);
        $service->update(['active' => false]);
        $this->assertSame([], $this->calculate($business, $service, $professional));
        $service->update(['active' => true]);
        $this->assertSame([], $this->calculate(Business::factory()->create(), $service, $professional));
    }

    public function test_local_recurring_hours_and_utc_exceptions_are_compared_as_instants(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $business->update(['timezone' => 'Europe/Madrid']);
        $this->exception($business, null, 'blocked', '09:00', '10:00');

        $this->assertSame([['08:00', '08:00'], ['10:00', '15:00']], $this->calculate($business, $service, $professional, [], '07:00', '20:00'));
    }

    private function schedule(array $attributes = []): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $professional = Professional::factory()->for($business)->create();
        $service = Service::factory()->for($business)->create(array_merge(['active' => true, 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0], $attributes));
        $professional->services()->attach($service);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '09:00', 'closes_at' => '17:00']);
        $this->professionalHours($professional, '09:00', '17:00');

        return [$business, $service, $professional];
    }

    private function professionalHours(Professional $professional, string $start, string $end): void
    {
        ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => 'monday', 'starts_at' => $start, 'ends_at' => $end]);
    }

    private function exception(Business $business, ?Professional $professional, string $kind, string $start, string $end): void
    {
        ScheduleException::factory()->for($business)->create(['professional_id' => $professional?->id, 'kind' => $kind, 'starts_at' => "2026-11-09 $start:00", 'ends_at' => "2026-11-09 $end:00"]);
    }

    private function interval(string $start, string $end): TimeInterval
    {
        return new TimeInterval(new DateTimeImmutable("2026-11-09 $start UTC"), new DateTimeImmutable("2026-11-09 $end UTC"));
    }

    private function calculate(Business $business, Service $service, Professional $professional, array $occupied = [], string $from = '08:00', string $to = '19:00'): array
    {
        $source = new class($occupied) implements AppointmentOccupancy
        {
            public function __construct(private array $intervals) {}

            public function forProfessional(Professional $professional, TimeInterval $period): array
            {
                return $this->intervals;
            }
        };
        $ranges = (new CalculateAvailability($source))->handle($business, $service, $professional, new DateTimeImmutable("2026-11-09 $from UTC"), new DateTimeImmutable("2026-11-09 $to UTC"));

        return array_map(fn ($range) => [$range->earliest->format('H:i'), $range->latest->format('H:i')], $ranges);
    }
}
