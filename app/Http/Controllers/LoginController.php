<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    // ponytail: single demo account from config, stateless (no token/session); swap for Auth::attempt once parents are real users.
    public function __invoke(Request $request): array
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $demo = config('auth.demo_user');

        // Evaluate both comparisons (no short-circuit) so timing doesn't reveal which field was wrong.
        $emailOk = hash_equals($demo['email'], $credentials['email']);
        $passwordOk = hash_equals($demo['password'], $credentials['password']);

        if (! ($emailOk && $passwordOk)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        return ['user' => ['name' => $demo['name'], 'email' => $demo['email']]];
    }
}
