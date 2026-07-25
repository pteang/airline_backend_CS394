<?php

namespace Tests\Feature;

use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\InternalUser;
use App\Models\PassengerProfile;
use App\Models\Route;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AirlineWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_passenger_can_book_pay_modify_and_cancel_a_flight(): void
    {
        Bus::fake();
        $admin = InternalUser::create([
            'full_name' => 'Admin User', 'email' => 'admin@example.com',
            'password_hash' => Hash::make('password123'), 'role' => 'admin', 'is_active' => true,
        ]);
        UserSession::create([
            'internal_user_id' => $admin->id, 'session_token' => 'admin-token',
            'login_at' => now(), 'expires_at' => now()->addDay(), 'is_active' => true,
        ]);
        $origin = Airport::create(['iata_code' => 'PNH', 'name' => 'Phnom Penh', 'city' => 'Phnom Penh', 'country' => 'Cambodia']);
        $destination = Airport::create(['iata_code' => 'BKK', 'name' => 'Bangkok', 'city' => 'Bangkok', 'country' => 'Thailand']);
        $route = Route::create([
            'origin_airport_id' => $origin->id, 'destination_airport_id' => $destination->id,
            'distance_km' => 500, 'estimated_duration' => 75, 'is_active' => true,
        ]);
        $aircraft = Aircraft::create([
            'registration_number' => 'XU-001', 'model' => 'A320', 'capacity' => 2, 'status' => 'available',
        ]);
        $aircraft->seats()->createMany([
            ['seat_number' => '1A', 'seat_class' => 'economy', 'is_window' => true],
            ['seat_number' => '1B', 'seat_class' => 'economy', 'is_aisle' => true],
        ]);

        $flightId = $this->withToken('admin-token')->postJson('/api/flights', [
            'flight_number' => 'K601', 'route_id' => $route->id, 'aircraft_id' => $aircraft->id,
            'departure_time' => now()->addDays(2), 'arrival_time' => now()->addDays(2)->addHour(),
            'base_price' => 100, 'class_prices' => [['seat_class' => 'economy', 'price' => 120]],
        ])->assertCreated()->json('id');

        $registration = $this->postJson('/api/auth/register', [
            'name' => 'Passenger One', 'email' => 'passenger@example.com',
            'password' => 'password123', 'passport_number' => 'P123',
            'nationality' => 'Cambodian', 'date_of_birth' => '2000-01-01',
        ])->assertCreated();
        $token = $registration->json('token');
        $profile = PassengerProfile::where('user_id', $registration->json('user.id'))->firstOrFail();
        $seats = Flight::findOrFail($flightId)->seats;

        $bookingId = $this->withToken($token)->postJson('/api/bookings', [
            'flight_id' => $flightId,
            'passengers' => [[
                'passenger_id' => $profile->id, 'seat_class' => 'economy',
                'flight_seat_id' => $seats[0]->id,
            ]],
        ])->assertCreated()->json('id');

        $travellerId = $this->withToken($token)->getJson("/api/bookings/{$bookingId}")
            ->assertOk()->json('passengers.0.id');
        $this->withToken($token)->putJson("/api/bookings/{$bookingId}", [
            'passenger_id' => $travellerId, 'seat_class' => 'economy',
            'flight_seat_id' => $seats[1]->id, 'special_request' => 'Vegetarian meal',
        ])->assertOk()->assertJsonPath('passengers.0.flight_seat_id', $seats[1]->id);

        $this->withToken($token)->postJson("/api/bookings/{$bookingId}/payment", [
            'payment_method' => 'credit_card',
        ])->assertCreated()->assertJsonPath('payment.amount', '120.00');

        $this->withToken($token)->postJson("/api/bookings/{$bookingId}/cancel")
            ->assertOk()->assertJsonPath('status', 'cancelled')
            ->assertJsonPath('payment.payment_status', 'refunded');
    }
}
