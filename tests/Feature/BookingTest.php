<?php

namespace Tests\Feature;

use App\Models\BookingPassenger;
use App\Models\FlightSeat;
use App\Models\PassengerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithAirline;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use InteractsWithAirline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    /** Register a passenger with a profile and return [token, profile]. */
    private function bookingActor(): array
    {
        $user = $this->passenger(withProfile: true);
        $profile = PassengerProfile::where('user_id', $user->id)->firstOrFail();

        return [$this->tokenFor($user), $profile, $user];
    }

    public function test_passenger_can_create_a_booking_and_reserve_a_seat(): void
    {
        [$token, $profile] = $this->bookingActor();
        $flight = $this->scheduledFlight();
        $seat = $flight->seats->firstWhere('aircraftSeat.seat_class.value', 'economy');

        $bookingId = $this->withToken($token)->postJson('/api/bookings', [
            'flight_id' => $flight->id,
            'passengers' => [[
                'passenger_id' => $profile->id, 'seat_class' => 'economy', 'flight_seat_id' => $seat->id,
            ]],
        ])->assertCreated()->json('id');

        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'pending']);
        $this->assertDatabaseHas('flight_seats', ['id' => $seat->id, 'is_available' => false]);
    }

    public function test_booking_response_matches_the_frontend_shape(): void
    {
        [$token, $profile] = $this->bookingActor();
        $flight = $this->scheduledFlight();
        $seat = $flight->seats->firstWhere('aircraftSeat.seat_class.value', 'economy');

        $this->withToken($token)->postJson('/api/bookings', [
            'flight_id' => $flight->id,
            'passengers' => [[
                'passenger_id' => $profile->id, 'seat_class' => 'economy', 'flight_seat_id' => $seat->id,
            ]],
        ])->assertCreated()->assertJsonStructure([
            'id', 'seat', 'seats', 'seatClass', 'price', 'tax', 'total', 'status', 'bookingRef', 'boardingGroup',
            'passenger' => ['name', 'email', 'phone', 'passport'],
            'flight' => ['id', 'number', 'originCode', 'destinationCode', 'departureTime'],
        ])->assertJsonPath('seats', [$seat->aircraftSeat->seat_number]);
    }

    public function test_booking_exposes_every_seat_for_the_boarding_passes(): void
    {
        [$token, $profile] = $this->bookingActor();
        $companion = PassengerProfile::where('user_id', $this->passenger(withProfile: true)->id)->firstOrFail();
        $flight = $this->scheduledFlight();
        $economySeats = $flight->seats->filter(fn ($s) => $s->aircraftSeat->seat_class->value === 'economy')->values();
        [$seatA, $seatB] = [$economySeats[0], $economySeats[1]];

        $this->withToken($token)->postJson('/api/bookings', [
            'flight_id' => $flight->id,
            'passengers' => [
                ['passenger_id' => $profile->id, 'seat_class' => 'economy', 'flight_seat_id' => $seatA->id],
                ['passenger_id' => $companion->id, 'seat_class' => 'economy', 'flight_seat_id' => $seatB->id],
            ],
        ])->assertCreated()
            ->assertJsonPath('seat', $seatA->aircraftSeat->seat_number)
            ->assertJsonPath('seats', [$seatA->aircraftSeat->seat_number, $seatB->aircraftSeat->seat_number]);
    }

    public function test_booking_a_taken_seat_is_rejected(): void
    {
        [$token, $profile] = $this->bookingActor();
        $flight = $this->scheduledFlight();
        $seat = $flight->seats->firstWhere('aircraftSeat.seat_class.value', 'economy');
        FlightSeat::whereKey($seat->id)->update(['is_available' => false]);

        $this->withToken($token)->postJson('/api/bookings', [
            'flight_id' => $flight->id,
            'passengers' => [[
                'passenger_id' => $profile->id, 'seat_class' => 'economy', 'flight_seat_id' => $seat->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('passengers');
    }

    public function test_booking_a_seat_in_the_wrong_class_is_rejected(): void
    {
        [$token, $profile] = $this->bookingActor();
        $flight = $this->scheduledFlight();
        $businessSeat = $flight->seats->firstWhere('aircraftSeat.seat_class.value', 'business');

        $this->withToken($token)->postJson('/api/bookings', [
            'flight_id' => $flight->id,
            'passengers' => [[
                'passenger_id' => $profile->id, 'seat_class' => 'economy', 'flight_seat_id' => $businessSeat->id,
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('passengers');
    }

    public function test_changing_a_seat_releases_the_old_one(): void
    {
        [$token, $profile] = $this->bookingActor();
        $flight = $this->scheduledFlight();
        $economy = $flight->seats->where('aircraftSeat.seat_class.value', 'economy')->values();

        $bookingId = $this->withToken($token)->postJson('/api/bookings', [
            'flight_id' => $flight->id,
            'passengers' => [[
                'passenger_id' => $profile->id, 'seat_class' => 'economy', 'flight_seat_id' => $economy[0]->id,
            ]],
        ])->assertCreated()->json('id');

        $this->withToken($token)->getJson("/api/bookings/{$bookingId}")->assertOk();
        $travellerId = BookingPassenger::where('booking_id', $bookingId)->value('id');

        $this->withToken($token)->putJson("/api/bookings/{$bookingId}", [
            'passenger_id' => $travellerId, 'seat_class' => 'economy', 'flight_seat_id' => $economy[1]->id,
        ])->assertOk();

        $this->assertDatabaseHas('flight_seats', ['id' => $economy[0]->id, 'is_available' => true]);
        $this->assertDatabaseHas('flight_seats', ['id' => $economy[1]->id, 'is_available' => false]);
    }

    public function test_cancelling_releases_seats_and_refunds_a_paid_booking(): void
    {
        [$token, $profile] = $this->bookingActor();
        $flight = $this->scheduledFlight();
        $seat = $flight->seats->firstWhere('aircraftSeat.seat_class.value', 'economy');

        $bookingId = $this->withToken($token)->postJson('/api/bookings', [
            'flight_id' => $flight->id,
            'passengers' => [[
                'passenger_id' => $profile->id, 'seat_class' => 'economy', 'flight_seat_id' => $seat->id,
            ]],
        ])->assertCreated()->json('id');

        $this->withToken($token)->postJson("/api/bookings/{$bookingId}/payment", [
            'payment_method' => 'credit_card',
        ])->assertCreated();

        $this->withToken($token)->postJson("/api/bookings/{$bookingId}/cancel")
            ->assertOk()
            ->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('paymentStatus', 'refunded');

        $this->assertDatabaseHas('flight_seats', ['id' => $seat->id, 'is_available' => true]);
    }

    public function test_a_passenger_cannot_view_another_passengers_booking(): void
    {
        [$ownerToken, $profile] = $this->bookingActor();
        $flight = $this->scheduledFlight();
        $seat = $flight->seats->firstWhere('aircraftSeat.seat_class.value', 'economy');

        $bookingId = $this->withToken($ownerToken)->postJson('/api/bookings', [
            'flight_id' => $flight->id,
            'passengers' => [[
                'passenger_id' => $profile->id, 'seat_class' => 'economy', 'flight_seat_id' => $seat->id,
            ]],
        ])->assertCreated()->json('id');

        $intruderToken = $this->tokenFor($this->passenger());
        $this->withToken($intruderToken)->getJson("/api/bookings/{$bookingId}")->assertForbidden();
    }
}
