<?php

namespace App\Application\Conversation;

use App\Application\Customer\ResolveCustomer;
use App\Models\AiTurn;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use LogicException;

final class ConversationTurnProcessor
{
    public function __construct(
        private AiTurnCoordinator $turns,
        private BusinessContextBuilder $context,
        private ConversationHistoryAdapter $history,
        private AppointmentProposals $proposals,
        private ConfirmAppointmentProposal $confirmations,
        private ResolveCustomer $customers,
        private EmergencyConversationResponse $emergencyResponse,
    ) {}

    /** Process or resume one participant's next turn; identities come from trusted application code. */
    public function processNext(
        Business $business, string $channel, string $senderIdentity, int $leaseSeconds = 300, ?int $expectedConversationId = null,
    ): ConversationMessage|AiTurnOutcome {
        $result = $this->turns->processNext($business, $channel, $senderIdentity, function (AiTurnClaim $claim) use ($business): string {
            $executionContext = $this->executionContext($business, $claim);
            if ($executionContext instanceof AiTurnOutcome) {
                throw new LogicException('The claimed turn is no longer authorized.');
            }
            $business = Business::query()->findOrFail($business->id);
            $operationTime = DateTimeImmutable::createFromInterface(now());
            $context = $this->context->handle($business);
            $context['operation_time'] = $operationTime->setTimezone(new DateTimeZone($context['business']['timezone']))->format(DATE_ATOM);
            $context = [...$context, ...$executionContext];

            $completed = $this->turns->completedBefore($business, $claim->inboundMessage->id);
            if ($completed instanceof AiTurnOutcome) {
                throw new LogicException('The claimed inbound message is no longer available.');
            }

            return ConversationAgent::make(
                runtimeContext: $context,
                availabilityTool: app()->makeWith(CheckAvailabilityTool::class, ['business' => $business]),
                appointmentTools: array_map(
                    fn (string $tool) => app()->makeWith($tool, ['business' => $business, 'claim' => $claim]),
                    [
                        RegisterCustomerTool::class,
                        GetCustomerAppointmentsTool::class,
                        PrepareCreateAppointmentTool::class,
                        PrepareRescheduleAppointmentTool::class,
                        PrepareCancelAppointmentTool::class,
                        ConfirmAppointmentProposalTool::class,
                    ],
                ),
            )->withMessages($this->history->completedTurns($completed))
                ->prompt($claim->inboundMessage->content)->text;
        }, $leaseSeconds, $expectedConversationId);

        if ($result !== AiTurnOutcome::FailureResolutionPending) {
            return $result;
        }

        $claim = $this->turns->claimFinalizationNext($business, $channel, $senderIdentity, $leaseSeconds, $expectedConversationId);
        if ($claim instanceof AiTurnOutcome) {
            return $claim;
        }

        return $this->turns->finalize($business, $claim, function (AiTurn $turn, Conversation $conversation) use ($business): string {
            if ($conversation->customer_id === null) {
                // A crash before normal context loading can leave the first turn unassociated.
                $this->customerContext($business, $turn, $conversation);

                return $this->emergencyResponse->build(null);
            }

            return $this->emergencyResponse->build($this->confirmations->recoverForTurn($turn, $conversation));
        });
    }

    /**
     * Reject identity failures before loading protected context or completing a proposal-originating turn.
     *
     * @return array<string, mixed>|AiTurnOutcome
     */
    private function executionContext(Business $business, AiTurnClaim $claim): array|AiTurnOutcome
    {
        return $this->turns->withOwnership($business, $claim, function (AiTurn $turn, Conversation $conversation) use ($business, $claim): array {
            $customer = $this->customerContext($business, $turn, $conversation);
            if (isset($customer['error'])) {
                throw new LogicException($customer['error']);
            }
            $context = ['customer' => $customer, 'active_proposal' => null];
            if ($customer['status'] !== 'identified') {
                return $context;
            }

            $active = $this->proposals->active($business, $claim);
            if ($active instanceof AppointmentProposal) {
                $context['active_proposal'] = [
                    ...$active->structuredDetails(),
                    'confirmation_response_is_later' => $active->confirmation_request_message_id !== null
                        && $turn->inbound_message_id > $active->confirmation_request_message_id,
                ];
            }
            $proposal = $this->proposals->recover($business, $claim);
            if ($proposal instanceof AppointmentProposal && $proposal->state === AppointmentProposalState::Prepared) {
                $context['prepared_proposal'] = $proposal->structuredDetails();
            }
            $result = $this->confirmations->recover($business, $claim);
            if (is_array($result)) {
                $context['appointment_operation_result'] = $result;
            }

            return $context;
        });
    }

    /** @return array{status: string, id?: int, first_name?: string, last_name?: string, error?: string} */
    private function customerContext(Business $business, AiTurn $turn, Conversation $conversation): array
    {
        $hasProposal = $conversation->customer_id === null && AppointmentProposal::query()->where('conversation_id', $conversation->id)->exists();
        $identityFailure = ['status' => 'identification_failed', 'error' => 'Customer identity could not be verified for the Conversation.'];
        try {
            $customer = $this->customers->handle($conversation->channel, $conversation->sender_identity);
        } catch (ValidationException) {
            return $conversation->customer_id !== null || $hasProposal ? $identityFailure : ['status' => 'unsupported_channel'];
        }

        if (($conversation->customer_id !== null && $conversation->customer_id !== $customer?->id) || $hasProposal) {
            return $identityFailure;
        }
        if ($customer === null) {
            return ['status' => 'unregistered'];
        }
        if ($conversation->customer_id === null) {
            $completed = $this->turns->completedBefore($business, $turn->inbound_message_id);
            if ($completed instanceof AiTurnOutcome || $completed->isNotEmpty()) {
                return ['status' => 'identification_failed', 'error' => 'Customer identity could not be verified for the Conversation history.'];
            }
            $conversation->customer()->associate($customer);
            if (! $conversation->save()) {
                throw new LogicException('The verified Customer could not be associated with the Conversation.');
            }
        }

        return [
            'status' => 'identified', 'id' => $customer->id,
            'first_name' => $customer->first_name, 'last_name' => $customer->last_name,
        ];
    }
}
