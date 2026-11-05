<?php

namespace Tests\Feature;

use App\Application\Availability\AppointmentOccupancy;
use App\Application\Availability\GetAppointmentAvailability;
use App\Application\Availability\PostgresAppointmentOccupancy;
use App\Application\Availability\TimeInterval;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessOperatingHour;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\Service;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppointmentOccupancyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(new DateTimeImmutable('2026-11-01 00:00 UTC'));
    }

    public function test_normal_availability_uses_confirmed_appointments_for_the_selected_professional(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $other = Professional::factory()->for($business)->create();
        $this->appointment($service, $professional, '10:00', '11:00');
        $this->appointment($service, $professional, '13:00', '14:00');
        $this->appointment($service, $other, '15:00', '16:00');
        $this->appointment($service, $professional, '15:00', '16:00', ['status' => 'cancelled']);
        $this->appointment($service, $professional, '16:00', '17:00', ['status' => 'completed']);

        $this->assertInstanceOf(PostgresAppointmentOccupancy::class, app(AppointmentOccupancy::class));
        $this->assertSame([['09:00', '09:00'], ['11:00', '12:00'], ['14:00', '16:00']], $this->ranges($business, $service, $professional));
    }

    public function test_persisted_buffers_block_availability_independently_of_later_service_changes(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $bookedService = Service::factory()->for($business)->create(['duration_minutes' => 60, 'buffer_before_minutes' => 15, 'buffer_after_minutes' => 20]);
        $this->appointment($bookedService, $professional, '11:00', '12:00', ['buffer_before_minutes' => 15, 'buffer_after_minutes' => 20]);

        $expected = [['09:00', '09:45'], ['12:20', '16:00']];
        $this->assertSame($expected, $this->ranges($business, $service, $professional));
        $bookedService->update(['duration_minutes' => 120, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0]);
        $this->assertSame($expected, $this->ranges($business, $service, $professional));
    }

    public function test_occupancy_intersecting_query_boundaries_uses_half_open_intervals_and_utc_instants(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $this->appointment($service, $professional, '09:00', '10:00', ['buffer_after_minutes' => 15]);
        $this->appointment($service, $professional, '11:00', '12:00', ['buffer_before_minutes' => 15]);
        $this->appointment($service, $professional, '13:00', '14:00');

        DB::statement("SET LOCAL TIME ZONE 'America/New_York'");
        $source = app(AppointmentOccupancy::class);
        $intervals = $source->forProfessional($professional, new TimeInterval(
            new DateTimeImmutable('2026-11-09 10:00 UTC'),
            new DateTimeImmutable('2026-11-09 10:45 UTC'),
        ));

        $this->assertCount(1, $intervals);
        $this->assertSame('09:00', $intervals[0]->start->format('H:i'));
        $this->assertSame('10:15', $intervals[0]->end->format('H:i'));
        $this->assertSame([], $source->forProfessional($professional, new TimeInterval(
            new DateTimeImmutable('2026-11-09 12:00 UTC'),
            new DateTimeImmutable('2026-11-09 13:00 UTC'),
        )));
    }

    public function test_adjacent_appointment_start_is_available(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $this->appointment($service, $professional, '10:00', '11:00');

        $this->assertSame([['09:00', '09:00'], ['11:00', '16:00']], $this->ranges($business, $service, $professional));
    }

    #[DataProvider('pastAndPresentRanges')]
    public function test_past_and_current_starts_are_not_available(string $from, string $to): void
    {
        [$business, $service, $professional] = $this->schedule();
        $this->travelTo(new DateTimeImmutable('2026-11-09 12:00:00 UTC'));

        $this->assertSame([], $this->ranges($business, $service, $professional, $from, $to));
    }

    public static function pastAndPresentRanges(): array
    {
        return [
            'past day' => ['2026-11-08 09:00 UTC', '2026-11-08 17:00 UTC'],
            'past today' => ['2026-11-09 09:00 UTC', '2026-11-09 11:00 UTC'],
            'exactly now' => ['2026-11-09 12:00 UTC', '2026-11-09 12:00 UTC'],
        ];
    }

    public function test_same_day_range_applies_safety_margin_and_future_starts_remain_available(): void
    {
        [$business, $service, $professional] = $this->schedule();
        $this->travelTo(new DateTimeImmutable('2026-11-09 12:00:00 UTC'));

        $result = app(GetAppointmentAvailability::class)->handle($business, $service->id, new DateTimeImmutable('2026-11-09 09:00 UTC'), new DateTimeImmutable('2026-11-09 17:00 UTC'), $professional->id);
        $range = $result->requestedProfessional->ranges[0];

        $this->assertSame('2026-11-09 12:02:00.000000', $range->earliest->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-11-09 16:00:00.000000', $range->latest->format('Y-m-d H:i:s.u'));
        $this->assertSame([['13:00', '13:00']], $this->ranges($business, $service, $professional, '2026-11-09 13:00 UTC', '2026-11-09 13:00 UTC'));
    }

    #[DataProvider('safetyMarginTimes')]
    public function test_safety_margin_rounds_only_the_lower_bound(string $now, string $expected): void
    {
        [$business, $service, $professional] = $this->schedule();
        $business->operatingHours()->update(['opens_at' => '00:00', 'closes_at' => '23:59']);
        $professional->availabilityIntervals()->update(['starts_at' => '00:00', 'ends_at' => '23:59']);
        $this->travelTo(new DateTimeImmutable($now));
        $earliest = new DateTimeImmutable($expected);
        $capability = app(GetAppointmentAvailability::class);

        $result = $capability->handle($business, $service->id, new DateTimeImmutable('2026-11-09 00:00 UTC'), new DateTimeImmutable('2026-11-09 20:00 UTC'), $professional->id);
        $this->assertSame($earliest->format('Y-m-d H:i:s.u'), $result->requestedProfessional->ranges[0]->earliest->format('Y-m-d H:i:s.u'));

        $before = $earliest->modify('-1 second');
        $result = $capability->handle($business, $service->id, $before, $before, $professional->id);
        $this->assertSame([], $result->requestedProfessional->ranges);

        $result = $capability->handle($business, $service->id, $earliest, $earliest, $professional->id);
        $this->assertCount(1, $result->requestedProfessional->ranges);

        $offGrid = $earliest->modify('+15 seconds');
        $result = $capability->handle($business, $service->id, $offGrid, $offGrid, $professional->id);
        $this->assertSame($offGrid->format('Y-m-d H:i:s.u'), $result->requestedProfessional->ranges[0]->earliest->format('Y-m-d H:i:s.u'));
    }

    public static function safetyMarginTimes(): array
    {
        return [
            'whole minute' => ['2026-11-09 12:00:00 UTC', '2026-11-09 12:02:00 UTC'],
            'seconds 15' => ['2026-11-09 12:00:15 UTC', '2026-11-09 12:03:00 UTC'],
            'seconds 35' => ['2026-11-09 12:00:35 UTC', '2026-11-09 12:03:00 UTC'],
            'seconds 59' => ['2026-11-09 12:00:59 UTC', '2026-11-09 12:03:00 UTC'],
            'next whole minute' => ['2026-11-09 12:01:00 UTC', '2026-11-09 12:03:00 UTC'],
            'microseconds only' => ['2026-11-09 12:00:00.000001 UTC', '2026-11-09 12:03:00 UTC'],
            'midnight rollover' => ['2026-11-08 23:59:59 UTC', '2026-11-09 00:02:00 UTC'],
        ];
    }

    private function schedule(): array
    {
        $business = Business::factory()->create(['timezone' => 'UTC']);
        $service = Service::factory()->for($business)->create(['duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'active' => true]);
        $professional = Professional::factory()->for($business)->create();
        $professional->services()->attach($service);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'monday', 'opens_at' => '09:00', 'closes_at' => '17:00']);
        ProfessionalAvailabilityInterval::factory()->for($professional)->create(['weekday' => 'monday', 'starts_at' => '09:00', 'ends_at' => '17:00']);

        return [$business, $service, $professional];
    }

    private function appointment(Service $service, Professional $professional, string $start, string $end, array $attributes = []): void
    {
        Appointment::factory()->create(array_merge([
            'service_id' => $service->id,
            'professional_id' => $professional->id,
            'starts_at' => "2026-11-09 $start:00+00",
            'ends_at' => "2026-11-09 $end:00+00",
        ], $attributes));
    }

    private function ranges(Business $business, Service $service, Professional $professional, string $from = '2026-11-09 08:00 UTC', string $to = '2026-11-09 19:00 UTC'): array
    {
        $result = app(GetAppointmentAvailability::class)->handle($business, $service->id, new DateTimeImmutable($from), new DateTimeImmutable($to), $professional->id);

        return array_map(fn ($range) => [$range->earliest->format('H:i'), $range->latest->format('H:i')], $result->requestedProfessional->ranges);
    }
}
