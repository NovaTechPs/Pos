<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $statusFilter = '';
    public string $paymentStatusFilter = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public ?int $branchFilter = null;
    public ?Order $selectedOrder = null;
    public ?array $selectedCustomerBalance = null;
    public bool $showDetailModal = false;

    public function mount(): void
    {
        $this->branchFilter = $this->getUserBranchId();
    }

    private function getTenantId(): ?int
    {
        return auth()->user()?->tenant_id ? (int) auth()->user()->tenant_id : null;
    }

    private function getUserBranchId(): ?int
    {
        return auth()->user()?->branch_id ? (int) auth()->user()->branch_id : null;
    }

    private function canUseBranch(?int $branchId): bool
    {
        $userBranchId = $this->getUserBranchId();

        if ($userBranchId) {
            return $branchId === null || $branchId === $userBranchId;
        }

        if (!$branchId) {
            return true;
        }

        return Branch::query()
            ->where('tenant_id', $this->getTenantId())
            ->whereKey($branchId)
            ->exists();
    }

    private function wholesaleQuery()
    {
        $tenantId = $this->getTenantId();

        return Order::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['wholesale', 'wholesale_van', 'van']);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedBranchFilter(): void
    {
        if (!$this->canUseBranch($this->branchFilter)) {
            $this->branchFilter = $this->getUserBranchId();
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = '';
        $this->paymentStatusFilter = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->branchFilter = $this->getUserBranchId();
        $this->resetPage();
    }

    public function viewOrder(int $orderId): void
    {
        $order = $this->wholesaleQuery()
            ->with([
                'items.product',
                'party',
                'branch',
                'user',
            ])
            ->findOrFail($orderId);

        $this->selectedOrder = $order;
        $this->selectedCustomerBalance = $this->buildCustomerBalance($order->customer_id);
        $this->showDetailModal = true;
    }

    private function buildCustomerBalance(?int $customerId): ?array
    {
        if (!$customerId) {
            return null;
        }

        $tenantId = $this->getTenantId();

        $customer = Party::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($customerId)
            ->first();

        if (!$customer) {
            return null;
        }

        $openingBalance = (float) ($customer->opening_balance ?? 0);
        $sales = (float) Order::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->where('status', 'completed')
            ->sum('total');

        $receipts = (float) DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('payable_type', Party::class)
            ->where('payable_id', $customer->id)
            ->where('type', 'receipt')
            ->whereNull('deleted_at')
            ->sum('amount');

        $otherPayments = (float) DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('payable_type', Party::class)
            ->where('payable_id', $customer->id)
            ->where('type', 'payment')
            ->whereNull('deleted_at')
            ->sum('amount');

        $balance = $openingBalance + $sales + $otherPayments - $receipts;

        return [
            'opening' => $openingBalance,
            'sales' => $sales,
            'receipts' => $receipts,
            'other_payments' => $otherPayments,
            'balance' => $balance,
        ];
    }

    public function closeModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedOrder = null;
        $this->selectedCustomerBalance = null;
    }

    public function printOrder(int $orderId): void
    {
        $order = $this->wholesaleQuery()
            ->with(['items.product', 'branch', 'party'])
            ->findOrFail($orderId);

        $items = $order->items->map(fn ($item) => [
            'name' => $item->product?->name ?? 'منتج غير محدد',
            'quantity' => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'total' => (float) $item->total_price,
        ])->values()->all();

        $this->dispatch('print-wholesale-invoice', data: [
            'title' => 'فاتورة مبيعات جملة',
            'invoice_number' => $order->invoice_number,
            'customer_name' => $order->customer_name ?: 'زبون جملة عابر',
            'customer_phone' => $order->customer_phone ?: '',
            'branch_name' => $order->branch?->name ?? '',
            'created_at' => optional($order->created_at)->format('Y-m-d H:i'),
            'items' => $items,
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'total' => (float) $order->total,
            'paid' => (float) $order->paid_amount,
            'remaining' => max(0, (float) $order->total - (float) $order->paid_amount),
            'notes' => $order->notes ?: '',
        ]);
    }

    public function render()
    {
        $tenantId = $this->getTenantId();
        $userBranchId = $this->getUserBranchId();

        $ordersQuery = $this->wholesaleQuery()
            ->with(['branch', 'party'])
            ->when($userBranchId, fn ($query) => $query->where('branch_id', $userBranchId))
            ->when(!$userBranchId && $this->branchFilter, fn ($query) => $query->where('branch_id', $this->branchFilter))
            ->when(trim($this->search) !== '', function ($query) {
                $term = trim($this->search);
                $query->where(function ($q) use ($term) {
                    $q->where('invoice_number', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%")
                        ->orWhere('customer_phone', 'like', "%{$term}%");
                });
            })
            ->when($this->statusFilter !== '', fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->paymentStatusFilter !== '', fn ($query) => $query->where('payment_status', $this->paymentStatusFilter))
            ->when($this->dateFrom !== '', fn ($query) => $query->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($query) => $query->whereDate('created_at', '<=', $this->dateTo))
            ->latest('id');

        $orders = $ordersQuery->paginate(15);

        $branches = !$userBranchId && $tenantId
            ? Branch::query()->where('tenant_id', $tenantId)->orderBy('name')->get()
            : collect();

        $summaryQuery = clone $ordersQuery;
        $summary = [
            'count' => (clone $summaryQuery)->toBase()->getCountForPagination(),
            'total' => (float) (clone $summaryQuery)->sum('total'),
            'paid' => (float) (clone $summaryQuery)->sum('paid_amount'),
        ];
        $summary['remaining'] = max(0, $summary['total'] - $summary['paid']);

        return $this->view([
            'orders' => $orders,
            'branches' => $branches,
            'summary' => $summary,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">
    <div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-6" dir="rtl">

        <div class="flex flex-col lg:flex-row justify-between gap-4 pb-6 border-b border-zinc-200 dark:border-zinc-800">
            <div>
                <flux:heading size="xl">فواتير مبيعات الجملة</flux:heading>
                <flux:subheading>عرض ومراجعة ومتابعة فواتير الجملة الصادرة من الفروع.</flux:subheading>
            </div>

        </div>

        @if (session()->has('message'))
            <flux:badge variant="success" class="w-full justify-start p-3 text-sm">
                {{ session('message') }}
            </flux:badge>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <flux:card>
                <div class="text-xs text-zinc-500">عدد الفواتير</div>
                <div class="mt-1 text-2xl font-bold">{{ number_format($summary['count']) }}</div>
            </flux:card>
            <flux:card>
                <div class="text-xs text-zinc-500">إجمالي المبيعات</div>
                <div class="mt-1 text-2xl font-bold text-emerald-600">{{ number_format($summary['total'], 2) }}</div>
            </flux:card>
            <flux:card>
                <div class="text-xs text-zinc-500">المتبقي</div>
                <div class="mt-1 text-2xl font-bold text-amber-600">{{ number_format($summary['remaining'], 2) }}</div>
            </flux:card>
        </div>

        <flux:card class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
                <div class="xl:col-span-2">
                    <flux:input wire:model.live.debounce.300ms="search" placeholder="رقم الفاتورة، العميل أو الهاتف..." icon="magnifying-glass" />
                </div>

                <flux:select wire:model.live="statusFilter">
                    <flux:select.option value="">كل الحالات</flux:select.option>
                    <flux:select.option value="completed">مكتملة</flux:select.option>
                    <flux:select.option value="pending">قيد الانتظار</flux:select.option>
                    <flux:select.option value="processing">قيد المعالجة</flux:select.option>
                    <flux:select.option value="cancelled">ملغاة</flux:select.option>
                </flux:select>

                <flux:select wire:model.live="paymentStatusFilter">
                    <flux:select.option value="">كل الدفعات</flux:select.option>
                    <flux:select.option value="paid">مدفوعة</flux:select.option>
                    <flux:select.option value="partial">جزئي</flux:select.option>
                    <flux:select.option value="unpaid">آجل</flux:select.option>
                </flux:select>

                @if ($branches->isNotEmpty())
                    <flux:select wire:model.live="branchFilter">
                        <flux:select.option value="">كل الفروع</flux:select.option>
                        @foreach ($branches as $branch)
                            <flux:select.option value="{{ $branch->id }}">{{ $branch->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @else
                    <div></div>
                @endif

                <flux:button wire:click="clearFilters" variant="subtle" icon="arrow-path">
                    مسح الفلاتر
                </flux:button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:max-w-xl">
                <flux:input type="date" wire:model.live="dateFrom" label="من تاريخ" />
                <flux:input type="date" wire:model.live="dateTo" label="إلى تاريخ" />
            </div>
        </flux:card>

        <flux:card class="p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-right border-collapse">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-600 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-800">
                        <tr>
                            <th class="p-3">الفاتورة</th>
                            <th class="p-3">العميل</th>
                            <th class="p-3">الفرع</th>
                            <th class="p-3">الإجمالي</th>
                            <th class="p-3">المدفوع</th>
                            <th class="p-3">المتبقي</th>
                            <th class="p-3">الحالة</th>
                            <th class="p-3">التاريخ</th>
                            <th class="p-3 text-center">الإجراء</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse ($orders as $order)
                            @php($remaining = max(0, (float) $order->total - (float) $order->paid_amount))
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition">
                                <td class="p-3">
                                    <div class="font-mono font-bold text-indigo-600 dark:text-indigo-400">{{ $order->invoice_number }}</div>
                                    <div class="text-xs text-zinc-500">#{{ $order->id }}</div>
                                </td>
                                <td class="p-3">
                                    <div class="font-medium">{{ $order->customer_name ?: 'زبون جملة عابر' }}</div>
                                    @if ($order->customer_phone)
                                        <div class="text-xs text-zinc-500" dir="ltr">{{ $order->customer_phone }}</div>
                                    @endif
                                </td>
                                <td class="p-3 text-zinc-600 dark:text-zinc-300">{{ $order->branch?->name ?? '-' }}</td>
                                <td class="p-3 font-bold">{{ number_format((float) $order->total, 2) }}</td>
                                <td class="p-3 text-emerald-600">{{ number_format((float) $order->paid_amount, 2) }}</td>
                                <td class="p-3 {{ $remaining > 0 ? 'text-amber-600 font-bold' : 'text-zinc-500' }}">{{ number_format($remaining, 2) }}</td>
                                <td class="p-3">
                                    @if ($order->payment_status === 'paid')
                                        <flux:badge variant="success">مدفوع</flux:badge>
                                    @elseif ($order->payment_status === 'partial')
                                        <flux:badge variant="warning">جزئي</flux:badge>
                                    @else
                                        <flux:badge variant="danger">آجل</flux:badge>
                                    @endif
                                </td>
                                <td class="p-3 text-xs text-zinc-500 whitespace-nowrap">{{ $order->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="p-3 text-center">
                                    <flux:button size="xs" variant="subtle" icon="eye" wire:click="viewOrder({{ $order->id }})">
                                        التفاصيل
                                    </flux:button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="text-center py-16 text-zinc-500">
                                    لا توجد فواتير جملة مطابقة للفلاتر الحالية.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($orders->hasPages())
                <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                    {{ $orders->links() }}
                </div>
            @endif
        </flux:card>

        <flux:modal wire:model="showDetailModal" name="wholesale-invoice-details" class="md:w-3/4 max-w-5xl space-y-6">
            @if ($selectedOrder)
                <div class="flex flex-col sm:flex-row justify-between gap-4 border-b border-zinc-200 dark:border-zinc-800 pb-4">
                    <div>
                        <flux:heading size="lg">فاتورة مبيعات جملة</flux:heading>
                        <flux:subheading>{{ $selectedOrder->invoice_number }} · {{ $selectedOrder->created_at?->format('Y-m-d H:i') }}</flux:subheading>
                    </div>
                    <div class="flex gap-2">
                        <flux:button size="sm" variant="subtle" icon="printer" wire:click="printOrder({{ $selectedOrder->id }})">
                            طباعة
                        </flux:button>
                        <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="closeModal" />
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <flux:card class="md:col-span-2">
                        <div class="text-xs text-zinc-500">العميل</div>
                        <div class="font-bold text-lg">{{ $selectedOrder->customer_name ?: 'زبون جملة عابر' }}</div>
                        <div class="text-sm text-zinc-500" dir="ltr">{{ $selectedOrder->customer_phone ?: 'لا يوجد هاتف' }}</div>
                    </flux:card>
                    <flux:card>
                        <div class="text-xs text-zinc-500">الفرع</div>
                        <div class="font-bold">{{ $selectedOrder->branch?->name ?? '-' }}</div>
                        <div class="text-xs text-zinc-500 mt-1">أنشأها: {{ $selectedOrder->user?->name ?? '-' }}</div>
                    </flux:card>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-800 rounded-lg overflow-hidden">
                    <div class="px-4 py-3 bg-zinc-50 dark:bg-zinc-800/50 font-bold">الأصناف</div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-right">
                            <thead class="bg-zinc-100 dark:bg-zinc-800">
                                <tr>
                                    <th class="p-3">المنتج</th>
                                    <th class="p-3">السعر</th>
                                    <th class="p-3">الكمية</th>
                                    <th class="p-3">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach ($selectedOrder->items as $item)
                                    <tr>
                                        <td class="p-3 font-medium">{{ $item->product?->name ?? 'منتج غير محدد' }}</td>
                                        <td class="p-3">{{ number_format((float) $item->unit_price, 2) }}</td>
                                        <td class="p-3 font-bold">{{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}</td>
                                        <td class="p-3 font-bold">{{ number_format((float) $item->total_price, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <flux:card class="space-y-3">
                        <div class="flex justify-between"><span>المجموع الفرعي</span><strong>{{ number_format((float) $selectedOrder->subtotal, 2) }}</strong></div>
                        <div class="flex justify-between"><span>الخصم</span><strong>{{ number_format((float) $selectedOrder->discount, 2) }}</strong></div>
                        <div class="flex justify-between border-t pt-3 border-zinc-200 dark:border-zinc-700"><span class="font-bold">الإجمالي</span><strong class="text-xl text-emerald-600">{{ number_format((float) $selectedOrder->total, 2) }}</strong></div>
                        <div class="flex justify-between"><span>المدفوع</span><strong>{{ number_format((float) $selectedOrder->paid_amount, 2) }}</strong></div>
                        <div class="flex justify-between"><span>المتبقي</span><strong class="text-amber-600">{{ number_format(max(0, (float) $selectedOrder->total - (float) $selectedOrder->paid_amount), 2) }}</strong></div>
                    </flux:card>

                    <flux:card>
                        <div class="text-xs text-zinc-500 mb-2">حالة الفاتورة</div>
                        <div class="flex gap-2 flex-wrap">
                            @if ($selectedOrder->status === 'completed')
                                <flux:badge variant="success">مكتملة</flux:badge>
                            @elseif ($selectedOrder->status === 'cancelled')
                                <flux:badge variant="danger">ملغاة</flux:badge>
                            @elseif ($selectedOrder->status === 'processing')
                                <flux:badge variant="info">قيد المعالجة</flux:badge>
                            @else
                                <flux:badge variant="warning">قيد الانتظار</flux:badge>
                            @endif
                            @if ($selectedOrder->payment_status === 'paid')
                                <flux:badge variant="success">الدفع مكتمل</flux:badge>
                            @elseif ($selectedOrder->payment_status === 'partial')
                                <flux:badge variant="warning">دفع جزئي</flux:badge>
                            @else
                                <flux:badge variant="danger">آجل</flux:badge>
                            @endif
                        </div>
                        @if ($selectedOrder->notes)
                            <div class="mt-5 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                                <div class="text-xs text-zinc-500 mb-1">ملاحظات</div>
                                <div class="text-sm whitespace-pre-line">{{ $selectedOrder->notes }}</div>
                            </div>
                        @endif
                    </flux:card>
                </div>

                @if ($selectedCustomerBalance)
                    <flux:card>
                        <div class="font-bold mb-3">رصيد العميل</div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                            <div><div class="text-xs text-zinc-500">الرصيد الافتتاحي</div><strong>{{ number_format($selectedCustomerBalance['opening'], 2) }}</strong></div>
                            <div><div class="text-xs text-zinc-500">المبيعات</div><strong>{{ number_format($selectedCustomerBalance['sales'], 2) }}</strong></div>
                            <div><div class="text-xs text-zinc-500">المقبوضات</div><strong class="text-emerald-600">{{ number_format($selectedCustomerBalance['receipts'], 2) }}</strong></div>
                            <div><div class="text-xs text-zinc-500">الرصيد الحالي</div><strong class="text-amber-600">{{ number_format($selectedCustomerBalance['balance'], 2) }}</strong></div>
                        </div>
                    </flux:card>
                @endif
            @endif
        </flux:modal>
    </div>
</flux:main>

@script
<script>
    $wire.on('print-wholesale-invoice', ({ data }) => {
        const popup = window.open('', '_blank', 'width=420,height=700');
        if (!popup) {
            return;
        }

        const esc = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const rows = (data.items || []).map(item => `
            <tr>
                <td>${esc(item.name)}</td>
                <td>${esc(item.quantity)}</td>
                <td>${Number(item.unit_price).toFixed(2)}</td>
                <td>${Number(item.total).toFixed(2)}</td>
            </tr>
        `).join('');

        popup.document.write(`<!doctype html>
<html dir="rtl"><head><meta charset="utf-8"><title>${esc(data.invoice_number)}</title>
<style>
body{font-family:Arial,sans-serif;width:80mm;margin:0 auto;padding:10px;font-size:12px;color:#000}
h1{font-size:18px;text-align:center;margin:0 0 6px}.center{text-align:center}.line{border-top:1px dashed #000;margin:8px 0}
table{width:100%;border-collapse:collapse}th,td{padding:4px 2px;border-bottom:1px solid #ddd;text-align:right}.totals div{display:flex;justify-content:space-between;padding:3px 0}.grand{font-size:15px;font-weight:bold}.note{white-space:pre-line;margin-top:8px}
@media print{body{width:auto}.no-print{display:none}}
</style></head><body>
<h1>${esc(data.title)}</h1>
<div class="center">${esc(data.invoice_number)}</div>
<div class="center">${esc(data.created_at)}</div>
<div class="line"></div>
<div>العميل: ${esc(data.customer_name)}</div>
<div>${esc(data.customer_phone)}</div>
<div>الفرع: ${esc(data.branch_name)}</div>
<div class="line"></div>
<table><thead><tr><th>الصنف</th><th>ك</th><th>السعر</th><th>الإجمالي</th></tr></thead><tbody>${rows}</tbody></table>
<div class="line"></div>
<div class="totals">
<div><span>المجموع</span><span>${Number(data.subtotal).toFixed(2)}</span></div>
<div><span>الخصم</span><span>${Number(data.discount).toFixed(2)}</span></div>
<div class="grand"><span>الإجمالي</span><span>${Number(data.total).toFixed(2)}</span></div>
<div><span>المدفوع</span><span>${Number(data.paid).toFixed(2)}</span></div>
<div><span>المتبقي</span><span>${Number(data.remaining).toFixed(2)}</span></div>
</div>
${data.notes ? `<div class="line"></div><div class="note">ملاحظات: ${esc(data.notes)}</div>` : ''}
<div class="line"></div><div class="center">شكراً لتعاملكم معنا</div>
<script>window.onload=()=>{window.print();window.onafterprint=()=>window.close()}<\/script>
</body></html>`);
        popup.document.close();
    });
</script>
@endscript
