<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Persists a domain event to the MongoDB activity log off the request path,
 * via the configured queue. Logging must never break the request, so failures
 * are swallowed after retries.
 */
class LogActivity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $event,
        public array $context = [],
        public ?string $actorType = null,
        public ?int $actorId = null,
    ) {}

    public function handle(): void
    {
        ActivityLog::create([
            'event' => $this->event,
            'actor_type' => $this->actorType,
            'actor_id' => $this->actorId,
            'context' => $this->context,
            'logged_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        // Don't let audit-logging failures cascade; surface to the app log only.
        logger()->warning('LogActivity failed: '.$e->getMessage(), [
            'event' => $this->event,
        ]);
    }
}
