<?php

namespace App\Models;

use App\Enums\SeatClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightClassPrice extends Model
{
    public $timestamps = false;

    protected $fillable = ['flight_id', 'seat_class', 'price'];

    protected $casts = [
        'seat_class' => SeatClass::class,
        'price' => 'decimal:2',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
