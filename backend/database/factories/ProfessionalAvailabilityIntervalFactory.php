<?php

namespace Database\Factories;

use App\Models\Professional;
use App\Models\ProfessionalAvailabilityInterval;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfessionalAvailabilityInterval>
 */
class ProfessionalAvailabilityIntervalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'professional_id' => Professional::factory(),
            'weekday' => 'monday',
            'starts_at' => '09:00:00',
            'ends_at' => '17:00:00',
        ];
    }
}
