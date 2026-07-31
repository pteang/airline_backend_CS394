<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSession;
use App\Http\Resources\UserResource;
use App\Jobs\LogActivity;
use App\Models\InternalUser;
use App\Models\PassengerProfile;
use App\Models\PasswordResetToken;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const TOKEN_TTL_HOURS = 24;

    /**
     * Register a passenger account. Contract body: { name, email, password, phone? }.
     * Returns { token, user } per docs/API_REFERENCE.md.
     */
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:255'],
            // Optional passenger-profile fields (not sent by the current frontend).
            'passport_number' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'required_with:passport_number', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'required_with:passport_number', 'date', 'before:today'],
            'special_needs' => ['nullable', 'string'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'full_name' => $data['name'],
                'email' => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null,
                'is_active' => true,
            ]);

            // Every passenger gets a profile so they can book straight away; the
            // frontend surfaces `profileId` as the traveller id. Passport and
            // other details are optional and may be filled in later.
            PassengerProfile::create([
                'user_id' => $user->id,
                'passport_number' => $data['passport_number'] ?? null,
                'nationality' => $data['nationality'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'special_needs' => $data['special_needs'] ?? null,
            ]);

            return $user;
        });

        LogActivity::dispatch('auth.register', ['email' => $user->email], 'passenger', $user->id);

        return response()->json([
            ...$this->issueToken(userId: $user->id),
            'user' => new UserResource($user->load('profile')),
        ], 201);
    }

    /**
     * Unified login. Authenticates against passengers (users) first, then
     * internal users (staff/admin). Returns { token, user } with the user's role.
     */
    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $passenger = User::where('email', $data['email'])->first();
        if ($passenger && Hash::check($data['password'], $passenger->password_hash)) {
            if (! $passenger->is_active) {
                throw ValidationException::withMessages(['email' => ['Account is inactive.']]);
            }
            LogActivity::dispatch('auth.passenger_login', ['email' => $passenger->email], 'passenger', $passenger->id);

            return response()->json([
                ...$this->issueToken(userId: $passenger->id),
                'user' => new UserResource($passenger->load('profile')),
            ]);
        }

        $internal = InternalUser::where('email', $data['email'])->first();
        if ($internal && Hash::check($data['password'], $internal->password_hash)) {
            if (! $internal->is_active) {
                throw ValidationException::withMessages(['email' => ['Account is inactive.']]);
            }
            LogActivity::dispatch('auth.staff_login', ['email' => $internal->email, 'role' => $internal->role->value], 'internal', $internal->id);

            return response()->json([
                ...$this->issueToken(internalUserId: $internal->id),
                'user' => new UserResource($internal),
            ]);
        }

        throw ValidationException::withMessages(['email' => ['Invalid email or password.']]);
    }

    /** Internal (staff/admin) login — kept for an explicit staff entry point. */
    public function staffLogin(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = InternalUser::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => ['Account is inactive.']]);
        }

        LogActivity::dispatch('auth.staff_login', ['email' => $user->email, 'role' => $user->role->value], 'internal', $user->id);

        return response()->json([
            ...$this->issueToken(internalUserId: $user->id),
            'user' => new UserResource($user),
        ]);
    }

    /** Revoke the current session and evict it from the session cache. */
    public function logout(Request $request)
    {
        $sessionId = $request->attributes->get('auth_session_id');
        $token = $request->attributes->get('auth_token');

        UserSession::whereKey($sessionId)->update(['is_active' => false, 'logout_at' => now()]);
        Cache::forget(AuthenticateSession::cacheKey($token));

        return response()->json(['message' => 'Logged out.']);
    }

    /** Return the currently authenticated user in the flat contract shape. */
    public function me(Request $request)
    {
        $user = $request->attributes->get('auth_user');

        return new UserResource($user->load($request->attributes->get('auth_type') === 'passenger' ? 'profile' : 'staff'));
    }

    public function updateProfile(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        $type = $request->attributes->get('auth_type');
        $table = $type === 'passenger' ? 'users' : 'internal_users';
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', "unique:{$table},email,{$user->id}"],
            'phone' => ['nullable', 'string', 'max:255'],
            'passport_number' => ['nullable', 'string', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'special_needs' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($user, $type, $data) {
            $user->update(array_filter([
                'full_name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => array_key_exists('phone', $data) ? $data['phone'] : null,
            ], fn ($value, $key) => $value !== null || ($key === 'phone' && array_key_exists('phone', $data)), ARRAY_FILTER_USE_BOTH));

            if ($type === 'passenger' && collect($data)->hasAny([
                'passport_number', 'nationality', 'date_of_birth', 'special_needs',
            ])) {
                $user->profile()->updateOrCreate(['user_id' => $user->id], collect($data)->only([
                    'passport_number', 'nationality', 'date_of_birth', 'special_needs',
                ])->all());
            }
        });

        return new UserResource($user->fresh($type === 'passenger' ? 'profile' : 'staff'));
    }

    /** PUT /auth/change-password — updates the current user's password. */
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6'],
        ]);

        $user = $request->attributes->get('auth_user');

        if (! Hash::check($data['current_password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['Current password is incorrect.'],
            ]);
        }

        $user->update(['password_hash' => Hash::make($data['new_password'])]);

        return response()->noContent();
    }

    public function sessions(Request $request)
    {
        $user = $request->attributes->get('auth_user');
        $column = $request->attributes->get('auth_type') === 'passenger' ? 'user_id' : 'internal_user_id';

        return UserSession::where($column, $user->id)
            ->latest('login_at')
            ->get(['id', 'login_at', 'expires_at', 'logout_at', 'is_active']);
    }

    public function revokeSession(Request $request, UserSession $session)
    {
        $user = $request->attributes->get('auth_user');
        $column = $request->attributes->get('auth_type') === 'passenger' ? 'user_id' : 'internal_user_id';
        abort_unless($session->{$column} === $user->id, 403);
        $session->update(['is_active' => false, 'logout_at' => now()]);

        return response()->noContent();
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $exists = User::where('email', $data['email'])->exists()
            || InternalUser::where('email', $data['email'])->exists();

        if ($exists) {
            $plainToken = Str::random(64);
            PasswordResetToken::create([
                'email' => $data['email'],
                'token_hash' => hash('sha256', $plainToken),
                'expires_at' => now()->addHour(),
                'created_at' => now(),
            ]);

            // Email the reset link (points at the frontend reset page).
            $resetUrl = rtrim(config('app.url'), '/')
                .'/reset-password?email='.urlencode($data['email'])
                .'&token='.$plainToken;

            try {
                Mail::html(
                    '<div style="font-family:Arial,sans-serif;max-width:480px;margin:auto">'
                    .'<h2 style="color:#0A1F44">Reset your Avion password</h2>'
                    .'<p>We received a request to reset your password. Click the button below to choose a new one:</p>'
                    .'<p style="margin:24px 0"><a href="'.$resetUrl.'" '
                    .'style="background:#0A1F44;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none">Reset password</a></p>'
                    .'<p style="color:#666;font-size:13px">Or paste this link into your browser:<br>'.$resetUrl.'</p>'
                    .'<p style="color:#999;font-size:12px">This link expires in 1 hour. If you didn\'t request this, you can ignore this email.</p>'
                    .'</div>',
                    function ($message) use ($data) {
                        $message->to($data['email'])->subject('Reset your Avion password');
                    }
                );
            } catch (\Throwable $e) {
                // Never leak whether the account exists or that mail failed.
                report($e);
            }
        }

        $payload = ['message' => 'If the account exists, a password reset link has been emailed.'];
        // In local/testing, also hand the token back so the flow can be tested without an inbox.
        if ($exists && app()->environment(['local', 'testing'])) {
            $payload['reset_token'] = $plainToken;
        }

        return response()->json($payload);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $reset = PasswordResetToken::where('email', $data['email'])
            ->where('token_hash', hash('sha256', $data['token']))
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (! $reset) {
            throw ValidationException::withMessages(['token' => ['The reset token is invalid or expired.']]);
        }

        DB::transaction(function () use ($data, $reset) {
            $user = User::where('email', $data['email'])->first()
                ?? InternalUser::where('email', $data['email'])->firstOrFail();
            $user->update(['password_hash' => Hash::make($data['password'])]);
            $reset->update(['used_at' => now()]);
            UserSession::where($user instanceof User ? 'user_id' : 'internal_user_id', $user->id)
                ->update(['is_active' => false, 'logout_at' => now()]);
        });

        return response()->json(['message' => 'Password reset successfully.']);
    }

    /** Create a user_sessions row and return the bearer token payload. */
    private function issueToken(?int $userId = null, ?int $internalUserId = null): array
    {
        $token = Str::random(64);
        $expiresAt = now()->addHours(self::TOKEN_TTL_HOURS);

        UserSession::create([
            'user_id' => $userId,
            'internal_user_id' => $internalUserId,
            'session_token' => $token,
            'login_at' => now(),
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
