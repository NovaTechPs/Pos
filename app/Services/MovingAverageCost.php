<?php

namespace App\Services;

use App\Models\Product;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a product's cost as the moving weighted average of the stock on hand:
 * each receipt blends its cost into the value already in stock, and goods that go back
 * to the supplier leave at the cost they came in with. Sales leave at the average, so they
 * do not change it. The cost is shared by all branches, like the product's cost price.
 */
class MovingAverageCost
{
    public const SETTING = 'moving_average_cost';

    public function enabled(int $tenantId): bool
    {
        return TenantSetting::getBool($tenantId, self::SETTING, true);
    }

    /**
     * Stock of the products across all branches.
     *
     * @param  list<int>  $productIds
     * @return array<int, float>
     */
    public function stockOnHand(int $tenantId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('branch_products')->where('tenant_id', $tenantId)->whereIn('product_id', $productIds)
            ->groupBy('product_id')->selectRaw('product_id, SUM(stock_quantity) as quantity')
            ->pluck('quantity', 'product_id')->map(fn ($quantity): float => (float) $quantity)->all();
    }

    /**
     * Applies stock moves, in order, to the product's average cost.
     *
     * @param  float  $stockBefore  stock across all branches before these moves
     * @param  list<array{0: float, 1: float}>  $moves  [quantity, unit cost]: a positive quantity is received at that
     *                                                  cost, a negative one leaves at the cost it was received at
     */
    public function apply(Product $product, float $stockBefore, array $moves): void
    {
        $quantity = max(0.0, $stockBefore);
        $value = $quantity * (float) $product->cost_price;

        foreach ($moves as [$moved, $unitCost]) {
            $unitCost = max(0.0, (float) $unitCost);
            if ($moved > 0) {
                $value += $moved * $unitCost;
                $quantity += $moved;
            } elseif ($moved < 0 && $quantity + $moved > 0.0005) {
                $value += $moved * $unitCost;
                $quantity += $moved;
            } elseif ($moved < 0) {
                $quantity = 0.0;
                $value = 0.0;
            }
        }

        // With nothing left in stock there is no average to keep; the last cost stays.
        if ($quantity <= 0.0005) {
            return;
        }

        $average = round(max(0.0, $value / $quantity), 2);
        if (abs($average - (float) $product->cost_price) >= 0.005) {
            $product->forceFill(['cost_price' => $average])->save();
        }
    }
}
