<?php

namespace App\Application\Conversation;

use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\ConversationMessage;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

final class ConversationTurnProcessor
{
    public function __construct(
        private AiTurnCoordinator $turns,
        private BusinessContextBuilder $context,
        private ConversationHistoryAdapter $history,
        private AppointmentProposals $proposals,
    ) {}

    /** Process or resume one participant's next turn; identities come from trusted application code. */
    public function processNext(Business $business, string $channel, string $senderIdentity, int $leaseSeconds = 300): ConversationMessage|AiTurnOutcome
    {
        return $this->turns->processNext($business, $channel, $senderIdentity, function (AiTurnClaim $claim) use ($business): string {
            $proposal = $this->proposals->recover($business, $claim);
            $business = Business::query()->findOrFail($business->id);
            $operationTime = DateTimeImmutable::createFromInterface(now());
            $context = $this->context->handle($business);
            $context['operation_time'] = $operationTime->setTimezone(new DateTimeZone($context['business']['timezone']))->format(DATE_ATOM);
            if ($proposal instanceof AppointmentProposal && $proposal->state === AppointmentProposalState::Prepared) {
                $context['prepared_proposal'] = $proposal->structuredDetails();
            }

            $completed = $this->turns->completedBefore($business, $claim->inboundMessage->id);
            if ($completed instanceof AiTurnOutcome) {
                throw new LogicException('The claimed inbound message is no longer available.');
            }

            return ConversationAgent::make(
                runtimeContext: $context,
                availabilityTool: app()->makeWith(CheckAvailabilityTool::class, ['business' => $business]),
            )->withMessages($this->history->completedTurns($completed))
                ->prompt($claim->inboundMessage->content)->text;
        }, $leaseSeconds);
    }
}
