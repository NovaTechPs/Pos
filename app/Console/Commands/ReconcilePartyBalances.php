<?php

namespace App\Console\Commands;

use App\Models\Party;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('parties:reconcile-balances {--tenant= : Only this store} {--party=* : Only these parties} {--fix : Save the ledger balance instead of only listing differences}')]
#[Description('Compare each customer and supplier stored balance with its statement ledger, and optionally correct it.')]
class ReconcilePartyBalances extends Command
{
    public function handle(): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $tenantId) => $query->whereKey($tenantId))->get();
        $differences = 0;
        foreach ($tenants as $tenant) {
            $owner = User::query()->where('tenant_id', $tenant->id)->where('is_owner', true)->first();
            if (! $owner) {
                $this->warn("Store {$tenant->id} has no owner account; skipped.");

                continue;
            }
            $ledger = new ReportingService($owner, null, false);
            Party::query()->where('tenant_id', $tenant->id)->when($this->option('party'), fn ($query, array $ids) => $query->whereIn('id', $ids))->orderBy('id')->each(function (Party $party) use ($ledger, &$differences): void {
                $expected = $ledger->partyLedgerBalance($party->id);
                $stored = round((float) $party->current_balance, 2);
                if (abs($expected - $stored) < 0.005) {
                    return;
                }
                $differences++;
                $this->line(sprintf('Store %d · %s (#%d): stored %.2f, statement %.2f, difference %.2f',
                    $party->tenant_id, $party->name, $party->id, $stored, $expected, $expected - $stored));
                if ($this->option('fix')) {
                    DB::transaction(function () use ($party, $ledger): void {
                        $locked = Party::query()->lockForUpdate()->findOrFail($party->id);
                        $locked->current_balance = $ledger->partyLedgerBalance($locked->id);
                        $locked->save();
                    });
                }
            });
        }

        $this->info($differences === 0 ? 'All balances match their statements.'
            : ($this->option('fix') ? "Corrected {$differences} balance(s)." : "{$differences} balance(s) differ. Run again with --fix to correct them."));

        return self::SUCCESS;
    }
}
