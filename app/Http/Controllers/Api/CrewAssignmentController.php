<?php

namespace App\Http\Controllers\Api;

use App\Enums\CrewAssignmentRole;
use App\Http\Controllers\Controller;
use App\Models\CrewAssignment;
use App\Models\Flight;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CrewAssignmentController extends Controller
{
    /** Crew assigned to a given flight. */
    public function index(Request $request)
    {
        $query = CrewAssignment::with(['staff.internalUser', 'flight']);

        if ($request->filled('flight_id')) {
            $query->where('flight_id', $request->integer('flight_id'));
        }
        if ($request->filled('staff_id')) {
            $query->where('staff_id', $request->integer('staff_id'));
        }

        return $query->paginate($request->integer('per_page', 50));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'flight_id' => ['required', 'exists:flights,id'],
            'staff_id' => ['required', 'exists:staff,id',
                Rule::unique('crew_assignment')->where(fn ($q) => $q->where('flight_id', $request->input('flight_id'))),
            ],
            'assignment_role' => ['required', Rule::enum(CrewAssignmentRole::class)],
        ]);

        $internalUser = $request->attributes->get('auth_user');
        $flight = Flight::findOrFail($data['flight_id']);
        $staff = Staff::findOrFail($data['staff_id']);
        $available = $staff->availability()->whereDate('available_date', $flight->departure_time)
            ->where('status', 'available')->exists();
        if (! $available) {
            throw ValidationException::withMessages(['staff_id' => ['Staff member is not marked available on the flight date.']]);
        }
        $conflict = $staff->assignments()->whereHas('flight', fn ($q) => $q
            ->whereNot('status', 'cancelled')
            ->where('departure_time', '<', $flight->arrival_time)
            ->where('arrival_time', '>', $flight->departure_time))->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['staff_id' => ['Staff member has an overlapping flight assignment.']]);
        }

        $assignment = DB::transaction(function () use ($data, $internalUser, $staff, $flight) {
            $assignment = CrewAssignment::create([
                ...$data, 'assigned_by' => $internalUser->id, 'assigned_at' => now(),
            ]);
            $staff->availability()->whereDate('available_date', $flight->departure_time)
                ->update(['status' => 'flight']);

            return $assignment;
        });

        return response()->json($assignment->load('staff.internalUser'), 201);
    }

    public function destroy(CrewAssignment $crewAssignment)
    {
        $crewAssignment->delete();

        return response()->json(null, 204);
    }
}
