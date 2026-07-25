<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Maps the normalized flight/route/aircraft tables to the frontend's flat
 * `Flight` shape from docs/api-contract.md. A `seat_class` may be supplied via
 * `additional(['seatClass' => ...])` (e.g. from a search filter) to pick the
 * matching class price; otherwise economy is used.
 */
class FlightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Normalize a search "class" param (e.g. "Economy", "First Class") to an
        // enum value ("economy", "first_class"); default to economy.
        $seatClass = str_replace(' ', '_', strtolower($request->query('class', 'economy')));

        $price = optional(
            $this->classPrices->firstWhere('seat_class.value', $seatClass)
        )->price ?? $this->base_price ?? 0;

        return [
            'id' => (string) $this->id,
            'number' => $this->flight_number,
            'origin' => $this->route->origin->city,
            'originCode' => $this->route->origin->iata_code,
            'destination' => $this->route->destination->city,
            'destinationCode' => $this->route->destination->iata_code,
            'departureTime' => $this->departure_time->format('H:i'),
            'arrivalTime' => $this->arrival_time->format('H:i'),
            'date' => $this->departure_time->format('Y-m-d'),
            'duration' => $this->duration(),
            'class' => $seatClass,
            'price' => (float) $price,
            'status' => $this->mapStatus($this->status->value),
            'gate' => $this->gate?->gate_code ?? '',
            'aircraft' => $this->aircraft->model,
            'availableSeats' => $this->when(
                $this->available_seats !== null,
                fn () => (int) $this->available_seats,
                $this->seats->where('is_available', true)->count(),
            ),
        ];
    }

    private function duration(): string
    {
        $minutes = $this->departure_time->diffInMinutes($this->arrival_time);

        return intdiv($minutes, 60).'h '.($minutes % 60).'m';
    }

    /** Collapse backend statuses into the contract's status set. */
    private function mapStatus(string $status): string
    {
        return match ($status) {
            'in_air' => 'departed',
            'landed' => 'arrived',
            default => $status, // scheduled|boarding|delayed|departed|arrived|cancelled
        };
    }
}
