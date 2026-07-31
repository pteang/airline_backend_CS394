<?php

namespace Tests\Feature;

use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithAirline;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use InteractsWithAirline, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); // Don't dispatch activity-log jobs during auth tests.
    }

    public function test_passenger_can_register_and_receive_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New Traveller',
            'email' => 'new@example.test',
            'password' => 'password123',
            'phone' => '+1-555-0000',
        ])->assertCreated();

        $response->assertJsonPath('user.email', 'new@example.test')
            ->assertJsonPath('user.role', 'passenger')
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'initials']]);

        $this->assertDatabaseHas('users', ['email' => 'new@example.test']);
    }

    public function test_registration_rejects_duplicate_email_and_short_password(): void
    {
        $this->passenger(); // occupies a random email; add an explicit dupe below
        $this->postJson('/api/auth/register', [
            'name' => 'A', 'email' => 'dupe@example.test', 'password' => 'password123',
        ])->assertCreated();

        $this->postJson('/api/auth/register', [
            'name' => 'B', 'email' => 'dupe@example.test', 'password' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_passenger_login_succeeds_with_correct_password(): void
    {
        $user = $this->passenger();

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password123',
        ])->assertOk()->assertJsonPath('user.role', 'passenger')->assertJsonPath('user.id', (string) $user->id);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = $this->passenger();

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_inactive_account_cannot_log_in(): void
    {
        $user = $this->passenger(active: false);

        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_staff_login_returns_staff_role(): void
    {
        $admin = $this->internalUser('admin');

        $this->postJson('/api/auth/staff/login', [
            'email' => $admin->email, 'password' => 'password123',
        ])->assertOk()->assertJsonPath('user.role', 'admin');
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = $this->passenger();
        $token = $this->tokenFor($user);

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('id', (string) $user->id)->assertJsonPath('role', 'passenger');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_passenger_can_update_profile(): void
    {
        $user = $this->passenger();
        $token = $this->tokenFor($user);

        $this->withToken($token)->putJson('/api/auth/me', [
            'name' => 'Renamed Traveller', 'phone' => '+1-555-9999',
        ])->assertOk()->assertJsonPath('name', 'Renamed Traveller');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'full_name' => 'Renamed Traveller']);
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        $user = $this->passenger();
        $token = $this->tokenFor($user);

        $this->withToken($token)->putJson('/api/auth/change-password', [
            'current_password' => 'wrong', 'new_password' => 'brand-new-pass',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->withToken($token)->putJson('/api/auth/change-password', [
            'current_password' => 'password123', 'new_password' => 'brand-new-pass',
        ])->assertNoContent();

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password_hash));
    }

    public function test_forgot_and_reset_password_flow(): void
    {
        $user = $this->passenger();

        $resetToken = $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()->json('reset_token'); // exposed only in local/testing

        $this->assertNotEmpty($resetToken);

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'reset-password-1',
            'password_confirmation' => 'reset-password-1',
        ])->assertOk();

        $this->assertTrue(Hash::check('reset-password-1', $user->fresh()->password_hash));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_does_not_leak_unknown_accounts(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.test'])
            ->assertOk()->assertJsonMissing(['reset_token' => true]);

        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_sessions_can_be_listed_and_revoked(): void
    {
        $user = $this->passenger();
        $token = $this->tokenFor($user);
        $other = UserSession::create([
            'user_id' => $user->id, 'session_token' => 'other-token',
            'login_at' => now(), 'expires_at' => now()->addDay(), 'is_active' => true,
        ]);

        $this->withToken($token)->getJson('/api/auth/sessions')
            ->assertOk()->assertJsonCount(2);

        $this->withToken($token)->deleteJson("/api/auth/sessions/{$other->id}")->assertNoContent();
        $this->assertDatabaseHas('user_sessions', ['id' => $other->id, 'is_active' => false]);
    }

    public function test_login_endpoint_is_rate_limited(): void
    {
        $payload = ['email' => 'brute@example.test', 'password' => 'nope'];

        // The limiter allows 10 requests/min; the 11th is throttled.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', $payload)->assertStatus(422);
        }

        $this->postJson('/api/auth/login', $payload)->assertStatus(429);
    }

    public function test_logout_deactivates_the_session(): void
    {
        $user = $this->passenger();
        $token = $this->tokenFor($user);

        $this->withToken($token)->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseHas('user_sessions', ['session_token' => $token, 'is_active' => false]);

        // The token no longer authenticates.
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }
}
