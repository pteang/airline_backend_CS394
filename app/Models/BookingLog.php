<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'old_status', 'new_status', 'actor_type',
        'actor_id', 'note', 'changed_at',
    ];

    protected $casts = ['changed_at' => 'datetime'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
