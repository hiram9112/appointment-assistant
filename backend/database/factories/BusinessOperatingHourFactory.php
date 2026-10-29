<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\BusinessOperatingHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessOperatingHour>
 */
class BusinessOperatingHourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_id' => Business::factory(),
            'weekday' => fake()->randomElement(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']),
            'opens_at' => '09:00:00',
            'closes_at' => '17:00:00',
        ];
    }
}
