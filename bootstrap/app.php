<?php

use App\Http\Middleware\AuthenticateBranchSyncDevice;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureApiTenant;
use App\Http\Middleware\EnsureShopCustomer;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\OpenDesktopPagesFromCentral;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Services\SystemErrorLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        // Shop customers sign in on their store's own page, never the staff login.
        $middleware->redirectGuestsTo(fn (Request $request): string => $request->is('shop/*') && $request->route('shop')
            ? route('shop.login', ['shop' => $request->route('shop')])
            : route('login'));
        $middleware->redirectUsersTo(fn (Request $request): string => $request->is('shop/*') && $request->route('shop')
            ? route('shop.home', ['shop' => $request->route('shop')])
            : route('home'));
        $middleware->web(append: [SetLocale::class, EnsureActiveAccount::class, OpenDesktopPagesFromCentral::class]);
        // Set TRUSTED_HOSTS (comma separated patterns, e.g. ^waslapos\.app$,^(.+\.)?waslapos\.app$) to reject
        // requests that name another host. Left empty, every host is accepted, as before.
        $trustedHosts = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_HOSTS', '')))));
        if ($trustedHosts !== []) {
            $middleware->trustHosts(at: $trustedHosts, subdomains: false);
        }
        $middleware->alias([
            'is_admin' => IsAdmin::class,
            'branch.sync' => AuthenticateBranchSyncDevice::class,
            'api.tenant' => EnsureApiTenant::class,
            'shop.customer' => EnsureShopCustomer::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): void {
            app(SystemErrorLog::class)->record($exception);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$desktopStoragePath = getenv('APP_STORAGE_PATH');
if (getenv('APP_DESKTOP') === 'true' && is_string($desktopStoragePath) && $desktopStoragePath !== '') {
    $application->useStoragePath($desktopStoragePath);
}

return $application;
