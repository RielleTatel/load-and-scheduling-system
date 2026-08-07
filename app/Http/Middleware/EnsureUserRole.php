<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * Gate a route group to a single role. Inactive accounts are refused
     * regardless of role — deactivation removes access without deleting history.
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        abort_unless($user && $user->is_active, 403);
        abort_unless($user->role->value === $role, 403);

        return $next($request);
    }
}
