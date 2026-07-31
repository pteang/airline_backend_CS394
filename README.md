# Airline System Backend API

Laravel 13 REST API implementing the CS 394 airline project: passenger
accounts, role-based staff administration, flight scheduling and search,
booking and seat selection, payments and e-tickets, crew scheduling,
aircraft maintenance, notifications, and audit/history records.

## Quick start

Requirements: PHP 8.3+ (with the `mongodb` PHP extension), Composer, and a
relational database. SQLite works for local development.

> **Note on dependencies.** `mongodb/laravel-mongodb` and `predis/predis` are
> declared in `composer.json`, so `composer install` needs the `mongodb` PHP
> extension present. Their *runtime* use is optional, though: with the default
> `.env` the app runs entirely on SQLite plus the `database` cache/queue —
> MongoDB (activity logs) and Redis (cache/queue) are only engaged when you
> point the relevant `.env` drivers at them. See
> [Optional Redis and MongoDB](#optional-redis-and-mongodb) below.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate:fresh --seed
php artisan serve
```

The API is served at `http://127.0.0.1:8000/api`. Seed accounts:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@airline.test` | `password` |
| Passenger | `jane@example.test` | `password` |

Login returns a bearer token. Send it in subsequent requests:

```http
Authorization: Bearer <token>
Accept: application/json
```

## Feature coverage

- Passenger registration/login, profile updates, password change/reset,
  expiring sessions, session listing/revocation, and logout
- Admin-created internal users and role/active-status management
- Airport, route, gate, aircraft, and seat-map CRUD
- Flight search by origin, destination, date, status, and class
- Flight scheduling with seat inventory and class pricing
- Aircraft, gate, and maintenance conflict detection
- Status changes with history, cancellation, and availability tracking
- Atomic seat reservation and seat changes with double-booking protection
- Booking history, cancellation, paid-payment refunds, and booking audit logs
- Payment confirmation and electronic ticket issuance/lookup
- Staff CRUD, daily availability, crew assignment, and overlap validation
- Maintenance schedules, completion status, work/parts/technician logs
- Passenger confirmation/cancellation notifications

## Main endpoints

Public:

- `POST /api/auth/register`, `/auth/login`, `/auth/staff/login`
- `POST /api/auth/forgot-password`, `/auth/reset-password`
- `GET /api/airports`, `/routes`, `/flights`, `/flights/search`
- `GET /api/flights/{id}`, `/flights/{id}/seats`

Authenticated:

- `GET|PUT /api/auth/me`, `PUT /auth/change-password`
- `GET /api/auth/sessions`, `DELETE /auth/sessions/{id}`, `POST /auth/logout`
- `GET|POST /api/bookings`, `GET|PUT /bookings/{id}`
- `POST /api/bookings/{id}/payment`, `/bookings/{id}/cancel`
- `GET /api/bookings/{id}/ticket`, `/notifications`

Internal staff:

- CRUD: `/airports`, `/routes`, `/aircraft`, `/gates`, `/staff`, `/maintenance`
- `POST /api/flights`, `PUT /flights/{id}`, `PATCH /flights/{id}/status`
- `GET|POST /api/crew-assignments`
- `POST /api/staff/{id}/availability`

Admin only:

- `GET|POST /api/internal-users`
- `PATCH /api/internal-users/{id}`

See the complete [API reference](docs/API_REFERENCE.md), the importable
[OpenAPI specification](docs/openapi.yaml), and
[Postman collection](postman_airline_collection.json) for ready-to-run request
examples. The original Insomnia export remains in
`insomnia_airline_collection.json`.

## Verification

```bash
php artisan test
vendor/bin/pint --test
php artisan route:list --path=api
```

The feature suite includes a complete create-flight → register → book → change
seat → pay → issue ticket → cancel/refund workflow.

## Optional Redis and MongoDB

The `mongodb/laravel-mongodb` and `predis/predis` packages ship as required
composer dependencies (so the `mongodb` PHP extension must be installed to run
`composer install`), but you do **not** need running Redis or MongoDB servers
for the core API or the test suite — those run on SQLite and the `database`
cache/queue by default.

For the advanced multi-database deployment, point the `.env` drivers at the
real services: set `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis` (Redis as
the cache/queue store) and configure the `mongodb` connection as the
activity-log store. Then run `php artisan queue:work` to process audit events.
# airline_backend_CS394
