<?php

namespace App\Http\Resources;

use App\Http\Controllers\Api\BookingController;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Maps a Booking (with its first traveller/seat) to the frontend's flat
 * `Booking` shape from the React app's types.ts:
 *   { id, flight, seat, seatClass, passenger:{name,email,phone,passport},
 *     price, tax, total, status, bookingRef, boardingGroup,
 *     paymentMethod?, paymentStatus? }
 *
 * The backend supports multiple travellers per booking; the frontend models a
 * booking as one passenger + one seat for its summaries, so we surface the
 * first traveller as `seat` while also exposing every booked seat as `seats`
 * (the Boarding Pass screen issues one pass per entry).
 * `tax` is a flat 15% of the base fare (matching the current UI) and `total`
 * is price + tax; the backend `payment.amount` remains the source of truth.
 */
class BookingResource extends JsonResource
{
    /** Boarding group by cabin, mirroring the frontend's grouping. */
    private const BOARDING_GROUP = [
        'first_class' => 'A',
        'business' => 'B',
        'economy' => 'C',
    ];

    public function toArray(Request $request): array
    {
        $traveller = $this->passengers->first();
        $seatClass = $traveller?->seat_class->value ?? 'economy';
        $seatNumber = $traveller?->flightSeat?->aircraftSeat?->seat_number ?? '';

        // Every seat booked in this reservation. The frontend issues one
        // boarding pass per seat, so it needs the full list (not just the
        // first traveller surfaced below for the one-seat summaries).
        $seats = $this->passengers
            ->map(fn ($p) => $p->flightSeat?->aircraftSeat?->seat_number)
            ->filter()
            ->values()
            ->all();

        $price = (float) BookingController::computeTotal($this->resource);
        $tax = round($price * 0.15, 2);

        // Passenger contact from the account holder; passport from the profile.
        $account = $this->passenger; // User
        $profile = $traveller?->passenger; // PassengerProfile

        return [
            'id' => (string) $this->id,
            // The first traveller's booking_passengers.id — needed by PUT /bookings/{id}
            // (Modify Booking) to identify which traveller/seat to change.
            'travellerId' => $traveller ? (string) $traveller->id : null,
            'flight' => new FlightResource($this->flight),
            'seat' => $seatNumber,
            'seats' => $seats,
            'seatClass' => $seatClass,
            'passenger' => [
                'name' => $account?->full_name ?? '',
                'email' => $account?->email ?? '',
                'phone' => $account?->phone ?? '',
                'passport' => $profile?->passport_number ?? '',
            ],
            'price' => $price,
            'tax' => $tax,
            'total' => round($price + $tax, 2),
            'status' => $this->mapStatus($this->status->value),
            'bookingRef' => $this->booking_ref,
            'boardingGroup' => self::BOARDING_GROUP[$seatClass] ?? 'C',
            'paymentMethod' => $this->whenLoaded('payment', fn () => $this->payment?->payment_method?->value),
            'paymentStatus' => $this->whenLoaded('payment', fn () => $this->payment?->payment_status?->value),
        ];
    }

    /** The frontend's BookingStatus set is confirmed|pending|cancelled. */
    private function mapStatus(string $status): string
    {
        return $status === 'completed' ? 'confirmed' : $status;
    }
}
