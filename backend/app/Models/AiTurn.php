<?php

namespace App\Models;

use App\Application\Conversation\AiTurnFailure;
use App\Application\Conversation\AiTurnFailureReason;
use App\Application\Conversation\AiTurnState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTurn extends Model
{
    protected $attributes = ['state' => 'pending', 'attempt_count' => 0, 'diagnostics' => '{}', 'fallback_used' => false];

    protected $fillable = [
        'inbound_message_id', 'assistant_message_id', 'state', 'claim_token', 'claimed_until', 'completed_at',
        'attempt_count', 'next_attempt_at', 'failure_classification', 'failure_reason', 'diagnostics',
        'terminal_at', 'terminal_reason', 'fallback_used',
    ];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return [
            'state' => AiTurnState::class, 'claimed_until' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'attempt_count' => 'integer', 'next_attempt_at' => 'immutable_datetime',
            'failure_classification' => AiTurnFailure::class, 'failure_reason' => AiTurnFailureReason::class,
            'diagnostics' => 'array', 'terminal_at' => 'immutable_datetime',
            'terminal_reason' => AiTurnFailureReason::class, 'fallback_used' => 'boolean',
        ];
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'inbound_message_id');
    }

    public function assistantMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'assistant_message_id');
    }
}
