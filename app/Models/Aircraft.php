<?php

namespace App\Models;

use App\Enums\AircraftStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aircraft extends Model
{
    protected $table = 'aircraft';

    public $timestamps = false;

    protected $fillable = [
        'registration_number', 'model', 'capacity',
        'status', 'manufacturer', 'flight_hours',
    ];

    protected $casts = [
        'status' => AircraftStatus::class,
    ];

    public function seats(): HasMany
    {
        return $this->hasMany(AircraftSeat::class);
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }
}
