<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->login($request->validated('email'), $request->validated('password'));

        if (! $user) {
            return self::failed(__('auth.failed'), 422, ['email' => [__('auth.failed')]]);
        }

        return $this->success(['user' => $user->toApi()], 'Logged in.');
    }

    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return $this->success(null, 'Logged out.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success(['user' => $request->user()->toApi()]);
    }
}
