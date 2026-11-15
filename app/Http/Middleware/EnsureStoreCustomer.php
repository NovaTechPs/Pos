<?php

namespace App\Http\Middleware;

use App\Services\OnlineShop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStoreCustomer
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $service = app(OnlineShop::class);
        if (! $service->storeCustomer($service->branch((string) $request->route('shop')))) {
            return redirect()->route('shop.login', ['shop' => $request->route('shop')]);
        }

        return $next($request);
    }
}
