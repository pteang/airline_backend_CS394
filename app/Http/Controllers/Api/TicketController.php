<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Ticket;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    /** Retrieve the ticket issued for a booking. */
    public function showForBooking(Request $request, Booking $booking)
    {
        $type = $request->attributes->get('auth_type');
        $user = $request->attributes->get('auth_user');
        if ($type === 'passenger' && $booking->passenger_id !== $user->id) {
            abort(403, 'This booking does not belong to you.');
        }

        $ticket = $booking->ticket()->with([
            'booking.flight.route.origin',
            'booking.flight.route.destination',
            'booking.passengers',
        ])->first();

        return $ticket ?? response()->json(['message' => 'No ticket issued yet.'], 404);
    }

    /** Look up a ticket by its code (e.g. for check-in / boarding). */
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'ticket_code' => ['required', 'string'],
        ]);

        $ticket = Ticket::where('ticket_code', $data['ticket_code'])
            ->with(['booking.flight.route', 'booking.passengers'])
            ->firstOrFail();

        return $ticket;
    }
}
