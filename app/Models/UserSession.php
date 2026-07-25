<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'internal_user_id', 'user_id', 'session_token',
        'login_at', 'expires_at', 'logout_at', 'is_active',
    ];

    protected $hidden = ['session_token'];

    protected $casts = [
        'login_at' => 'datetime',
        'expires_at' => 'datetime',
        'logout_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function internalUser(): BelongsTo
    {
        return $this->belongsTo(InternalUser::class);
    }

    /** The authenticated party behind this session (passenger or internal user). */
    public function owner(): ?Model
    {
        return $this->user_id ? $this->user : $this->internalUser;
    }
}
