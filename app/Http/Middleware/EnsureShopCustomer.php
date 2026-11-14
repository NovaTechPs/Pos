<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets only signed-in shop customers through. Unlike auth:customer it leaves the default guard on
 * staff accounts, so code written for staff users never receives a customer account.
 */
class EnsureShopCustomer
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! auth('customer')->check()) {
            return redirect()->guest(route('shop.login', ['shop' => $request->route('shop')]));
        }

        return $next($request);
    }
}
