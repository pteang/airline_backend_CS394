<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AircraftAssignmentLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['aircraft_id', 'flight_id', 'assigned_by', 'assigned_at'];

    protected $casts = ['assigned_at' => 'datetime'];
}
