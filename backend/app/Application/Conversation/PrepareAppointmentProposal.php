<?php

namespace App\Application\Conversation;

use App\Application\Availability\CalculateAvailability;
use App\Application\Customer\ResolveCustomer;
use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Professional;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PrepareAppointmentProposal
{
    public function __construct(
        private AiTurnCoordinator $turns,
        private AppointmentProposals $proposals,
        private ResolveCustomer $resolve,
        private CalculateAvailability $availability,
        private BusinessLocalTimestamp $timestamps,
    ) {}

    /**
     * Only proposal persistence is authorized here; no Appointment capability is invoked.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function handle(Business $business, AiTurnClaim $claim, AppointmentProposalOperation $operation, array $arguments): array
    {
        try {
            $result = $this->turns->withOwnership($business, $claim, function (AiTurn $turn, Conversation $conversation) use ($business, $claim, $operation, $arguments): array {
                try {
                    $customer = $this->resolve->handle($conversation->channel, $conversation->sender_identity);
                } catch (ValidationException) {
                    return ['status' => 'identification_failed'];
                }
                if ($customer === null) {
                    return ['status' => 'identification_failed'];
                }
                $business = Business::query()->findOrFail($business->id);
                $idRule = ['integer:strict', 'min:1', 'max:'.PHP_INT_MAX];
                $rules = match ($operation) {
                    AppointmentProposalOperation::Create => [
                        'service_id' => ['required', ...$idRule], 'professional_id' => ['required', ...$idRule],
                        'starts_at' => ['required', 'string', 'max:25'],
                    ],
                    AppointmentProposalOperation::Reschedule => [
                        'appointment_id' => ['required', ...$idRule], 'professional_id' => ['nullable', ...$idRule],
                        'starts_at' => ['required', 'string', 'max:25'],
                    ],
                    AppointmentProposalOperation::Cancel => ['appointment_id' => ['required', ...$idRule]],
                };
                $data = Validator::make($arguments, $rules)->validate();
                $start = isset($data['starts_at'])
                    ? CarbonImmutable::instance($this->timestamps->parse($data['starts_at'], new DateTimeZone($business->timezone), 'starts_at'))->utc()
                    : null;
                $existing = AppointmentProposal::query()->where('originating_turn_id', $turn->id)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->operation !== $operation) {
                        return ['status' => 'parameters_conflict'];
                    }
                    $payload = match ($operation) {
                        AppointmentProposalOperation::Create => AppointmentProposalPayload::create($data['service_id'], $data['professional_id'], $start),
                        AppointmentProposalOperation::Reschedule => AppointmentProposalPayload::reschedule($data['appointment_id'], $data['professional_id'] ?? $existing->payload['professional_id'], $start),
                        AppointmentProposalOperation::Cancel => AppointmentProposalPayload::cancel($data['appointment_id']),
                    };

                    return $existing->payload == $payload->toArray()
                        ? $existing->structuredDetails() : ['status' => 'parameters_conflict'];
                }

                $appointment = null;
                $previous = null;
                if ($operation !== AppointmentProposalOperation::Create) {
                    $appointment = Appointment::query()->whereKey($data['appointment_id'])->where('customer_id', $customer->id)
                        ->whereHas('service', fn ($query) => $query->where('business_id', $business->id))
                        ->whereHas('professional', fn ($query) => $query->where('business_id', $business->id))
                        ->with(['service', 'professional'])->first();
                    if ($appointment === null) {
                        return ['status' => 'invalid_reference'];
                    }
                    if ($appointment->status !== 'confirmed' || $appointment->starts_at <= now()) {
                        return ['status' => 'not_eligible'];
                    }
                    $previous = new AppointmentProposalPresentation(
                        $appointment->service->name, $this->professionalName($appointment->professional),
                        $appointment->starts_at, $appointment->ends_at, $business->timezone,
                    );
                    if ($operation === AppointmentProposalOperation::Cancel) {
                        return $this->prepared($business, $claim, AppointmentProposalPayload::cancel($appointment->id), $previous);
                    }
                }

                $service = $business->services()->where('active', true)->find($appointment?->service_id ?? $data['service_id']);
                $professional = $business->professionals()->find($data['professional_id'] ?? $appointment?->professional_id);
                if ($service === null || $professional === null || ! $professional->services()->whereKey($service->id)->exists()) {
                    return ['status' => 'invalid_reference'];
                }
                $instant = DateTimeImmutable::createFromInterface($start);
                $ranges = $appointment === null
                    ? $this->availability->handle($business, $service, $professional, $instant, $instant)
                    : $this->availability->forRescheduling($business, $service, $professional, $instant, $instant, $appointment->id);
                if ($ranges === []) {
                    return ['status' => 'slot_unavailable'];
                }
                $payload = $appointment === null
                    ? AppointmentProposalPayload::create($service->id, $professional->id, $start)
                    : AppointmentProposalPayload::reschedule($appointment->id, $professional->id, $start);
                $presentation = new AppointmentProposalPresentation(
                    $service->name, $this->professionalName($professional), $start,
                    $start->addMinutes($service->duration_minutes), $business->timezone, $previous,
                );

                return $this->prepared($business, $claim, $payload, $presentation);
            });
        } catch (ValidationException $exception) {
            return ['status' => 'clarification_required', 'errors' => $exception->errors()];
        }

        return $result instanceof AiTurnOutcome ? ['status' => 'not_authorized', 'reason' => $result->name] : $result;
    }

    /** @return array<string, mixed> */
    private function prepared(Business $business, AiTurnClaim $claim, AppointmentProposalPayload $payload, AppointmentProposalPresentation $presentation): array
    {
        $proposal = $this->proposals->prepare($business, $claim, $payload, $presentation);

        return $proposal instanceof AppointmentProposal ? $proposal->structuredDetails() : ['status' => 'not_prepared', 'reason' => $proposal->name];
    }

    private function professionalName(Professional $professional): string
    {
        return $professional->display_name ?? $professional->first_name.' '.$professional->last_name;
    }
}
