<?php

namespace App\Traits;

use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

trait HasProductPriceHistory
{
    #[Locked]
    public ?int $priceHistoryProductId = null;

    public ?int $priceHistoryCustomerId = null;

    public bool $showPriceHistory = false;

    #[On('open-product-price-history')]
    public function openPriceHistory(int $productId, ?int $customerId = null): void
    {
        abort_unless(auth()->check() && $this->tenantId(), 403);
        Product::query()->where('tenant_id', $this->tenantId())->findOrFail($productId);
        $this->priceHistoryProductId = $productId;
        $this->priceHistoryCustomerId = $customerId;
        $this->showPriceHistory = true;
        $this->resetPage('priceSalesPage');
        $this->resetPage('priceCustomerPage');
        $this->resetPage('priceCostsPage');
        unset($this->productPriceHistory);
    }

    public function updatedPriceHistoryCustomerId(): void
    {
        $this->resetPage('priceCustomerPage');
    }

    /** @return array{product: Product, customers: Collection, customerSales: ?LengthAwarePaginator, sales: LengthAwarePaginator, costs: LengthAwarePaginator} */
    #[Computed]
    public function productPriceHistory(): array
    {
        abort_unless(auth()->check() && $this->tenantId(), 403);
        $tenantId = $this->tenantId();
        $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($this->priceHistoryProductId);
        $sales = DB::table('order_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->leftJoin('parties as customers', function (JoinClause $join) use ($tenantId): void {
                $join->on('customers.id', '=', 'orders.customer_id')->where('customers.tenant_id', $tenantId);
            })
            ->where('orders.tenant_id', $tenantId)
            ->where('items.tenant_id', $tenantId)
            ->where('items.product_id', $product->id)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', 'completed')
            ->where('orders.type', '!=', 'return')
            ->select('items.id', 'items.unit_price as price', 'items.quantity', 'orders.invoice_number as invoice', 'orders.created_at as date')
            ->selectRaw("COALESCE(NULLIF(orders.customer_name, ''), customers.name) as party")
            ->orderByDesc('orders.created_at')->orderByDesc('items.id');
        $costs = DB::table('purchase_items as items')
            ->join('purchases', 'purchases.id', '=', 'items.purchase_id')
            ->leftJoin('parties', function (JoinClause $join) use ($tenantId): void {
                $join->on('parties.id', '=', 'purchases.supplier_id')->where('parties.tenant_id', $tenantId);
            })
            ->where('purchases.tenant_id', $tenantId)->where('items.product_id', $product->id)
            ->whereNull('purchases.deleted_at')
            ->select('items.id', 'items.unit_cost as price', 'items.quantity', 'parties.name as party', 'purchases.reference_number as invoice', 'purchases.created_at as date')
            ->orderByDesc('purchases.created_at')->orderByDesc('items.id');

        return [
            'product' => $product,
            'customers' => DB::table('parties')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('type', ['customer', 'both'])->orderBy('name')->get(['id', 'name']),
            'customerSales' => $this->priceHistoryCustomerId ? (clone $sales)->where('orders.customer_id', $this->priceHistoryCustomerId)->paginate(10, ['*'], 'priceCustomerPage') : null,
            'sales' => $sales->paginate(10, ['*'], 'priceSalesPage'),
            'costs' => $costs->paginate(10, ['*'], 'priceCostsPage'),
        ];
    }
}
