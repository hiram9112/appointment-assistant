<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentAudit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppointmentAudit> */
class AppointmentAuditFactory extends Factory
{
    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'action' => 'created',
            'actor_type' => 'system',
            'actor_id' => 'scheduler',
            'source' => 'internal',
            'before_state' => null,
            'after_state' => ['status' => 'confirmed'],
            'occurred_at' => now(),
        ];
    }
}
