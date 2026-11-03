<?php

namespace App\Models;

use Database\Factories\ProfessionalAvailabilityIntervalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalAvailabilityInterval extends Model
{
    /** @use HasFactory<ProfessionalAvailabilityIntervalFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['weekday', 'starts_at', 'ends_at'];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
