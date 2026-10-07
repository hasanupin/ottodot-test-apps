<?php

use Illuminate\Support\Facades\Route;

// The React app shell. Guests are sent to /login, logged-in users away from it (bootstrap/app.php).
Route::middleware('auth')->group(function () {
    Route::view('/', 'app');

    // Management pages (07c) and the roster (12). The API decides what each role may see or change.
    foreach (['trial-classes', 'teachers', 'parents', 'students', 'bookings', 'payments', 'roster'] as $page) {
        Route::view("/{$page}", 'app');
    }
});
Route::view('/login', 'app')->middleware('guest')->name('login');

Route::view('/sample', 'sample');
