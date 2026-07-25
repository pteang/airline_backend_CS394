<?php

namespace App\Http\Controllers\Api;

use App\Enums\FlightStatus;
use App\Enums\SeatClass;
use App\Http\Controllers\Controller;
use App\Http\Resources\FlightResource;
use App\Http\Resources\SeatResource;
use App\Jobs\LogActivity;
use App\Models\Aircraft;
use App\Models\AircraftAssignmentLog;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\MaintenanceSchedule;
use App\Models\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FlightController extends Controller
{
    /** How long flight-search results are cached in Redis. */
    private const SEARCH_CACHE_TTL = 60;

    /**
     * Public flight search / listing. Results are cached in Redis (tagged
     * 'flights') keyed by the query params, and invalidated on any flight write.
     */
    public function index(Request $request)
    {
        $params = $request->only([
            'flight_number', 'status', 'origin_airport_id',
            'destination_airport_id', 'departure_date', 'per_page', 'page',
        ]);
        ksort($params);
        $cacheKey = 'flight_search:'.Cache::get('flight_search_version', 1).':'.md5(json_encode($params));

        return Cache::remember($cacheKey, self::SEARCH_CACHE_TTL, function () use ($request) {
            $query = Flight::with(['route.origin', 'route.destination', 'aircraft', 'classPrices']);

            if ($request->filled('flight_number')) {
                $query->where('flight_number', $request->query('flight_number'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->query('status'));
            }
            if ($request->filled('origin_airport_id') || $request->filled('destination_airport_id')) {
                $query->whereHas('route', function ($q) use ($request) {
                    if ($request->filled('origin_airport_id')) {
                        $q->where('origin_airport_id', $request->integer('origin_airport_id'));
                    }
                    if ($request->filled('destination_airport_id')) {
                        $q->where('destination_airport_id', $request->integer('destination_airport_id'));
                    }
                });
            }
            if ($date = $request->query('departure_date')) {
                $query->whereDate('departure_time', $date);
            }

            return $query->orderBy('departure_time')->paginate($request->integer('per_page', 25));
        });
    }

    /** Drop every cached flight-search result. */
    private function flushSearchCache(): void
    {
        Cache::forever('flight_search_version', (string) hrtime(true));
    }

    /**
     * Contract search: GET /flights/search — returns a flat Flight[] matching
     * docs/api-contract.md. origin/destination arrive as "City (IATA)" labels.
     */
    public function search(Request $request)
    {
        $originCode = $this->iata($request->query('origin'));
        $destCode = $this->iata($request->query('destination'));

        $query = Flight::with(['route.origin', 'route.destination', 'aircraft', 'gate', 'classPrices'])
            ->withCount(['seats as available_seats' => fn ($q) => $q->where('is_available', true)]);

        if ($originCode) {
            $query->whereHas('route.origin', fn ($q) => $q->where('iata_code', $originCode));
        }
        if ($destCode) {
            $query->whereHas('route.destination', fn ($q) => $q->where('iata_code', $destCode));
        }
        if ($date = $request->query('date')) {
            $query->whereDate('departure_time', $date);
        }

        $flights = $query->orderBy('departure_time')->get();

        return FlightResource::collection($flights);
    }

    /** Contract: GET /flights/:id — flat Flight shape. */
    public function show(Request $request, Flight $flight)
    {
        $flight->load(['route.origin', 'route.destination', 'aircraft', 'gate', 'classPrices'])
            ->loadCount(['seats as available_seats' => fn ($q) => $q->where('is_available', true)]);

        return new FlightResource($flight);
    }

    /** Contract: GET /flights/:id/seats — flat Seat[] with per-class prices. */
    public function seats(Flight $flight)
    {
        $flight->load(['seats.aircraftSeat', 'classPrices']);

        $prices = $flight->classPrices->keyBy(fn ($p) => $p->seat_class->value);
        $flight->seats->each(function ($seat) use ($prices) {
            $class = $seat->aircraftSeat->seat_class->value;
            $seat->setAttribute('seat_price', (float) ($prices[$class]->price ?? $flight->base_price ?? 0));
        });

        return SeatResource::collection($flight->seats);
    }

    /** Pull an IATA code from a "City (JFK)" label, or return it as-is if bare. */
    private function iata(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        if (preg_match('/\(([A-Za-z]{3})\)/', $value, $m)) {
            return strtoupper($m[1]);
        }

        return strlen($value) === 3 ? strtoupper($value) : null;
    }

    /** Create a flight, generate its seat inventory, and set class prices. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'flight_number' => ['required', 'string', 'max:255', 'unique:flights,flight_number'],
            'route_id' => ['required', 'exists:routes,id'],
            'aircraft_id' => ['required', 'exists:aircraft,id'],
            'gate_id' => ['nullable', 'exists:gates,id'],
            'departure_time' => ['required', 'date'],
            'arrival_time' => ['required', 'date', 'after:departure_time'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::enum(FlightStatus::class)],
            'class_prices' => ['sometimes', 'array'],
            'class_prices.*.seat_class' => ['required_with:class_prices', Rule::enum(SeatClass::class)],
            'class_prices.*.price' => ['required_with:class_prices', 'numeric', 'min:0'],
        ]);

        $internalUser = $request->attributes->get('auth_user');

        $this->validateResources(
            (int) $data['aircraft_id'],
            isset($data['gate_id']) ? (int) $data['gate_id'] : null,
            $data['route_id'],
            $data['departure_time'],
            $data['arrival_time'],
        );

        $flight = DB::transaction(function () use ($data, $internalUser) {
            $flight = Flight::create([
                'flight_number' => $data['flight_number'],
                'route_id' => $data['route_id'],
                'aircraft_id' => $data['aircraft_id'],
                'gate_id' => $data['gate_id'] ?? null,
                'departure_time' => $data['departure_time'],
                'arrival_time' => $data['arrival_time'],
                'base_price' => $data['base_price'] ?? null,
                'status' => $data['status'] ?? FlightStatus::Scheduled->value,
                'created_by' => $internalUser->id,
            ]);

            // Generate one flight_seat per physical aircraft seat.
            $seats = Aircraft::findOrFail($data['aircraft_id'])->seats()->get();
            $rows = $seats->map(fn ($seat) => [
                'flight_id' => $flight->id,
                'aircraft_seat_id' => $seat->id,
                'is_available' => true,
            ])->all();
            if ($rows) {
                $flight->seats()->insert($rows);
            }

            foreach ($data['class_prices'] ?? [] as $price) {
                $flight->classPrices()->create($price);
            }
            AircraftAssignmentLog::create([
                'aircraft_id' => $flight->aircraft_id, 'flight_id' => $flight->id,
                'assigned_by' => $internalUser->id, 'assigned_at' => now(),
            ]);
            $flight->aircraft()->update(['status' => 'assigned']);

            return $flight;
        });

        $this->flushSearchCache();

        return response()->json($flight->load(['classPrices']), 201);
    }

    public function update(Request $request, Flight $flight)
    {
        $data = $request->validate([
            'gate_id' => ['nullable', 'exists:gates,id'],
            'departure_time' => ['sometimes', 'date'],
            'arrival_time' => ['sometimes', 'date'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (isset($data['departure_time']) || isset($data['arrival_time']) || array_key_exists('gate_id', $data)) {
            $this->validateResources(
                $flight->aircraft_id,
                array_key_exists('gate_id', $data) ? $data['gate_id'] : $flight->gate_id,
                $flight->route_id,
                $data['departure_time'] ?? $flight->departure_time,
                $data['arrival_time'] ?? $flight->arrival_time,
                $flight->id,
            );
        }
        if (isset($data['departure_time'], $data['arrival_time']) && strtotime($data['arrival_time']) <= strtotime($data['departure_time'])) {
            abort(422, 'Arrival time must be after departure time.');
        }
        $flight->update($data);
        $this->flushSearchCache();

        return $flight->load(['route', 'aircraft']);
    }

    /** Change flight status and record the transition in flight_status_log. */
    public function updateStatus(Request $request, Flight $flight)
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(FlightStatus::class)],
            'reason' => ['nullable', 'string'],
        ]);

        $internalUser = $request->attributes->get('auth_user');
        $old = $flight->status;

        DB::transaction(function () use ($flight, $data, $old, $internalUser) {
            $flight->update(['status' => $data['status']]);
            $flight->statusLogs()->create([
                'old_status' => $old?->value,
                'new_status' => $data['status'],
                'reason' => $data['reason'] ?? null,
                'changed_by' => $internalUser->id,
            ]);
        });

        $this->flushSearchCache();

        LogActivity::dispatch('flight.status_changed', [
            'flight_id' => $flight->id,
            'flight_number' => $flight->flight_number,
            'from' => $old?->value,
            'to' => $data['status'],
            'reason' => $data['reason'] ?? null,
        ], 'internal', $internalUser->id);

        return $flight->load('statusLogs');
    }

    public function destroy(Flight $flight)
    {
        $flight->delete();
        $this->flushSearchCache();

        return response()->json(null, 204);
    }

    private function validateResources(int $aircraftId, ?int $gateId, int $routeId, $departure, $arrival, ?int $ignoreFlightId = null): void
    {
        $aircraft = Aircraft::findOrFail($aircraftId);
        abort_if(in_array($aircraft->status->value, ['maintenance', 'retired'], true), 422, 'Aircraft is not operational.');
        abort_if($aircraft->seats()->count() === 0, 422, 'Aircraft must have a seat map before scheduling.');

        $overlap = Flight::where('aircraft_id', $aircraftId)
            ->whereNot('status', FlightStatus::Cancelled->value)
            ->when($ignoreFlightId, fn ($q) => $q->whereKeyNot($ignoreFlightId))
            ->where('departure_time', '<', $arrival)->where('arrival_time', '>', $departure)->exists();
        abort_if($overlap, 422, 'Aircraft is already assigned during this time.');

        $maintenance = MaintenanceSchedule::where('aircraft_id', $aircraftId)
            ->where('is_completed', false)
            ->whereDate('scheduled_date', '<=', $arrival)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $departure))
            ->exists();
        abort_if($maintenance, 422, 'Aircraft has overlapping maintenance.');

        if ($gateId) {
            $gate = Gate::findOrFail($gateId);
            $routeOrigin = Route::findOrFail($routeId)->origin_airport_id;
            abort_if($gate->airport_id !== $routeOrigin || $gate->status->value === 'closed', 422, 'Gate must be open and belong to the origin airport.');
            $gateOverlap = Flight::where('gate_id', $gateId)->whereNot('status', FlightStatus::Cancelled->value)
                ->when($ignoreFlightId, fn ($q) => $q->whereKeyNot($ignoreFlightId))
                ->where('departure_time', '<', $arrival)->where('arrival_time', '>', $departure)->exists();
            abort_if($gateOverlap, 422, 'Gate is occupied during this time.');
        }
    }
}
