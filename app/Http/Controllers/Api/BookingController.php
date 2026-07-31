<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\SeatClass;
use App\Enums\TripType;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Jobs\LogActivity;
use App\Models\Booking;
use App\Models\Flight;
use App\Models\FlightSeat;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    /** Relations BookingResource needs to render the flat frontend shape. */
    public const RELATIONS = [
        'passenger', 'flight.route.origin', 'flight.route.destination',
        'flight.aircraft', 'flight.gate', 'flight.classPrices',
        'passengers.flightSeat.aircraftSeat', 'passengers.passenger',
        'payment', 'ticket',
    ];

    /** List the authenticated passenger's bookings. */
    public function index(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        $bookings = Booking::query()
            ->when($request->attributes->get('auth_type') === 'passenger', fn ($q) => $q->where('passenger_id', $user->id))
            ->with(self::RELATIONS)
            ->orderByDesc('booked_at')
            ->paginate($request->integer('per_page', 25));

        return BookingResource::collection($bookings);
    }

    public function show(Request $request, Booking $booking)
    {
        $this->authorizeOwner($request, $booking);

        return new BookingResource($booking->load([...self::RELATIONS, 'returnFlight']));
    }

    public function update(Request $request, Booking $booking)
    {
        $this->authorizeOwner($request, $booking);
        abort_if($booking->status === BookingStatus::Cancelled, 422, 'A cancelled booking cannot be modified.');
        $data = $request->validate([
            'passenger_id' => ['required', 'exists:booking_passengers,id'],
            'flight_seat_id' => ['nullable', 'integer'],
            'seat_class' => ['sometimes', Rule::enum(SeatClass::class)],
            'special_request' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($booking, $data) {
            $traveller = $booking->passengers()->lockForUpdate()->findOrFail($data['passenger_id']);
            $seatClass = $data['seat_class'] ?? $traveller->seat_class->value;
            $newSeatId = $data['flight_seat_id'] ?? null;
            if ($newSeatId && $newSeatId !== $traveller->flight_seat_id) {
                $newSeat = FlightSeat::whereKey($newSeatId)->where('flight_id', $booking->flight_id)
                    ->with('aircraftSeat')->lockForUpdate()->first();
                if (! $newSeat || ! $newSeat->is_available || $newSeat->aircraftSeat->seat_class->value !== $seatClass) {
                    throw ValidationException::withMessages(['flight_seat_id' => ['The selected seat is unavailable or in the wrong class.']]);
                }
                $newSeat->update(['is_available' => false]);
            }
            if ($traveller->flight_seat_id && $traveller->flight_seat_id !== $newSeatId) {
                FlightSeat::whereKey($traveller->flight_seat_id)->update(['is_available' => true]);
            }
            $traveller->update([
                'flight_seat_id' => $newSeatId,
                'seat_class' => $seatClass,
                'special_request' => $data['special_request'] ?? $traveller->special_request,
            ]);
        });

        return new BookingResource($booking->fresh(self::RELATIONS));
    }

    /**
     * Create a booking with one or more travellers. Seats (when requested) are
     * locked and reserved atomically; the total is derived from class prices.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'flight_id' => ['required', 'exists:flights,id'],
            'trip_type' => ['sometimes', Rule::enum(TripType::class)],
            'return_flight_id' => ['nullable', 'required_if:trip_type,round_trip', 'different:flight_id', 'exists:flights,id'],
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.passenger_id' => ['required', 'exists:passenger_profiles,id'],
            'passengers.*.seat_class' => ['required', Rule::enum(SeatClass::class)],
            'passengers.*.flight_seat_id' => ['nullable', 'integer', 'distinct'],
            'passengers.*.special_request' => ['nullable', 'string'],
        ]);

        $user = $request->attributes->get('auth_user');

        $booking = DB::transaction(function () use ($data, $user) {
            $flight = Flight::findOrFail($data['flight_id']);

            $booking = Booking::create([
                'booking_ref' => $this->generateRef(),
                'passenger_id' => $user->id,
                'flight_id' => $flight->id,
                'trip_type' => $data['trip_type'] ?? TripType::OneWay->value,
                'return_flight_id' => $data['return_flight_id'] ?? null,
                'status' => BookingStatus::Pending->value,
            ]);
            $booking->logs()->create([
                'new_status' => BookingStatus::Pending->value,
                'actor_type' => 'passenger', 'actor_id' => $user->id,
                'note' => 'Booking created.',
            ]);

            $prices = $flight->classPrices()->pluck('price', 'seat_class');

            foreach ($data['passengers'] as $traveller) {
                $seatClass = $traveller['seat_class'];
                $flightSeatId = $traveller['flight_seat_id'] ?? null;

                if ($flightSeatId !== null) {
                    // Lock the seat row to prevent double-booking under concurrency.
                    $seat = FlightSeat::where('id', $flightSeatId)
                        ->where('flight_id', $flight->id)
                        ->with('aircraftSeat')
                        ->lockForUpdate()
                        ->first();

                    if (! $seat || ! $seat->is_available) {
                        throw ValidationException::withMessages([
                            'passengers' => ["Seat {$flightSeatId} is unavailable."],
                        ]);
                    }
                    if ($seat->aircraftSeat->seat_class->value !== $seatClass) {
                        throw ValidationException::withMessages([
                            'passengers' => ["Seat {$flightSeatId} is not in {$seatClass} class."],
                        ]);
                    }

                    $seat->update(['is_available' => false]);
                }

                $booking->passengers()->create([
                    'passenger_id' => $traveller['passenger_id'],
                    'flight_seat_id' => $flightSeatId,
                    'seat_class' => $seatClass,
                    'special_request' => $traveller['special_request'] ?? null,
                ]);
            }

            return $booking;
        });

        LogActivity::dispatch('booking.created', [
            'booking_ref' => $booking->booking_ref,
            'flight_id' => $booking->flight_id,
            'travellers' => $booking->passengers()->count(),
        ], 'passenger', $user->id);

        return (new BookingResource($booking->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    /** Cancel a booking, release its seats, and flag any payment for refund. */
    public function cancel(Request $request, Booking $booking)
    {
        $this->authorizeOwner($request, $booking);

        if ($booking->status === BookingStatus::Cancelled) {
            return response()->json(['message' => 'Booking already cancelled.'], 422);
        }

        DB::transaction(function () use ($booking, $request) {
            $oldStatus = $booking->status->value;
            $seatIds = $booking->passengers()->whereNotNull('flight_seat_id')->pluck('flight_seat_id');
            FlightSeat::whereIn('id', $seatIds)->update(['is_available' => true]);

            $booking->update(['status' => BookingStatus::Cancelled->value]);
            $booking->logs()->create([
                'old_status' => $oldStatus, 'new_status' => BookingStatus::Cancelled->value,
                'actor_type' => $request->attributes->get('auth_type'),
                'actor_id' => $request->user()->id, 'note' => 'Booking cancelled.',
            ]);
            Notification::create([
                'user_id' => $booking->passenger_id, 'title' => 'Booking cancelled',
                'message' => "Booking {$booking->booking_ref} has been cancelled.",
            ]);

            if ($booking->payment && $booking->payment->payment_status === PaymentStatus::Paid) {
                $booking->payment->update(['payment_status' => PaymentStatus::Refunded->value]);
            }
        });

        return new BookingResource($booking->fresh(self::RELATIONS));
    }

    /** The total fare for a booking, from class prices with base_price fallback. */
    public static function computeTotal(Booking $booking): string
    {
        $flight = $booking->flight;
        $prices = $flight->classPrices()->pluck('price', 'seat_class');

        $total = $booking->passengers->sum(function ($traveller) use ($prices, $flight) {
            return (float) ($prices[$traveller->seat_class->value] ?? $flight->base_price ?? 0);
        });

        return number_format($total, 2, '.', '');
    }

    private function authorizeOwner(Request $request, Booking $booking): void
    {
        $type = $request->attributes->get('auth_type');
        $user = $request->attributes->get('auth_user');

        // Internal users may view/manage any booking; passengers only their own.
        if ($type === 'passenger' && $booking->passenger_id !== $user->id) {
            abort(403, 'This booking does not belong to you.');
        }
    }

    private function generateRef(): string
    {
        do {
            $ref = strtoupper(Str::random(6));
        } while (Booking::where('booking_ref', $ref)->exists());

        return $ref;
    }
}
