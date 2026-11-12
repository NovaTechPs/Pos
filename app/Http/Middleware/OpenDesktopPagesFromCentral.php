<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In the desktop app only the point of sale and its offline companions run on the device's own database.
 * Every other store page opens from the central server, so it always shows and saves live data; while the
 * device is offline the page explains that and offers the local equivalent where one exists.
 */
class OpenDesktopPagesFromCentral
{
    /** Pages that always run on the device, online or offline. */
    private const LOCAL_PAGES = [
        'tenant.dashboard',
        'tenant.pos',
        'tenant.barcode.printer',
        'tenant.sale-invoices.pdf',
        'tenant.returns.pdf',
        'tenant.vouchers.pdf',
    ];

    /** Pages that open from the server when online and have a local, device-only version for offline use. */
    private const LOCAL_FALLBACK_PAGES = [
        'tenant.sale-invoices' => 'فواتير مبيعات هذا الجهاز',
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = (string) $request->route()?->getName();
        if (! config('app.desktop') || config('branch-sync.mode') !== 'edge' || ! $request->user()
            || ! str_starts_with($routeName, 'tenant.') || in_array($routeName, self::LOCAL_PAGES, true)
            || ! $request->isMethod('GET') || $request->expectsJson()) {
            return $next($request);
        }

        $localFallback = self::LOCAL_FALLBACK_PAGES[$routeName] ?? null;
        if ($localFallback !== null && $request->boolean('local')) {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');
        $query = collect($request->query())->except(['_workspace', 'local'])->all();

        return response()->view('desktop.central-page', [
            'centralPath' => $path.($query !== [] ? '?'.http_build_query($query) : ''),
            'localFallbackLabel' => $localFallback,
            'localFallbackUrl' => $localFallback !== null ? $request->fullUrlWithQuery(['local' => 1]) : null,
        ]);
    }
}
