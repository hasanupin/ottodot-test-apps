<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\ParentController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\TeacherController;
use App\Http\Controllers\TrialClassController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Smoke-test endpoint: proves the API is reachable and the database answers.
Route::get('/ping', fn () => [
    'status' => 'ok',
    'database' => DB::connection()->getDatabaseName(),
    'db_time' => DB::scalar('SELECT NOW()'),
]);

// Throttled against brute force: 5 attempts per minute per IP.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Lists: every role may call these; the service scopes the rows to what the user may see.
    Route::get('/trial-classes', [TrialClassController::class, 'index']);
    Route::get('/students', [StudentController::class, 'index']);
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/parents', [ParentController::class, 'index'])->middleware('role:admin,teacher');

    // Booking flow. The parent is always the session user; the controller 404s on someone else's booking.
    Route::middleware('role:parent')->group(function () {
        Route::post('/bookings', [BookingController::class, 'store']);
        Route::get('/bookings/{booking}', [BookingController::class, 'show']);
        Route::post('/bookings/{booking}/pay', [BookingController::class, 'pay']);
        Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    });
    Route::get('/trial-classes/{trialClass}/roster', [TrialClassController::class, 'roster'])->middleware('role:teacher,admin');

    Route::middleware('role:admin')->group(function () {
        Route::apiResource('trial-classes', TrialClassController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('teachers', TeacherController::class)->except(['show']);
        Route::apiResource('parents', ParentController::class)->only(['store', 'update', 'destroy'])
            ->parameters(['parents' => 'guardian']);
        Route::apiResource('students', StudentController::class)->only(['store', 'update', 'destroy']);
        Route::get('/payments', [PaymentController::class, 'index']);
    });
});
