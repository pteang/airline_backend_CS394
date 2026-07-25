<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceDetail extends Model
{
    protected $table = 'maintenance_detail';

    public $timestamps = false;

    protected $fillable = [
        'schedule_id', 'aircraft_id', 'work_done',
        'parts_used', 'technician_id', 'technician_notes', 'logged_at',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'schedule_id');
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'technician_id');
    }
}
