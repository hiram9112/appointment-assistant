<?php

namespace Database\Factories;

use App\Models\ChannelIdentity;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelIdentity>
 */
class ChannelIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'channel' => 'whatsapp',
            'external_id' => fake()->uuid(),
        ];
    }
}
