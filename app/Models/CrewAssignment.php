<?php

namespace App\Models;

use App\Enums\CrewAssignmentRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewAssignment extends Model
{
    protected $table = 'crew_assignment';

    public $timestamps = false;

    protected $fillable = ['flight_id', 'staff_id', 'assignment_role', 'assigned_by', 'assigned_at'];

    protected $casts = [
        'assignment_role' => CrewAssignmentRole::class,
        'assigned_at' => 'datetime',
    ];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(InternalUser::class, 'assigned_by');
    }
}
