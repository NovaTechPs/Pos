<?php

namespace App\Services;

use App\Models\TenantSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Warns the cashier about products that have an expired batch, or one expiring within a week, in the
 * branch while the product is still in stock. Stock is not tracked per batch, so this is a hint to
 * check the shelf, not a statement about the piece being sold.
 */
class ExpiryWarnings
{
    public const SOON_DAYS = 7;

    /**
     * @param  list<int>  $productIds
     * @return array<int, array{status: 'expired'|'soon', date: string}>
     */
    public function forProducts(int $tenantId, ?int $branchId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if ($tenantId <= 0 || ! $branchId || $productIds === [] || ! TenantSetting::getBool($tenantId, 'track_expiry')) {
            return [];
        }

        $today = Carbon::today();

        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('branch_products', fn ($join) => $join->on('branch_products.branch_id', '=', 'purchases.branch_id')->on('branch_products.product_id', '=', 'purchase_items.product_id'))
            ->where('purchases.tenant_id', $tenantId)->whereNull('purchases.deleted_at')
            ->where('purchases.branch_id', $branchId)
            ->where('branch_products.stock_quantity', '>', 0)
            ->whereIn('purchase_items.product_id', $productIds)
            ->whereNotNull('purchase_items.expiry_date')
            ->whereDate('purchase_items.expiry_date', '<=', $today->copy()->addDays(self::SOON_DAYS)->toDateString())
            ->groupBy('purchase_items.product_id')
            ->selectRaw('purchase_items.product_id, MIN(purchase_items.expiry_date) as earliest')
            ->get()
            ->mapWithKeys(function (object $row) use ($today): array {
                $date = substr((string) $row->earliest, 0, 10);

                return [(int) $row->product_id => ['status' => $date < $today->toDateString() ? 'expired' : 'soon', 'date' => $date]];
            })
            ->all();
    }
}
