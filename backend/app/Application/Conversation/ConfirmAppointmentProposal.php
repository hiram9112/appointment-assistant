<?php

namespace App\Application\Conversation;

use App\Application\Appointment\CancelAppointment;
use App\Application\Appointment\CreateAppointment;
use App\Application\Appointment\RescheduleAppointment;
use App\Application\Customer\ResolveCustomer;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Customer;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ConfirmAppointmentProposal
{
    public function __construct(
        private AiTurnCoordinator $turns,
        private ResolveCustomer $resolve,
        private CreateAppointment $create,
        private RescheduleAppointment $reschedule,
        private CancelAppointment $cancel,
    ) {}

    /** The decision is model-interpreted; all operation parameters come from persistence.
     * @return array<string, mixed>
     */
    public function handle(Business $business, AiTurnClaim $claim, bool $confirmed): array
    {
        $result = $this->turns->withOwnership($business, $claim, function (AiTurn $turn, Conversation $conversation) use ($business, $confirmed): array {
            $customer = $this->customer($conversation);
            if ($customer === null) {
                return ['status' => 'identification_failed'];
            }
            $recovered = $this->resultForTurn($conversation, $turn, $customer);
            if ($recovered !== null) {
                return $recovered;
            }

            $proposal = AppointmentProposal::query()->where('conversation_id', $conversation->id)
                ->whereIn('state', [AppointmentProposalState::Prepared, AppointmentProposalState::Pending])
                ->lockForUpdate()->first();
            if ($proposal === null) {
                return ['status' => 'no_pending_proposal'];
            }
            $origin = $proposal->originatingTurn;
            $request = $proposal->confirmationRequestMessage;
            if ($proposal->state !== AppointmentProposalState::Pending || $request === null
                || $request->role !== 'assistant' || $request->conversation_id !== $conversation->id
                || $origin->state !== AiTurnState::Completed || $origin->assistant_message_id !== $request->id
                || $turn->inboundMessage->role !== 'user' || $turn->inbound_message_id <= $request->id) {
                return ['status' => 'confirmation_required'];
            }

            $base = [
                'proposal_id' => $proposal->id, 'operation' => $proposal->operation->value,
                'business_id' => $business->id, 'customer_id' => $customer->id,
            ];
            if (! $confirmed) {
                return $this->persist($proposal, $turn, [...$base, 'status' => 'rejected', 'outcome' => 'declined']);
            }

            $payload = $proposal->canonicalPayload();
            $appointment = null;
            if ($payload->appointmentId !== null) {
                $appointment = Appointment::query()->whereKey($payload->appointmentId)->where('customer_id', $customer->id)
                    ->whereHas('service', fn ($query) => $query->where('business_id', $business->id))
                    ->whereHas('professional', fn ($query) => $query->where('business_id', $business->id))
                    ->lockForUpdate()->first();
                if ($appointment === null) {
                    return $this->persist($proposal, $turn, [...$base, 'status' => 'rejected', 'outcome' => 'invalid_appointment']);
                }
            }
            $business = Business::query()->findOrFail($business->id);
            $before = $appointment === null ? null : $this->snapshot($appointment, $business);
            $actorId = (string) $customer->id;
            $result = match ($proposal->operation) {
                AppointmentProposalOperation::Create => $this->create->handle(
                    $business, $customer->id, $payload->serviceId, $payload->professionalId,
                    DateTimeImmutable::createFromInterface($payload->startsAt), 'customer', $actorId, $conversation->channel,
                ),
                AppointmentProposalOperation::Reschedule => $this->reschedule->handle(
                    $business, $customer->id, $payload->appointmentId, DateTimeImmutable::createFromInterface($payload->startsAt),
                    $payload->professionalId, 'customer', $actorId, $conversation->channel,
                ),
                AppointmentProposalOperation::Cancel => $this->cancel->handle(
                    $business, $customer->id, $payload->appointmentId, 'customer', $actorId, $conversation->channel,
                ),
            };
            if (! $result instanceof Appointment) {
                return $this->persist($proposal, $turn, [...$base, 'status' => 'rejected', 'outcome' => Str::snake($result->name)]);
            }
            $after = $this->snapshot($result, $business);
            $outcome = match ($proposal->operation) {
                AppointmentProposalOperation::Create => 'created',
                AppointmentProposalOperation::Cancel => 'cancelled',
                AppointmentProposalOperation::Reschedule => $before === $after ? 'unchanged' : 'rescheduled',
            };

            return $this->persist($proposal, $turn, [
                ...$base, 'status' => 'completed', 'outcome' => $outcome,
                'changed' => $outcome !== 'unchanged', 'appointment' => $after, 'previous' => $before,
            ]);
        });

        return $result instanceof AiTurnOutcome ? ['status' => 'not_authorized', 'reason' => $result->name] : $result;
    }

    /** Recover only this confirming turn, before another provider attempt.
     * @return array<string, mixed>|AiTurnOutcome|null
     */
    public function recover(Business $business, AiTurnClaim $claim): array|AiTurnOutcome|null
    {
        return $this->turns->withOwnership($business, $claim, function (AiTurn $turn, Conversation $conversation): ?array {
            $customer = $this->customer($conversation);

            return $customer === null ? null : $this->resultForTurn($conversation, $turn, $customer);
        });
    }

    /** A missing association is never ownership proof for an existing proposal. */
    private function customer(Conversation $conversation): ?Customer
    {
        try {
            $customer = $this->resolve->handle($conversation->channel, $conversation->sender_identity);
        } catch (ValidationException) {
            return null;
        }

        return $customer !== null && ($conversation->customer_id === $customer->id
            || ($conversation->customer_id === null && ! AppointmentProposal::query()->where('conversation_id', $conversation->id)->exists()))
            ? $customer : null;
    }

    /** @return array<string, mixed>|null */
    private function resultForTurn(Conversation $conversation, AiTurn $turn, Customer $customer): ?array
    {
        $proposal = AppointmentProposal::query()->where('conversation_id', $conversation->id)
            ->where('confirming_turn_id', $turn->id)->lockForUpdate()->first();

        return $proposal !== null && ($proposal->result['customer_id'] ?? null) === $customer->id
            ? $proposal->result : null;
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function persist(AppointmentProposal $proposal, AiTurn $turn, array $result): array
    {
        $proposal->update([
            'state' => $result['status'] === 'completed' ? AppointmentProposalState::Completed : AppointmentProposalState::Rejected,
            'confirming_turn_id' => $turn->id, 'result' => $result,
        ]);

        return $proposal->fresh()->result;
    }

    /** Snapshot authoritative persisted state, never prior assistant prose.
     * @return array<string, mixed>
     */
    private function snapshot(Appointment $appointment, Business $business): array
    {
        $appointment->load(['service', 'professional']);
        $professional = $appointment->professional;

        return [
            'id' => $appointment->id, 'service_id' => $appointment->service_id,
            'professional_id' => $appointment->professional_id, 'service_name' => $appointment->service->name,
            'professional_name' => $professional->display_name ?? $professional->first_name.' '.$professional->last_name,
            'starts_at' => $appointment->starts_at->setTimezone($business->timezone)->toIso8601String(),
            'ends_at' => $appointment->ends_at->setTimezone($business->timezone)->toIso8601String(),
            'timezone' => $business->timezone, 'status' => $appointment->status,
            'buffer_before_minutes' => $appointment->buffer_before_minutes, 'buffer_after_minutes' => $appointment->buffer_after_minutes,
        ];
    }
}
