<?php

namespace App\Services;

use App\Models\BranchProduct;
use App\Models\Order;
use DomainException;
use Illuminate\Support\Facades\DB;

class BranchStockAllocator
{
    public function deduct(Order $order, int $productId, float $quantity, bool $shared, bool $allowNegative): void
    {
        $this->deductMany($order, [$productId => $quantity], $shared, $allowNegative);
    }

    /** @param array<int, float> $quantities */
    public function deductMany(Order $order, array $quantities, bool $shared, bool $allowNegative): void
    {
        if ($quantities === []) {
            return;
        }
        DB::transaction(function () use ($order, $quantities, $shared, $allowNegative): void {
            $stocks = BranchProduct::query()->where('tenant_id', $order->tenant_id)
                ->whereIn('product_id', array_keys($quantities))
                ->when(! $shared, fn ($query) => $query->where('branch_id', $order->branch_id))
                ->orderBy('product_id')->orderBy('id')->lockForUpdate()->get()->groupBy('product_id');
            foreach ($quantities as $productId => $quantity) {
                $rows = $stocks->get($productId, collect());
                $local = $rows->firstWhere('branch_id', $order->branch_id);
                if (! $local) {
                    throw new DomainException('الصنف غير مرتبط بفرع البيع.');
                }
                $available = (float) $rows->sum(fn ($row) => max(0, (float) $row->stock_quantity));
                if (! $allowNegative && $quantity > $available + 0.000001) {
                    throw new DomainException('المخزون غير كافٍ لإتمام البيع.');
                }
                $remaining = $quantity;
                foreach ($rows->sortByDesc(fn ($row) => (int) $row->branch_id === (int) $order->branch_id) as $row) {
                    if ($remaining <= 0.000001) {
                        break;
                    }
                    $take = min($remaining, max(0, (float) $row->stock_quantity));
                    if ($take > 0) {
                        StockMovementContext::forDocument($order, fn () => $row->decrement('stock_quantity', $take));
                        $remaining -= $take;
                    }
                }
                if ($remaining > 0.000001 && $allowNegative) {
                    StockMovementContext::forDocument($order, fn () => $local->decrement('stock_quantity', $remaining));
                }
            }
        });
    }

    public function restore(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $expected = $order->items()->get()->groupBy('product_id')->map(fn ($items) => (float) $items->sum('quantity'));
            $movements = DB::table('stock_movements')->where('tenant_id', $order->tenant_id)->where('source_id', $order->id)
                ->whereIn('source_type', ['order', 'sale_reversal'])->selectRaw('product_id, branch_id, SUM(quantity) AS effect')
                ->groupBy('product_id', 'branch_id')->orderBy('product_id')->orderBy('branch_id')->get();
            $effects = $movements->groupBy('product_id')->map(fn ($rows) => -(float) $rows->sum('effect'));
            foreach ($expected as $productId => $quantity) {
                if (abs($quantity - $effects->get($productId, 0)) > 0.0001) {
                    throw new DomainException('سجل مخزون الفاتورة غير مكتمل؛ تعذرت إعادة كمياتها.');
                }
            }
            foreach ($movements as $movement) {
                if (abs((float) $movement->effect) < 0.0001) {
                    continue;
                }
                if ((float) $movement->effect > 0 || ! $expected->has($movement->product_id)) {
                    throw new DomainException('تعذرت مطابقة حركات مخزون الفاتورة.');
                }
                $row = BranchProduct::query()->where('tenant_id', $order->tenant_id)->where('branch_id', $movement->branch_id)
                    ->where('product_id', $movement->product_id)->lockForUpdate()->firstOrFail();
                StockMovementContext::forDocument($order, fn () => $row->increment('stock_quantity', -(float) $movement->effect), 'sale_reversal');
            }
        });
    }
}
