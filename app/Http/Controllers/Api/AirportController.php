<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use Illuminate\Http\Request;

class AirportController extends Controller
{
    public function index(Request $request)
    {
        $query = Airport::query();

        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('iata_code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")
                ->orWhere('city', 'like', "%{$search}%")
                ->orWhere('country', 'like', "%{$search}%"));
        }

        return $query->orderBy('iata_code')->paginate($request->integer('per_page', 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'iata_code' => ['required', 'string', 'size:3', 'unique:airports,iata_code'],
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(Airport::create($data), 201);
    }

    public function show(Airport $airport)
    {
        return $airport->load('gates');
    }

    public function update(Request $request, Airport $airport)
    {
        $data = $request->validate([
            'iata_code' => ['sometimes', 'string', 'size:3', 'unique:airports,iata_code,'.$airport->id],
            'name' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'country' => ['sometimes', 'string', 'max:255'],
        ]);

        $airport->update($data);

        return $airport;
    }

    public function destroy(Airport $airport)
    {
        $airport->delete();

        return response()->json(null, 204);
    }
}
