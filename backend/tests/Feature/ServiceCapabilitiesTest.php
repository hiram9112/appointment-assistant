<?php

namespace Tests\Feature;

use App\Application\Service\GetServiceInformation;
use App\Application\Service\ListServices;
use App\Application\Service\ProfessionalSummary;
use App\Application\Service\ServiceInformation;
use App\Models\Business;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_returns_only_active_business_services_ordered_by_name_and_id(): void
    {
        $business = Business::factory()->create();
        $z = Service::factory()->for($business)->create(['name' => 'Zebra']);
        $a = Service::factory()->for($business)->create(['name' => 'Alpha']);
        $a2 = Service::factory()->for($business)->create(['name' => 'Alpha']);
        Service::factory()->for($business)->create(['name' => 'Hidden', 'active' => false]);
        Service::factory()->create(['name' => 'Other']);

        $result = (new ListServices)->handle($business);

        $this->assertContainsOnlyInstancesOf(ServiceInformation::class, $result);
        $this->assertSame([$a->id, $a2->id, $z->id], array_map(fn (ServiceInformation $item): int => $item->id, $result));
    }

    public function test_capabilities_return_complete_information_with_ordered_same_business_eligible_professionals(): void
    {
        $business = Business::factory()->create();
        $service = Service::factory()->for($business)->create([
            'name' => 'Facial', 'description' => 'A facial treatment', 'duration_minutes' => 45,
            'price_amount' => '123.45', 'currency_code' => 'EUR',
        ]);
        $zoe = Professional::factory()->for($business)->create(['first_name' => 'Zoe', 'last_name' => 'Alpha', 'display_name' => 'Z']);
        $anaZ = Professional::factory()->for($business)->create(['first_name' => 'Ana', 'last_name' => 'Zulu', 'display_name' => null]);
        $anaA = Professional::factory()->for($business)->create(['first_name' => 'Ana', 'last_name' => 'Alpha', 'display_name' => null]);
        $anaA2 = Professional::factory()->for($business)->create(['first_name' => 'Ana', 'last_name' => 'Alpha', 'display_name' => 'Annie']);
        $other = Professional::factory()->create(['first_name' => 'Aardvark']);
        Professional::factory()->for($business)->create(['first_name' => 'Not eligible']);
        $service->professionals()->attach([$zoe->id, $anaZ->id, $anaA2->id, $anaA->id, $other->id]);

        $expected = new ServiceInformation($service->id, 'Facial', 'A facial treatment', 45, '123.45', 'EUR', [
            new ProfessionalSummary($anaA->id, 'Ana', 'Alpha', 'Ana Alpha'),
            new ProfessionalSummary($anaA2->id, 'Ana', 'Alpha', 'Annie'),
            new ProfessionalSummary($anaZ->id, 'Ana', 'Zulu', 'Ana Zulu'),
            new ProfessionalSummary($zoe->id, 'Zoe', 'Alpha', 'Z'),
        ]);
        $detail = (new GetServiceInformation)->handle($business, $service->id);
        $catalogue = (new ListServices)->handle($business);

        $this->assertNotNull($detail);
        $this->assertCount(1, $catalogue);
        $this->assertSame($this->serviceValues($expected), $this->serviceValues($detail));
        $this->assertSame($this->serviceValues($expected), $this->serviceValues($catalogue[0]));
    }

    public function test_null_and_zero_prices_and_nullable_description_remain_distinct(): void
    {
        $business = Business::factory()->create();
        $unpriced = Service::factory()->for($business)->create(['description' => null, 'price_amount' => null, 'currency_code' => null]);
        $free = Service::factory()->for($business)->create(['price_amount' => '0.00', 'currency_code' => 'EUR']);

        $unpricedResult = (new GetServiceInformation)->handle($business, $unpriced->id);
        $freeResult = (new GetServiceInformation)->handle($business, $free->id);

        $this->assertNull($unpricedResult->description);
        $this->assertNull($unpricedResult->priceAmount);
        $this->assertNull($unpricedResult->currencyCode);
        $this->assertSame([], $unpricedResult->eligibleProfessionals);
        $this->assertSame('0.00', $freeResult->priceAmount);
        $this->assertSame('EUR', $freeResult->currencyCode);
    }

    public function test_inactive_missing_and_other_business_services_return_null(): void
    {
        $business = Business::factory()->create();
        $inactive = Service::factory()->for($business)->create(['active' => false]);
        $other = Service::factory()->create();
        $capability = new GetServiceInformation;

        $this->assertNull($capability->handle($business, $inactive->id));
        $this->assertNull($capability->handle($business, $other->id));
        $this->assertNull($capability->handle($business, 99999999));
    }

    public function test_reference_catalogue_and_consultation_exercise_both_capabilities(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $consultation = $business->services()->where('name', 'Initial beauty consultation')->firstOrFail();

        $catalogue = (new ListServices)->handle($business);
        $result = (new GetServiceInformation)->handle($business, $consultation->id);

        $this->assertCount(8, $catalogue);
        $this->assertSame(['Deep facial cleansing', 'Firming body treatment', 'Hydrating facial treatment', 'Initial beauty consultation', 'Laser hair removal — full legs', 'Laser hair removal — underarms', 'Premium facial treatment', 'Wellness massage'], array_map(fn (ServiceInformation $item): string => $item->name, $catalogue));
        $this->assertSame(30, $result->durationMinutes);
        $this->assertSame('0.00', $result->priceAmount);
        $this->assertSame('EUR', $result->currencyCode);
        $this->assertSame(['Claudia Pérez', 'Laura Martín', 'Marta Ruiz', 'Sara Gómez'], array_map(fn (ProfessionalSummary $item): string => $item->displayName, $result->eligibleProfessionals));
        $this->assertSame($this->serviceValues($result), $this->serviceValues($catalogue[3]));
    }

    private function serviceValues(ServiceInformation $service): array
    {
        $values = get_object_vars($service);
        $values['eligibleProfessionals'] = array_map(
            fn (ProfessionalSummary $professional): array => get_object_vars($professional),
            $service->eligibleProfessionals,
        );

        return $values;
    }
}
