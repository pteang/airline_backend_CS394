<?php

namespace App\Models;

use App\Enums\FlightStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flight extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'flight_number', 'route_id', 'aircraft_id', 'gate_id',
        'departure_time', 'arrival_time', 'status', 'base_price', 'created_by',
    ];

    protected $casts = [
        'departure_time' => 'datetime',
        'arrival_time' => 'datetime',
        'status' => FlightStatus::class,
        'base_price' => 'decimal:2',
    ];

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }

    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(InternalUser::class, 'created_by');
    }

    public function seats(): HasMany
    {
        return $this->hasMany(FlightSeat::class);
    }

    public function classPrices(): HasMany
    {
        return $this->hasMany(FlightClassPrice::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(FlightStatusLog::class);
    }

    public function crew(): HasMany
    {
        return $this->hasMany(CrewAssignment::class);
    }
}
