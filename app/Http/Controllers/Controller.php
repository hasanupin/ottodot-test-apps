<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /** Standard success envelope: {"success": true, "message": "...", "data": ...}. */
    protected function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    /**
     * Standard failure envelope: {"success": false, "message": "...", "errors": {...}}.
     * Static so bootstrap/app.php renders API exceptions with the same shape.
     */
    public static function failed(string $message, int $status, array $errors = []): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status);
    }
}
