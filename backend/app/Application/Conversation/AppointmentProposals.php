<?php

namespace App\Application\Conversation;

use App\Models\AiTurn;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Business;
use App\Models\Conversation;
use App\Models\Professional;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AppointmentProposals
{
    public function __construct(
        private AiTurnCoordinator $turns,
        private ConversationParticipantLock $lock,
    ) {}

    /** The caller supplies validated canonical details, never raw model tool arguments. */
    public function prepare(
        Business $business,
        AiTurnClaim $claim,
        AppointmentProposalPayload $payload,
        AppointmentProposalPresentation $presentation,
    ): AppointmentProposal|AppointmentProposalOutcome|AiTurnOutcome {
        return $this->turns->withOwnership($business, $claim, function (AiTurn $turn, Conversation $conversation) use ($business, $payload, $presentation): AppointmentProposal|AppointmentProposalOutcome {
            $existing = AppointmentProposal::query()->where('originating_turn_id', $turn->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing->operation === $payload->operation && $existing->payload == $payload->toArray()
                    && $existing->presentation == $presentation->toArray()
                    ? $existing : AppointmentProposalOutcome::ParametersConflict;
            }
            if (! $this->inBusiness($business, $payload)
                || ($payload->startsAt !== null && ! $payload->startsAt->equalTo($presentation->startsAt))
                || ($payload->operation === AppointmentProposalOperation::Reschedule) !== ($presentation->previous !== null)
                || $presentation->timezone !== $business->timezone) {
                return AppointmentProposalOutcome::InvalidScope;
            }

            $active = $this->activeQuery($conversation->id)->lockForUpdate()->first();
            if ($active) {
                $active->update(['state' => AppointmentProposalState::Rejected]);
            }

            return AppointmentProposal::query()->create([
                'conversation_id' => $conversation->id, 'originating_turn_id' => $turn->id,
                'operation' => $payload->operation, 'payload' => $payload->toArray(),
                'presentation' => $presentation->toArray(),
            ]);
        });
    }

    public function recover(Business $business, AiTurnClaim $claim): AppointmentProposal|AppointmentProposalOutcome|AiTurnOutcome
    {
        return $this->turns->withOwnership($business, $claim, fn (AiTurn $turn): AppointmentProposal|AppointmentProposalOutcome => AppointmentProposal::query()
            ->where('originating_turn_id', $turn->id)->lockForUpdate()->first() ?? AppointmentProposalOutcome::NotFound);
    }

    public function active(Business $business, AiTurnClaim $claim): AppointmentProposal|AppointmentProposalOutcome|AiTurnOutcome
    {
        return $this->turns->withOwnership($business, $claim, fn (AiTurn $turn, Conversation $conversation): AppointmentProposal|AppointmentProposalOutcome => $this->activeQuery($conversation->id)
            ->lockForUpdate()->first() ?? AppointmentProposalOutcome::NotFound);
    }

    public function reject(Business $business, AiTurnClaim $claim, int $proposalId): AppointmentProposal|AppointmentProposalOutcome|AiTurnOutcome
    {
        return $this->turns->withOwnership($business, $claim, function (AiTurn $turn, Conversation $conversation) use ($proposalId): AppointmentProposal|AppointmentProposalOutcome {
            $proposal = AppointmentProposal::query()->where('conversation_id', $conversation->id)->whereKey($proposalId)->lockForUpdate()->first();
            if (! $proposal) {
                return AppointmentProposalOutcome::NotFound;
            }
            if ($proposal->state === AppointmentProposalState::Completed) {
                return AppointmentProposalOutcome::InvalidTransition;
            }
            if ($proposal->state !== AppointmentProposalState::Rejected) {
                $proposal->update(['state' => AppointmentProposalState::Rejected]);
            }

            return $proposal;
        });
    }

    /** No elapsed-time check: only an actually newer Conversation invalidates a proposal. */
    public function invalidateReplacedConversation(Business $business, int $conversationId): int|AppointmentProposalOutcome
    {
        return DB::transaction(function () use ($business, $conversationId): int|AppointmentProposalOutcome {
            $conversation = Conversation::query()->where('business_id', $business->id)->find($conversationId);
            if (! $conversation) {
                return AppointmentProposalOutcome::NotFound;
            }
            $this->lock->acquire($business->id, $conversation->channel, $conversation->sender_identity);
            $current = Conversation::query()->where('business_id', $business->id)
                ->where('channel', $conversation->channel)->where('sender_identity', $conversation->sender_identity)
                ->orderByDesc('last_activity_at')->orderByDesc('id')->firstOrFail();
            if ($current->id === $conversation->id) {
                return AppointmentProposalOutcome::ConversationStillCurrent;
            }

            $proposals = $this->activeQuery($conversation->id)->lockForUpdate()->get();
            foreach ($proposals as $proposal) {
                $proposal->update(['state' => AppointmentProposalState::Rejected]);
            }

            return $proposals->count();
        });
    }

    private function activeQuery(int $conversationId): Builder
    {
        return AppointmentProposal::query()->where('conversation_id', $conversationId)
            ->whereIn('state', [AppointmentProposalState::Prepared, AppointmentProposalState::Pending]);
    }

    private function inBusiness(Business $business, AppointmentProposalPayload $payload): bool
    {
        if ($payload->serviceId !== null && ! Service::query()->where('business_id', $business->id)->whereKey($payload->serviceId)->exists()) {
            return false;
        }
        if ($payload->professionalId !== null && ! Professional::query()->where('business_id', $business->id)->whereKey($payload->professionalId)->exists()) {
            return false;
        }

        return $payload->appointmentId === null || Appointment::query()->whereKey($payload->appointmentId)
            ->whereHas('service', fn (Builder $query) => $query->where('business_id', $business->id))
            ->whereHas('professional', fn (Builder $query) => $query->where('business_id', $business->id))->exists();
    }
}
