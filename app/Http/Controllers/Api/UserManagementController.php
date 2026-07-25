<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\InternalUser;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        return InternalUser::with('staff')->paginate($request->integer('per_page', 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:internal_users,email'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'employee_id' => ['nullable', 'required_unless:role,admin', 'unique:staff,employee_id'],
            'staff_role' => ['nullable', Rule::in(['pilot', 'cabin_crew', 'ground_staff', 'engineer'])],
            'joined_date' => ['nullable', 'date'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = InternalUser::create([
                'full_name' => $data['name'], 'email' => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'phone' => $data['phone'] ?? null, 'role' => $data['role'], 'is_active' => true,
            ]);
            if (! empty($data['employee_id'])) {
                Staff::create([
                    'internal_user_id' => $user->id, 'employee_id' => $data['employee_id'],
                    'staff_role' => $data['staff_role'] ?? 'ground_staff',
                    'joined_date' => $data['joined_date'] ?? today(),
                ]);
            }

            return $user;
        });

        return response()->json($user->load('staff'), 201);
    }

    public function update(Request $request, InternalUser $internalUser)
    {
        $data = $request->validate([
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        abort_if($request->user()->id === $internalUser->id && ($data['is_active'] ?? true) === false, 422, 'You cannot deactivate yourself.');
        $internalUser->update($data);

        return $internalUser->load('staff');
    }
}
