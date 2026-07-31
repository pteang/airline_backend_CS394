<?php

namespace App\Http\Controllers\Api;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Jobs\LogActivity;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    /**
     * Pay for a booking. On success the payment is recorded, the booking is
     * confirmed, and a ticket is issued — all atomically.
     */
    public function store(Request $request, Booking $booking)
    {
        $type = $request->attributes->get('auth_type');
        $user = $request->attributes->get('auth_user');
        if ($type === 'passenger' && $booking->passenger_id !== $user->id) {
            abort(403, 'This booking does not belong to you.');
        }

        $data = $request->validate([
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ]);

        if ($booking->status === BookingStatus::Cancelled) {
            return response()->json(['message' => 'Cannot pay for a cancelled booking.'], 422);
        }
        if ($booking->payment && $booking->payment->payment_status === PaymentStatus::Paid) {
            return response()->json(['message' => 'Booking is already paid.'], 422);
        }

        $booking->loadMissing('passengers', 'flight.classPrices');
        $amount = BookingController::computeTotal($booking);

        $result = DB::transaction(function () use ($booking, $data, $amount) {
            $payment = $booking->payment()->updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'amount' => $amount,
                    'payment_method' => $data['payment_method'],
                    'payment_status' => PaymentStatus::Paid->value,
                    'transaction_ref' => 'TXN-'.strtoupper(Str::random(12)),
                    'paid_at' => now(),
                ],
            );

            $booking->update(['status' => BookingStatus::Confirmed->value]);
            $booking->logs()->create([
                'old_status' => BookingStatus::Pending->value,
                'new_status' => BookingStatus::Confirmed->value,
                'actor_type' => 'passenger', 'actor_id' => $booking->passenger_id,
                'note' => 'Payment received and ticket issued.',
            ]);
            $ticket = $booking->ticket()->firstOrCreate(
                ['booking_id' => $booking->id],
                [
                    'ticket_number' => 'TKT-'.strtoupper(Str::random(10)),
                    'ticket_code' => strtoupper(Str::random(8)),
                    'issued_at' => now(),
                ],
            );
            $booking->passenger->notifications()->create([
                'title' => 'Booking confirmed',
                'message' => "Booking {$booking->booking_ref} is confirmed. Ticket {$ticket->ticket_number} is ready.",
            ]);

            return compact('payment', 'ticket');
        });

        LogActivity::dispatch('payment.paid', [
            'booking_ref' => $booking->booking_ref,
            'amount' => $amount,
            'method' => $data['payment_method'],
            'ticket_number' => $result['ticket']->ticket_number,
        ], $type, $user->id);

        return response()->json([
            'payment' => $result['payment'],
            'ticket' => $result['ticket'],
            'booking' => new BookingResource($booking->fresh(BookingController::RELATIONS)),
        ], 201);
    }

    public function show(Request $request, Booking $booking)
    {
        $type = $request->attributes->get('auth_type');
        $user = $request->attributes->get('auth_user');
        if ($type === 'passenger' && $booking->passenger_id !== $user->id) {
            abort(403, 'This booking does not belong to you.');
        }

        return $booking->payment ?? response()->json(['message' => 'No payment found.'], 404);
    }
}
