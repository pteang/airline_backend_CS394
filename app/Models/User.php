<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Model
{
    public $timestamps = false;

    protected $fillable = ['full_name', 'email', 'password_hash', 'phone', 'is_active'];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function profile(): HasOne
    {
        return $this->hasOne(PassengerProfile::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'passenger_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }
}
