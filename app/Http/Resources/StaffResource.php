<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Maps a Staff record (+ its internal user, availability, and assignments) to
 * the frontend's `StaffMember` shape:
 *   { id, name, role, email, phone, license, availability, initials,
 *     assignments: string[], nextFlight?, yearsExp }
 */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->internalUser;
        $name = $user?->full_name ?? '';

        // Upcoming crew assignments (need flight loaded for the flight number).
        $upcoming = $this->assignments
            ->filter(fn ($a) => $a->flight && $a->flight->departure_time?->isFuture())
            ->sortBy(fn ($a) => $a->flight->departure_time);

        $nextFlight = $upcoming->first()?->flight?->flight_number;

        return [
            'id' => (string) $this->id,
            'name' => $name,
            'role' => $this->staff_role->value,
            'email' => $user?->email ?? '',
            'phone' => $user?->phone ?? '',
            'license' => $this->license_number ?? '',
            'availability' => $this->latestAvailabilityStatus(),
            'initials' => $this->initials($name),
            'assignments' => $this->assignments
                ->map(fn ($a) => $a->flight?->flight_number)
                ->filter()
                ->values(),
            'nextFlight' => $nextFlight,
            'yearsExp' => $this->joined_date
                ? (int) Carbon::parse($this->joined_date)->diffInYears(now())
                : 0,
        ];
    }

    /** The most recent recorded availability status, defaulting to available. */
    private function latestAvailabilityStatus(): string
    {
        return $this->availability
            ->sortByDesc('available_date')
            ->first()?->status?->value ?? 'available';
    }

    private function initials(string $name): string
    {
        return Str::of($name)
            ->explode(' ')
            ->filter()
            ->map(fn ($p) => Str::upper(Str::substr($p, 0, 1)))
            ->take(2)
            ->implode('');
    }
}
