<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * One-time correction on the central server after the POS credit-sale fix: stored customer and
     * supplier balances that differ from their statements are set to the statement balance.
     * Al-Azm and the Hook delivery companies are left for the owner's decision, as are delivery
     * companies that are also the customer on their invoices. Every change is recorded in
     * party_balance_corrections with its previous value.
     */
    public function up(): void
    {
        // Branch devices hold only a cached copy of balances; the central server owns them.
        if (config('branch-sync.mode') === 'edge' || config('app.desktop')) {
            return;
        }

        Artisan::call('parties:reconcile-balances', [
            '--fix' => true,
            '--except-name' => ['العزم', 'هوك'],
            '--reason' => 'pos-credit-sale-fix-2026-11-12',
        ]);
        Log::info('Party balance reconciliation after the POS credit-sale fix', ['output' => Artisan::output()]);
    }

    /** Corrections are kept; party_balance_corrections holds the previous balances if they must be restored. */
    public function down(): void {}
};
