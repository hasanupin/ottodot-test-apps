<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('role:parent') or 'role:teacher,admin'. Runs after auth:sanctum. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(in_array($request->user()?->role?->value, $roles, true), 403, 'This action is not allowed for your role.');

        return $next($request);
    }
}
