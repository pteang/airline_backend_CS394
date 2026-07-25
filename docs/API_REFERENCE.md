# Airline System API Reference

Version: 1.0  
Base URL: `http://127.0.0.1:8000/api`  
Content type: `application/json`

This document describes the REST API implemented in this repository. The
machine-readable OpenAPI definition is available in
[`openapi.yaml`](openapi.yaml).

## Authentication and access

Protected endpoints use a custom bearer token backed by `user_sessions`.

```http
Authorization: Bearer YOUR_TOKEN
Accept: application/json
Content-Type: application/json
```

There are three access levels:

| Access | Description |
|---|---|
| Public | No token required |
| Authenticated | Passenger or internal-user token |
| Internal | An internal admin, manager, or agent token |
| Admin | Internal token with the `admin` role |

Passenger accounts are stored in `users`. Staff-facing accounts are stored in
`internal_users`. Login tokens expire after 24 hours.

## Common conventions

### Dates

- Date: `YYYY-MM-DD`
- Date/time: ISO 8601, for example `2026-08-01T09:00:00+07:00`
- Times are serialized using the application's configured timezone.

### Pagination

Collection endpoints generally accept:

| Parameter | Type | Default | Description |
|---|---:|---:|---|
| `page` | integer | `1` | Page number |
| `per_page` | integer | `25` | Results per page; crew assignments default to 50 |

Laravel pagination responses have this form:

```json
{
  "current_page": 1,
  "data": [],
  "first_page_url": "http://127.0.0.1:8000/api/flights?page=1",
  "from": 1,
  "last_page": 1,
  "per_page": 25,
  "to": 1,
  "total": 1
}
```

### Errors

| Status | Meaning |
|---:|---|
| `401` | Bearer token missing, invalid, or expired |
| `403` | Authenticated but not allowed to access the resource |
| `404` | Resource not found |
| `422` | Validation or business-rule failure |
| `500` | Unexpected server error |

Validation errors:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

### Enumerations

| Field | Accepted values |
|---|---|
| Internal role | `admin`, `manager`, `agent` |
| Staff role | `pilot`, `cabin_crew`, `ground_staff`, `engineer` |
| Availability | `available`, `unavailable`, `on_leave`, `assigned` |
| Flight status | `scheduled`, `boarding`, `departed`, `in_air`, `landed`, `arrived`, `delayed`, `cancelled` |
| Booking status | `pending`, `confirmed`, `cancelled`, `completed` |
| Trip type | `one_way`, `round_trip` |
| Seat class | `economy`, `business`, `first_class` |
| Aircraft status | `available`, `assigned`, `maintenance`, `retired` |
| Gate status | `available`, `occupied`, `closed` |
| Crew assignment role | `captain`, `first_officer`, `purser`, `flight_attendant`, `ground_crew` |
| Maintenance type | `routine`, `repair`, `inspection`, `overhaul` |
| Payment method | `credit_card`, `debit_card`, `paypal`, `bank_transfer`, `cash` |
| Payment status | `pending`, `paid`, `failed`, `refunded` |

## Authentication

### Register passenger

`POST /auth/register` — Public

```json
{
  "name": "Sok Dara",
  "email": "dara@example.com",
  "password": "password123",
  "phone": "+85512345678",
  "passport_number": "N1234567",
  "nationality": "Cambodian",
  "date_of_birth": "2000-01-20",
  "special_needs": null
}
```

`name`, `email`, and a password of at least eight characters are required.
Profile fields are optional.

Response: `201 Created`

```json
{
  "token": "64-character-session-token",
  "token_type": "Bearer",
  "expires_at": "2026-07-24T12:00:00+07:00",
  "user": {
    "id": "2",
    "name": "Sok Dara",
    "email": "dara@example.com",
    "role": "passenger"
  }
}
```

### Login

`POST /auth/login` — Public, passenger or internal account  
`POST /auth/staff/login` — Public, internal account only

```json
{
  "email": "jane@example.test",
  "password": "password"
}
```

Response: `200 OK`, using the same token response shape as registration.

### Current profile

`GET /auth/me` — Authenticated  
`PUT /auth/me` — Authenticated

Update payload fields are optional:

```json
{
  "name": "Sok Dara",
  "phone": "+85598765432",
  "passport_number": "N1234567",
  "nationality": "Cambodian",
  "date_of_birth": "2000-01-20",
  "special_needs": "Wheelchair assistance"
}
```

