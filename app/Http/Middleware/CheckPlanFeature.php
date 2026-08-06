<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPlanFeature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
   public function handle(Request $request, Closure $next, string $featureKey)
    {
        $user = $request->user();

        if (!$user || !$user->tenant || !$user->tenant->hasFeature($featureKey)) {
            abort(403, 'هذه الميزة غير متاحة في خطة اشتراكك الحالية.');
        }

        return $next($request);
    }
}
