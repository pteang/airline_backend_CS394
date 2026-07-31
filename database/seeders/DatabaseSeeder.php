<?php

namespace Database\Seeders;

use App\Enums\AircraftStatus;
use App\Enums\BookingStatus;
use App\Enums\CrewAssignmentRole;
use App\Enums\FlightStatus;
use App\Enums\GateStatus;
use App\Enums\MaintenanceType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SeatClass;
use App\Enums\StaffAvailabilityStatus;
use App\Enums\StaffRole;
use App\Enums\TripType;
use App\Enums\UserRole;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Booking;
use App\Models\CrewAssignment;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\InternalUser;
use App\Models\MaintenanceSchedule;
use App\Models\PassengerProfile;
use App\Models\Route;
use App\Models\Staff;
use App\Models\User;
use Faker\Factory as Faker;
use Faker\Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bulk demo seeder — generates a realistic, sizeable dataset so every screen is
 * populated for real end-to-end testing: airports, routes, aircraft with full
 * seat maps, gates, flights across every status, staff of every role with
 * availability + crew assignments, passengers with profiles, bookings in every
 * state with payments/tickets, maintenance history, and notifications.
 *
 * Tunable volumes:
 */
class DatabaseSeeder extends Seeder
{
    private const AIRCRAFT_COUNT = 12;

    private const FLIGHTS = 70;

    private const STAFF = 24;

    private const PASSENGERS = 45;

    private const BOOKINGS = 90;

    private const MAINTENANCE = 18;

    private Generator $faker;

    public function run(): void
    {
        $this->faker = Faker::create();
        $this->faker->seed(2026); // deterministic runs

        $airports = $this->seedAirports();
        $routes = $this->seedRoutes($airports);
        $gates = $this->seedGates($airports);
        [$admins, $staff] = $this->seedStaff();
        $aircraft = $this->seedAircraft();
        $flights = $this->seedFlights($routes, $aircraft, $gates, $admins->first());
        $this->seedStaffAvailability($staff, $flights);
        $this->seedCrewAssignments($flights, $staff, $admins->first());
        $passengers = $this->seedPassengers();
        $this->seedBookings($flights, $passengers);
        $this->seedMaintenance($aircraft, $staff);

        $this->command?->info(sprintf(
            'Seeded: %d airports, %d routes, %d aircraft, %d flights, %d staff, %d passengers, %d bookings.',
            $airports->count(), $routes->count(), $aircraft->count(), $flights->count(),
            $staff->count() + $admins->count(), $passengers->count(), Booking::count(),
        ));
    }

    // ── Reference data ─────────────────────────────────────────────────────

    /** @return Collection<int, Airport> */
    private function seedAirports()
    {
        $data = [
            ['JFK', 'John F. Kennedy Intl', 'New York', 'USA'],
            ['LAX', 'Los Angeles Intl', 'Los Angeles', 'USA'],
            ['ORD', "O'Hare Intl", 'Chicago', 'USA'],
            ['SFO', 'San Francisco Intl', 'San Francisco', 'USA'],
            ['MIA', 'Miami Intl', 'Miami', 'USA'],
            ['LHR', 'Heathrow', 'London', 'UK'],
            ['CDG', 'Charles de Gaulle', 'Paris', 'France'],
            ['FRA', 'Frankfurt', 'Frankfurt', 'Germany'],
            ['AMS', 'Schiphol', 'Amsterdam', 'Netherlands'],
            ['DXB', 'Dubai Intl', 'Dubai', 'UAE'],
            ['SIN', 'Changi', 'Singapore', 'Singapore'],
            ['HND', 'Haneda', 'Tokyo', 'Japan'],
            ['NRT', 'Narita', 'Tokyo', 'Japan'],
            ['SYD', 'Kingsford Smith', 'Sydney', 'Australia'],
            ['PNH', 'Phnom Penh Intl', 'Phnom Penh', 'Cambodia'],
            ['BKK', 'Suvarnabhumi', 'Bangkok', 'Thailand'],
        ];

        return collect($data)->map(fn ($a) => Airport::create([
            'iata_code' => $a[0], 'name' => $a[1], 'city' => $a[2], 'country' => $a[3],
        ]));
    }

