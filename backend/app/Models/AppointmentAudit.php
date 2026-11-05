<?php

namespace App\Models;

use Database\Factories\AppointmentAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentAudit extends Model
{
    /** @use HasFactory<AppointmentAuditFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = ['appointment_id', 'action', 'actor_type', 'actor_id', 'source', 'before_state', 'after_state', 'occurred_at'];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
