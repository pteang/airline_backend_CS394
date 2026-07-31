<?php

namespace Tests\Concerns;

use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\InternalUser;
use App\Models\PassengerProfile;
use App\Models\Route;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Shared builders for feature tests. Creates the minimal domain graph directly
 * via the models (matching AirlineWorkflowTest) and mints session tokens the
 * same way AuthController::issueToken does, so the auth.session middleware
 * resolves them.
 */
trait InteractsWithAirline
{
    protected function internalUser(string $role = 'admin', bool $active = true): InternalUser
    {
        return InternalUser::create([
            'full_name' => Str::title($role).' User',
            'email' => $role.'-'.Str::random(6).'@airline.test',
            'password_hash' => Hash::make('password123'),
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    protected function passenger(bool $active = true, bool $withProfile = false): User
    {
        $user = User::create([
            'full_name' => 'Passenger '.Str::random(4),
            'email' => 'pax-'.Str::random(6).'@example.test',
            'password_hash' => Hash::make('password123'),
            'phone' => '+1-555-0101',
            'is_active' => $active,
        ]);

        if ($withProfile) {
            PassengerProfile::create([
                'user_id' => $user->id,
                'passport_number' => 'P'.random_int(1000000, 9999999),
                'nationality' => 'US',
                'date_of_birth' => '1990-01-01',
            ]);
        }

        return $user;
    }

    /** Mint a valid bearer token for a passenger (User) or InternalUser. */
    protected function tokenFor(Model $user): string
    {
        $token = Str::random(64);
        UserSession::create([
            'user_id' => $user instanceof User ? $user->id : null,
            'internal_user_id' => $user instanceof InternalUser ? $user->id : null,
            'session_token' => $token,
            'login_at' => now(),
            'expires_at' => now()->addDay(),
            'is_active' => true,
        ]);

        return $token;
    }

    protected function airport(string $iata, ?string $city = null): Airport
    {
        return Airport::create([
            'iata_code' => $iata,
            'name' => $iata.' International',
            'city' => $city ?? $iata.' City',
            'country' => 'Testland',
        ]);
    }

    protected function route(?Airport $origin = null, ?Airport $destination = null): Route
    {
        $origin ??= $this->airport('AAA', 'Alpha');
        $destination ??= $this->airport('BBB', 'Bravo');

        return Route::create([
            'origin_airport_id' => $origin->id,
            'destination_airport_id' => $destination->id,
            'distance_km' => 500,
            'estimated_duration' => 90,
            'is_active' => true,
        ]);
    }

    /** An available aircraft with a small economy+business seat map. */
    protected function aircraftWithSeats(string $status = 'available'): Aircraft
    {
        $aircraft = Aircraft::create([
            'registration_number' => 'REG-'.Str::upper(Str::random(5)),
            'model' => 'A320',
            'capacity' => 4,
            'status' => $status,
        ]);

        $aircraft->seats()->createMany([
            ['seat_number' => '1A', 'seat_class' => 'business', 'is_window' => true],
            ['seat_number' => '1B', 'seat_class' => 'business', 'is_aisle' => true],
            ['seat_number' => '10A', 'seat_class' => 'economy', 'is_window' => true],
            ['seat_number' => '10B', 'seat_class' => 'economy', 'is_aisle' => true],
        ]);

        return $aircraft;
    }

    /**
     * A scheduled flight with generated seat inventory and class prices, ready
     * to be booked. Returns the persisted Flight (with `seats` loaded).
     */
    protected function scheduledFlight(?InternalUser $creator = null, ?Route $route = null, ?Aircraft $aircraft = null): Flight
    {
        $creator ??= $this->internalUser();
        $route ??= $this->route();
        $aircraft ??= $this->aircraftWithSeats();

        static $sequence = 0;
        $sequence++;

        $flight = Flight::create([
            'flight_number' => 'FL'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'departure_time' => now()->addDays(3)->setTime(9, 0),
            'arrival_time' => now()->addDays(3)->setTime(11, 0),
            'status' => 'scheduled',
            'base_price' => 150,
            'created_by' => $creator->id,
        ]);

        foreach ($aircraft->seats as $seat) {
            $flight->seats()->create(['aircraft_seat_id' => $seat->id, 'is_available' => true]);
        }

        $flight->classPrices()->createMany([
            ['seat_class' => 'economy', 'price' => 150],
            ['seat_class' => 'business', 'price' => 400],
        ]);

        return $flight->load('seats.aircraftSeat');
    }
}
