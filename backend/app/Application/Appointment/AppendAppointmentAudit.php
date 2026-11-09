<?php

namespace App\Application\Appointment;

use App\Models\Appointment;
use App\Models\AppointmentAudit;

final class AppendAppointmentAudit
{
    /** Actor and source must come from a trusted application caller. */
    public function created(Appointment $appointment, string $actorType, string $actorId, string $source): AppointmentAudit
    {
        $persisted = $appointment->newQuery()->findOrFail($appointment->getKey());

        return $this->handle($persisted, 'created', $actorType, $actorId, $source, null, [
            'id' => $persisted->id,
            'customer_id' => $persisted->customer_id,
            'service_id' => $persisted->service_id,
            'professional_id' => $persisted->professional_id,
            'starts_at' => $persisted->starts_at->utc()->toJSON(),
            'ends_at' => $persisted->ends_at->utc()->toJSON(),
            'buffer_before_minutes' => $persisted->buffer_before_minutes,
            'buffer_after_minutes' => $persisted->buffer_after_minutes,
            'status' => $persisted->status,
            'created_at' => $persisted->created_at->utc()->toJSON(),
            'updated_at' => $persisted->updated_at->utc()->toJSON(),
        ]);
    }

    /**
     * Actor and source must come from a trusted application caller.
     *
     * @param  array<string, mixed>|null  $beforeState
     * @param  array<string, mixed>  $afterState
     */
    public function handle(Appointment $appointment, string $action, string $actorType, string $actorId, string $source, ?array $beforeState, array $afterState, bool $lateNotice = false, ?\DateTimeInterface $occurredAt = null): AppointmentAudit
    {
        return $appointment->audits()->create([
            'action' => $action,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'source' => $source,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'occurred_at' => $occurredAt ?? now(),
            'late_notice' => $lateNotice,
        ]);
    }
}
