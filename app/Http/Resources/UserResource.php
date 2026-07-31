<?php

namespace App\Http\Resources;

use App\Models\InternalUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Maps a passenger (User) or internal (InternalUser) record to the frontend's
 * flat `User` shape from docs/API_REFERENCE.md:
 *   { id, name, email, phone?, role, initials }
 *
 * Frontend roles are guest | passenger | staff | admin. Internal `admin` maps to
 * "admin"; other internal roles (manager, agent) map to "staff"; passengers to
 * "passenger".
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isInternal = $this->resource instanceof InternalUser;

        $role = 'passenger';
        if ($isInternal) {
            $role = $this->role->value === 'admin' ? 'admin' : 'staff';
        }

        return [
            'id' => (string) $this->id,
            'name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $role,
            'initials' => $this->initials($this->full_name),
            // Present only where the passenger profile is loaded (GET/PUT
            // /auth/me); the booking flow uses it as the traveller id.
            'profileId' => $this->when(
                ! $isInternal && $this->relationLoaded('profile'),
                fn () => optional($this->profile)->id ? (string) $this->profile->id : null,
            ),
        ];
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
