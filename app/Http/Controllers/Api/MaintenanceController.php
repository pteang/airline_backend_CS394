<?php

namespace App\Http\Controllers\Api;

use App\Enums\MaintenanceType;
use App\Http\Controllers\Controller;
use App\Models\Aircraft;
use App\Models\Flight;
use App\Models\MaintenanceSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MaintenanceController extends Controller
{
    public function index(Request $request)
    {
        $query = MaintenanceSchedule::with(['aircraft', 'technician.internalUser', 'details']);

        if ($request->filled('aircraft_id')) {
            $query->where('aircraft_id', $request->integer('aircraft_id'));
        }
        if ($request->has('is_completed')) {
            $query->where('is_completed', $request->boolean('is_completed'));
        }

        return $query->orderBy('scheduled_date')->paginate($request->integer('per_page', 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'aircraft_id' => ['required', 'exists:aircraft,id'],
            'maintenance_type' => ['required', Rule::enum(MaintenanceType::class)],
            'scheduled_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:scheduled_date'],
            'technician_id' => ['nullable', 'exists:staff,id'],
        ]);

        $conflict = Flight::where('aircraft_id', $data['aircraft_id'])->whereNot('status', 'cancelled')
            ->whereDate('departure_time', '<=', $data['end_date'] ?? $data['scheduled_date'])
            ->whereDate('arrival_time', '>=', $data['scheduled_date'])->exists();
        abort_if($conflict, 422, 'Maintenance overlaps an assigned flight.');
        $schedule = DB::transaction(function () use ($data) {
            $schedule = MaintenanceSchedule::create($data);
            Aircraft::whereKey($data['aircraft_id'])->update(['status' => 'maintenance']);

            return $schedule;
        });

        return response()->json($schedule->load('aircraft'), 201);
    }

    public function show(MaintenanceSchedule $maintenanceSchedule)
    {
        return $maintenanceSchedule->load(['aircraft', 'technician.internalUser', 'details']);
    }

    public function update(Request $request, MaintenanceSchedule $maintenanceSchedule)
    {
        $data = $request->validate([
            'maintenance_type' => ['sometimes', Rule::enum(MaintenanceType::class)],
            'scheduled_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date'],
            'technician_id' => ['nullable', 'exists:staff,id'],
            'is_completed' => ['boolean'],
        ]);

        DB::transaction(function () use ($maintenanceSchedule, $data) {
            $maintenanceSchedule->update($data);
            if (($data['is_completed'] ?? false) === true) {
                $maintenanceSchedule->aircraft()->update(['status' => 'available']);
            }
        });

        return $maintenanceSchedule;
    }

    public function destroy(MaintenanceSchedule $maintenanceSchedule)
    {
        $maintenanceSchedule->delete();

        return response()->json(null, 204);
    }

    /** Log the work performed against a maintenance schedule. */
    public function storeDetail(Request $request, MaintenanceSchedule $maintenanceSchedule)
    {
        $data = $request->validate([
            'work_done' => ['required', 'string'],
            'parts_used' => ['nullable', 'string'],
            'technician_id' => ['nullable', 'exists:staff,id'],
            'technician_notes' => ['nullable', 'string'],
        ]);

        $detail = $maintenanceSchedule->details()->create([
            ...$data,
            'aircraft_id' => $maintenanceSchedule->aircraft_id,
            'logged_at' => now(),
        ]);

        return response()->json($detail, 201);
    }
}
