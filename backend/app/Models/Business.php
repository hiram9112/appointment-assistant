<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'timezone',
        'address_line',
        'postal_code',
        'city',
        'country_code',
        'phone',
        'email',
        'payment_methods',
        'appointments_recommended',
        'arrival_lead_minutes',
        'cancellation_notice_minutes',
        'rescheduling_notice_minutes',
    ];

    protected function casts(): array
    {
        return [
            'payment_methods' => 'array',
            'appointments_recommended' => 'boolean',
            'arrival_lead_minutes' => 'integer',
            'cancellation_notice_minutes' => 'integer',
            'rescheduling_notice_minutes' => 'integer',
        ];
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(BusinessOperatingHour::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
