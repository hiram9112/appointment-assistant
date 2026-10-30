<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Professional;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfessionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_professional_identity_and_business_relationships_are_persisted(): void
    {
        $business = Business::factory()->create();
        $professional = Professional::factory()->for($business)->create(['first_name' => 'Laura', 'last_name' => 'Martín', 'display_name' => 'Laura M.']);
        Professional::factory()->create();

        $retrieved = Professional::findOrFail($professional->id);
        $this->assertSame('Laura', $retrieved->first_name);
        $this->assertSame('Martín', $retrieved->last_name);
        $this->assertSame('Laura M.', $retrieved->display_name);
        $this->assertTrue($retrieved->business->is($business));
        $this->assertSame([$professional->id], $business->professionals()->pluck('id')->all());
        $this->assertNotNull($retrieved->created_at);
        $this->assertNotNull($retrieved->updated_at);
    }

    public function test_professionals_with_the_same_name_remain_distinct_by_id(): void
    {
        $business = Business::factory()->create();
        $first = Professional::factory()->for($business)->create(['first_name' => 'Sara', 'last_name' => 'Gómez', 'display_name' => 'Sara G.']);
        $second = Professional::factory()->for($business)->create(['first_name' => 'Sara', 'last_name' => 'Gómez', 'display_name' => 'Sara G.']);
        $third = Professional::factory()->for($business)->create(['first_name' => 'Sara', 'last_name' => 'Ruiz', 'display_name' => null]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($second->id, $third->id);
        $this->assertSame('Sara G.', $second->fresh()->display_name);
        $this->assertNull($third->fresh()->display_name);
        $this->assertCount(3, $business->professionals()->where('first_name', 'Sara')->get());
    }

    public function test_first_name_is_required(): void
    {
        $this->expectException(QueryException::class);
        Professional::factory()->create(['first_name' => null]);
    }

    public function test_last_name_is_required(): void
    {
        $this->expectException(QueryException::class);
        Professional::factory()->create(['last_name' => null]);
    }

    public function test_reference_professionals_are_seeded_and_reseeding_preserves_their_ids(): void
    {
        $this->seed();
        $business = Business::where('name', 'Lúmina Estética Avanzada')->firstOrFail();
        $original = $business->professionals()->orderBy('first_name')->get();

        $this->assertSame([
            ['Claudia', 'Pérez', null],
            ['Laura', 'Martín', null],
            ['Marta', 'Ruiz', null],
            ['Sara', 'Gómez', null],
        ], $original->map(fn (Professional $professional) => [$professional->first_name, $professional->last_name, $professional->display_name])->all());

        $canonical = $original->firstWhere('first_name', 'Laura');
        $canonical->update(['display_name' => 'Laura M.']);
        $unrelated = Professional::factory()->for($business)->create(['first_name' => 'Sara', 'last_name' => 'Ruiz', 'display_name' => 'Sara R.']);
        $otherBusiness = Business::factory()->create();
        $other = Professional::factory()->for($otherBusiness)->create(['first_name' => 'Laura', 'last_name' => 'Martín', 'display_name' => 'Other Laura']);
        $this->seed();
        $this->seed();

        $this->assertSame($original->pluck('id')->all(), $business->professionals()->whereIn('id', $original->pluck('id'))->orderBy('first_name')->pluck('id')->all());
        $this->assertSame($canonical->id, $business->professionals()->where('first_name', 'Laura')->where('last_name', 'Martín')->firstOrFail()->id);
        $this->assertNull($canonical->fresh()->display_name);
        $this->assertSame(5, $business->professionals()->count());
        $this->assertSame(0, $business->professionals()->whereIn('id', $original->pluck('id'))->whereNotNull('display_name')->count());
        $this->assertSame('Sara R.', $unrelated->fresh()->display_name);
        $this->assertSame('Other Laura', $other->fresh()->display_name);
        $this->assertSame(1, $otherBusiness->professionals()->count());
    }
}
