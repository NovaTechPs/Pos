<?php

namespace App\Services;

use App\Models\Party;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Weekly comparison of every stored customer and supplier balance with its statement.
 * It only reads balances and saves the result for the store owner's dashboard; it never corrects them.
 */
class PartyBalanceCheck
{
    public const SETTING = 'party_balance_check';

    public const INTERVAL_DAYS = 7;

    /** @return array{checked_at: string, count: int, differences: list<array{id: int, name: string, stored: float, statement: float}>}|null */
    public function lastResult(int $tenantId): ?array
    {
        $result = json_decode((string) TenantSetting::get($tenantId, self::SETTING, ''), true);

        return is_array($result) && isset($result['checked_at'], $result['count'], $result['differences']) ? $result : null;
    }

    public function isDue(int $tenantId): bool
    {
        $last = $this->lastResult($tenantId);

        return $last === null || Carbon::parse($last['checked_at'])->lt(now()->subDays(self::INTERVAL_DAYS));
    }

    /** @return array{checked_at: string, count: int, differences: list<array{id: int, name: string, stored: float, statement: float}>} */
    public function run(int $tenantId): array
    {
        $owner = User::query()->where('tenant_id', $tenantId)->where('is_owner', true)->first();
        $differences = [];
        if ($owner) {
            $statements = (new ReportingService($owner, null, false))->partyLedgerBalances();
            Party::query()->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name', 'current_balance'])
                ->each(function (Party $party) use ($statements, &$differences): void {
                    $statement = $statements[$party->id] ?? 0.0;
                    $stored = round((float) $party->current_balance, 2);
                    if (abs($statement - $stored) >= 0.005) {
                        $differences[] = ['id' => $party->id, 'name' => $party->name, 'stored' => $stored, 'statement' => $statement];
                    }
                });
        }
        $result = ['checked_at' => now()->toIso8601String(), 'count' => count($differences), 'differences' => array_slice($differences, 0, 100)];
        TenantSetting::set($tenantId, self::SETTING, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result;
    }
}
