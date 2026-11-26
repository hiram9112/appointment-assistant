<?php

namespace App\Application\Conversation;

use App\Models\Business;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class PrepareCreateAppointmentTool implements Tool
{
    public function __construct(
        private Business $business,
        private AiTurnClaim $claim,
        private PrepareAppointmentProposal $prepare,
    ) {}

    public function description(): string
    {
        return 'Prepare, but never create, an Appointment for the identified Customer in the current Business. '
            .'First establish one canonical Service, an explicitly Customer-selected eligible Professional, and an exact start. '
            .'Clarify missing or ambiguous references; never assign or substitute a Professional automatically. '
            .'Use a Business-local RFC 3339 start with seconds and a valid explicit UTC offset. '
            .'Availability is informational, not reserved. Returned structured proposal details are authoritative persisted data. '
            .'Use those details to ask for explicit confirmation in natural, concise, professional Spanish, expressing dates naturally rather than exposing technical timestamps. '
            .'Preserve answers to other informational questions in the same response. Do not claim the Appointment has already been created.';
    }

    public function handle(Request $request): string
    {
        return json_encode($this->prepare->handle($this->business, $this->claim, AppointmentProposalOperation::Create, $request->all()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'service_id' => $schema->integer()->min(1)->max(PHP_INT_MAX)->description('Unambiguous active Service ID from current Business context.')->required(),
            'professional_id' => $schema->integer()->min(1)->max(PHP_INT_MAX)->description('Professional ID explicitly selected by the Customer.')->required(),
            'starts_at' => $schema->string()->format('date-time')->max(25)->description('Exact Business-local start, YYYY-MM-DDTHH:MM:SS±HH:MM.')->required(),
        ];
    }
}
