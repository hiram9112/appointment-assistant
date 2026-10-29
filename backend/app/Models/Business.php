<?php

namespace App\Models;

use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
