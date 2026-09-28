<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// Smoke-test endpoint: proves the API is reachable and the database answers.
Route::get('/ping', fn () => [
    'status' => 'ok',
    'database' => DB::connection()->getDatabaseName(),
    'db_time' => DB::scalar('SELECT NOW()'),
]);
