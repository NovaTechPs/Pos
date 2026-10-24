<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->canAccessApplication()) {
            auth('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, __('Your account or tenant is inactive.'));
        }

        $user?->loadRolePermissions();

        return $next($request);
    }
}
