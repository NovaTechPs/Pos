<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {
    use WithPagination;

    // ============================================================
    // Filters
    // ============================================================

    public string $search = '';
    public string $date_from = '';
    public string $date_to = '';
    public string $payment_status = '';
    public ?int $selectedBranchId = null;

    // ============================================================
    // UI state
    // ============================================================

    public ?Order $selectedOrder = null;
    public bool $showDetailsModal = false;
    public bool $showAdvanced = false;

    // ============================================================
    // Mount
    // ============================================================

    public function mount(): void
    {
        $today = Carbon::today()->format('Y-m-d');

        $this->date_from = $today;
        $this->date_to = $today;

        $user = Auth::user();

        $this->selectedBranchId = $user?->branch_id
            ?? session('active_branch_id')
            ?? null;
    }

    // ============================================================
    // Filter updates
    // ============================================================

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->normalizeDates();
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->normalizeDates();
        $this->resetPage();
    }

    public function updatedPaymentStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedBranchId(): void
    {
        $user = Auth::user();

        if ($user?->branch_id) {
            $this->selectedBranchId = (int) $user->branch_id;
        } elseif ($this->selectedBranchId) {
            $this->validateBranchSelection();
            session(['active_branch_id' => $this->selectedBranchId]);
        }

        $this->resetPage();
    }

    private function normalizeDates(): void
    {
        if ($this->date_from && $this->date_to && $this->date_from > $this->date_to) {
            [$this->date_from, $this->date_to] = [$this->date_to, $this->date_from];
        }
    }

    // ============================================================
    // Quick date ranges
    // ============================================================

    public function setDateRange(string $range): void
    {
        $today = Carbon::today();

        match ($range) {
            'today' => [
                $this->date_from = $today->format('Y-m-d'),
                $this->date_to = $today->format('Y-m-d'),
            ],
            'yesterday' => [
                $this->date_from = $today->copy()->subDay()->format('Y-m-d'),
                $this->date_to = $today->copy()->subDay()->format('Y-m-d'),
            ],
            'week' => [
                $this->date_from = $today->copy()->startOfWeek()->format('Y-m-d'),
                $this->date_to = $today->format('Y-m-d'),
            ],
            'month' => [
                $this->date_from = $today->copy()->startOfMonth()->format('Y-m-d'),
                $this->date_to = $today->format('Y-m-d'),
            ],
            'last_month' => [
                $this->date_from = $today->copy()->subMonth()->startOfMonth()->format('Y-m-d'),
                $this->date_to = $today->copy()->subMonth()->endOfMonth()->format('Y-m-d'),
            ],
            'year' => [
                $this->date_from = $today->copy()->startOfYear()->format('Y-m-d'),
                $this->date_to = $today->format('Y-m-d'),
            ],
            default => null,
        };

        $this->resetPage();
    }

    // ============================================================
    // Reset
    // ============================================================

    public function resetFilters(): void
    {
        $today = Carbon::today()->format('Y-m-d');
        $user = Auth::user();

        $this->search = '';
        $this->date_from = $today;
        $this->date_to = $today;
        $this->payment_status = '';
        $this->selectedBranchId = $user?->branch_id
            ?? session('active_branch_id')
            ?? null;

        $this->resetPage();
    }

    // ============================================================
    // Tenant + branch helpers
    // ============================================================

    private function tenantId(): ?int
    {
        $tenantId = session('active_tenant_id');

        if (!$tenantId) {
            $tenantId = Auth::user()?->tenant_id;
        }

        return $tenantId ? (int) $tenantId : null;
    }

    private function validateBranchSelection(): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId || !$this->selectedBranchId) {
            return;
        }

        $exists = Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($this->selectedBranchId)
            ->exists();

        if (!$exists) {
            $this->selectedBranchId = null;
            session()->forget('active_branch_id');
        }
    }

    private function effectiveBranchId(): ?int
    {
        $user = Auth::user();

        if ($user?->branch_id) {
            return (int) $user->branch_id;
        }

        return $this->selectedBranchId ? (int) $this->selectedBranchId : null;
    }

    // ============================================================
    // Query helpers
    // ============================================================

    private function applyOrderFilters($query, array $types = ['pos'])
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return $query->whereRaw('1 = 0');
        }

        $query
            ->where('orders.tenant_id', $tenantId)
            ->whereIn('orders.type', $types);

        $branchId = $this->effectiveBranchId();

        if ($branchId) {
            $query->where('orders.branch_id', $branchId);
        }

        if ($this->date_from) {
            $query->whereDate('orders.created_at', '>=', $this->date_from);
        }

        if ($this->date_to) {
            $query->whereDate('orders.created_at', '<=', $this->date_to);
        }

        if ($this->payment_status !== '' && $this->payment_status !== 'all') {
            $query->where('orders.payment_status', $this->payment_status);
        }

        $search = trim($this->search);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('orders.invoice_number', 'like', '%' . $search . '%')
                    ->orWhere('orders.customer_name', 'like', '%' . $search . '%')
                    ->orWhere('orders.customer_phone', 'like', '%' . $search . '%');
            });
        }

        return $query;
    }

    private function salesQuery()
    {
        return $this->applyOrderFilters(
            Order::query(),
            ['pos']
        );
    }

    private function returnsQuery()
    {
        return $this->applyOrderFilters(
            Order::query(),
            ['return']
        );
    }

    // ============================================================
    // View order details
    // ============================================================

    public function viewOrderDetails(int $orderId): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return;
        }

        $query = Order::query()
            ->where('tenant_id', $tenantId)
            ->with(['items.product']);

        $branchId = $this->effectiveBranchId();

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $this->selectedOrder = $query->findOrFail($orderId);
        $this->showDetailsModal = true;
    }

    public function closeModal(): void
    {
        $this->showDetailsModal = false;
        $this->selectedOrder = null;
    }

    // ============================================================
    // Export CSV
    // ============================================================

    public function exportCsv()
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return null;
        }

        $query = $this->salesQuery()->orderBy('orders.id');
        $filename = 'pos-report-' . now()->format('Y-m-d-H-i-s') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM for Arabic in Excel.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'رقم الفاتورة',
                'التاريخ',
                'العميل',
                'الهاتف',
                'المجموع الفرعي',
                'الخصم',
                'الضريبة',
                'الإجمالي',
                'المدفوع',
                'المتبقي',
                'حالة الدفع',
                'التكلفة',
                'الربح',
            ]);

            $query->chunkById(500, function ($orders) use ($handle) {
                foreach ($orders as $order) {
                    fputcsv($handle, [
                        $order->invoice_number,
                        $order->created_at?->format('Y-m-d H:i:s'),
                        $order->customer_name ?: 'زبون عام',
                        $order->customer_phone,
                        (float) ($order->subtotal ?? 0),
                        (float) ($order->discount ?? 0),
                        (float) ($order->tax_amount ?? 0),
                        (float) ($order->total ?? 0),
                        (float) ($order->paid_amount ?? 0),
                        max(0, (float) ($order->total ?? 0) - (float) ($order->paid_amount ?? 0)),
                        match ($order->payment_status) {
                            'paid' => 'مدفوع',
                            'partial' => 'مدفوع جزئياً',
                            'unpaid' => 'غير مدفوع',
                            default => $order->payment_status,
                        },
                        (float) ($order->total_cost ?? 0),
                        (float) ($order->total_profit ?? 0),
                    ]);
                }
            }, 'orders.id');

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    // ============================================================
    // Render
    // ============================================================

    public function render()
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();

        if (!$tenantId) {
            return $this->view([
                'orders' => Order::query()->whereRaw('1 = 0')->paginate(20),
                'branches' => collect(),
                'stats' => $this->emptyStats(),
                'dailySales' => collect(),
                'topProducts' => collect(),
                'paymentBreakdown' => collect(),
            ])->layout('layouts::tenant');
        }

        $this->validateBranchSelection();

        // --------------------------------------------------------
        // Branches
        // --------------------------------------------------------

        $branchesQuery = Branch::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name');

        if ($user?->branch_id) {
            $branchesQuery->whereKey($user->branch_id);
        }

        $branches = $branchesQuery->get();

        // --------------------------------------------------------
        // Sales statistics
        // --------------------------------------------------------

        $salesStats = (clone $this->salesQuery())
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(subtotal),0) as subtotal')
            ->selectRaw('COALESCE(SUM(discount),0) as discount')
            ->selectRaw('COALESCE(SUM(tax_amount),0) as tax')
            ->selectRaw('COALESCE(SUM(total),0) as total')
            ->selectRaw('COALESCE(SUM(paid_amount),0) as paid')
            ->selectRaw('COALESCE(SUM(total_cost),0) as total_cost')
            ->selectRaw('COALESCE(SUM(total_profit),0) as total_profit')
            ->first();

        $ordersCount = (int) ($salesStats->orders_count ?? 0);
        $subtotal = (float) ($salesStats->subtotal ?? 0);
        $discount = (float) ($salesStats->discount ?? 0);
        $tax = (float) ($salesStats->tax ?? 0);
        $total = (float) ($salesStats->total ?? 0);
        $paid = (float) ($salesStats->paid ?? 0);
        $totalCost = (float) ($salesStats->total_cost ?? 0);
        $profit = (float) ($salesStats->total_profit ?? 0);
        $receivable = max(0, $total - $paid);
        $avgOrderValue = $ordersCount > 0 ? $total / $ordersCount : 0;
        $profitMargin = $total > 0 ? ($profit / $total) * 100 : 0;

        // --------------------------------------------------------
        // Quantity sold
        // --------------------------------------------------------

        $quantityQuery = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id');

        $this->applyOrderFilters($quantityQuery, ['pos']);

        $totalUnits = (float) $quantityQuery->sum('order_items.quantity');

        // --------------------------------------------------------
        // Returns
        // --------------------------------------------------------

        $returnsStats = (clone $this->returnsQuery())
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(total),0) as total')
            ->selectRaw('COALESCE(SUM(total_profit),0) as total_profit')
            ->selectRaw('COALESCE(SUM(paid_amount),0) as paid')
            ->first();

        $returnsCount = (int) ($returnsStats->orders_count ?? 0);
        $returnsTotal = abs((float) ($returnsStats->total ?? 0));
        $returnsProfit = abs((float) ($returnsStats->total_profit ?? 0));
        $returnsPaid = abs((float) ($returnsStats->paid ?? 0));

        $netSalesAfterReturns = $total - $returnsTotal;
        $netProfitAfterReturns = $profit - $returnsProfit;
        $netCollectedAfterReturns = $paid - $returnsPaid;

        // --------------------------------------------------------
        // Payment breakdown
        // --------------------------------------------------------

        $paymentBreakdown = (clone $this->salesQuery())
            ->select('payment_status')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(total),0) as total')
            ->groupBy('payment_status')
            ->get()
            ->mapWithKeys(function ($row) {
                return [
                    $row->payment_status => [
                        'count' => (int) $row->count,
                        'total' => (float) $row->total,
                    ],
                ];
            });

        // --------------------------------------------------------
        // Daily sales chart
        // --------------------------------------------------------

        $dailySales = (clone $this->salesQuery())
            ->selectRaw('DATE(orders.created_at) as day')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('COALESCE(SUM(orders.total),0) as total')
            ->groupBy(DB::raw('DATE(orders.created_at)'))
            ->orderBy('day')
            ->get();

        $maxDailySales = max(
            1,
            (float) $dailySales->max(fn ($row) => (float) $row->total)
        );

        // --------------------------------------------------------
        // Top products
        // --------------------------------------------------------

        $topProductsQuery = OrderItem::query()
            ->select('order_items.product_id')
            ->select('products.name')
            ->selectRaw('SUM(order_items.quantity) as quantity')
            ->selectRaw('SUM(order_items.total_price) as total_sales')
            ->selectRaw('SUM(order_items.total_cost) as total_cost')
            ->selectRaw('SUM(order_items.total_price - order_items.total_cost) as profit')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id');

        $this->applyOrderFilters($topProductsQuery, ['pos']);

        $topProducts = $topProductsQuery
            ->groupBy('order_items.product_id', 'products.name')
            ->orderByDesc('total_sales')
            ->limit(8)
            ->get();

        // --------------------------------------------------------
        // Main order list
        // --------------------------------------------------------

        $orders = $this->salesQuery()
            ->with(['items.product'])
            ->select('orders.*')
            ->latest('orders.id')
            ->paginate(20);

        $stats = [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax' => $tax,
            'total' => $total,
            'paid' => $paid,
            'receivable' => $receivable,
            'total_cost' => $totalCost,
            'profit' => $profit,
            'profit_margin' => $profitMargin,
            'orders_count' => $ordersCount,
            'avg_order_value' => $avgOrderValue,
            'total_units' => $totalUnits,
            'returns_count' => $returnsCount,
            'returns_total' => $returnsTotal,
            'returns_profit' => $returnsProfit,
            'returns_paid' => $returnsPaid,
            'net_sales_after_returns' => $netSalesAfterReturns,
            'net_profit_after_returns' => $netProfitAfterReturns,
            'net_collected_after_returns' => $netCollectedAfterReturns,
        ];

        return $this->view([
            'orders' => $orders,
            'branches' => $branches,
            'stats' => $stats,
            'dailySales' => $dailySales,
            'maxDailySales' => $maxDailySales,
            'topProducts' => $topProducts,
            'paymentBreakdown' => $paymentBreakdown,
        ])->layout('layouts::tenant');
    }

    private function emptyStats(): array
    {
        return [
            'subtotal' => 0,
            'discount' => 0,
            'tax' => 0,
            'total' => 0,
            'paid' => 0,
            'receivable' => 0,
            'total_cost' => 0,
            'profit' => 0,
            'profit_margin' => 0,
            'orders_count' => 0,
            'avg_order_value' => 0,
            'total_units' => 0,
            'returns_count' => 0,
            'returns_total' => 0,
            'returns_profit' => 0,
            'returns_paid' => 0,
            'net_sales_after_returns' => 0,
            'net_profit_after_returns' => 0,
            'net_collected_after_returns' => 0,
        ];
    }
};
?>

