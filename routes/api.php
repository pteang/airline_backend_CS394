<?php

use App\Http\Controllers\Api\AircraftController;
use App\Http\Controllers\Api\AirportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CrewAssignmentController;
use App\Http\Controllers\Api\FlightController;
use App\Http\Controllers\Api\GateController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\RouteController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\UserManagementController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public / auth
|--------------------------------------------------------------------------
*/
// Rate-limited so credential endpoints resist brute-force / abuse (per IP).
Route::middleware('throttle:10,1')->group(function () {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);
    Route::post('auth/staff/login', [AuthController::class, 'staffLogin']);
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword']);
});

// Public read-only reference & flight search.
Route::get('airports', [AirportController::class, 'index']);
Route::get('airports/{airport}', [AirportController::class, 'show']);
Route::get('routes', [RouteController::class, 'index']);
Route::get('routes/{route}', [RouteController::class, 'show']);
// Contract flight endpoints (flat shapes). `search` must precede `{flight}`.
Route::get('flights/search', [FlightController::class, 'search']);
Route::get('flights', [FlightController::class, 'index']);
Route::get('flights/{flight}/seats', [FlightController::class, 'seats']);
Route::get('flights/{flight}', [FlightController::class, 'show']);
Route::post('tickets/lookup', [TicketController::class, 'lookup']);

/*
|--------------------------------------------------------------------------
| Authenticated (any logged-in user)
|--------------------------------------------------------------------------
*/
Route::middleware('auth.session')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::put('auth/me', [AuthController::class, 'updateProfile']);
    Route::put('auth/change-password', [AuthController::class, 'changePassword']);
    Route::get('auth/sessions', [AuthController::class, 'sessions']);
    Route::delete('auth/sessions/{session}', [AuthController::class, 'revokeSession']);

    // Passenger booking journey.
    Route::get('bookings', [BookingController::class, 'index']);
    Route::post('bookings', [BookingController::class, 'store']);
    Route::get('bookings/{booking}', [BookingController::class, 'show']);
    Route::put('bookings/{booking}', [BookingController::class, 'update']);
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::post('bookings/{booking}/payment', [PaymentController::class, 'store']);
    Route::get('bookings/{booking}/payment', [PaymentController::class, 'show']);
    Route::get('bookings/{booking}/ticket', [TicketController::class, 'showForBooking']);

    /*
    |----------------------------------------------------------------------
    | Internal-user only (staff/admin management)
    |----------------------------------------------------------------------
    */
    Route::middleware('internal')->group(function () {
        // Reference data management.
        Route::apiResource('airports', AirportController::class)->except(['index', 'show']);
        Route::apiResource('routes', RouteController::class)->except(['index', 'show']);
        Route::apiResource('aircraft', AircraftController::class);
        Route::post('aircraft/{aircraft}/seats', [AircraftController::class, 'storeSeats']);
        Route::apiResource('gates', GateController::class);

        // Flight operations.
        Route::post('flights', [FlightController::class, 'store']);
        Route::put('flights/{flight}', [FlightController::class, 'update']);
        Route::patch('flights/{flight}/status', [FlightController::class, 'updateStatus']);
        Route::delete('flights/{flight}', [FlightController::class, 'destroy']);

        // Staff & crew.
        Route::apiResource('staff', StaffController::class);
        Route::get('staff/{staff}/availability', [StaffController::class, 'availability']);
        Route::post('staff/{staff}/availability', [StaffController::class, 'storeAvailability']);
        Route::get('crew-assignments', [CrewAssignmentController::class, 'index']);
        Route::post('crew-assignments', [CrewAssignmentController::class, 'store']);
        Route::delete('crew-assignments/{crewAssignment}', [CrewAssignmentController::class, 'destroy']);

        // Maintenance.
        Route::apiResource('maintenance', MaintenanceController::class)->parameters([
            'maintenance' => 'maintenanceSchedule',
        ]);
        Route::post('maintenance/{maintenanceSchedule}/details', [MaintenanceController::class, 'storeDetail']);

        Route::middleware('internal:admin')->group(function () {
            Route::get('internal-users', [UserManagementController::class, 'index']);
            Route::post('internal-users', [UserManagementController::class, 'store']);
            Route::patch('internal-users/{internalUser}', [UserManagementController::class, 'update']);
        });
    });
});
