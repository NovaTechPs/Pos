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
use Illuminate\Support\Facades\Schema;

#[Signature('parties:reconcile-balances
    {--tenant= : Only this store}
    {--party=* : Only these parties}
    {--except-name=* : Skip parties whose name contains this text}
    {--fix : Save the ledger balance instead of only listing differences}
    {--reason=manual : Recorded with each correction}')]
#[Description('Compare each customer and supplier stored balance with its statement ledger, and optionally correct it.')]
class ReconcilePartyBalances extends Command
{
    public function handle(): int
    {
        $tenants = Tenant::query()->when($this->option('tenant'), fn ($query, $tenantId) => $query->whereKey($tenantId))->get();
        $exceptNames = array_values(array_filter(array_map('trim', (array) $this->option('except-name'))));
        $differences = 0;
        foreach ($tenants as $tenant) {
            $owner = User::query()->where('tenant_id', $tenant->id)->where('is_owner', true)->first();
            if (! $owner) {
                $this->warn("Store {$tenant->id} has no owner account; skipped.");

                continue;
            }
            $ledger = new ReportingService($owner, null, false);
            Party::query()->where('tenant_id', $tenant->id)
                ->when($this->option('party'), fn ($query, array $ids) => $query->whereIn('id', $ids))
                ->orderBy('id')->each(function (Party $party) use ($ledger, $exceptNames, &$differences): void {
                    $expected = $ledger->partyLedgerBalance($party->id);
                    $stored = round((float) $party->current_balance, 2);
                    if (abs($expected - $stored) < 0.005) {
                        return;
                    }
                    $differences++;
                    $line = sprintf('Store %d · %s (#%d): stored %.2f, statement %.2f, difference %.2f',
                        $party->tenant_id, $party->name, $party->id, $stored, $expected, $expected - $stored);
                    $skip = $this->skipReason($party, $exceptNames);
                    if ($skip !== null) {
                        $this->line($line.' — not corrected: '.$skip);

                        return;
                    }
                    $this->line($line);
                    if ($this->option('fix')) {
                        $this->correct($party->id, $ledger);
                    }
                });
        }

        $this->info($differences === 0 ? 'All balances match their statements.'
            : ($this->option('fix') ? "Checked {$differences} difference(s); corrected the ones not marked as skipped." : "{$differences} balance(s) differ. Run again with --fix to correct them."));

        return self::SUCCESS;
    }

    /** @param  list<string>  $exceptNames */
    private function skipReason(Party $party, array $exceptNames): ?string
    {
        foreach ($exceptNames as $name) {
            if (mb_stripos($party->name, $name) !== false) {
                return 'excluded by name';
            }
        }
        // Whether a delivery company that is also the invoice customer owes the delivery fee once
        // or twice is not decided yet, so such balances are left for a manual decision.
        $sharedDelivery = DB::table('orders')->where('tenant_id', $party->tenant_id)->whereNull('deleted_at')
            ->where('customer_id', $party->id)->where('delivery_party_id', $party->id)->where('delivery_fee', '>', 0)->exists();

        return $sharedDelivery ? 'delivery company that is also the customer on its invoices' : null;
    }

    private function correct(int $partyId, ReportingService $ledger): void
    {
        DB::transaction(function () use ($partyId, $ledger): void {
            $party = Party::query()->lockForUpdate()->findOrFail($partyId);
            $previous = round((float) $party->current_balance, 2);
            $party->current_balance = $ledger->partyLedgerBalance($party->id);
            $party->save();
            if (Schema::hasTable('party_balance_corrections')) {
                DB::table('party_balance_corrections')->insert([
                    'tenant_id' => $party->tenant_id, 'party_id' => $party->id, 'previous_balance' => $previous,
                    'corrected_balance' => $party->current_balance, 'reason' => mb_substr((string) $this->option('reason'), 0, 255),
                    'created_at' => now(),
                ]);
            }
        });
    }
}
