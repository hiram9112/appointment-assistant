<?php

namespace App\Models;

use Database\Factories\ChannelIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelIdentity extends Model
{
    /** @use HasFactory<ChannelIdentityFactory> */
    use HasFactory;

    protected $fillable = ['customer_id', 'channel', 'external_id'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