Passenger profile fields apply only to passenger accounts.

### Change password

`PUT /auth/change-password` — Authenticated

```json
{
  "current_password": "password",
  "new_password": "new-password-123"
}
```

Response: `204 No Content`.

### Password reset

`POST /auth/forgot-password` — Public

```json
{ "email": "dara@example.com" }
```

The response is deliberately identical whether or not the account exists. In
`local` and `testing`, it also contains `reset_token`; production integrations
should deliver that token through email.

`POST /auth/reset-password` — Public

```json
{
  "email": "dara@example.com",
  "token": "RESET_TOKEN",
  "password": "new-password-123",
  "password_confirmation": "new-password-123"
}
```

Reset tokens expire after one hour and can be used once. A successful reset
revokes all existing sessions for that account.

### Session management

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/auth/sessions` | List the current account's sessions |
| `DELETE` | `/auth/sessions/{session}` | Revoke one owned session |
| `POST` | `/auth/logout` | Revoke the current session |

Logout response:

```json
{ "message": "Logged out." }
```

## Airports and routes

### Airports

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `GET` | `/airports` | Public | Paginated list; filter: `search` across code, name, city, and country |
| `GET` | `/airports/{airport}` | Public | Airport with gates |
| `POST` | `/airports` | Internal | Create |
| `PUT/PATCH` | `/airports/{airport}` | Internal | Update |
| `DELETE` | `/airports/{airport}` | Internal | Delete |

Create payload:

```json
{
  "iata_code": "PNH",
  "name": "Phnom Penh International Airport",
  "city": "Phnom Penh",
  "country": "Cambodia"
}
```

IATA codes must be unique and exactly three characters.

### Routes

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `GET` | `/routes` | Public | Paginated list; filters: `origin_airport_id`, `destination_airport_id`, `is_active` |
| `GET` | `/routes/{route}` | Public | Route with airports and flights |
| `POST` | `/routes` | Internal | Create |
| `PUT/PATCH` | `/routes/{route}` | Internal | Update |
| `DELETE` | `/routes/{route}` | Internal | Delete |

```json
{
  "origin_airport_id": 1,
  "destination_airport_id": 2,
  "distance_km": 500,
  "estimated_duration": 75,
  "is_active": true
}
```

Origin and destination must be different.

## Aircraft, seats, and gates

### Aircraft

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `GET` | `/aircraft?status=available` | Internal | Paginated aircraft list |
| `GET` | `/aircraft/{aircraft}` | Internal | Aircraft and seat map |
| `POST` | `/aircraft` | Internal | Create |
| `PUT/PATCH` | `/aircraft/{aircraft}` | Internal | Update |
| `DELETE` | `/aircraft/{aircraft}` | Internal | Delete |

```json
{
  "registration_number": "XU-001",
  "model": "Airbus A320",
  "capacity": 180,
  "status": "available",
  "manufacturer": "Airbus",
  "flight_hours": 12000
}
```

### Add aircraft seats

`POST /aircraft/{aircraft}/seats` — Internal

```json
{
  "seats": [
    {
      "seat_number": "1A",
      "seat_class": "business",
      "is_window": true,
      "is_aisle": false
    },
    {
      "seat_number": "10C",
      "seat_class": "economy",
      "is_window": false,
      "is_aisle": true
    }
  ]
}
```

### Gates

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `GET` | `/gates?airport_id=1&status=available` | Internal | Paginated list |
| `GET` | `/gates/{gate}` | Internal | Gate and airport |
| `POST` | `/gates` | Internal | Create |
| `PUT/PATCH` | `/gates/{gate}` | Internal | Update |
| `DELETE` | `/gates/{gate}` | Internal | Delete |

```json
{
  "airport_id": 1,
  "gate_code": "A1",
  "status": "available"
}
```

## Flights

### Paginated flight list

`GET /flights` — Public

Query parameters:

| Parameter | Description |
|---|---|
| `flight_number` | Exact flight number |
| `status` | Flight status |
| `origin_airport_id` | Origin airport ID |
| `destination_airport_id` | Destination airport ID |
| `departure_date` | Departure date, `YYYY-MM-DD` |
| `page`, `per_page` | Pagination |

### Flat flight search

`GET /flights/search` — Public

| Parameter | Example |
|---|---|
| `origin` | `Phnom Penh (PNH)` or `PNH` |
| `destination` | `Bangkok (BKK)` or `BKK` |
| `date` | `2026-08-01` |

Response: an unpaginated array of flight resources.

### Flight details and seats

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `GET` | `/flights/{flight}` | Public | Flight, route, aircraft, gate, prices, available-seat count |
| `GET` | `/flights/{flight}/seats` | Public | Flight seat inventory with physical seat and price |

### Schedule flight

`POST /flights` — Internal

```json
{
  "flight_number": "K601",
  "route_id": 1,
  "aircraft_id": 1,
  "gate_id": 1,
  "departure_time": "2026-08-01T09:00:00+07:00",
  "arrival_time": "2026-08-01T10:15:00+07:00",
  "base_price": 100,
  "status": "scheduled",
  "class_prices": [
    { "seat_class": "economy", "price": 100 },
    { "seat_class": "business", "price": 250 }
  ]
}
```

Creation automatically generates one `flight_seat` for every seat in the
aircraft and writes an aircraft-assignment history record.

Scheduling fails with `422` when:

- the aircraft is in maintenance or retired;
- the aircraft has no seat map;
- the aircraft or gate has an overlapping flight;
- maintenance overlaps the proposed flight;
- the gate is closed or does not belong to the origin airport.

### Update or cancel flight

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `PUT` | `/flights/{flight}` | Internal | Update gate, departure/arrival time, or base price |
| `PATCH` | `/flights/{flight}/status` | Internal | Change status and append a status log |
| `DELETE` | `/flights/{flight}` | Internal | Delete flight |

Status payload:

```json
{
  "status": "delayed",
  "reason": "Severe weather at destination"
}
```

## Bookings, payments, and tickets

Passengers may access only their own bookings. Internal users may access all
bookings.

### List and view bookings

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `GET` | `/bookings` | Authenticated | Passenger history or all bookings for internal users |
| `GET` | `/bookings/{booking}` | Authenticated | Full booking details |

### Create booking

`POST /bookings` — Authenticated

```json
{
  "flight_id": 1,
  "trip_type": "one_way",
  "return_flight_id": null,
  "passengers": [
    {
      "passenger_id": 1,
      "seat_class": "economy",
      "flight_seat_id": 5,
      "special_request": "Vegetarian meal"
    }
  ]
}
```

`passenger_id` refers to a `passenger_profiles.id`. `flight_seat_id` is
optional. When provided, the selected seat must belong to the flight, be
available, and match `seat_class`. Seat rows are locked in a transaction to
prevent double booking. A round trip requires a different `return_flight_id`.

Response: `201 Created`, with initial status `pending`.

### Modify passenger seat/request

`PUT /bookings/{booking}` — Authenticated

```json
{
  "passenger_id": 10,
  "flight_seat_id": 8,
  "seat_class": "economy",
  "special_request": "Wheelchair assistance"
}
```

Here `passenger_id` is the `booking_passengers.id` returned by the booking
response. The old seat is released atomically when a new one is reserved.
Cancelled bookings cannot be modified.

### Pay

`POST /bookings/{booking}/payment` — Authenticated

```json
{ "payment_method": "credit_card" }
```

The server calculates the amount using flight class prices, falling back to
`base_price`. A successful payment:

1. records a paid payment;
2. changes the booking to `confirmed`;
3. issues an electronic ticket;
4. writes a booking log;
5. creates a passenger notification.

Response: `201 Created`

```json
{
  "payment": {
    "amount": "120.00",
    "payment_method": "credit_card",
    "payment_status": "paid",
    "transaction_ref": "TXN-ABC123"
  },
  "ticket": {
    "ticket_number": "TKT-ABC123",
    "ticket_code": "ZXCV1234"
  },
  "booking": {
    "status": "confirmed"
  }
}
```

### Cancel

`POST /bookings/{booking}/cancel` — Authenticated

Cancellation releases selected seats and changes a paid payment to `refunded`.
It also creates a booking log and passenger notification.

### Payment and ticket retrieval

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `GET` | `/bookings/{booking}/payment` | Authenticated | Retrieve payment |
| `GET` | `/bookings/{booking}/ticket` | Authenticated | Retrieve issued ticket |
| `POST` | `/tickets/lookup` | Public | Look up by ticket code |

```json
{ "ticket_code": "ZXCV1234" }
```

## Staff and crew

### Staff

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `GET` | `/staff?staff_role=pilot` | Internal | Paginated list |
| `GET` | `/staff/{staff}` | Internal | Staff, account, availability, assignments |
| `POST` | `/staff` | Internal | Create staff profile for an internal account |
| `PUT/PATCH` | `/staff/{staff}` | Internal | Update |
| `DELETE` | `/staff/{staff}` | Internal | Delete |

```json
{
  "internal_user_id": 2,
  "employee_id": "EMP-002",
  "license_number": "LIC-12345",
  "license_expiry": "2028-12-31",
  "staff_role": "pilot",
  "joined_date": "2024-01-10"
}
```

### Availability

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/staff/{staff}/availability` | List daily availability |
| `POST` | `/staff/{staff}/availability` | Create or replace a date's status |

