<?php

namespace App\Models;

use App\Application\Conversation\AppointmentProposalOperation;
use App\Application\Conversation\AppointmentProposalPayload;
use App\Application\Conversation\AppointmentProposalPresentation;
use App\Application\Conversation\AppointmentProposalState;
use Database\Factories\AppointmentProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentProposal extends Model
{
    /** @use HasFactory<AppointmentProposalFactory> */
    use HasFactory;

    protected $attributes = ['state' => 'prepared'];

    protected $fillable = [
        'conversation_id', 'originating_turn_id', 'operation', 'payload', 'presentation',
        'state', 'confirmation_request_message_id', 'confirming_turn_id', 'result',
    ];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'operation' => AppointmentProposalOperation::class,
            'state' => AppointmentProposalState::class,
            'payload' => 'array',
            'presentation' => 'array',
            'result' => 'array',
        ];
    }

    public function canonicalPayload(): AppointmentProposalPayload
    {
        return AppointmentProposalPayload::fromArray($this->operation, $this->payload);
    }

    public function canonicalPresentation(): AppointmentProposalPresentation
    {
        return AppointmentProposalPresentation::fromArray($this->presentation);
    }

    /** @return array{status: string, proposal_id: int, operation: string, payload: array, presentation: array} */
    public function structuredDetails(): array
    {
        return [
            'status' => $this->state->value,
            'proposal_id' => $this->id,
            'operation' => $this->operation->value,
            'payload' => $this->canonicalPayload()->toArray(),
            'presentation' => $this->canonicalPresentation()->toLocalArray(),
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function originatingTurn(): BelongsTo
    {
        return $this->belongsTo(AiTurn::class, 'originating_turn_id');
    }

    public function confirmationRequestMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'confirmation_request_message_id');
    }

    public function confirmingTurn(): BelongsTo
    {
        return $this->belongsTo(AiTurn::class, 'confirming_turn_id');
    }
}
