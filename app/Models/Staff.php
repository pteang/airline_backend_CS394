<?php

namespace App\Models;

use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Staff extends Model
{
    protected $table = 'staff';

    public $timestamps = false;

    protected $fillable = [
        'internal_user_id', 'employee_id', 'license_number',
        'license_expiry', 'staff_role', 'joined_date',
    ];

    protected $casts = [
        'staff_role' => StaffRole::class,
        'license_expiry' => 'date',
        'joined_date' => 'date',
    ];

    public function internalUser(): BelongsTo
    {
        return $this->belongsTo(InternalUser::class);
    }

    public function availability(): HasMany
    {
        return $this->hasMany(StaffAvailability::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CrewAssignment::class);
    }
}
