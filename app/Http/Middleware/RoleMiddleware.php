<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$roles
    ): Response {
        if (! $request->user()) {
            abort(401);
        }

        if (! $request->user()->hasRole(array_values($roles))) {
            abort(403);
        }

        return $next($request);
    }
}
