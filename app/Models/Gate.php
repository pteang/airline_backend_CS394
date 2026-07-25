<?php

namespace App\Models;

use App\Enums\GateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Gate extends Model
{
    public $timestamps = false;

    protected $fillable = ['airport_id', 'gate_code', 'status'];

    protected $casts = [
        'status' => GateStatus::class,
    ];

    public function airport(): BelongsTo
    {
        return $this->belongsTo(Airport::class);
    }
}
