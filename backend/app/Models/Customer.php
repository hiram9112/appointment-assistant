<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    protected $fillable = ['first_name', 'last_name', 'phone'];

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function channelIdentities(): HasMany
    {
        return $this->hasMany(ChannelIdentity::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
