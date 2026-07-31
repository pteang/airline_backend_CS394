<?php

namespace Tests\Feature;

use App\Models\CrewAssignment;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithAirline;
use Tests\TestCase;

class StaffCrewTest extends TestCase
{
    use InteractsWithAirline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    private function staffMember(string $role = 'pilot'): Staff
    {
        return Staff::create([
            'internal_user_id' => $this->internalUser('manager')->id,
            'employee_id' => 'EMP-'.random_int(1000, 9999),
            'staff_role' => $role,
            'joined_date' => '2021-01-01',
        ]);
    }

    public function test_internal_user_can_create_staff(): void
    {
        $token = $this->tokenFor($this->internalUser());
        $employee = $this->internalUser('agent');

        $this->withToken($token)->postJson('/api/staff', [
            'internal_user_id' => $employee->id,
            'employee_id' => 'EMP-5000',
            'staff_role' => 'cabin_crew',
            'joined_date' => '2022-06-01',
        ])->assertCreated()->assertJsonPath('role', 'cabin_crew');

        $this->assertDatabaseHas('staff', ['employee_id' => 'EMP-5000']);
    }

    public function test_frontend_staff_roles_are_accepted(): void
    {
        // Alignment with the frontend: copilot / manager / technician must be valid.
        $token = $this->tokenFor($this->internalUser());

        foreach (['copilot', 'manager', 'technician'] as $i => $role) {
            $employee = $this->internalUser('agent');
            $this->withToken($token)->postJson('/api/staff', [
                'internal_user_id' => $employee->id,
                'employee_id' => 'EMP-70'.$i,
                'staff_role' => $role,
                'joined_date' => '2022-01-01',
            ])->assertCreated()->assertJsonPath('role', $role);
        }
    }

    public function test_staff_response_matches_the_frontend_shape(): void
    {
        $token = $this->tokenFor($this->internalUser());
        $staff = $this->staffMember('pilot');

        $this->withToken($token)->getJson("/api/staff/{$staff->id}")
            ->assertOk()->assertJsonStructure([
                'id', 'name', 'role', 'email', 'phone', 'license',
                'availability', 'initials', 'assignments', 'yearsExp',
            ]);
    }

    public function test_staff_availability_can_be_recorded_and_listed(): void
    {
        $token = $this->tokenFor($this->internalUser());
        $staff = $this->staffMember();
        $date = now()->addDays(3)->toDateString();

        $this->withToken($token)->postJson("/api/staff/{$staff->id}/availability", [
            'available_date' => $date, 'status' => 'available',
        ])->assertCreated();

        $this->withToken($token)->getJson("/api/staff/{$staff->id}/availability")
            ->assertOk()->assertJsonPath('0.status', 'available');
    }

    public function test_crew_can_be_assigned_to_a_flight_when_available(): void
    {
        $admin = $this->internalUser();
        $token = $this->tokenFor($admin);
        $flight = $this->scheduledFlight($admin);
        $staff = $this->staffMember('pilot');
        $staff->availability()->create([
            'available_date' => $flight->departure_time->toDateString(), 'status' => 'available',
        ]);

        $this->withToken($token)->postJson('/api/crew-assignments', [
            'flight_id' => $flight->id, 'staff_id' => $staff->id, 'assignment_role' => 'captain',
        ])->assertCreated();

        $this->assertDatabaseHas('crew_assignment', ['flight_id' => $flight->id, 'staff_id' => $staff->id]);
    }

    public function test_crew_assignment_requires_availability(): void
    {
        $admin = $this->internalUser();
        $token = $this->tokenFor($admin);
        $flight = $this->scheduledFlight($admin);
        $staff = $this->staffMember('pilot'); // no availability recorded

        $this->withToken($token)->postJson('/api/crew-assignments', [
            'flight_id' => $flight->id, 'staff_id' => $staff->id, 'assignment_role' => 'captain',
        ])->assertStatus(422)->assertJsonValidationErrors('staff_id');
    }

    public function test_overlapping_crew_assignment_is_rejected(): void
    {
        $admin = $this->internalUser();
        $token = $this->tokenFor($admin);
        $route = $this->route();

        // Two flights sharing a date but overlapping in time (different aircraft).
        $flightA = $this->scheduledFlight($admin, $route, $this->aircraftWithSeats());
        $flightB = $this->scheduledFlight($admin, $route, $this->aircraftWithSeats());
        $flightB->update([
            'departure_time' => $flightA->departure_time->copy()->addMinutes(30),
            'arrival_time' => $flightA->arrival_time->copy()->addMinutes(30),
        ]);

        $staff = $this->staffMember('pilot');
        $staff->availability()->create([
            'available_date' => $flightA->departure_time->toDateString(), 'status' => 'available',
        ]);

        // Seed an existing assignment to flightA directly so the second attempt
        // reaches the overlap guard (rather than the availability guard).
        CrewAssignment::create([
            'flight_id' => $flightA->id, 'staff_id' => $staff->id,
            'assignment_role' => 'captain', 'assigned_by' => $admin->id, 'assigned_at' => now(),
        ]);

        $this->withToken($token)->postJson('/api/crew-assignments', [
            'flight_id' => $flightB->id, 'staff_id' => $staff->id, 'assignment_role' => 'first_officer',
        ])->assertStatus(422)->assertJsonValidationErrors('staff_id');
    }

    public function test_admin_can_create_an_internal_user_but_manager_cannot(): void
    {
        $adminToken = $this->tokenFor($this->internalUser('admin'));
        $this->withToken($adminToken)->postJson('/api/internal-users', [
            'name' => 'New Ops', 'email' => 'ops@airline.test', 'password' => 'password123', 'role' => 'admin',
        ])->assertCreated();

        $managerToken = $this->tokenFor($this->internalUser('manager'));
        $this->withToken($managerToken)->postJson('/api/internal-users', [
            'name' => 'Nope', 'email' => 'nope@airline.test', 'password' => 'password123', 'role' => 'admin',
        ])->assertForbidden();
    }
}
