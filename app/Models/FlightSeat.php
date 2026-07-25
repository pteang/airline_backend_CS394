<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightSeat extends Model
{
    public $timestamps = false;

    protected $fillable = ['flight_id', 'aircraft_seat_id', 'is_available'];

    protected $casts = [
        'is_available' => 'boolean',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function aircraftSeat(): BelongsTo
    {
        return $this->belongsTo(AircraftSeat::class);
    }
}
