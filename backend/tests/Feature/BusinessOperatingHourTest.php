<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessOperatingHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessOperatingHourTest extends TestCase
{
    use RefreshDatabase;

    public function test_intervals_are_persisted_and_retrieved_for_their_business(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $first = BusinessOperatingHour::factory()->for($business)->create([
            'weekday' => 'monday', 'opens_at' => '09:00:00', 'closes_at' => '12:00:00',
        ]);
        $second = BusinessOperatingHour::factory()->for($business)->create([
            'weekday' => 'monday', 'opens_at' => '14:00:00', 'closes_at' => '18:00:00',
        ]);
        BusinessOperatingHour::factory()->for($otherBusiness)->create(['weekday' => 'monday']);

        $retrieved = BusinessOperatingHour::findOrFail($first->id);
        $this->assertSame($business->id, $retrieved->business->id);
        $this->assertSame('monday', $retrieved->weekday);
        $this->assertSame('09:00:00', $retrieved->opens_at);
        $this->assertSame('12:00:00', $retrieved->closes_at);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $business->fresh()->operatingHours->modelKeys());
        $this->assertFalse($business->operatingHours()->where('weekday', 'sunday')->exists());
    }

    public function test_reference_schedule_is_seeded_and_seeding_is_repeatable(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $business->operatingHours()->where('weekday', 'monday')->update(['opens_at' => '08:00:00']);
        BusinessOperatingHour::factory()->for($business)->create(['weekday' => 'sunday']);
        $otherBusiness = Business::factory()->create();
        $otherInterval = BusinessOperatingHour::factory()->for($otherBusiness)->create();

        $this->seed();

        $hours = $business->fresh()->operatingHours->keyBy('weekday');

        $this->assertDatabaseCount('business_operating_hours', 7);
        $this->assertDatabaseHas('business_operating_hours', ['id' => $otherInterval->id, 'business_id' => $otherBusiness->id]);
        $this->assertCount(6, $hours);
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday'] as $weekday) {
            $this->assertSame('09:00:00', $hours[$weekday]->opens_at);
            $this->assertSame('20:00:00', $hours[$weekday]->closes_at);
        }
        $this->assertSame('09:00:00', $hours['saturday']->opens_at);
        $this->assertSame('14:00:00', $hours['saturday']->closes_at);
        $this->assertFalse($hours->has('sunday'));

    }
}