    /** @return Collection<int, Route> */
    private function seedRoutes($airports)
    {
        $routes = collect();
        // Full mesh: every airport connects to every other, both directions, so
        // any origin→destination the passenger searches has real flights.
        foreach ($airports as $origin) {
            foreach ($airports as $dest) {
                if ($origin->id === $dest->id) {
                    continue;
                }
                $routes->push(Route::create([
                    'origin_airport_id' => $origin->id,
                    'destination_airport_id' => $dest->id,
                    'distance_km' => $this->faker->numberBetween(400, 14000),
                    'estimated_duration' => $this->faker->numberBetween(60, 900),
                    'is_active' => true,
                ]));
            }
        }

        return $routes;
    }

    /** @return Collection<int, Gate> */
    private function seedGates($airports)
    {
        $gates = collect();
        foreach ($airports as $airport) {
            foreach (range(1, $this->faker->numberBetween(2, 5)) as $i) {
                $gates->push(Gate::create([
                    'airport_id' => $airport->id,
                    'gate_code' => $this->faker->randomElement(['A', 'B', 'C', 'D']).$this->faker->numberBetween(1, 30),
                    'status' => $this->faker->randomElement(GateStatus::cases())->value,
                ]));
            }
        }

        return $gates;
    }

    // ── Fleet ──────────────────────────────────────────────────────────────

    /** @return Collection<int, Aircraft> */
    private function seedAircraft()
    {
        $models = [
            ['Airbus', 'A320neo'], ['Airbus', 'A321'], ['Airbus', 'A350-900'], ['Airbus', 'A380'],
            ['Boeing', '737-800'], ['Boeing', '777-300ER'], ['Boeing', '787-9'], ['Boeing', '747-8'],
        ];

        return collect(range(1, self::AIRCRAFT_COUNT))->map(function ($i) use ($models) {
            [$mfr, $model] = $this->faker->randomElement($models);
            $status = $this->faker->randomElement([
                AircraftStatus::Available, AircraftStatus::Available, AircraftStatus::Available,
                AircraftStatus::Assigned, AircraftStatus::Maintenance, AircraftStatus::Retired,
            ])->value;

            $aircraft = Aircraft::create([
                'registration_number' => 'N'.$this->faker->unique()->numberBetween(1000, 9999).$this->faker->randomLetter().$this->faker->randomLetter(),
                'model' => $model,
                'manufacturer' => $mfr,
                'capacity' => 0, // set after building the seat map
                'status' => $status,
                'flight_hours' => $this->faker->numberBetween(2000, 60000),
            ]);

            $capacity = $this->buildSeatMap($aircraft);
            $aircraft->update(['capacity' => $capacity]);

            return $aircraft;
        });
    }

    /** Build a first/business/economy seat map; returns the seat count. */
    private function buildSeatMap(Aircraft $aircraft): int
    {
        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];
        $layout = [
            [range(1, 2), SeatClass::FirstClass->value],
            [range(3, $this->faker->numberBetween(5, 8)), SeatClass::Business->value],
            [range(10, $this->faker->numberBetween(22, 34)), SeatClass::Economy->value],
        ];

        $rows = [];
        foreach ($layout as [$rowRange, $class]) {
            foreach ($rowRange as $row) {
                foreach ($columns as $col) {
                    $rows[] = [
                        'aircraft_id' => $aircraft->id,
                        'seat_number' => $row.$col,
                        'seat_class' => $class,
                        'is_window' => in_array($col, ['A', 'F'], true),
                        'is_aisle' => in_array($col, ['C', 'D'], true),
                    ];
                }
            }
        }
        $aircraft->seats()->insert($rows);

