<?php

namespace App\Models;

use App\Application\Conversation\AiTurnState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiTurn extends Model
{
    protected $attributes = ['state' => 'pending'];

    protected $fillable = ['inbound_message_id', 'assistant_message_id', 'state', 'claim_token', 'claimed_until', 'completed_at'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['state' => AiTurnState::class, 'claimed_until' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
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
