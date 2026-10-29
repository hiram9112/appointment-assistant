<?php

namespace App\Models;

use Database\Factories\BusinessOperatingHourFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessOperatingHour extends Model
{
    /** @use HasFactory<BusinessOperatingHourFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['weekday', 'opens_at', 'closes_at'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
