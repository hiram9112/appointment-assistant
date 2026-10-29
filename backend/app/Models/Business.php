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
    ];

    public function operatingHours(): HasMany
    {
        return $this->hasMany(BusinessOperatingHour::class);
    }
}
