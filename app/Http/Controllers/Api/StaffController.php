<?php

namespace App\Http\Controllers\Api;

use App\Enums\StaffAvailabilityStatus;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    /** Relations StaffResource needs for the flat frontend shape. */
    private const RELATIONS = ['internalUser', 'availability', 'assignments.flight'];

    public function index(Request $request)
    {
        $query = Staff::with(self::RELATIONS);

        if ($role = $request->query('staff_role')) {
            $query->where('staff_role', $role);
        }

        return StaffResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'internal_user_id' => ['required', 'exists:internal_users,id', 'unique:staff,internal_user_id'],
            'employee_id' => ['required', 'string', 'max:255', 'unique:staff,employee_id'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'license_expiry' => ['nullable', 'date'],
            'staff_role' => ['required', Rule::enum(StaffRole::class)],
            'joined_date' => ['required', 'date'],
        ]);

        return (new StaffResource(Staff::create($data)->load(self::RELATIONS)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Staff $staff)
    {
        return new StaffResource($staff->load(self::RELATIONS));
    }

    public function update(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'employee_id' => ['sometimes', 'string', 'max:255', 'unique:staff,employee_id,'.$staff->id],
            'license_number' => ['nullable', 'string', 'max:255'],
            'license_expiry' => ['nullable', 'date'],
            'staff_role' => ['sometimes', Rule::enum(StaffRole::class)],
            'joined_date' => ['sometimes', 'date'],
        ]);

        $staff->update($data);

        return new StaffResource($staff->load(self::RELATIONS));
    }

    public function destroy(Staff $staff)
    {
        $staff->delete();

        return response()->json(null, 204);
    }

    // --- Availability -----------------------------------------------------

    public function storeAvailability(Request $request, Staff $staff)
    {
        $data = $request->validate([
            'available_date' => ['required', 'date'],
            'status' => ['required', Rule::enum(StaffAvailabilityStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $availability = $staff->availability()->updateOrCreate(
            ['available_date' => $data['available_date']],
            ['status' => $data['status'], 'reason' => $data['reason'] ?? null],
        );

        return response()->json($availability, 201);
    }

    public function availability(Staff $staff)
    {
        return $staff->availability()->orderBy('available_date')->get();
    }
}
