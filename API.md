# Airline System — Backend API

> For the complete endpoint documentation, examples, errors, and business
> rules, see [`docs/API_REFERENCE.md`](docs/API_REFERENCE.md). The importable
> Swagger/Postman/Insomnia definition is
> [`docs/openapi.yaml`](docs/openapi.yaml).

Laravel 11 REST API for the airline portal. Built from the five drawSQL schema
exports, with the schema corrected (see "Schema fixes" below).

## Data architecture

| Store          | Role                              | Used for                                                        |
|----------------|-----------------------------------|-----------------------------------------------------------------|
| **PostgreSQL** | Core relational system of record  | All domain tables (users, flights, bookings, payments, …)       |
| **Redis**      | Cache + queue                     | Flight-search cache, session-token resolution cache, job queue  |
| **MongoDB**    | Logs / documents                  | `activity_logs` collection (auth, bookings, payments, status)   |

- **Flight searches** — `GET /flights` results are cached in Redis (tag `flights`,
  60s TTL) and invalidated on any flight create/update/status/delete.
- **User sessions** — the `user_sessions` table in Postgres stays the source of
  truth; each token's resolution is cached in Redis (`session_token:*`, 15 min)
  so authenticated requests skip a DB round-trip. Logout evicts the key.
- **Queue processing** — audit/activity logging runs as Redis-queued
  `App\Jobs\LogActivity` jobs that write to MongoDB off the request path.

## Setup

Requires PostgreSQL, Redis, and MongoDB running locally (all pre-configured in `.env`).

```bash
cd ~/airline-backend
composer install
createdb airline                 # once, if it doesn't exist
php artisan migrate:fresh --seed # build Postgres schema + demo data
php artisan queue:work redis     # in a second terminal — processes log jobs
php artisan serve                # http://127.0.0.1:8000
```

Relevant `.env` (already set):

```
DB_CONNECTION=pgsql        DB_DATABASE=airline   DB_PORT=5432
CACHE_STORE=redis          QUEUE_CONNECTION=redis
REDIS_CLIENT=predis        REDIS_PORT=6379       # no native phpredis ext here
MONGO_DB_URI=mongodb://127.0.0.1:27017           MONGO_DB_DATABASE=airline_logs
```

> Redis cache lives in db **1**, the queue in db **0** (Laravel defaults). Inspect
> cache keys with `redis-cli -n 1 KEYS '*'`.

## Auth model

Custom token auth backed by your `user_sessions` table (not Sanctum PATs).
Login returns a `token`; send it as `Authorization: Bearer <token>`.

- **Passengers** authenticate against `users` → `POST /api/auth/login`
- **Internal users** (staff/admin) authenticate against `internal_users` → `POST /api/auth/staff/login`
- A session belongs to exactly one owner (passenger *or* internal user).
- `auth.session` middleware validates the token; `internal` middleware restricts
  staff-only routes and supports role narrowing (`internal:admin,manager`).

### Seeded accounts
| Role      | Email                | Password |
|-----------|----------------------|----------|
| Admin     | admin@airline.test   | password |
| Passenger | jane@example.test    | password |

## Endpoints

### Public
- `POST /api/auth/register` — passenger sign-up (optional passenger profile)
- `POST /api/auth/login` / `POST /api/auth/staff/login`
- `POST /api/auth/forgot-password` / `POST /api/auth/reset-password`
- `GET  /api/airports`, `GET /api/airports/{id}`
- `GET  /api/routes`, `GET /api/routes/{id}`
- `GET  /api/flights` — search: `?origin_airport_id=&destination_airport_id=&departure_date=&status=`
- `GET  /api/flights/{id}` — includes `available_seats` count + class prices
- `POST /api/tickets/lookup` — `{ "ticket_code": "..." }`

### Authenticated (passenger)
- `GET|PUT /api/auth/me`, `POST /api/auth/logout`
- `PUT /api/auth/change-password`
- `GET /api/auth/sessions`, `DELETE /api/auth/sessions/{id}`
- `GET  /api/bookings` — my bookings
- `POST /api/bookings` — create booking (see payload below)
- `GET|PUT /api/bookings/{id}` — view or change a passenger seat/request
- `POST /api/bookings/{id}/cancel` — releases seats, refunds payment
- `POST /api/bookings/{id}/payment` — `{ "payment_method": "credit_card" }` → pays, confirms, issues ticket
- `GET  /api/bookings/{id}/payment`, `GET /api/bookings/{id}/ticket`
- `GET /api/notifications`, `PATCH /api/notifications/{id}/read`

### Internal only (staff/admin)
- Airports / routes / gates / aircraft — full CRUD
- `POST /api/aircraft/{id}/seats` — bulk seat map
- `POST /api/flights` — create flight (auto-generates seat inventory + class prices)
- `PATCH /api/flights/{id}/status` — change status, logged to `flight_status_log`
- `staff` CRUD + `/staff/{id}/availability`
- `crew-assignments` (assign staff to flights)
- `maintenance` schedules + `/maintenance/{id}/details`
- Admin: `internal-users` list/create + role/active-status update

### Create-booking payload
```json
{
  "flight_id": 1,
  "trip_type": "one_way",
  "passengers": [
    { "passenger_id": 1, "seat_class": "business", "flight_seat_id": 5 },
    { "passenger_id": 1, "seat_class": "economy",  "flight_seat_id": 1 }
  ]
}
```
`flight_seat_id` is optional (class-only booking). When given, the seat is locked
(`lockForUpdate`) and reserved atomically; its class must match `seat_class`.
The fare is derived from `flight_class_prices` (falling back to `base_price`).

## Schema fixes applied

The drawSQL exports had blocking issues; the migrations here correct them:

1. **Empty `ENUM('')`** on ~15 columns → real value sets (see `app/Enums/`).
2. **Reversed foreign keys** (drawSQL inverted them) → corrected direction.
3. **Duplicate constraint names** → removed.
4. **`INT` vs `INT UNSIGNED` mismatch** on FKs → all FKs match their PKs.
5. **`user_sessions` uniqueness** → owner columns are now nullable & non-unique
   (a user has many sessions; a session has one owner).
6. **Duplicated `staff` / `aircraft` tables** across files → one canonical each.
7. **Ambiguous `passenger_id`** → `bookings.passenger_id` → `users`,
   `booking_passengers.passenger_id` → `passenger_profiles`.
8. Typos fixed (`staff_availability`, `available_date`); `flights.gate_id`
   nullable; added `changed_at` to the status log; boolean defaults added.
