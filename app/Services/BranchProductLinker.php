<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Makes every product available in every branch of its store, so a product added by one
 * branch can be sold by the others.
 */
class BranchProductLinker
{
    /**
     * Add the missing branch rows with no stock. A product with unified prices takes its
     * existing prices; otherwise the new branch starts at price 0.
     *
     * @return int number of branch rows added
     */
    public function linkMissing(?int $tenantId = null, ?int $branchId = null): int
    {
        $price = fn (string $column, string $fallback): string => "CASE WHEN p.is_price_unified = 1 THEN COALESCE((SELECT s.{$column} FROM branch_products s WHERE s.product_id = p.id ORDER BY s.id LIMIT 1), {$fallback}) ELSE {$fallback} END";
        $now = now()->toDateTimeString();

        $missing = DB::table('products as p')
            ->join('branches as b', 'b.tenant_id', '=', 'p.tenant_id')
            ->when($tenantId, fn ($query) => $query->where('p.tenant_id', $tenantId))
            ->when($branchId, fn ($query) => $query->where('b.id', $branchId))
            ->whereNotExists(fn ($query) => $query->from('branch_products as bp')
                ->whereColumn('bp.product_id', 'p.id')
                ->whereColumn('bp.branch_id', 'b.id'));

        $count = (clone $missing)->count();
        if ($count === 0) {
            return 0;
        }

        DB::table('branch_products')->insertUsing(
            ['tenant_id', 'branch_id', 'product_id', 'stock_quantity', 'retail_price', 'wholesale_price', 'min_wholesale_quantity', 'created_at', 'updated_at'],
            $missing->selectRaw("p.tenant_id, b.id, p.id, 0, {$price('retail_price', '0')}, {$price('wholesale_price', '0')}, {$price('min_wholesale_quantity', '1')}, ?, ?", [$now, $now]),
        );

        return $count;
    }
}
