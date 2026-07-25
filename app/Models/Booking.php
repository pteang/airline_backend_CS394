<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\TripType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Booking extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_ref', 'passenger_id', 'flight_id', 'trip_type',
        'return_flight_id', 'status', 'booked_at',
    ];

    protected $casts = [
        'trip_type' => TripType::class,
        'status' => BookingStatus::class,
        'booked_at' => 'datetime',
    ];

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'passenger_id');
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function returnFlight(): BelongsTo
    {
        return $this->belongsTo(Flight::class, 'return_flight_id');
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(BookingPassenger::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function ticket(): HasOne
    {
        return $this->hasOne(Ticket::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BookingLog::class);
    }
}
