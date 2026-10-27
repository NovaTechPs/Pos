<?php

use App\Http\Middleware\AuthenticateBranchSyncDevice;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureApiTenant;
use App\Http\Middleware\IsAdmin;
use App\Http\Middleware\SetLocale;
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
        $middleware->web(append: [SetLocale::class, EnsureActiveAccount::class]);
        $middleware->alias([
            'is_admin' => IsAdmin::class,
            'branch.sync' => AuthenticateBranchSyncDevice::class,
            'api.tenant' => EnsureApiTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

$desktopStoragePath = getenv('APP_STORAGE_PATH');
if (getenv('APP_DESKTOP') === 'true' && is_string($desktopStoragePath) && $desktopStoragePath !== '') {
    $application->useStoragePath($desktopStoragePath);
}

return $application;
