<?php

namespace Database\Factories;

use App\Application\Conversation\AppointmentProposalPayload;
use App\Application\Conversation\AppointmentProposalPresentation;
use App\Models\AiTurn;
use App\Models\AppointmentProposal;
use App\Models\Conversation;
use App\Models\Professional;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentProposal>
 */
class AppointmentProposalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'originating_turn_id' => fn (array $attributes): int => AiTurn::query()->create([
                'inbound_message_id' => Conversation::findOrFail($attributes['conversation_id'])->messages()
                    ->create(['role' => 'user', 'content' => 'Book an Appointment'])->id,
            ])->id,
            'operation' => 'create',
            'payload' => function (array $attributes): array {
                $conversation = Conversation::findOrFail($attributes['conversation_id']);

                return AppointmentProposalPayload::create(
                    Service::factory()->create(['business_id' => $conversation->business_id])->id,
                    Professional::factory()->create(['business_id' => $conversation->business_id, 'display_name' => 'Dra. Laura'])->id,
                    CarbonImmutable::parse('2030-02-01 10:00:00 UTC'),
                )->toArray();
            },
            'presentation' => fn (array $attributes): array => (new AppointmentProposalPresentation(
                Service::findOrFail($attributes['payload']['service_id'])->name,
                Professional::findOrFail($attributes['payload']['professional_id'])->display_name,
                CarbonImmutable::parse($attributes['payload']['starts_at']),
                CarbonImmutable::parse($attributes['payload']['starts_at'])->addHour(),
                Conversation::findOrFail($attributes['conversation_id'])->business->timezone,
            ))->toArray(),
        ];
    }
}
