<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use App\Models\ScheduleException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurring_intervals_support_breaks_and_unavailable_days(): void
    {
        $professional = Professional::factory()->create();
        $first = ProfessionalAvailabilityInterval::factory()->for($professional)->create([
            'weekday' => 'monday', 'starts_at' => '09:00:00', 'ends_at' => '13:30:00',
        ]);
        $second = ProfessionalAvailabilityInterval::factory()->for($professional)->create([
            'weekday' => 'monday', 'starts_at' => '14:30:00', 'ends_at' => '17:00:00',
        ]);

        $this->assertTrue($first->fresh()->professional->is($professional));
        $this->assertSame('13:30:00', $first->fresh()->ends_at);
        $this->assertSame('14:30:00', $second->fresh()->starts_at);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $professional->availabilityIntervals()->pluck('id')->all());
        $this->assertFalse($professional->availabilityIntervals()->where('weekday', 'sunday')->exists());
    }

    public function test_recurring_intervals_must_be_positive(): void
    {
        $this->expectException(QueryException::class);
        ProfessionalAvailabilityInterval::factory()->create(['starts_at' => '17:00:00', 'ends_at' => '09:00:00']);
    }

    public function test_recurring_interval_weekday_must_be_valid(): void
    {
        $this->expectException(QueryException::class);
        ProfessionalAvailabilityInterval::factory()->create(['weekday' => 'invalid']);
    }

    public function test_exceptions_persist_business_and_professional_scopes_and_kinds(): void
    {
        $business = Business::factory()->create();
        $professional = Professional::factory()->for($business)->create();
        $businessBlock = ScheduleException::factory()->for($business)->create();
        $partialBlock = ScheduleException::factory()->for($business)->create([
            'professional_id' => $professional->id, 'reason' => 'Unavailable',
        ]);
        $fullDayBlock = ScheduleException::factory()->for($business)->create([
            'professional_id' => $professional->id, 'starts_at' => '2026-11-13 00:00:00', 'ends_at' => '2026-11-14 00:00:00',
        ]);
        $additional = ScheduleException::factory()->for($business)->create([
            'professional_id' => $professional->id, 'kind' => 'available',
        ]);
        $outsideHours = ScheduleException::factory()->for($business)->create([
            'professional_id' => $professional->id, 'kind' => 'available',
            'starts_at' => '2026-11-10 21:00:00', 'ends_at' => '2026-11-10 22:00:00',
        ]);

        $this->assertNull($businessBlock->fresh()->professional);
        $this->assertTrue($businessBlock->fresh()->business->is($business));
        $this->assertTrue($partialBlock->fresh()->professional->is($professional));
        $this->assertSame('Unavailable', $partialBlock->fresh()->reason);
        $this->assertSame('2026-11-14 00:00:00', $fullDayBlock->fresh()->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('blocked', $partialBlock->fresh()->kind);
        $this->assertSame('available', $additional->fresh()->kind);
        $this->assertSame('available', $outsideHours->fresh()->kind);
        $this->assertCount(5, $business->scheduleExceptions);
        $this->assertCount(4, $professional->scheduleExceptions);
    }

    public function test_business_scoped_available_exception_is_persisted(): void
    {
        $business = Business::factory()->create();
        $exception = ScheduleException::factory()->for($business)->create([
            'professional_id' => null,
            'kind' => 'available',
            'starts_at' => '2026-11-10 18:00:00',
            'ends_at' => '2026-11-10 20:00:00',
        ])->fresh();

        $this->assertNull($exception->professional_id);
        $this->assertSame('available', $exception->kind);
        $this->assertTrue($exception->business->is($business));
        $this->assertSame('2026-11-10 18:00:00', $exception->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 20:00:00', $exception->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_exception_kind_must_be_valid(): void
    {
        $this->expectException(QueryException::class);
        ScheduleException::factory()->create(['kind' => 'unknown']);
    }

    public function test_exception_interval_must_be_positive(): void
    {
        $this->expectException(QueryException::class);
        ScheduleException::factory()->create(['ends_at' => '2026-11-10 08:00:00']);
    }

    public function test_professional_exception_must_belong_to_the_same_business(): void
    {
        $professional = Professional::factory()->create();
        $otherBusiness = Business::factory()->create();

        $this->expectException(QueryException::class);
        ScheduleException::factory()->for($otherBusiness)->create(['professional_id' => $professional->id]);
    }

    public function test_reference_recurring_schedules_are_complete_and_repeatable(): void
    {
        $this->seed();
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $expected = [
            'Laura' => [
                'monday' => ['09:00-13:30', '14:30-17:00'], 'tuesday' => ['09:00-13:30', '14:30-17:00'],
                'wednesday' => ['09:00-13:30', '14:30-17:00'], 'thursday' => ['09:00-13:30', '14:30-17:00'],
                'friday' => ['09:00-13:30', '14:30-17:00'],
            ],
            'Marta' => [
                'monday' => ['11:00-15:00', '16:00-20:00'], 'tuesday' => ['11:00-15:00', '16:00-20:00'],
                'wednesday' => ['11:00-15:00', '16:00-20:00'], 'thursday' => ['11:00-15:00', '16:00-20:00'],
                'friday' => ['09:00-15:00', '16:00-17:00'],
            ],
            'Claudia' => [
                'tuesday' => ['10:00-14:00', '15:00-19:00'], 'wednesday' => ['10:00-14:00', '15:00-19:00'],
                'thursday' => ['10:00-14:00', '15:00-19:00'], 'friday' => ['10:00-14:00', '15:00-19:00'],
                'saturday' => ['09:00-14:00'],
            ],
            'Sara' => [
                'monday' => ['12:00-14:00', '15:00-20:00'], 'tuesday' => ['12:00-14:00', '15:00-20:00'],
                'wednesday' => ['12:00-14:00', '15:00-20:00'], 'thursday' => ['09:00-14:00', '15:00-17:00'],
                'friday' => ['09:00-14:00', '15:00-17:00'],
            ],
        ];

        foreach ($expected as $name => $days) {
            $professional = $business->professionals()->where('first_name', $name)->firstOrFail();
            $actual = $professional->availabilityIntervals()->orderBy('starts_at')->get()->groupBy('weekday')
                ->map(fn ($intervals) => $intervals->map(fn ($interval) => substr($interval->starts_at, 0, 5).'-'.substr($interval->ends_at, 0, 5))->all())->all();
            $this->assertEquals($days, $actual);
        }
    }

    public function test_reference_exceptions_are_local_business_times_stored_in_utc_and_repeatable(): void
    {
        $this->seed();
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $exceptions = $business->scheduleExceptions()->with('professional')->get();
        $actual = $exceptions->map(fn (ScheduleException $exception) => [
            $exception->professional?->first_name,
            $exception->starts_at->format('Y-m-d H:i:s'),
            $exception->ends_at->format('Y-m-d H:i:s'),
            $exception->kind,
            $exception->reason,
        ])->all();

        $this->assertEqualsCanonicalizing([
            ['Laura', '2026-11-10 08:00:00', '2026-11-10 11:00:00', 'blocked', 'Personal block.'],
            ['Marta', '2026-11-12 16:00:00', '2026-11-12 19:00:00', 'blocked', 'Professional unavailable.'],
            ['Claudia', '2026-11-12 23:00:00', '2026-11-13 23:00:00', 'blocked', null],
            [null, '2026-11-11 17:00:00', '2026-11-11 19:00:00', 'blocked', null],
        ], $actual);
    }

    public function test_reseeding_restores_missing_reference_entries_without_removing_other_configuration(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $laura = $business->professionals()->where('first_name', 'Laura')->firstOrFail();
        $laura->availabilityIntervals()->where('weekday', 'monday')->where('starts_at', '09:00:00')->delete();
        $laura->scheduleExceptions()->update(['reason' => 'Changed']);
        $otherBusiness = Business::factory()->create();
        $otherException = ScheduleException::factory()->for($otherBusiness)->create();
        $otherProfessional = Professional::factory()->for($otherBusiness)->create();
        $otherInterval = ProfessionalAvailabilityInterval::factory()->for($otherProfessional)->create();

        $this->seed();

        $this->assertSame(2, $laura->availabilityIntervals()->where('weekday', 'monday')->count());
        $this->assertSame('Personal block.', $laura->scheduleExceptions()->firstOrFail()->reason);
        $this->assertNotNull($otherException->fresh());
        $this->assertNotNull($otherInterval->fresh());
    }
}
