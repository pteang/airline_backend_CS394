<?php

namespace Tests\Feature;

use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Concerns\InteractsWithAirline;
use Tests\TestCase;

/**
 * Guards the three access tiers: public, authenticated, internal, and admin.
 */
class AuthorizationTest extends TestCase
{
    use InteractsWithAirline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
    }

    public function test_protected_routes_reject_missing_tokens(): void
    {
        $this->getJson('/api/bookings')->assertUnauthorized();
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->postJson('/api/flights', [])->assertUnauthorized();
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken('not-a-real-token')->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_expired_token_is_rejected(): void
    {
        $user = $this->passenger();
        $token = $this->tokenFor($user);
        // Force expiry in the past.
        UserSession::where('session_token', $token)->update(['expires_at' => now()->subHour()]);

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_passenger_cannot_reach_internal_routes(): void
    {
        $token = $this->tokenFor($this->passenger());

        $this->withToken($token)->getJson('/api/staff')->assertForbidden();
        $this->withToken($token)->postJson('/api/aircraft', [])->assertForbidden();
    }

    public function test_non_admin_internal_user_cannot_reach_admin_routes(): void
    {
        $managerToken = $this->tokenFor($this->internalUser('manager'));

        $this->withToken($managerToken)->getJson('/api/internal-users')->assertForbidden();
    }

    public function test_admin_can_reach_internal_and_admin_routes(): void
    {
        $adminToken = $this->tokenFor($this->internalUser('admin'));

        $this->withToken($adminToken)->getJson('/api/staff')->assertOk();
        $this->withToken($adminToken)->getJson('/api/internal-users')->assertOk();
    }

    public function test_inactive_user_token_is_forbidden(): void
    {
        $user = $this->passenger();
        $token = $this->tokenFor($user);
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/auth/me')->assertForbidden();
    }

    public function test_public_endpoints_need_no_token(): void
    {
        $this->getJson('/api/airports')->assertOk();
        $this->getJson('/api/flights')->assertOk();
        $this->getJson('/api/flights/search')->assertOk();
    }
}
