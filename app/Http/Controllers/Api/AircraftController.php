<?php

namespace App\Http\Controllers\Api;

use App\Enums\AircraftStatus;
use App\Enums\SeatClass;
use App\Http\Controllers\Controller;
use App\Models\Aircraft;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AircraftController extends Controller
{
    public function index(Request $request)
    {
        $query = Aircraft::query();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return $query->paginate($request->integer('per_page', 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'registration_number' => ['required', 'string', 'max:255', 'unique:aircraft,registration_number'],
            'model' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::enum(AircraftStatus::class)],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'flight_hours' => ['sometimes', 'integer', 'min:0'],
        ]);

        return response()->json(Aircraft::create($data), 201);
    }

    public function show(Aircraft $aircraft)
    {
        return $aircraft->load('seats');
    }

    public function update(Request $request, Aircraft $aircraft)
    {
        $data = $request->validate([
            'registration_number' => ['sometimes', 'string', 'max:255', 'unique:aircraft,registration_number,'.$aircraft->id],
            'model' => ['sometimes', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::enum(AircraftStatus::class)],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'flight_hours' => ['sometimes', 'integer', 'min:0'],
        ]);

        $aircraft->update($data);

        return $aircraft;
    }

    public function destroy(Aircraft $aircraft)
    {
        $aircraft->delete();

        return response()->json(null, 204);
    }

    /** Bulk-create the seat map for an aircraft. */
    public function storeSeats(Request $request, Aircraft $aircraft)
    {
        $data = $request->validate([
            'seats' => ['required', 'array', 'min:1'],
            'seats.*.seat_number' => ['required', 'string', 'max:255'],
            'seats.*.seat_class' => ['required', Rule::enum(SeatClass::class)],
            'seats.*.is_window' => ['boolean'],
            'seats.*.is_aisle' => ['boolean'],
        ]);

        $created = collect($data['seats'])->map(fn ($seat) => $aircraft->seats()->create($seat));

        return response()->json($created, 201);
    }
}
