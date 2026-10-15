<?php

namespace App\Http\Middleware;

use App\Models\BranchSyncDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBranchSyncDevice
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if (! $plainTextToken) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $device = BranchSyncDevice::query()
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->where('is_active', true)
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $device->forceFill(['last_seen_at' => now()])->saveQuietly();
        $request->attributes->set('branchSyncDevice', $device);

        return $next($request);
    }
}
