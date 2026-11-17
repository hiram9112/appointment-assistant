<?php

namespace App\Application\Conversation;

use App\Application\Appointment\CustomerAppointment;
use App\Application\Appointment\GetCustomerAppointments;
use App\Application\Appointment\GetCustomerAppointmentsOutcome;
use App\Application\Customer\ResolveCustomer;
use App\Models\AiTurn;
use App\Models\Business;
use App\Models\Conversation;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class GetCustomerAppointmentsTool implements Tool
{
    public function __construct(
        private Business $business,
        private AiTurnClaim $claim,
        private AiTurnCoordinator $turns,
        private ResolveCustomer $resolve,
        private GetCustomerAppointments $appointments,
    ) {}

    public function description(): string
    {
        return 'List the identified current Customer’s Appointments in the current Business, including history and all statuses. '
            .'No identity or scope arguments are accepted; Laravel resolves the trusted channel identity on every call, including after registration. '
            .'Distinguish no Appointments from failed identification. Clarify ambiguous references when several Appointments are returned.';
    }

    public function handle(Request $request): string
    {
        $result = $this->turns->withOwnership($this->business, $this->claim, function (AiTurn $turn, Conversation $conversation): array {
            try {
                $customer = $this->resolve->handle($conversation->channel, $conversation->sender_identity);
            } catch (ValidationException) {
                return ['status' => 'identification_failed'];
            }
            $appointments = $this->appointments->handle($this->business, $customer?->id);
            if ($appointments === GetCustomerAppointmentsOutcome::IdentificationFailed) {
                return ['status' => 'identification_failed'];
            }

            return [
                'status' => $appointments === [] ? 'no_appointments' : 'ok',
                'appointments' => array_map(fn (CustomerAppointment $appointment): array => [
                    'id' => $appointment->id,
                    'service_name' => $appointment->service_name,
                    'professional_name' => $appointment->professional_name,
                    'starts_at' => $appointment->starts_at->format(DATE_ATOM),
                    'ends_at' => $appointment->ends_at->format(DATE_ATOM),
                    'status' => $appointment->status,
                ], $appointments),
            ];
        });

        return json_encode(
            $result instanceof AiTurnOutcome ? ['status' => 'not_authorized', 'reason' => $result->name] : $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
