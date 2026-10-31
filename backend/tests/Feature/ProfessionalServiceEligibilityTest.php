<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProfessionalServiceEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationships_work_in_both_directions_with_multiple_entities(): void
    {
        $business = Business::factory()->create();
        $professionals = Professional::factory()->for($business)->count(2)->create();
        $services = Service::factory()->for($business)->count(2)->create();
        $professionals[0]->services()->attach($services->modelKeys());
        $services[0]->professionals()->attach($professionals[1]);

        $this->assertEqualsCanonicalizing($services->modelKeys(), $professionals[0]->services->modelKeys());
        $this->assertEqualsCanonicalizing($professionals->modelKeys(), $services[0]->professionals->modelKeys());
    }

    public function test_duplicate_pairs_are_rejected(): void
    {
        $professional = Professional::factory()->create();
        $service = Service::factory()->for($professional->business)->create();
        $professional->services()->attach($service);

        $this->expectException(QueryException::class);
        $professional->services()->attach($service);
    }

    public function test_nonexistent_professional_is_rejected(): void
    {
        $service = Service::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('professional_service')->insert(['professional_id' => 99999999, 'service_id' => $service->id]);
    }

    public function test_nonexistent_service_is_rejected(): void
    {
        $professional = Professional::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('professional_service')->insert(['professional_id' => $professional->id, 'service_id' => 99999999]);
    }

    public function test_deleting_either_entity_removes_its_pivot_rows(): void
    {
        $business = Business::factory()->create();
        $professionals = Professional::factory()->for($business)->count(2)->create();
        $services = Service::factory()->for($business)->count(2)->create();
        $professionals[0]->services()->attach($services->modelKeys());
        $professionals[1]->services()->attach($services[0]);

        $professionals[0]->delete();
        $this->assertDatabaseMissing('professional_service', ['professional_id' => $professionals[0]->id]);
        $this->assertDatabaseHas('professional_service', ['professional_id' => $professionals[1]->id, 'service_id' => $services[0]->id]);
        $services[0]->delete();
        $this->assertDatabaseCount('professional_service', 0);
    }

    public function test_reference_eligibility_is_exact_and_reseeding_restores_it_without_removing_unrelated_links(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $expected = [
            'Laura' => ['Deep facial cleansing', 'Hydrating facial treatment', 'Initial beauty consultation', 'Premium facial treatment'],
            'Marta' => ['Initial beauty consultation', 'Laser hair removal — full legs', 'Laser hair removal — underarms'],
            'Claudia' => ['Firming body treatment', 'Initial beauty consultation', 'Wellness massage'],
            'Sara' => ['Deep facial cleansing', 'Firming body treatment', 'Hydrating facial treatment', 'Initial beauty consultation', 'Laser hair removal — underarms'],
        ];

        foreach ($expected as $name => $serviceNames) {
            $professional = $business->professionals()->where('first_name', $name)->firstOrFail();
            $this->assertEqualsCanonicalizing($serviceNames, $professional->services()->pluck('name')->all());
        }

        $laura = $business->professionals()->where('first_name', 'Laura')->firstOrFail();
        $consultation = $business->services()->where('name', 'Initial beauty consultation')->firstOrFail();
        $massage = $business->services()->where('name', 'Wellness massage')->firstOrFail();
        $laura->services()->detach($consultation);
        $laura->services()->attach($massage);
        $extraService = Service::factory()->for($business)->create();
        $extraProfessional = Professional::factory()->for($business)->create();
        $laura->services()->attach($extraService);
        $extraProfessional->services()->attach($consultation);

        $this->seed();
        $this->seed();

        foreach ($expected as $name => $serviceNames) {
            $professional = $business->professionals()->where('first_name', $name)->firstOrFail();
            $this->assertEqualsCanonicalizing($serviceNames, $professional->services()->where('services.id', '!=', $extraService->id)->pluck('name')->all());
        }
        $this->assertDatabaseHas('professional_service', ['professional_id' => $laura->id, 'service_id' => $extraService->id]);
        $this->assertDatabaseHas('professional_service', ['professional_id' => $extraProfessional->id, 'service_id' => $consultation->id]);
        $this->assertDatabaseMissing('professional_service', ['professional_id' => $laura->id, 'service_id' => $massage->id]);
    }
}
