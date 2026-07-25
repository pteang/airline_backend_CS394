<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassengerProfile extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'passport_number', 'nationality', 'date_of_birth', 'special_needs',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
