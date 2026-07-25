<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Airport extends Model
{
    public $timestamps = false;

    protected $fillable = ['iata_code', 'name', 'city', 'country'];

    public function gates(): HasMany
    {
        return $this->hasMany(Gate::class);
    }

    public function departingRoutes(): HasMany
    {
        return $this->hasMany(Route::class, 'origin_airport_id');
    }

    public function arrivingRoutes(): HasMany
    {
        return $this->hasMany(Route::class, 'destination_airport_id');
    }
}
