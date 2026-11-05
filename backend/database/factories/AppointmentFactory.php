<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
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
            'service_id' => Service::factory(),
            'professional_id' => fn (array $attributes) => Professional::factory()->create([
                'business_id' => Service::findOrFail($attributes['service_id'])->business_id,
            ])->id,
            'starts_at' => '2030-01-01 10:00:00+00:00',
            'ends_at' => '2030-01-01 11:00:00+00:00',
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'status' => 'confirmed',
        ];
    }
}
