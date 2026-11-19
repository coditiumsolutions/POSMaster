<?php

namespace App\Http\Middleware;

use App\Services\AppRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSoftwareRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->hasRole(AppRoles::Software)) {
            abort(403, 'Access denied. Software role required.');
        }

        return $next($request);
    }
}
