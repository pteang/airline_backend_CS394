<?php

namespace App\Models;

use App\Enums\MaintenanceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceSchedule extends Model
{
    protected $table = 'maintenance_schedule';

    public $timestamps = false;

    protected $fillable = [
        'aircraft_id', 'maintenance_type', 'scheduled_date',
        'end_date', 'technician_id', 'is_completed',
    ];

    protected $casts = [
        'maintenance_type' => MaintenanceType::class,
        'scheduled_date' => 'date',
        'end_date' => 'date',
        'is_completed' => 'boolean',
    ];

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'technician_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(MaintenanceDetail::class, 'schedule_id');
    }
}
