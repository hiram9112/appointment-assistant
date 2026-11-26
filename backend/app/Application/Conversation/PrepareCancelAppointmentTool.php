<?php

namespace App\Application\Conversation;

use App\Models\Business;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class PrepareCancelAppointmentTool implements Tool
{
    public function __construct(
        private Business $business,
        private AiTurnClaim $claim,
        private PrepareAppointmentProposal $prepare,
    ) {}

    public function description(): string
    {
        return 'Prepare, but never execute, cancellation of one unambiguously identified future confirmed Customer Appointment in the current Business. '
            .'Clarify missing or ambiguous Appointment references instead of guessing. '
            .'Laravel resolves identity; returned structured proposal details are authoritative persisted data. '
            .'Use those details to ask for explicit confirmation in natural, concise, professional Spanish, expressing dates naturally rather than exposing technical timestamps. '
            .'Preserve answers to other informational questions in the same response. Do not claim the Appointment has already been cancelled.';
    }

    public function handle(Request $request): string
    {
        return json_encode($this->prepare->handle($this->business, $this->claim, AppointmentProposalOperation::Cancel, $request->all()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'appointment_id' => $schema->integer()->min(1)->max(PHP_INT_MAX)->description('Unambiguous ID returned by the current Customer Appointment read tool.')->required(),
        ];
    }
}