```json
{
  "available_date": "2026-08-01",
  "status": "available",
  "reason": null
}
```

### Crew assignments

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/crew-assignments?flight_id=1&staff_id=2` | List/filter assignments |
| `POST` | `/crew-assignments` | Assign staff |
| `DELETE` | `/crew-assignments/{crewAssignment}` | Remove assignment |

```json
{
  "flight_id": 1,
  "staff_id": 2,
  "assignment_role": "captain"
}
```

The staff member must be marked `available` on the departure date and must not
have an overlapping assignment. A successful assignment changes that day's
availability to `assigned`.

## Maintenance

| Method | Endpoint | Access | Description |
|---|---|---|---|
| `GET` | `/maintenance?aircraft_id=1&is_completed=false` | Internal | List schedules |
| `GET` | `/maintenance/{maintenanceSchedule}` | Internal | Schedule and work logs |
| `POST` | `/maintenance` | Internal | Schedule maintenance |
| `PUT/PATCH` | `/maintenance/{maintenanceSchedule}` | Internal | Update/complete |
| `DELETE` | `/maintenance/{maintenanceSchedule}` | Internal | Delete |
| `POST` | `/maintenance/{maintenanceSchedule}/details` | Internal | Add maintenance log |

Schedule payload:

```json
{
  "aircraft_id": 1,
  "maintenance_type": "inspection",
  "scheduled_date": "2026-08-10",
  "end_date": "2026-08-11",
  "technician_id": 4
}
```

Maintenance cannot overlap a non-cancelled assigned flight. Scheduling changes
the aircraft status to `maintenance`; completing it changes the status to
`available`.

Maintenance log payload:

```json
{
  "work_done": "Completed engine and hydraulic inspection",
  "parts_used": "Hydraulic filter",
  "technician_id": 4,
  "technician_notes": "No additional defects found"
}
```

## Notifications

Passenger-only endpoints:

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/notifications?unread=1` | List notifications; `unread` filters unread items |
| `PATCH` | `/notifications/{notification}/read` | Mark an owned notification read |

