<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    /** Starts a session for valid credentials; null when they don't match. */
    public function login(string $email, string $password): ?User
    {
        // ponytail: an unknown email skips the hash check, so it answers slightly faster than a wrong
        // password; the 5/min throttle limits probing. Add a dummy Hash::check if enumeration matters.
        // Explicit 'web' (session) guard: auth:sanctum switches the default guard to 'sanctum', which can't attempt().
        if (! Auth::guard('web')->attempt(['email' => $email, 'password' => $password])) {
            return null;
        }

        session()->regenerate();   // new session id after login (session fixation)

        return Auth::guard('web')->user();
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();
    }
}
