<?php

use App\Http\Controllers\Api\BranchSyncOperationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['branch.sync', 'throttle:120,1'])
    ->post('/branch-sync/operations', [BranchSyncOperationController::class, 'store'])
    ->name('api.branch-sync.operations.store');
