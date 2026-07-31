<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Maps an Aircraft (+ its maintenance schedules/details) to the frontend's
 * `Aircraft` shape:
 *   { id, registration, model, manufacturer, capacity, status,
 *     lastMaintenance, nextMaintenance, age, maintenanceLogs[] }
 *
 * `lastMaintenance` / `nextMaintenance` are derived from the maintenance
 * schedule. `age` is not tracked in the schema, so it is reported as 0.
 */
class AircraftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $schedules = $this->whenLoaded('maintenanceSchedules', fn () => $this->maintenanceSchedules, collect());

        $past = $schedules
            ->filter(fn ($s) => $s->scheduled_date && ! $s->scheduled_date->isFuture())
            ->sortByDesc('scheduled_date');
        $future = $schedules
            ->filter(fn ($s) => $s->scheduled_date && $s->scheduled_date->isFuture())
            ->sortBy('scheduled_date');

        return [
            'id' => (string) $this->id,
            'registration' => $this->registration_number,
            'model' => $this->model,
            'manufacturer' => $this->manufacturer ?? '',
            'capacity' => (int) $this->capacity,
            'status' => $this->status->value,
            'lastMaintenance' => $past->first()?->scheduled_date?->toDateString() ?? '',
            'nextMaintenance' => $future->first()?->scheduled_date?->toDateString() ?? '',
            'age' => 0,
            'maintenanceLogs' => $schedules
                ->sortByDesc('scheduled_date')
                ->map(fn ($s) => $this->mapLog($s))
                ->values(),
        ];
    }

    /** One MaintenanceSchedule → the frontend's MaintenanceLog shape. */
    private function mapLog($schedule): array
    {
        $status = $schedule->is_completed
            ? 'completed'
            : ($schedule->scheduled_date && ! $schedule->scheduled_date->isFuture() ? 'in-progress' : 'scheduled');

        $detail = $schedule->relationLoaded('details') ? $schedule->details->first() : null;

        return [
            'id' => (string) $schedule->id,
            'date' => $schedule->scheduled_date?->toDateString() ?? '',
            'type' => $schedule->maintenance_type->value,
            'maintenanceType' => $schedule->maintenance_type->value,
            'technician' => $schedule->technician?->internalUser?->full_name ?? 'TBD',
            'notes' => $detail?->work_done ?? '',
            'status' => $status,
        ];
    }
}
