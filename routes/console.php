<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('branch-sync:push')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn (): bool => config('branch-sync.mode') === 'edge');