<flux:main class="min-h-screen bg-slate-50 p-4 font-sans sm:p-6" dir="rtl">

    {{-- ============================================================
         Header
    ============================================================= --}}
    <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-2xl font-black tracking-tight text-slate-900">
                    تقارير مبيعات نقاط البيع
                </h1>
                <span class="rounded-full border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-[10px] font-black text-indigo-700">
                    تقرير تشغيلي
                </span>
            </div>
            <p class="mt-1 text-xs font-semibold text-slate-500">
                تحليل المبيعات، المدفوعات، الأرباح، المرتجعات، حركة المنتجات وسجل الفواتير.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button
                type="button"
                wire:click="exportCsv"
                wire:loading.attr="disabled"
                wire:target="exportCsv"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 shadow-sm transition hover:border-emerald-300 hover:bg-emerald-50 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <flux:icon icon="arrow-down-tray" class="h-4 w-4" />
                <span wire:loading.remove wire:target="exportCsv">تصدير Excel / CSV</span>
                <span wire:loading wire:target="exportCsv">جاري التصدير...</span>
            </button>

            <button
                type="button"
                onclick="window.print()"
                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-black text-slate-700 shadow-sm transition hover:bg-slate-100"
            >
                <flux:icon icon="printer" class="h-4 w-4" />
                طباعة التقرير
            </button>

            <button
                type="button"
                wire:click="resetFilters"
                class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-black text-white shadow-sm transition hover:bg-slate-800"
            >
                <flux:icon icon="arrow-path" class="h-4 w-4" />
                إعادة ضبط
            </button>
        </div>
    </div>

    {{-- ============================================================
         Filter panel
    ============================================================= --}}
    <section class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
        <div class="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
            <div>
                <h2 class="text-sm font-black text-slate-900">فلاتر التقرير</h2>
                <p class="mt-0.5 text-[11px] font-semibold text-slate-400">كل المؤشرات والنتائج أسفل هذا القسم تتأثر بالفلاتر المحددة.</p>
            </div>

            <div class="flex flex-wrap gap-2 text-[10px] font-black">
                <button type="button" wire:click="setDateRange('today')" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50">اليوم</button>
                <button type="button" wire:click="setDateRange('yesterday')" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50">أمس</button>
                <button type="button" wire:click="setDateRange('week')" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50">هذا الأسبوع</button>
                <button type="button" wire:click="setDateRange('month')" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50">هذا الشهر</button>
                <button type="button" wire:click="setDateRange('last_month')" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50">الشهر الماضي</button>
                <button type="button" wire:click="setDateRange('year')" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50">هذه السنة</button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
            <div class="xl:col-span-2">
                <label class="mb-1.5 block text-[11px] font-black text-slate-600">بحث</label>
                <div class="relative">
                    <flux:icon icon="magnifying-glass" class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        type="text"
                        wire:model.live.debounce.350ms="search"
                        placeholder="رقم الفاتورة، اسم العميل أو الهاتف..."
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pe-10 ps-3 text-sm font-semibold text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white"
                    >
                </div>
            </div>

            <div>
                <label class="mb-1.5 block text-[11px] font-black text-slate-600">من تاريخ</label>
                <input type="date" wire:model.live="date_from" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-bold text-slate-800 outline-none focus:border-indigo-500 focus:bg-white">
            </div>

            <div>
                <label class="mb-1.5 block text-[11px] font-black text-slate-600">إلى تاريخ</label>
                <input type="date" wire:model.live="date_to" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-bold text-slate-800 outline-none focus:border-indigo-500 focus:bg-white">
            </div>

            <div>
                <label class="mb-1.5 block text-[11px] font-black text-slate-600">حالة الدفع</label>
                <select wire:model.live="payment_status" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-bold text-slate-800 outline-none focus:border-indigo-500 focus:bg-white">
                    <option value="">الكل</option>
                    <option value="paid">مدفوع</option>
                    <option value="partial">مدفوع جزئياً</option>
                    <option value="unpaid">غير مدفوع</option>
                </select>
            </div>
        </div>

        <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
            <div class="md:col-span-1">
                <label class="mb-1.5 block text-[11px] font-black text-slate-600">الفرع</label>
                <select
                    wire:model.live="selectedBranchId"
                    @disabled(Auth::user()?->branch_id)
                    class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-bold text-slate-800 outline-none focus:border-indigo-500 focus:bg-white disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500"
                >
                    @if(!Auth::user()?->branch_id)
                        <option value="">كل الفروع</option>
                    @endif
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
                @if(Auth::user()?->branch_id)
                    <div class="mt-1 text-[10px] font-bold text-slate-400">الفرع مقيد حسب صلاحية المستخدم.</div>
                @endif
            </div>

            <div class="md:col-span-2 flex items-end">
                <button
                    type="button"
                    wire:click="$toggle('showAdvanced')"
                    class="w-full rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-xs font-black text-slate-600 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
                >
                    <span class="inline-flex items-center gap-2">
                        <flux:icon icon="adjustments-horizontal" class="h-4 w-4" />
                        {{ $showAdvanced ? 'إخفاء المعلومات الإضافية' : 'إظهار معلومات التقرير' }}
                    </span>
                </button>
            </div>
        </div>

        @if($showAdvanced)
            <div class="mt-4 grid grid-cols-1 gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3">
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3">
                    <div class="text-[10px] font-black text-slate-400">فترة التقرير</div>
                    <div class="mt-1 text-sm font-black text-slate-800">{{ $date_from }} → {{ $date_to }}</div>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3">
                    <div class="text-[10px] font-black text-slate-400">النطاق</div>
                    <div class="mt-1 text-sm font-black text-slate-800">{{ $selectedBranchId ? 'فرع محدد' : 'كل الفروع المسموحة' }}</div>
                </div>
                <div class="rounded-xl border border-slate-100 bg-slate-50 p-3">
                    <div class="text-[10px] font-black text-slate-400">الفاتورة المعروضة</div>
                    <div class="mt-1 text-sm font-black text-slate-800">{{ number_format($orders->total()) }} فاتورة</div>
                </div>
            </div>
        @endif
    </section>

    {{-- ============================================================
         Primary KPIs
    ============================================================= --}}
    <section class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border border-emerald-200 bg-gradient-to-br from-emerald-50 to-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="text-[11px] font-black text-emerald-700">إجمالي المبيعات</div>
                    <div class="mt-1 text-2xl font-black tracking-tight text-slate-900">{{ number_format($stats['total'], 2) }}</div>
                    <div class="mt-1 text-[10px] font-bold text-slate-400">بعد الخصم والضريبة حسب الفواتير المحفوظة</div>
                </div>
                <div class="rounded-2xl bg-emerald-100 p-3 text-emerald-700">
                    <flux:icon icon="banknotes" class="h-6 w-6" />
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-indigo-200 bg-gradient-to-br from-indigo-50 to-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="text-[11px] font-black text-indigo-700">صافي المبيعات بعد المرتجعات</div>
                    <div class="mt-1 text-2xl font-black tracking-tight text-slate-900">{{ number_format($stats['net_sales_after_returns'], 2) }}</div>
                    <div class="mt-1 text-[10px] font-bold text-slate-400">المبيعات ناقص قيمة المرتجعات</div>
                </div>
                <div class="rounded-2xl bg-indigo-100 p-3 text-indigo-700">
                    <flux:icon icon="chart-bar" class="h-6 w-6" />
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-gradient-to-br from-amber-50 to-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="text-[11px] font-black text-amber-700">الذمم المستحقة</div>
                    <div class="mt-1 text-2xl font-black tracking-tight text-slate-900">{{ number_format($stats['receivable'], 2) }}</div>
                    <div class="mt-1 text-[10px] font-bold text-slate-400">إجمالي الفواتير ناقص المدفوع</div>
                </div>
                <div class="rounded-2xl bg-amber-100 p-3 text-amber-700">
                    <flux:icon icon="clock" class="h-6 w-6" />
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-violet-200 bg-gradient-to-br from-violet-50 to-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <div class="text-[11px] font-black text-violet-700">صافي الربح بعد المرتجعات</div>
                    <div class="mt-1 text-2xl font-black tracking-tight text-slate-900">{{ number_format($stats['net_profit_after_returns'], 2) }}</div>
                    <div class="mt-1 text-[10px] font-bold text-slate-400">الهامش التقريبي: {{ number_format($stats['profit_margin'], 1) }}%</div>
                </div>
                <div class="rounded-2xl bg-violet-100 p-3 text-violet-700">
                    <flux:icon icon="presentation-chart-line" class="h-6 w-6" />
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================
         Secondary KPIs
    ============================================================= --}}
    <section class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4 xl:grid-cols-8">
        @php
            $miniCards = [
                ['label' => 'عدد الفواتير', 'value' => number_format($stats['orders_count']), 'icon' => 'document-text', 'tone' => 'indigo'],
                ['label' => 'متوسط الفاتورة', 'value' => number_format($stats['avg_order_value'], 2), 'icon' => 'calculator', 'tone' => 'slate'],
                ['label' => 'إجمالي الكميات', 'value' => number_format($stats['total_units'], 2), 'icon' => 'cube', 'tone' => 'sky'],
                ['label' => 'إجمالي التكلفة', 'value' => number_format($stats['total_cost'], 2), 'icon' => 'scale', 'tone' => 'rose'],
                ['label' => 'إجمالي الخصم', 'value' => number_format($stats['discount'], 2), 'icon' => 'tag', 'tone' => 'orange'],
                ['label' => 'الضريبة', 'value' => number_format($stats['tax'], 2), 'icon' => 'receipt-percent', 'tone' => 'fuchsia'],
                ['label' => 'المرتجعات', 'value' => number_format($stats['returns_total'], 2), 'icon' => 'arrow-uturn-right', 'tone' => 'red'],
                ['label' => 'عدد المرتجعات', 'value' => number_format($stats['returns_count']), 'icon' => 'arrow-path', 'tone' => 'slate'],
            ];
        @endphp

        @foreach($miniCards as $card)
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="mb-2 flex items-center justify-between">
                    <div class="text-[10px] font-black text-slate-400">{{ $card['label'] }}</div>
                    <flux:icon icon="{{ $card['icon'] }}" class="h-4 w-4 text-slate-400" />
                </div>
                <div class="text-sm font-black text-slate-900">{{ $card['value'] }}</div>
            </div>
        @endforeach
    </section>

    {{-- ============================================================
         Analytics row
    ============================================================= --}}
    <section class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-3">
        {{-- Daily sales --}}
        <div class="xl:col-span-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h3 class="text-sm font-black text-slate-900">حركة المبيعات اليومية</h3>
                    <p class="mt-0.5 text-[10px] font-semibold text-slate-400">إجمالي المبيعات وعدد الفواتير لكل يوم ضمن الفترة المحددة.</p>
                </div>
                <div class="rounded-lg bg-slate-50 px-3 py-1.5 text-[10px] font-black text-slate-500">
                    {{ $dailySales->count() }} يوم
                </div>
            </div>

            @if($dailySales->isNotEmpty())
                <div class="space-y-3">
                    @foreach($dailySales as $day)
                        @php
                            $width = min(100, (($day->total ?? 0) / $maxDailySales) * 100);
                        @endphp
                        <div>
                            <div class="mb-1 flex items-center justify-between gap-3 text-[10px] font-black">
                                <div class="text-slate-500">{{ Carbon::parse($day->day)->format('Y-m-d') }}</div>
                                <div class="text-slate-800">{{ number_format((float) $day->total, 2) }} ر.س <span class="text-slate-400">({{ number_format((int) $day->orders_count) }} فاتورة)</span></div>
                            </div>
                            <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
                                <div class="h-full rounded-full bg-indigo-500 transition-all" style="width: {{ $width }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="flex min-h-44 items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 text-center text-xs font-bold text-slate-400">
                    لا توجد بيانات يومية ضمن الفلاتر الحالية.
                </div>
            @endif
        </div>

        {{-- Payment mix --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="mb-5">
                <h3 class="text-sm font-black text-slate-900">توزيع حالات الدفع</h3>
                <p class="mt-0.5 text-[10px] font-semibold text-slate-400">عدد الفواتير وقيمتها حسب حالة السداد.</p>
            </div>

            @php
                $paymentRows = [
                    'paid' => ['label' => 'مدفوع', 'icon' => 'check-circle'],
                    'partial' => ['label' => 'مدفوع جزئياً', 'icon' => 'clock'],
                    'unpaid' => ['label' => 'غير مدفوع', 'icon' => 'exclamation-circle'],
                ];
            @endphp

            <div class="space-y-3">
                @foreach($paymentRows as $key => $row)
                    @php
                        $data = $paymentBreakdown->get($key, ['count' => 0, 'total' => 0]);
                    @endphp
                    <div class="rounded-xl border border-slate-100 bg-slate-50 p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex items-center gap-2">
                                <div class="rounded-lg bg-white p-2 text-slate-500 shadow-sm">
                                    <flux:icon icon="{{ $row['icon'] }}" class="h-4 w-4" />
                                </div>
                                <div>
                                    <div class="text-[11px] font-black text-slate-700">{{ $row['label'] }}</div>
                                    <div class="text-[10px] font-semibold text-slate-400">{{ number_format($data['count']) }} فاتورة</div>
                                </div>
                            </div>
                            <div class="text-left font-mono text-xs font-black text-slate-900">{{ number_format($data['total'], 2) }}</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ============================================================
         Top products + profit panel
    ============================================================= --}}
    <section class="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-3">
        <div class="xl:col-span-2 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-black text-slate-900">أفضل المنتجات مبيعاً</h3>
                    <p class="mt-0.5 text-[10px] font-semibold text-slate-400">المنتجات الأعلى مساهمة في قيمة مبيعات نقاط البيع.</p>
                </div>
                <flux:icon icon="cube" class="h-5 w-5 text-slate-300" />
            </div>

            @if($topProducts->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full text-right text-xs">
                        <thead class="border-b border-slate-100 bg-slate-50 text-[10px] font-black text-slate-500">
                            <tr>
                                <th class="px-3 py-2.5">#</th>
                                <th class="px-3 py-2.5">المنتج</th>
                                <th class="px-3 py-2.5 text-center">الكمية</th>
                                <th class="px-3 py-2.5">المبيعات</th>
                                <th class="px-3 py-2.5">التكلفة</th>
                                <th class="px-3 py-2.5">الربح</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($topProducts as $index => $product)
                                <tr class="transition hover:bg-slate-50">
                                    <td class="px-3 py-3 font-black text-slate-400">{{ $index + 1 }}</td>
                                    <td class="px-3 py-3">
                                        <div class="max-w-[260px] truncate font-black text-slate-800">{{ $product->name ?: 'منتج محذوف' }}</div>
                                    </td>
                                    <td class="px-3 py-3 text-center font-mono font-black text-slate-700">{{ number_format((float) $product->quantity, 2) }}</td>
                                    <td class="px-3 py-3 font-mono font-black text-indigo-700">{{ number_format((float) $product->total_sales, 2) }}</td>
                                    <td class="px-3 py-3 font-mono font-bold text-slate-500">{{ number_format((float) $product->total_cost, 2) }}</td>
                                    <td class="px-3 py-3 font-mono font-black text-emerald-600">{{ number_format((float) $product->profit, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="flex min-h-40 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 text-xs font-bold text-slate-400">
                    لا توجد حركة منتجات ضمن الفترة المحددة.
                </div>
            @endif
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <h3 class="text-sm font-black text-slate-900">ملخص مالي</h3>
            <p class="mt-0.5 text-[10px] font-semibold text-slate-400">قراءة سريعة للأرقام الأساسية للتقرير.</p>

            <div class="mt-4 space-y-2">
                <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 text-xs">
                    <span class="font-bold text-slate-500">المجموع الفرعي</span>
                    <span class="font-mono font-black text-slate-900">{{ number_format($stats['subtotal'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 text-xs">
                    <span class="font-bold text-slate-500">الخصومات</span>
                    <span class="font-mono font-black text-rose-600">-{{ number_format($stats['discount'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 text-xs">
                    <span class="font-bold text-slate-500">الضريبة</span>
                    <span class="font-mono font-black text-slate-900">{{ number_format($stats['tax'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-indigo-50 px-3 py-3 text-xs">
                    <span class="font-black text-indigo-700">إجمالي المبيعات</span>
                    <span class="font-mono font-black text-indigo-800">{{ number_format($stats['total'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-emerald-50 px-3 py-3 text-xs">
                    <span class="font-black text-emerald-700">المدفوع</span>
                    <span class="font-mono font-black text-emerald-800">{{ number_format($stats['paid'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-amber-50 px-3 py-3 text-xs">
                    <span class="font-black text-amber-700">المتبقي</span>
                    <span class="font-mono font-black text-amber-800">{{ number_format($stats['receivable'], 2) }}</span>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-violet-50 px-3 py-3 text-xs">
                    <span class="font-black text-violet-700">الربح</span>
                    <span class="font-mono font-black text-violet-800">{{ number_format($stats['profit'], 2) }}</span>
                </div>
            </div>
        </div>
    </section>

    {{-- ============================================================
         Invoice list
    ============================================================= --}}
    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div>
                <h3 class="text-sm font-black text-slate-900">سجل فواتير نقاط البيع</h3>
                <p class="mt-0.5 text-[10px] font-semibold text-slate-400">عرض تفصيلي لكل فاتورة ضمن الفلاتر الحالية.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-[10px] font-black">
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-slate-600">{{ number_format($orders->total()) }} فاتورة</span>
                <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-emerald-700">ربح {{ number_format($stats['profit'], 2) }}</span>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-[1100px] w-full text-right text-xs">
                <thead class="border-b border-slate-200 bg-slate-50 text-[10px] font-black text-slate-500">
                    <tr>
                        <th class="px-4 py-3">الفاتورة</th>
                        <th class="px-4 py-3">العميل</th>
                        <th class="px-4 py-3">التاريخ</th>
                        <th class="px-4 py-3 text-center">الأصناف</th>
                        <th class="px-4 py-3">الإجمالي</th>
                        <th class="px-4 py-3">المدفوع</th>
                        <th class="px-4 py-3">المتبقي</th>
                        <th class="px-4 py-3">الربح</th>
                        <th class="px-4 py-3">الدفع</th>
                        <th class="px-4 py-3 text-center">التفاصيل</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($orders as $order)
                        @php
                            $orderItemsQty = $order->items->sum(fn ($item) => (float) ($item->quantity ?? 0));
                            $remaining = max(0, (float) ($order->total ?? 0) - (float) ($order->paid_amount ?? 0));
                        @endphp
                        <tr class="transition hover:bg-slate-50/80">
                            <td class="px-4 py-3">
                                <div class="font-mono font-black text-indigo-700">{{ $order->invoice_number }}</div>
                                <div class="mt-0.5 text-[10px] font-bold text-slate-400">#{{ $order->id }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="max-w-[180px] truncate font-black text-slate-800">{{ $order->customer_name ?: 'زبون عام' }}</div>
                                @if($order->customer_phone)
                                    <div class="mt-0.5 font-mono text-[10px] text-slate-400">{{ $order->customer_phone }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-[10px] font-bold text-slate-500">
                                {{ $order->created_at?->format('Y-m-d') }}
                                <div class="mt-0.5 text-slate-400">{{ $order->created_at?->format('h:i A') }}</div>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <span class="rounded-lg bg-slate-100 px-2.5 py-1.5 font-mono font-black text-slate-700">{{ number_format($orderItemsQty, 2) }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono font-black text-slate-900">{{ number_format((float) $order->total, 2) }} ر.س</td>
                            <td class="px-4 py-3 font-mono font-black text-emerald-600">{{ number_format((float) ($order->paid_amount ?? 0), 2) }}</td>
                            <td class="px-4 py-3 font-mono font-black {{ $remaining > 0 ? 'text-amber-600' : 'text-slate-400' }}">{{ number_format($remaining, 2) }}</td>
                            <td class="px-4 py-3 font-mono font-black text-violet-600">{{ number_format((float) ($order->total_profit ?? 0), 2) }}</td>
                            <td class="px-4 py-3">
                                @switch($order->payment_status)
                                    @case('paid')
                                        <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-[10px] font-black text-emerald-700">مدفوع</span>
                                        @break
                                    @case('partial')
                                        <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2.5 py-1 text-[10px] font-black text-amber-700">جزئي</span>
                                        @break
                                    @case('unpaid')
                                        <span class="inline-flex rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[10px] font-black text-rose-700">غير مدفوع</span>
                                        @break
                                    @default
                                        <span class="inline-flex rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-black text-slate-600">غير محدد</span>
                                @endswitch
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button
                                    type="button"
                                    wire:click="viewOrderDetails({{ $order->id }})"
                                    class="inline-flex rounded-xl border border-slate-200 bg-white p-2 text-slate-500 shadow-sm transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700"
                                    title="عرض التفاصيل"
                                >
                                    <flux:icon icon="eye" class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-16 text-center">
                                <div class="mx-auto flex max-w-sm flex-col items-center">
                                    <div class="rounded-2xl bg-slate-100 p-4 text-slate-300">
                                        <flux:icon icon="document-magnifying-glass" class="h-10 w-10" />
                                    </div>
                                    <div class="mt-3 text-sm font-black text-slate-600">لا توجد نتائج</div>
                                    <div class="mt-1 text-[11px] font-semibold text-slate-400">جرّب تغيير التاريخ أو البحث أو حالة الدفع.</div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($orders->hasPages())
            <div class="border-t border-slate-100 p-4">
                {{ $orders->links() }}
            </div>
        @endif
    </section>

    {{-- ============================================================
         Details modal
    ============================================================= --}}
    @if($showDetailsModal && $selectedOrder)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm"
            wire:click.self="closeModal"
        >
            <div class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-4 border-b border-slate-100 bg-slate-50 p-4 sm:p-5">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-black text-slate-900">تفاصيل الفاتورة</h3>
                            <span class="rounded-full bg-indigo-50 px-2.5 py-1 font-mono text-[10px] font-black text-indigo-700">{{ $selectedOrder->invoice_number }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-3 text-[10px] font-semibold text-slate-400">
                            <span>{{ $selectedOrder->created_at?->format('Y-m-d h:i A') }}</span>
                            <span>المعرف #{{ $selectedOrder->id }}</span>
                            <span>النوع: {{ $selectedOrder->type }}</span>
                        </div>
                    </div>

                    <button type="button" wire:click="closeModal" class="rounded-xl p-2 text-slate-400 transition hover:bg-slate-200 hover:text-slate-700">
                        <flux:icon icon="x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="overflow-y-auto p-4 sm:p-5">
                    <div class="mb-5 grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] font-black text-slate-400">العميل</div>
                            <div class="mt-1 font-black text-slate-800">{{ $selectedOrder->customer_name ?: 'زبون عام' }}</div>
                            @if($selectedOrder->customer_phone)
                                <div class="mt-0.5 font-mono text-[10px] text-slate-400">{{ $selectedOrder->customer_phone }}</div>
                            @endif
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] font-black text-slate-400">المجموع الفرعي</div>
                            <div class="mt-1 font-mono text-lg font-black text-slate-900">{{ number_format((float) ($selectedOrder->subtotal ?? 0), 2) }}</div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                            <div class="text-[10px] font-black text-slate-400">المدفوع</div>
                            <div class="mt-1 font-mono text-lg font-black text-emerald-600">{{ number_format((float) ($selectedOrder->paid_amount ?? 0), 2) }}</div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-indigo-50 p-3">
                            <div class="text-[10px] font-black text-indigo-500">الإجمالي النهائي</div>
                            <div class="mt-1 font-mono text-lg font-black text-indigo-800">{{ number_format((float) ($selectedOrder->total ?? 0), 2) }}</div>
                        </div>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="min-w-[900px] w-full text-right text-xs">
                            <thead class="bg-slate-900 text-[10px] font-black text-white">
                                <tr>
                                    <th class="px-3 py-3 text-center">#</th>
                                    <th class="px-3 py-3">المنتج</th>
                                    <th class="px-3 py-3 text-center">الكمية</th>
                                    <th class="px-3 py-3">سعر الوحدة</th>
                                    <th class="px-3 py-3">الخصم</th>
                                    <th class="px-3 py-3">التكلفة</th>
                                    <th class="px-3 py-3">الإجمالي</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($selectedOrder->items as $index => $item)
                                    <tr>
                                        <td class="px-3 py-3 text-center font-black text-slate-400">{{ $index + 1 }}</td>
                                        <td class="px-3 py-3 font-black text-slate-800">{{ $item->product?->name ?? 'منتج محذوف' }}</td>
                                        <td class="px-3 py-3 text-center font-mono font-black text-slate-700">{{ number_format((float) ($item->quantity ?? 0), 2) }}</td>
                                        <td class="px-3 py-3 font-mono font-bold text-slate-700">{{ number_format((float) ($item->unit_price ?? 0), 2) }}</td>
                                        <td class="px-3 py-3 font-mono font-bold text-rose-600">{{ number_format((float) ($item->discount ?? 0), 2) }}</td>
                                        <td class="px-3 py-3 font-mono font-bold text-slate-500">{{ number_format((float) ($item->total_cost ?? 0), 2) }}</td>
                                        <td class="px-3 py-3 font-mono font-black text-emerald-600">{{ number_format((float) ($item->total_price ?? 0), 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 text-xs">
                                <span class="font-bold text-slate-500">الخصم</span>
                                <span class="font-mono font-black text-rose-600">{{ number_format((float) ($selectedOrder->discount ?? 0), 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 text-xs">
                                <span class="font-bold text-slate-500">الضريبة</span>
                                <span class="font-mono font-black text-slate-800">{{ number_format((float) ($selectedOrder->tax_amount ?? 0), 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5 text-xs">
                                <span class="font-bold text-slate-500">إجمالي التكلفة</span>
                                <span class="font-mono font-black text-slate-800">{{ number_format((float) ($selectedOrder->total_cost ?? 0), 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between rounded-xl bg-emerald-50 px-3 py-3 text-xs">
                                <span class="font-black text-emerald-700">الربح</span>
                                <span class="font-mono text-sm font-black text-emerald-800">{{ number_format((float) ($selectedOrder->total_profit ?? 0), 2) }}</span>
                            </div>
                        </div>

                        <div class="rounded-xl border border-slate-200 bg-white p-4">
                            <div class="mb-2 text-[10px] font-black text-slate-400">ملاحظات الفاتورة</div>
                            <div class="min-h-20 whitespace-pre-line text-xs font-semibold leading-6 text-slate-600">
                                {{ $selectedOrder->notes ?: 'لا توجد ملاحظات مسجلة.' }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2 border-t border-slate-100 bg-slate-50 p-4">
                    <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-black text-white transition hover:bg-slate-800">
                        <flux:icon icon="printer" class="h-4 w-4" />
                        طباعة
                    </button>
                    <button type="button" wire:click="closeModal" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-black text-slate-700 transition hover:bg-slate-100">
                        إغلاق
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ============================================================
         Print styles
    ============================================================= --}}
    <style>
        @media print {
            body {
                background: white !important;
            }

            body * {
                visibility: hidden !important;
            }

            flux\:main,
            flux\:main * {
                visibility: visible !important;
            }

            flux\:main {
                position: absolute !important;
                inset: 0 !important;
                width: 100% !important;
                padding: 0 !important;
                background: white !important;
            }

            button,
            input,
            select,
            textarea {
                display: none !important;
            }

            .fixed {
                position: static !important;
            }

            .shadow-sm,
            .shadow-xl,
            .shadow-2xl {
                box-shadow: none !important;
            }

            @page {
                size: A4 landscape;
                margin: 10mm;
            }
        }
    </style>
</flux:main>
