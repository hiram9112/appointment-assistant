<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_data_is_persisted_and_retrieved(): void
    {
        $customer = Customer::create([
            'first_name' => 'María José',
            'last_name' => 'García de la Peña',
            'phone' => '+34612345678',
        ]);

        $retrieved = Customer::findOrFail($customer->id);

        $this->assertSame('María José', $retrieved->first_name);
        $this->assertSame('García de la Peña', $retrieved->last_name);
        $this->assertSame('+34612345678', $retrieved->phone);
        $this->assertNotNull($retrieved->created_at);
        $this->assertNotNull($retrieved->updated_at);
    }

    public function test_customer_with_single_surname_and_no_phone_is_persisted(): void
    {
        $customer = Customer::factory()->create(['last_name' => 'Martín', 'phone' => null]);

        $retrieved = Customer::findOrFail($customer->id);

        $this->assertSame('Martín', $retrieved->last_name);
        $this->assertNull($retrieved->phone);
    }

    public function test_first_name_is_required(): void
    {
        $this->expectException(QueryException::class);

        Customer::factory()->create(['first_name' => null]);
    }

    public function test_last_name_is_required(): void
    {
        $this->expectException(QueryException::class);

        Customer::factory()->create(['last_name' => null]);
    }

    public function test_customers_with_the_same_full_name_remain_distinct(): void
    {
        $first = Customer::factory()->create(['first_name' => 'Sara', 'last_name' => 'Gómez']);
        $second = Customer::factory()->create(['first_name' => 'Sara', 'last_name' => 'Gómez']);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Customer::where('first_name', 'Sara')->where('last_name', 'Gómez')->count());
    }

    public function test_customers_can_share_a_phone_number(): void
    {
        $first = Customer::factory()->create(['first_name' => 'Ana', 'phone' => '+34612345678']);
        $second = Customer::factory()->create(['first_name' => 'Luis', 'phone' => '+34612345678']);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, Customer::where('phone', '+34612345678')->count());
    }
}
