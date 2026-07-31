<?php

namespace Tests\Feature;

use App\Models\PassengerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithAirline;
use Tests\TestCase;

class PaymentTicketTest extends TestCase
{
    use InteractsWithAirline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    /** Create a pending booking for one economy seat; return [token, bookingId]. */
    private function pendingBooking(): array
    {
        $user = $this->passenger(withProfile: true);
        $token = $this->tokenFor($user);
        $profile = PassengerProfile::where('user_id', $user->id)->firstOrFail();
        $flight = $this->scheduledFlight();
        $seat = $flight->seats->firstWhere('aircraftSeat.seat_class.value', 'economy');

        $bookingId = $this->withToken($token)->postJson('/api/bookings', [
            'flight_id' => $flight->id,
            'passengers' => [[
                'passenger_id' => $profile->id, 'seat_class' => 'economy', 'flight_seat_id' => $seat->id,
            ]],
        ])->assertCreated()->json('id');

        return [$token, $bookingId];
    }

    public function test_paying_confirms_the_booking_and_issues_a_ticket(): void
    {
        [$token, $bookingId] = $this->pendingBooking();

        $this->withToken($token)->postJson("/api/bookings/{$bookingId}/payment", [
            'payment_method' => 'credit_card',
        ])->assertCreated()
            ->assertJsonPath('payment.amount', '150.00')
            ->assertJsonPath('payment.payment_status', 'paid')
            ->assertJsonPath('booking.status', 'confirmed')
            ->assertJsonStructure(['ticket' => ['ticket_number', 'ticket_code']]);

        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('tickets', ['booking_id' => $bookingId]);
    }

    public function test_paying_twice_is_rejected(): void
    {
        [$token, $bookingId] = $this->pendingBooking();

        $this->withToken($token)->postJson("/api/bookings/{$bookingId}/payment", ['payment_method' => 'cash'])
            ->assertCreated();
        $this->withToken($token)->postJson("/api/bookings/{$bookingId}/payment", ['payment_method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_ticket_can_be_retrieved_for_booking_and_looked_up_by_code(): void
    {
        [$token, $bookingId] = $this->pendingBooking();

        $this->withToken($token)->postJson("/api/bookings/{$bookingId}/payment", ['payment_method' => 'paypal'])
            ->assertCreated();

        $ticketCode = $this->withToken($token)->getJson("/api/bookings/{$bookingId}/ticket")
            ->assertOk()->json('ticket_code');

        $this->assertNotEmpty($ticketCode);

        // Public lookup by code (no auth).
        $this->postJson('/api/tickets/lookup', ['ticket_code' => $ticketCode])
            ->assertOk()->assertJsonPath('ticket_code', $ticketCode);
    }

    public function test_khmer_payment_methods_are_accepted(): void
    {
        // Alignment with the frontend: aba_pay / acleda / wing must be valid.
        [$token, $bookingId] = $this->pendingBooking();

        $this->withToken($token)->postJson("/api/bookings/{$bookingId}/payment", [
            'payment_method' => 'aba_pay',
        ])->assertCreated()->assertJsonPath('payment.payment_method', 'aba_pay');
    }

    public function test_unknown_ticket_code_returns_404(): void
    {
        $this->postJson('/api/tickets/lookup', ['ticket_code' => 'NOPE1234'])->assertNotFound();
    }

    public function test_ticket_before_payment_is_404(): void
    {
        [$token, $bookingId] = $this->pendingBooking();

        $this->withToken($token)->getJson("/api/bookings/{$bookingId}/ticket")->assertNotFound();
    }
}
