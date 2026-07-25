<?php

namespace App\Models;

use App\Enums\SeatClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AircraftSeat extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'aircraft_id', 'seat_number', 'seat_class', 'is_window', 'is_aisle',
    ];

    protected $casts = [
        'seat_class' => SeatClass::class,
        'is_window' => 'boolean',
        'is_aisle' => 'boolean',
    ];

    public function aircraft(): BelongsTo
    {
        return $this->belongsTo(Aircraft::class);
    }
}
