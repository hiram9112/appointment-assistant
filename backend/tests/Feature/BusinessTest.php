<?php

namespace Tests\Feature;

use App\Models\Business;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_business_profile_can_be_persisted_and_retrieved(): void
    {
        $attributes = [
            'name' => 'Example Business',
            'description' => 'A customer-facing description.',
            'timezone' => 'Europe/Paris',
            'address_line' => '12 Example Street',
            'postal_code' => '75001',
            'city' => 'Paris',
            'country_code' => 'FR',
            'phone' => '+33 1 23 45 67 89',
            'email' => 'hello@example.test',
        ];

        $business = Business::factory()->create($attributes);
        $retrieved = Business::findOrFail($business->id);

        $this->assertSame($attributes, $retrieved->only(array_keys($attributes)));
        $this->assertNotNull($retrieved->created_at);
        $this->assertNotNull($retrieved->updated_at);
    }

    public function test_optional_profile_and_contact_fields_can_be_null(): void
    {
        $optionalFields = array_fill_keys([
            'description', 'address_line', 'postal_code', 'city', 'country_code', 'phone', 'email',
        ], null);

        $business = Business::factory()->create($optionalFields);
        $retrieved = Business::findOrFail($business->id);

        $this->assertSame($optionalFields, $retrieved->only(array_keys($optionalFields)));
    }

    public function test_timezone_is_required(): void
    {
        $this->expectException(QueryException::class);

        Business::factory()->create(['timezone' => null]);
    }

    public function test_default_seeder_creates_reference_business_core_profile(): void
    {
        $this->seed();

        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseHas('businesses', [
            'name' => 'Lúmina Estética Avanzada',
            'description' => "Lúmina Estética Avanzada is an independent beauty center offering facial treatments, body treatments, laser hair removal and wellness services.\n\nThe center operates from a single location with multiple Professionals and uses appointments as its primary service model.\n\nWhatsApp is its main conversational Customer channel.\n\nThe Business does not provide medical or clinical treatments.",
            'timezone' => 'Europe/Madrid',
            'address_line' => 'Calle Lúmina 18',
            'postal_code' => '50005',
            'city' => 'Zaragoza',
            'country_code' => 'ES',
            'phone' => '+34 600 000 000',
            'email' => 'hola@lumina-demo.test',
        ]);
    }

    public function test_repeated_seeding_does_not_duplicate_the_reference_business(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('businesses', 1);
        $this->assertDatabaseHas('businesses', ['name' => 'Lúmina Estética Avanzada']);
    }
}
