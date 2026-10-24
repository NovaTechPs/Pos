<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureApiTenant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user && $user->is_active && $user->tenant_id, 403, 'حساب المستخدم غير مرتبط بمنشأة نشطة.');
        abort_unless(Tenant::query()->whereKey($user->tenant_id)->where('is_active', true)->exists(), 403, 'المنشأة غير نشطة.');

        $requestedTenantId = $request->input('tenant_id', $request->header('X-Tenant-ID'));
        abort_unless($requestedTenantId === null || (int) $requestedTenantId === (int) $user->tenant_id, 404);

        foreach (['branch_id', 'from_branch_id', 'to_branch_id'] as $key) {
            $branchId = $request->input($key, $key === 'branch_id' ? $request->header('X-Branch-ID') : null);

            if ($branchId === null || $branchId === '') {
                continue;
            }

            $branchQuery = Branch::query()
                ->where('tenant_id', $user->tenant_id)
                ->whereKey($branchId);

            if ($user->branch_id && ! $user->isTenantOwner()) {
                $branchQuery->whereKey($user->branch_id);
            }

            abort_unless($branchQuery->exists(), 404);
        }

        $user->loadRolePermissions();

        return $next($request);
    }
}
