<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InternalUser extends Model
{
    public $timestamps = false;

    protected $fillable = ['full_name', 'email', 'password_hash', 'phone', 'role', 'is_active'];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'role' => UserRole::class,
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }
}
