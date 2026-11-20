<?php

namespace App\Application\Conversation;

use App\Models\Business;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class ConfirmAppointmentProposalTool implements Tool
{
    public function __construct(
        private Business $business,
        private AiTurnClaim $claim,
        private ConfirmAppointmentProposal $confirm,
    ) {}

    public function description(): string
    {
        return 'Confirm or decline the current persisted proposal only after a subsequent explicit Customer response to the accepted confirmation question. '
            .'Use confirm only for unconditional affirmative acceptance; conditional, ambiguous, or changed preferences require clarification or a new proposal, never execution of the old one. '
            .'Laravel supplies all identity and operation parameters. Report only the authoritative returned result, including rejection or unchanged rescheduling; never invent success. '
            .'no_pending_proposal does not negate earlier success; verify current state via GetCustomerAppointmentsTool.';
    }

    public function handle(Request $request): string
    {
        try {
            $data = Validator::make($request->all(), ['decision' => ['required', 'string', 'in:confirm,decline']])->validate();
            $result = $this->confirm->handle($this->business, $this->claim, $data['decision'] === 'confirm');
        } catch (ValidationException $exception) {
            $result = ['status' => 'invalid_arguments', 'errors' => $exception->errors()];
        }

        return json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['decision' => $schema->string()->enum(['confirm', 'decline'])->required()];
    }
}
