<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory;

    protected $fillable = ['business_id', 'customer_id', 'channel', 'sender_identity', 'last_activity_at'];

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['last_activity_at' => 'immutable_datetime'];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('created_at')->orderBy('id');
    }
}
