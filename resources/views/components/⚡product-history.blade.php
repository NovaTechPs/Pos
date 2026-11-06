<?php

use App\Models\Product;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Locked]
    public ?int $productId = null;

    #[Locked]
    public string $productName = '';

    public bool $showHistory = false;

    #[On('show-product-history')]
    public function openHistory(int $productId): void
    {
        $product = Product::query()->where('tenant_id', $this->tenantId())->findOrFail($productId);
        abort_unless(auth()->user()->can('pos.view') || auth()->user()->can('sales_invoices.view') || auth()->user()->can('purchases.view'), 403);
        $this->productId = $product->id;
        $this->productName = $product->name;
        $this->showHistory = true;
        $this->resetPage('productHistoryPage');
    }

    private function tenantId(): int
    {
        $user = auth()->user();

        return (int) ($user->isSaaSAdmin() ? session('active_tenant_id') : $user->tenant_id);
    }

    public function render(): mixed
    {
        $rows = null;
        if ($this->showHistory && $this->productId) {
            $tenantId = $this->tenantId();
            $branchId = auth()->user()->branch_id ?: session('active_branch_id');
            if ($branchId) {
                abort_unless(Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->exists(), 404);
            }
            $sales = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->leftJoin('parties as customers', fn ($join) => $join->on('customers.id', '=', 'orders.customer_id')->where('customers.tenant_id', $tenantId))
                ->where('orders.tenant_id', $tenantId)->where('order_items.tenant_id', $tenantId)
                ->whereNull('orders.deleted_at')->where('orders.status', 'completed')
                ->whereIn('orders.type', ['pos', 'wholesale', 'return'])
                ->where('order_items.product_id', $this->productId)
                ->when($branchId, fn ($query) => $query->where('orders.branch_id', $branchId))
                ->where(fn ($query) => $query
                    ->whereIn('orders.type', auth()->user()->can('pos.view') ? ['pos', 'return'] : [])
                    ->orWhereIn('orders.type', auth()->user()->can('sales_invoices.view') ? ['wholesale'] : []))
                ->selectRaw("orders.id as invoice_id, orders.invoice_number as reference, orders.created_at as event_date, COALESCE(NULLIF(orders.customer_name, ''), customers.name) as party_name, order_items.quantity, order_items.unit_price as price, orders.type as kind, order_items.returned_to_stock as returned_to_stock");
            $purchases = DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                ->leftJoin('parties', fn ($join) => $join->on('parties.id', '=', 'purchases.supplier_id')->where('parties.tenant_id', $tenantId))
                ->where('purchases.tenant_id', $tenantId)->whereNull('purchases.deleted_at')
                ->where('purchase_items.product_id', $this->productId)
                ->when($branchId, fn ($query) => $query->where('purchases.branch_id', $branchId))
                ->when(! auth()->user()->can('purchases.view'), fn ($query) => $query->whereRaw('1 = 0'))
                ->selectRaw("purchases.id as invoice_id, purchases.reference_number as reference, purchases.created_at as event_date, parties.name as party_name, purchase_items.quantity, purchase_items.unit_cost as price, 'purchase' as kind, NULL as returned_to_stock");
            $rows = DB::query()->fromSub($sales->unionAll($purchases), 'history')
                ->orderByDesc('event_date')->orderByDesc('invoice_id')->paginate(20, ['*'], 'productHistoryPage');
        }

        return $this->view(compact('rows'));
    }
};
?>

<div>
    @if ($showHistory)
        <div class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-900/60 p-4" dir="rtl" role="dialog" aria-modal="true" aria-label="حركة الصنف" x-data x-on:keydown.escape.window="$wire.set('showHistory', false)">
            <section class="max-h-[85dvh] w-full max-w-4xl overflow-auto rounded-2xl bg-white p-4 text-slate-900 shadow-xl dark:bg-slate-900 dark:text-white">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <h2 class="font-bold">حركة الصنف — {{ $productName }}</h2>
                    <button type="button" wire:click="$set('showHistory', false)" class="rounded-lg border px-3 py-2">إغلاق</button>
                </div>
                <table class="w-full text-right text-sm">
                    <thead><tr><th class="p-2">التاريخ</th><th class="p-2">النوع</th><th class="p-2">الزبون / المورد</th><th class="p-2">الكمية</th><th class="p-2">السعر</th><th class="p-2">الفاتورة</th></tr></thead>
                    <tbody>
                        @forelse ($rows as $row)
                            <tr wire:key="history-{{ $row->kind }}-{{ $loop->index }}" class="border-t border-slate-200">
                                <td class="p-2">{{ $row->event_date }}</td>
                                <td class="p-2">{{ ['pos' => 'بيع', 'wholesale' => 'بيع', 'return' => $row->returned_to_stock ? 'مرتجع سليم' : 'مرتجع تالف', 'purchase' => 'شراء'][$row->kind] }}</td>
                                <td class="p-2">{{ $row->party_name ?: ($row->kind === 'purchase' ? 'بدون مورد' : 'زبون عام') }}</td>
                                <td class="p-2">{{ number_format($row->quantity, 2) }}</td><td class="p-2">{{ number_format($row->price, 2) }}</td>
                                <td class="p-2"><a class="font-bold text-indigo-600" href="{{ route($row->kind === 'purchase' ? 'tenant.purchase-invoices' : ($row->kind === 'wholesale' ? 'tenant.sale-invoices' : 'tenant.pos'), ['invoice' => $row->invoice_id]) }}" target="_blank" rel="noopener">{{ $row->reference }} ↗</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="p-6 text-center">لا توجد حركة مسجلة لهذا الصنف.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="mt-4">{{ $rows->links() }}</div>
            </section>
        </div>
    @endif
</div>
