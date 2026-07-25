<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    public $timestamps = false;

    protected $fillable = ['booking_id', 'ticket_number', 'issued_at', 'ticket_code'];

    protected $casts = [
        'issued_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
