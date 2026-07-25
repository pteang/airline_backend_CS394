<?php

namespace Database\Seeders;

use App\Enums\AircraftStatus;
use App\Enums\FlightStatus;
use App\Enums\SeatClass;
use App\Enums\StaffRole;
use App\Enums\UserRole;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Flight;
use App\Models\InternalUser;
use App\Models\PassengerProfile;
use App\Models\Route;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // --- Accounts ---------------------------------------------------
        $admin = InternalUser::create([
            'full_name' => 'Ops Admin',
            'email' => 'admin@airline.test',
            'password_hash' => Hash::make('password'),
            'role' => UserRole::Admin->value,
            'is_active' => true,
        ]);

        Staff::create([
            'internal_user_id' => $admin->id,
            'employee_id' => 'EMP-0001',
            'staff_role' => StaffRole::Pilot->value,
            'joined_date' => '2020-01-15',
        ]);

        $passenger = User::create([
            'full_name' => 'Jane Traveller',
            'email' => 'jane@example.test',
            'password_hash' => Hash::make('password'),
            'phone' => '+1-555-0100',
            'is_active' => true,
        ]);

        PassengerProfile::create([
            'user_id' => $passenger->id,
            'passport_number' => 'P1234567',
            'nationality' => 'US',
            'date_of_birth' => '1990-05-20',
        ]);

        // --- Reference data ---------------------------------------------
        $jfk = Airport::create(['iata_code' => 'JFK', 'name' => 'John F. Kennedy Intl', 'city' => 'New York', 'country' => 'USA']);
        $lax = Airport::create(['iata_code' => 'LAX', 'name' => 'Los Angeles Intl', 'city' => 'Los Angeles', 'country' => 'USA']);

        $route = Route::create([
            'origin_airport_id' => $jfk->id,
            'destination_airport_id' => $lax->id,
            'distance_km' => 3983,
            'estimated_duration' => 360,
            'is_active' => true,
        ]);

        $aircraft = Aircraft::create([
            'registration_number' => 'N12345',
            'model' => 'A320',
            'capacity' => 6,
            'status' => AircraftStatus::Available->value,
            'manufacturer' => 'Airbus',
            'flight_hours' => 12000,
        ]);

        // Small seat map: 2 business + 4 economy.
        foreach (['1A', '1B'] as $num) {
            $aircraft->seats()->create(['seat_number' => $num, 'seat_class' => SeatClass::Business->value, 'is_window' => true]);
        }
        foreach (['10A', '10B', '10C', '10D'] as $num) {
            $aircraft->seats()->create(['seat_number' => $num, 'seat_class' => SeatClass::Economy->value]);
        }

        // --- A scheduled flight with seat inventory + class prices ------
        $flight = Flight::create([
            'flight_number' => 'AB100',
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'departure_time' => now()->addDays(7)->setTime(9, 0),
            'arrival_time' => now()->addDays(7)->setTime(12, 0),
            'status' => FlightStatus::Scheduled->value,
            'base_price' => 199.00,
            'created_by' => $admin->id,
        ]);

        foreach ($aircraft->seats as $seat) {
            $flight->seats()->create(['aircraft_seat_id' => $seat->id, 'is_available' => true]);
        }

        $flight->classPrices()->createMany([
            ['seat_class' => SeatClass::Economy->value, 'price' => 199.00],
            ['seat_class' => SeatClass::Business->value, 'price' => 549.00],
        ]);
    }
}
