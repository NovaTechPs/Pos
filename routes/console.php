<?php

use App\Models\Tenant;
use App\Services\PartyBalanceCheck;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('branch-sync:push')
    ->everyTenSeconds()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn (): bool => config('branch-sync.mode') === 'edge');

Schedule::command('branch-sync:pull')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->when(fn (): bool => config('branch-sync.mode') === 'edge');

// Where a scheduler runs, check every store's party balances against their statements each week
// (the central server also starts this check from the owner's dashboard when it is due).
Schedule::call(fn () => Tenant::query()->pluck('id')
    ->each(fn (int $tenantId) => app(PartyBalanceCheck::class)->run($tenantId)))
    ->weekly()
    ->name('party-balance-check')
    ->withoutOverlapping()
    ->when(fn (): bool => config('branch-sync.mode') !== 'edge');
