<?php

namespace App\Models;

use App\Enums\StaffAvailabilityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAvailability extends Model
{
    protected $table = 'staff_availability';

    public $timestamps = false;

    protected $fillable = ['staff_id', 'available_date', 'status', 'reason'];

    protected $casts = [
        'available_date' => 'date',
        'status' => StaffAvailabilityStatus::class,
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
