<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Maps a flight_seat (joined to its aircraft_seat) to the frontend's `Seat`
 * shape: { id, row, column, class, price, status }.
 *
 * Seat numbers like "12A" split into row (12) and column ("A"). The controller
 * pre-attaches the class price as a `seat_price` attribute on each flight_seat.
 */
class SeatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $number = $this->aircraftSeat->seat_number;
        preg_match('/^(\d+)([A-Za-z]*)$/', $number, $m);

        return [
            'id' => (string) $this->id,
            'row' => (int) ($m[1] ?? 0),
            'column' => $m[2] ?? $number,
            'class' => $this->aircraftSeat->seat_class->value,
            'price' => (float) ($this->seat_price ?? 0),
            'status' => $this->is_available ? 'available' : 'taken',
        ];
    }
}
