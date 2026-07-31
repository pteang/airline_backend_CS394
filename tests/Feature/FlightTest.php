<?php

namespace Tests\Feature;

use App\Models\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithAirline;
use Tests\TestCase;

class FlightTest extends TestCase
{
    use InteractsWithAirline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_public_flight_search_returns_flat_shape_filtered_by_route(): void
    {
        $jfk = $this->airport('JFK', 'New York');
        $lax = $this->airport('LAX', 'Los Angeles');
        $flight = $this->scheduledFlight(route: $this->route($jfk, $lax));

        $this->getJson('/api/flights/search?origin=JFK&destination=LAX')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.number', $flight->flight_number)
            ->assertJsonPath('0.originCode', 'JFK')
            ->assertJsonPath('0.destinationCode', 'LAX')
            ->assertJsonPath('0.availableSeats', 4);

        // A non-matching origin yields nothing.
        $this->getJson('/api/flights/search?origin=SFO')->assertOk()->assertJsonCount(0);
    }

    public function test_flight_seats_endpoint_returns_priced_seats(): void
    {
        $flight = $this->scheduledFlight();

        $this->getJson("/api/flights/{$flight->id}/seats")
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.status', 'available')
            ->assertJsonStructure(['*' => ['id', 'row', 'column', 'class', 'price', 'status']]);
    }

    public function test_internal_user_can_schedule_a_flight_with_seat_inventory(): void
    {
        $admin = $this->internalUser();
        $token = $this->tokenFor($admin);
        $route = $this->route();
        $aircraft = $this->aircraftWithSeats();

        $flightId = $this->withToken($token)->postJson('/api/flights', [
            'flight_number' => 'ZZ900',
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'departure_time' => now()->addDays(5)->toIso8601String(),
            'arrival_time' => now()->addDays(5)->addHours(2)->toIso8601String(),
            'base_price' => 120,
            'class_prices' => [['seat_class' => 'economy', 'price' => 120]],
        ])->assertCreated()->json('id');

        // One flight_seat generated per physical seat (4).
        $this->assertDatabaseCount('flight_seats', 4);
        $this->assertDatabaseHas('flights', ['id' => $flightId, 'flight_number' => 'ZZ900']);
    }

    public function test_scheduling_rejects_an_aircraft_in_maintenance(): void
    {
        $admin = $this->internalUser();
        $token = $this->tokenFor($admin);
        $route = $this->route();
        $aircraft = $this->aircraftWithSeats(status: 'maintenance');

        $this->withToken($token)->postJson('/api/flights', [
            'flight_number' => 'MX1',
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'departure_time' => now()->addDays(2)->toIso8601String(),
            'arrival_time' => now()->addDays(2)->addHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_scheduling_detects_an_overlapping_aircraft_assignment(): void
    {
        $admin = $this->internalUser();
        $token = $this->tokenFor($admin);
        $existing = $this->scheduledFlight($admin);

        // Reuse the same aircraft + overlapping window -> conflict.
        $this->withToken($token)->postJson('/api/flights', [
            'flight_number' => 'OVL1',
            'route_id' => $existing->route_id,
            'aircraft_id' => $existing->aircraft_id,
            'departure_time' => $existing->departure_time->copy()->addMinutes(30)->toIso8601String(),
            'arrival_time' => $existing->arrival_time->copy()->addMinutes(30)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_scheduling_rejects_a_closed_gate(): void
    {
        $admin = $this->internalUser();
        $token = $this->tokenFor($admin);
        $origin = $this->airport('ORG', 'Origin');
        $route = $this->route($origin, $this->airport('DST', 'Dest'));
        $aircraft = $this->aircraftWithSeats();
        $gate = Gate::create(['airport_id' => $origin->id, 'gate_code' => 'G1', 'status' => 'closed']);

        $this->withToken($token)->postJson('/api/flights', [
            'flight_number' => 'GT1',
            'route_id' => $route->id,
            'aircraft_id' => $aircraft->id,
            'gate_id' => $gate->id,
            'departure_time' => now()->addDays(2)->toIso8601String(),
            'arrival_time' => now()->addDays(2)->addHour()->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_status_change_records_history(): void
    {
        $admin = $this->internalUser();
        $token = $this->tokenFor($admin);
        $flight = $this->scheduledFlight($admin);

        $this->withToken($token)->patchJson("/api/flights/{$flight->id}/status", [
            'status' => 'boarding', 'reason' => 'On schedule',
        ])->assertOk()->assertJsonPath('status', 'boarding');

        $this->assertDatabaseHas('flight_status_log', [
            'flight_id' => $flight->id, 'old_status' => 'scheduled', 'new_status' => 'boarding',
        ]);
    }

    public function test_maintenance_conflicting_with_a_flight_is_rejected(): void
    {
        $admin = $this->internalUser();
        $token = $this->tokenFor($admin);
        $flight = $this->scheduledFlight($admin);

        $this->withToken($token)->postJson('/api/maintenance', [
            'aircraft_id' => $flight->aircraft_id,
            'maintenance_type' => 'routine',
            'scheduled_date' => $flight->departure_time->toDateString(),
        ])->assertStatus(422);

        $this->assertDatabaseCount('maintenance_schedule', 0);
    }
}
