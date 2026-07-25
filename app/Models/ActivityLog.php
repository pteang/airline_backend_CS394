<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Append-only activity / audit log stored in MongoDB (the "logs & documents"
 * store). Written asynchronously via the Redis queue — see App\Jobs\LogActivity.
 */
class ActivityLog extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'activity_logs';

    protected $guarded = [];

    protected $casts = [
        'context' => 'array',
        'logged_at' => 'datetime',
    ];
}
