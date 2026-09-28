<?php

use App\Http\Controllers\LoginController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Smoke-test endpoint: proves the API is reachable and the database answers.
Route::get('/ping', fn () => [
    'status' => 'ok',
    'database' => DB::connection()->getDatabaseName(),
    'db_time' => DB::scalar('SELECT NOW()'),
]);

// Demo login (no DB). Throttled against brute force: 5 attempts per minute per IP.
Route::post('/login', LoginController::class)->middleware('throttle:5,1');
