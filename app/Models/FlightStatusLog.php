<?php

namespace App\Models;

use App\Enums\FlightStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightStatusLog extends Model
{
    protected $table = 'flight_status_log';

    public $timestamps = false;

    protected $fillable = ['flight_id', 'old_status', 'new_status', 'reason', 'changed_by'];

    protected $casts = [
        'old_status' => FlightStatus::class,
        'new_status' => FlightStatus::class,
        'changed_at' => 'datetime',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(InternalUser::class, 'changed_by');
    }
}
