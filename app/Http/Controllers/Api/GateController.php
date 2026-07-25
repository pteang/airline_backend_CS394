<?php

namespace App\Http\Controllers\Api;

use App\Enums\GateStatus;
use App\Http\Controllers\Controller;
use App\Models\Gate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GateController extends Controller
{
    public function index(Request $request)
    {
        $query = Gate::with('airport');

        if ($request->filled('airport_id')) {
            $query->where('airport_id', $request->integer('airport_id'));
        }

        return $query->paginate($request->integer('per_page', 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'airport_id' => ['required', 'exists:airports,id'],
            'gate_code' => ['required', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(GateStatus::class)],
        ]);

        return response()->json(Gate::create($data), 201);
    }

    public function show(Gate $gate)
    {
        return $gate->load('airport');
    }

    public function update(Request $request, Gate $gate)
    {
        $data = $request->validate([
            'airport_id' => ['sometimes', 'exists:airports,id'],
            'gate_code' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(GateStatus::class)],
        ]);

        $gate->update($data);

        return $gate;
    }

    public function destroy(Gate $gate)
    {
        $gate->delete();

        return response()->json(null, 204);
    }
}
