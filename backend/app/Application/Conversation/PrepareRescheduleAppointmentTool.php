<?php

namespace App\Application\Conversation;

use App\Models\Business;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class PrepareRescheduleAppointmentTool implements Tool
{
    public function __construct(
        private Business $business,
        private AiTurnClaim $claim,
        private PrepareAppointmentProposal $prepare,
    ) {}

    public function description(): string
    {
        return 'Prepare, but never execute, rescheduling of one unambiguously identified Customer Appointment in the current Business. '
            .'Clarify ambiguous Appointment references. Omit professional_id or use null to retain the current Professional; only supply a different ID when requested by the Customer. '
            .'Use an exact Business-local RFC 3339 start with seconds and a valid explicit UTC offset. '
            .'Checks availability excluding the original Appointment, without reserving a slot. Returned structured original and proposed details are authoritative persisted data. '
            .'Use both sets of details, including any Professional change, to ask for explicit confirmation in natural, concise, professional Spanish, expressing dates naturally rather than exposing technical timestamps. '
            .'Preserve answers to other informational questions in the same response. Do not claim the Appointment has already been rescheduled.';
    }

    public function handle(Request $request): string
    {
        return json_encode($this->prepare->handle($this->business, $this->claim, AppointmentProposalOperation::Reschedule, $request->all()), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'appointment_id' => $schema->integer()->min(1)->max(PHP_INT_MAX)->description('Unambiguous ID returned by the current Customer Appointment read tool.')->required(),
            'professional_id' => $schema->integer()->min(1)->max(PHP_INT_MAX)->nullable()->description('Omit or use null to keep the existing Professional; change only when requested.'),
            'starts_at' => $schema->string()->format('date-time')->max(25)->description('Exact new Business-local start, YYYY-MM-DDTHH:MM:SS±HH:MM.')->required(),
        ];
    }
}
