<?php

namespace App\Models;

use Database\Factories\ConversationMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    /** @use HasFactory<ConversationMessageFactory> */
    use HasFactory;

    protected $fillable = ['conversation_id', 'role', 'content', 'idempotency_key'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
