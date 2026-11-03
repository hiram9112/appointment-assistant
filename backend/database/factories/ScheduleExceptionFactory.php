<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\ScheduleException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleException>
 */
class ScheduleExceptionFactory extends Factory
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
            'professional_id' => null,
            'starts_at' => '2026-11-10 08:00:00',
            'ends_at' => '2026-11-10 11:00:00',
            'kind' => 'blocked',
            'reason' => null,
        ];
    }
}