        return count($rows);
    }

    // ── People: internal users + staff ───────────────────────────────────────

    /** @return array{0: Collection, 1: Collection} */
    private function seedStaff(): array
    {
        // Fixed demo accounts (match the frontend's quick-login).
        $admin = InternalUser::create([
            'full_name' => 'Ops Admin', 'email' => 'admin@airline.test',
            'password_hash' => Hash::make('password'), 'phone' => '+1-555-0001',
            'role' => UserRole::Admin->value, 'is_active' => true,
        ]);

        $captainUser = InternalUser::create([
            'full_name' => 'Capt. James Wilson', 'email' => 'captain@airline.test',
            'password_hash' => Hash::make('password'), 'phone' => '+1-555-0002',
            'role' => UserRole::Manager->value, 'is_active' => true,
        ]);

        $admins = collect([$admin]);
        $staff = collect();

        // The demo captain gets a pilot staff record.
        $staff->push(Staff::create([
            'internal_user_id' => $captainUser->id, 'employee_id' => 'EMP-0001',
            'license_number' => 'ATP-US-'.$this->faker->numberBetween(10000, 99999),
            'license_expiry' => now()->addYears(3)->toDateString(),
            'staff_role' => StaffRole::Pilot->value, 'joined_date' => '2016-03-01',
        ]));

        $roles = StaffRole::cases();
        foreach (range(2, self::STAFF) as $i) {
            $role = $roles[$i % count($roles)];
            $user = InternalUser::create([
                'full_name' => $this->faker->name(),
                'email' => $this->faker->unique()->safeEmail(),
                'password_hash' => Hash::make('password'),
                'phone' => $this->faker->phoneNumber(),
                'role' => $this->faker->randomElement([UserRole::Agent, UserRole::Agent, UserRole::Manager])->value,
                'is_active' => $this->faker->boolean(92),
            ]);
            $staff->push(Staff::create([
                'internal_user_id' => $user->id,
                'employee_id' => 'EMP-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'license_number' => strtoupper(Str::random(3)).'-'.$this->faker->numberBetween(10000, 99999),
                'license_expiry' => now()->addMonths($this->faker->numberBetween(2, 48))->toDateString(),
                'staff_role' => $role->value,
                'joined_date' => $this->faker->dateTimeBetween('-12 years', '-3 months')->format('Y-m-d'),
            ]));
        }

        return [$admins, $staff];
    }

    // ── Flights ──────────────────────────────────────────────────────────────

    /** @return Collection<int, Flight> */
    private function seedFlights($routes, $aircraft, $gates, InternalUser $creator)
    {
        $activeRoutes = $routes->where('is_active', true)->values();
        $bookableAircraft = $aircraft->filter(fn ($a) => in_array($a->status->value, ['available', 'assigned'], true))->values();
        if ($bookableAircraft->isEmpty()) {
            $bookableAircraft = $aircraft;
        }

        $flights = collect();
        $flightNo = 100;

        // Cache each aircraft's physical seat ids once (avoids re-querying per flight).
        $seatIdsByAircraft = [];
        foreach ($bookableAircraft as $plane) {
            $seatIdsByAircraft[$plane->id] = $plane->seats()->pluck('id')->all();
        }

        // For every route, schedule several flights spread across the next 3 weeks
        // so any origin→destination search returns a rich set of upcoming options.
        foreach ($activeRoutes as $route) {
            $perRoute = $this->faker->numberBetween(2, 4);
            $airportGates = $gates->where('airport_id', $route->origin_airport_id);

            foreach (range(1, $perRoute) as $i) {
                $plane = $bookableAircraft->random();
                $day = $this->faker->numberBetween(0, 21); // today .. +3 weeks (all upcoming)
                $depart = Carbon::today()->addDays($day)->setTime($this->faker->numberBetween(5, 21), $this->faker->randomElement([0, 15, 30, 45]));
                $durMin = $route->estimated_duration ?: $this->faker->numberBetween(75, 780);
                $arrive = (clone $depart)->addMinutes($durMin);

                // Mostly scheduled, occasionally delayed — all upcoming and bookable.
                $status = $this->faker->randomElement([
                    FlightStatus::Scheduled, FlightStatus::Scheduled, FlightStatus::Scheduled, FlightStatus::Delayed,
                ]);

                $gate = $airportGates->isNotEmpty() && $this->faker->boolean(75) ? $airportGates->random() : null;

                $base = $this->faker->numberBetween(89, 1200);
                $flight = Flight::create([
                    'flight_number' => 'AV'.$flightNo++,
                    'route_id' => $route->id,
                    'aircraft_id' => $plane->id,
                    'gate_id' => $gate?->id,
                    'departure_time' => $depart,
                    'arrival_time' => $arrive,
                    'status' => $status->value,
                    'base_price' => $base,
                    'created_by' => $creator->id,
                ]);

                // Flight seat inventory (one row per physical seat).
                $seatRows = array_map(fn ($id) => [
                    'flight_id' => $flight->id, 'aircraft_seat_id' => $id, 'is_available' => true,
                ], $seatIdsByAircraft[$plane->id] ?? []);
                if ($seatRows) {
                    $flight->seats()->insert($seatRows);
                }

                $flight->classPrices()->createMany([
                    ['seat_class' => SeatClass::Economy->value, 'price' => $base],
                    ['seat_class' => SeatClass::Business->value, 'price' => round($base * 2.6, 2)],
                    ['seat_class' => SeatClass::FirstClass->value, 'price' => round($base * 4.5, 2)],
                ]);

                $flights->push($flight);
            }
        }

        return $flights;
    }

    private function seedStaffAvailability($staff, $flights): void
    {
        foreach ($staff as $member) {
            $used = [];
            foreach (range(-3, 21) as $offset) {
                if (! $this->faker->boolean(70)) {
                    continue;
                }
                $date = Carbon::today()->addDays($offset)->toDateString();
                if (isset($used[$date])) {
                    continue;
                }
                $used[$date] = true;
                $status = $this->faker->randomElement([
                    StaffAvailabilityStatus::Available, StaffAvailabilityStatus::Available,
                    StaffAvailabilityStatus::Unavailable, StaffAvailabilityStatus::OnLeave,
                    StaffAvailabilityStatus::Training,
                ])->value;
                // Write through Eloquent so the date cast matches the format used
                // by the crew-assignment updateOrCreate below.
                $member->availability()->create([
                    'available_date' => $date,
                    'status' => $status,
                    'reason' => $status === 'on-leave' ? 'Approved leave' : null,
                ]);
            }
        }
    }

    private function seedCrewAssignments($flights, $staff, InternalUser $assignedBy): void
    {
        $pilots = $staff->filter(fn ($s) => in_array($s->staff_role->value, [StaffRole::Pilot->value, StaffRole::Copilot->value], true))->values();
        $cabin = $staff->filter(fn ($s) => in_array($s->staff_role->value, [StaffRole::CabinCrew->value, StaffRole::Manager->value], true))->values();

        foreach ($flights->random(min($flights->count(), 40)) as $flight) {
            $crew = collect();
            if ($pilots->isNotEmpty()) {
                $crew->push([$pilots->random(), CrewAssignmentRole::Captain]);
            }
            if ($pilots->count() > 1) {
                $crew->push([$pilots->random(), CrewAssignmentRole::FirstOfficer]);
            }
            foreach ($cabin->random(min($cabin->count(), $this->faker->numberBetween(1, 3))) as $c) {
                $crew->push([$c, CrewAssignmentRole::FlightAttendant]);
            }

            $usedStaff = [];
            foreach ($crew as [$member, $role]) {
                if (isset($usedStaff[$member->id])) {
                    continue; // one row per (flight, staff)
                }
                $usedStaff[$member->id] = true;
                CrewAssignment::create([
                    'flight_id' => $flight->id, 'staff_id' => $member->id,
                    'assignment_role' => $role->value,
                    'assigned_by' => $assignedBy->id, 'assigned_at' => now(),
                ]);
                // Reflect the assignment in the crew member's availability.
                // whereDate normalizes the date-cast column so this matches a
                // pre-existing row; only create when none exists.
                $date = $flight->departure_time->toDateString();
                $updated = $member->availability()->whereDate('available_date', $date)
                    ->update(['status' => StaffAvailabilityStatus::Flight->value]);
                if ($updated === 0) {
                    $member->availability()->create([
                        'available_date' => $date,
                        'status' => StaffAvailabilityStatus::Flight->value,
                    ]);
                }
            }
        }
    }

    // ── Passengers ───────────────────────────────────────────────────────────

    /** @return Collection<int, array{user: User, profile: PassengerProfile}> */
    private function seedPassengers()
    {
        $passengers = collect();

        // Fixed demo passenger.
        $jane = User::create([
            'full_name' => 'Jane Traveller', 'email' => 'jane@example.test',
            'password_hash' => Hash::make('password'), 'phone' => '+1-555-0100', 'is_active' => true,
        ]);
        $passengers->push([
            'user' => $jane,
            'profile' => PassengerProfile::create([
                'user_id' => $jane->id, 'passport_number' => 'P1234567',
                'nationality' => 'US', 'date_of_birth' => '1990-05-20',
            ]),
        ]);

        foreach (range(2, self::PASSENGERS) as $i) {
            $user = User::create([
                'full_name' => $this->faker->name(),
                'email' => $this->faker->unique()->safeEmail(),
                'password_hash' => Hash::make('password'),
                'phone' => $this->faker->phoneNumber(),
                'is_active' => $this->faker->boolean(96),
            ]);
            $passengers->push([
                'user' => $user,
                'profile' => PassengerProfile::create([
                    'user_id' => $user->id,
                    'passport_number' => strtoupper($this->faker->bothify('?#######')),
                    'nationality' => $this->faker->countryCode(),
                    'date_of_birth' => $this->faker->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
                    'special_needs' => $this->faker->boolean(12) ? $this->faker->randomElement(['Wheelchair assistance', 'Vegetarian meal', 'Extra legroom']) : null,
                ]),
            ]);
        }

        return $passengers;
    }

    // ── Bookings + payments + tickets ────────────────────────────────────────

    private function seedBookings($flights, $passengers): void
    {
        $bookableFlights = $flights->filter(fn ($f) => $f->status->value !== FlightStatus::Cancelled->value)->values();
        if ($bookableFlights->isEmpty()) {
            return;
        }

        foreach (range(1, self::BOOKINGS) as $i) {
            $flight = $bookableFlights->random();
            $pax = $passengers->random();

            $available = $flight->seats()->where('is_available', true)->with('aircraftSeat')->take(3)->get();
            if ($available->isEmpty()) {
                continue;
            }
            $seat = $available->first();

            $status = $this->faker->randomElement([
                BookingStatus::Confirmed, BookingStatus::Confirmed, BookingStatus::Confirmed,
                BookingStatus::Pending, BookingStatus::Cancelled, BookingStatus::Completed,
            ]);

            $booking = Booking::create([
                'booking_ref' => $this->uniqueRef(),
                'passenger_id' => $pax['user']->id,
                'flight_id' => $flight->id,
                'trip_type' => TripType::OneWay->value,
                'status' => $status->value,
                'booked_at' => $this->faker->dateTimeBetween('-20 days', 'now'),
            ]);

            $seatClass = $seat->aircraftSeat->seat_class->value;
            $reserve = $status !== BookingStatus::Cancelled;
            if ($reserve) {
                $seat->update(['is_available' => false]);
            }
            $booking->passengers()->create([
                'passenger_id' => $pax['profile']->id,
                'flight_seat_id' => $reserve ? $seat->id : null,
                'seat_class' => $seatClass,
                'special_request' => $this->faker->boolean(15) ? 'Window seat preferred' : null,
            ]);

            $booking->logs()->create([
                'new_status' => $status->value, 'actor_type' => 'passenger',
                'actor_id' => $pax['user']->id, 'note' => 'Booking '.$status->value.'.',
            ]);

            $price = $flight->classPrices()->where('seat_class', $seatClass)->value('price') ?? $flight->base_price;

            if (in_array($status, [BookingStatus::Confirmed, BookingStatus::Completed], true)) {
                $booking->payment()->create([
                    'amount' => $price,
                    'payment_method' => $this->faker->randomElement(PaymentMethod::cases())->value,
                    'payment_status' => PaymentStatus::Paid->value,
                    'transaction_ref' => 'TXN-'.strtoupper(Str::random(12)),
                    'paid_at' => $booking->booked_at,
                ]);
                $booking->ticket()->create([
                    'ticket_number' => 'TKT-'.strtoupper(Str::random(10)),
                    'ticket_code' => strtoupper(Str::random(8)),
                    'issued_at' => $booking->booked_at,
                ]);
                $pax['user']->notifications()->create([
                    'title' => 'Booking confirmed',
                    'message' => "Booking {$booking->booking_ref} on {$flight->flight_number} is confirmed.",
                    'is_read' => $this->faker->boolean(50),
                ]);
            } elseif ($status === BookingStatus::Cancelled) {
                $pax['user']->notifications()->create([
                    'title' => 'Booking cancelled',
                    'message' => "Booking {$booking->booking_ref} has been cancelled.",
                    'is_read' => $this->faker->boolean(40),
                ]);
            }
        }
    }

    // ── Maintenance ──────────────────────────────────────────────────────────

    private function seedMaintenance($aircraft, $staff): void
    {
        $technicians = $staff->filter(fn ($s) => in_array($s->staff_role->value, [StaffRole::Technician->value, StaffRole::Engineer->value], true))->values();

        foreach (range(1, self::MAINTENANCE) as $i) {
            $plane = $aircraft->random();
            $tech = $technicians->isNotEmpty() ? $technicians->random() : null;
            $start = Carbon::today()->addDays($this->faker->numberBetween(-60, 45));
            $completed = $start->isPast() && $this->faker->boolean(70);

            $schedule = MaintenanceSchedule::create([
                'aircraft_id' => $plane->id,
                'maintenance_type' => $this->faker->randomElement(MaintenanceType::cases())->value,
                'scheduled_date' => $start->toDateString(),
                'end_date' => (clone $start)->addDays($this->faker->numberBetween(0, 6))->toDateString(),
                'technician_id' => $tech?->id,
                'is_completed' => $completed,
            ]);

            if ($completed) {
                $schedule->details()->create([
                    'aircraft_id' => $plane->id,
                    'work_done' => $this->faker->randomElement([
                        'Routine C-Check completed. All systems nominal.',
                        'Engine compressor blade inspection and replacement.',
                        'Avionics software update and ILS calibration.',
                        'Landing gear hydraulics service.',
                    ]),
                    'parts_used' => $this->faker->boolean(60) ? $this->faker->randomElement(['Hydraulic seals', 'Compressor blades', 'Brake pads', 'Filters']) : null,
                    'technician_id' => $tech?->id,
                    'technician_notes' => $this->faker->boolean(50) ? 'Signed off; returned to service.' : null,
                    'logged_at' => now(),
                ]);
            }
        }
    }

    private function uniqueRef(): string
    {
        do {
            $ref = strtoupper(Str::random(6));
        } while (Booking::where('booking_ref', $ref)->exists());

        return $ref;
    }
}