## Internal user administration

Admin-only endpoints:

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/internal-users` | Paginated internal accounts |
| `POST` | `/internal-users` | Create an internal account and optional staff profile |
| `PATCH` | `/internal-users/{internalUser}` | Change role or active status |

Create example:

```json
{
  "name": "Operations Manager",
  "email": "manager@airline.test",
  "password": "password123",
  "phone": "+85512345678",
  "role": "manager",
  "employee_id": "EMP-003",
  "staff_role": "ground_staff",
  "joined_date": "2026-07-23"
}
```

Role/status update:

```json
{
  "role": "manager",
  "is_active": true
}
```

An admin cannot deactivate their own account.

## Complete endpoint index

| Group | Public | Authenticated | Internal | Admin |
|---|---:|---:|---:|---:|
| Authentication | 5 | 7 | 0 | 0 |
| Airports/routes/flights | 9 | 0 | 15 | 0 |
| Bookings/payments/tickets | 1 | 8 | 0 | 0 |
| Aircraft/gates | 0 | 0 | 11 | 0 |
| Staff/crew/maintenance | 0 | 0 | 16 | 0 |
| Notifications | 0 | 2 | 0 | 0 |
| Internal users | 0 | 0 | 0 | 3 |

Run `php artisan route:list --path=api` to inspect the authoritative route list.
