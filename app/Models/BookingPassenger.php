<?php

namespace App\Models;

use App\Enums\SeatClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingPassenger extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'passenger_id', 'flight_seat_id', 'seat_class', 'special_request',
    ];

    protected $casts = [
        'seat_class' => SeatClass::class,
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(PassengerProfile::class, 'passenger_id');
    }

    public function flightSeat(): BelongsTo
    {
        return $this->belongsTo(FlightSeat::class);
    }
}
