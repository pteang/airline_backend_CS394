<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'origin_airport_id', 'destination_airport_id',
        'distance_km', 'estimated_duration', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function origin(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'origin_airport_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Airport::class, 'destination_airport_id');
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }
}
