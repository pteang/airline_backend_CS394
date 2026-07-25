<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Route;
use Illuminate\Http\Request;

class RouteController extends Controller
{
    public function index(Request $request)
    {
        $query = Route::with(['origin', 'destination']);

        if ($request->filled('origin_airport_id')) {
            $query->where('origin_airport_id', $request->integer('origin_airport_id'));
        }
        if ($request->filled('destination_airport_id')) {
            $query->where('destination_airport_id', $request->integer('destination_airport_id'));
        }
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return $query->paginate($request->integer('per_page', 25));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'origin_airport_id' => ['required', 'exists:airports,id'],
            'destination_airport_id' => ['required', 'different:origin_airport_id', 'exists:airports,id'],
            'distance_km' => ['nullable', 'integer', 'min:0'],
            'estimated_duration' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        return response()->json(Route::create($data)->load(['origin', 'destination']), 201);
    }

    public function show(Route $route)
    {
        return $route->load(['origin', 'destination']);
    }

    public function update(Request $request, Route $route)
    {
        $data = $request->validate([
            'origin_airport_id' => ['sometimes', 'exists:airports,id'],
            'destination_airport_id' => ['sometimes', 'different:origin_airport_id', 'exists:airports,id'],
            'distance_km' => ['nullable', 'integer', 'min:0'],
            'estimated_duration' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $route->update($data);

        return $route->load(['origin', 'destination']);
    }

    public function destroy(Route $route)
    {
        $route->delete();

        return response()->json(null, 204);
    }
}
