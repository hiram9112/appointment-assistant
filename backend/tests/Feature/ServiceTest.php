<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_service_configuration_and_business_relationships_are_persisted(): void
    {
        $business = Business::factory()->create();
        $otherBusiness = Business::factory()->create();
        $attributes = [
            'name' => 'Facial treatment',
            'description' => 'A complete treatment.',
            'duration_minutes' => 90,
            'price_amount' => '12345678.91',
            'currency_code' => 'EUR',
            'active' => false,
        ];
        $service = Service::factory()->for($business)->create($attributes);
        Service::factory()->for($otherBusiness)->create();

        $retrieved = Service::findOrFail($service->id);
        $this->assertSame($attributes, $retrieved->only(array_keys($attributes)));
        $this->assertTrue($retrieved->business->is($business));
        $this->assertSame([$service->id], $business->services()->pluck('id')->all());
        $this->assertNotNull($retrieved->created_at);
        $this->assertNotNull($retrieved->updated_at);
    }

    public function test_nullable_price_and_description_remain_distinct_from_zero_price(): void
    {
        $unpriced = Service::factory()->create(['description' => null, 'price_amount' => null, 'currency_code' => null]);
        $free = Service::factory()->create(['price_amount' => '0.00', 'currency_code' => 'EUR', 'active' => true]);

        $this->assertNull($unpriced->fresh()->description);
        $this->assertNull($unpriced->fresh()->price_amount);
        $this->assertNull($unpriced->fresh()->currency_code);
        $this->assertSame('0.00', $free->fresh()->price_amount);
        $this->assertTrue($free->fresh()->active);
    }

    public function test_name_is_required(): void
    {
        $this->expectException(QueryException::class);
        Service::factory()->create(['name' => null]);
    }

    public function test_duration_is_required(): void
    {
        $this->expectException(QueryException::class);
        Service::factory()->create(['duration_minutes' => null]);
    }

    public function test_reference_services_are_seeded_with_exact_configuration(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $actual = $business->services()->orderBy('name')->get()->map(fn (Service $service) => [
            $service->name, $service->duration_minutes, $service->price_amount,
            $service->currency_code, $service->active, $service->description,
        ])->all();

        $this->assertSame([
            ['Deep facial cleansing', 60, '55.00', 'EUR', true, null],
            ['Firming body treatment', 60, '70.00', 'EUR', true, null],
            ['Hydrating facial treatment', 60, '65.00', 'EUR', true, null],
            ['Initial beauty consultation', 30, '0.00', 'EUR', true, null],
            ['Laser hair removal — full legs', 45, '65.00', 'EUR', true, null],
            ['Laser hair removal — underarms', 20, '25.00', 'EUR', true, null],
            ['Premium facial treatment', 90, '85.00', 'EUR', true, null],
            ['Wellness massage', 60, '50.00', 'EUR', true, null],
        ], $actual);
    }

    public function test_reseeding_restores_reference_services_and_preserves_unrelated_services(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $original = $business->services()->where('name', 'Wellness massage')->firstOrFail();
        $original->update(['duration_minutes' => 10, 'price_amount' => null, 'active' => false]);
        $business->services()->where('name', 'Deep facial cleansing')->delete();
        $unrelatedService = Service::factory()->for($business)->create(['name' => 'Additional service']);
        $otherService = Service::factory()->create(['name' => 'Additional service']);

        $this->seed();
        $this->seed();

        $this->assertCount(9, $business->services);
        $this->assertSame(1, $business->services()->where('name', 'Wellness massage')->count());
        $this->assertSame(1, $business->services()->where('name', 'Deep facial cleansing')->count());
        $this->assertSame(8, $business->services()->where('name', '!=', 'Additional service')->distinct()->count('name'));
        $this->assertSame(60, $original->fresh()->duration_minutes);
        $this->assertSame('50.00', $original->fresh()->price_amount);
        $this->assertTrue($original->fresh()->active);
        $this->assertNotNull($unrelatedService->fresh());
        $this->assertNotNull($otherService->fresh());
    }
}
