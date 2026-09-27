<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public string $search = '';

    public string $statusFilter = '';

    public string $paymentStatusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public ?int $branchFilter = null;

    /*
    |--------------------------------------------------------------------------
    | Details
    |--------------------------------------------------------------------------
    */

    public ?Order $selectedOrder = null;

    public ?array $selectedCustomerBalance = null;

    public bool $showDetailModal = false;

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    */

    public int $perPage = 15;

    /*
    |--------------------------------------------------------------------------
    | Mount
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $this->branchFilter = $this->getUserBranchId();
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant / Branch
    |--------------------------------------------------------------------------
    */

    private function getTenantId(): ?int
    {
        $user = auth()->user();

        return session('active_tenant_id')
            ?: $user?->tenant_id
            ?: $user?->tenants?->first()?->id;
    }

    private function getUserBranchId(): ?int
    {
        return auth()->user()?->branch_id
            ? (int) auth()->user()->branch_id
            : null;
    }

    private function canUseBranch(?int $branchId): bool
    {
        $tenantId = $this->getTenantId();
        $userBranchId = $this->getUserBranchId();

        if (!$tenantId) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | User belongs to a specific branch
        |--------------------------------------------------------------------------
        */

        if ($userBranchId) {
            return $branchId === null || $branchId === $userBranchId;
        }

        /*
        |--------------------------------------------------------------------------
        | Tenant owner / manager can use tenant branches
        |--------------------------------------------------------------------------
        */

        if (!$branchId) {
            return true;
        }

        return Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($branchId)
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Base Wholesale Query
    |--------------------------------------------------------------------------
    */

    private function wholesaleQuery()
    {
        $tenantId = $this->getTenantId();

        return Order::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', [
                'wholesale',
                'wholesale_van',
                'van',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Filter Updates
    |--------------------------------------------------------------------------
    */

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

    public function updatedPerPage(): void
    {
        $allowed = [15, 25, 50, 100];

        if (!in_array($this->perPage, $allowed, true)) {
            $this->perPage = 15;
        }

        $this->resetPage();
    }

    /*
    |--------------------------------------------------------------------------
    | Clear Filters
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Open Order Details
    |--------------------------------------------------------------------------
    */

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

        $this->selectedCustomerBalance = $this->buildCustomerBalance(
            $order->customer_id
        );

        $this->showDetailModal = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Customer Balance
    |--------------------------------------------------------------------------
    |
    | This is a display calculation only.
    | It does NOT modify Party::current_balance.
    |
    */

    private function buildCustomerBalance(?int $customerId): ?array
    {
        if (!$customerId) {
            return null;
        }

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            return null;
        }

        $customer = Party::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($customerId)
            ->first();

        if (!$customer) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | Wholesale Sales
        |--------------------------------------------------------------------------
        */

        $sales = (float) Order::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->whereIn('type', [
                'wholesale',
                'wholesale_van',
                'van',
            ])
            ->where('status', 'completed')
            ->sum('total');

        /*
        |--------------------------------------------------------------------------
        | Receipts
        |--------------------------------------------------------------------------
        */

        $receipts = (float) DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('payable_type', Party::class)
            ->where('payable_id', $customer->id)
            ->where('type', 'receipt')
            ->whereNull('deleted_at')
            ->sum('amount');

        /*
        |--------------------------------------------------------------------------
        | Other Payments
        |--------------------------------------------------------------------------
        |
        | Kept because your existing accounting structure allows
        | payment records against Party.
        |
        */

        $otherPayments = (float) DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->where('payable_type', Party::class)
            ->where('payable_id', $customer->id)
            ->where('type', 'payment')
            ->whereNull('deleted_at')
            ->sum('amount');

        /*
        |--------------------------------------------------------------------------
        | Display Balance
        |--------------------------------------------------------------------------
        */

        $openingBalance = (float) ($customer->opening_balance ?? 0);

        $balance = $openingBalance
            + $sales
            + $otherPayments
            - $receipts;

        return [
            'opening' => $openingBalance,
            'sales' => $sales,
            'receipts' => $receipts,
            'other_payments' => $otherPayments,
            'balance' => $balance,

            /*
            |--------------------------------------------------------------------------
            | Authoritative balance from Party
            |--------------------------------------------------------------------------
            */

            'current_balance' => (float) ($customer->current_balance ?? 0),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Close Details
    |--------------------------------------------------------------------------
    */

    public function closeModal(): void
    {
        $this->showDetailModal = false;

        $this->selectedOrder = null;

        $this->selectedCustomerBalance = null;
    }

    /*
    |--------------------------------------------------------------------------
    | Print
    |--------------------------------------------------------------------------
    */

    public function printOrder(int $orderId): void
    {
        $order = $this->wholesaleQuery()
            ->with([
                'items.product',
                'branch',
                'party',
            ])
            ->findOrFail($orderId);

        $items = $order->items
            ->map(fn ($item) => [
                'name' => $item->product?->name ?? 'منتج غير محدد',
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total' => (float) $item->total_price,
            ])
            ->values()
            ->all();

        $paid = (float) $order->paid_amount;
        $total = (float) $order->total;

        $this->dispatch(
            'print-wholesale-invoice',
            data: [
                'title' => 'فاتورة مبيعات جملة',
                'invoice_number' => $order->invoice_number,
                'customer_name' => $order->customer_name
                    ?: $order->party?->name
                    ?: 'زبون جملة عابر',
                'customer_phone' => $order->customer_phone
                    ?: $order->party?->phone
                    ?: '',
                'branch_name' => $order->branch?->name ?? '',
                'created_at' => optional($order->created_at)
                    ->format('Y-m-d H:i'),

                'items' => $items,

                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'total' => $total,
                'paid' => $paid,
                'remaining' => max(0, $total - $paid),
                'notes' => $order->notes ?: '',
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        $tenantId = $this->getTenantId();

        $userBranchId = $this->getUserBranchId();

        /*
        |--------------------------------------------------------------------------
        | Main Query
        |--------------------------------------------------------------------------
        */

        $ordersQuery = $this->wholesaleQuery()
            ->with([
                'branch:id,name',
                'party:id,name,phone,current_balance',
            ])

            /*
            |--------------------------------------------------------------------------
            | Branch Security
            |--------------------------------------------------------------------------
            */

            ->when(
                $userBranchId,
                fn ($query) =>
                    $query->where('branch_id', $userBranchId)
            )

            /*
            |--------------------------------------------------------------------------
            | Branch Filter
            |--------------------------------------------------------------------------
            */

            ->when(
                !$userBranchId && $this->branchFilter,
                fn ($query) =>
                    $query->where('branch_id', $this->branchFilter)
            )

            /*
            |--------------------------------------------------------------------------
            | Search
            |--------------------------------------------------------------------------
            */

            ->when(
                trim($this->search) !== '',
                function ($query) {
                    $term = trim($this->search);

                    $query->where(function ($q) use ($term) {
                        $q->where(
                            'invoice_number',
                            'like',
                            "%{$term}%"
                        )
                        ->orWhere(
                            'customer_name',
                            'like',
                            "%{$term}%"
                        )
                        ->orWhere(
                            'customer_phone',
                            'like',
                            "%{$term}%"
                        );
                    });
                }
            )

            /*
            |--------------------------------------------------------------------------
            | Status
            |--------------------------------------------------------------------------
            */

            ->when(
                $this->statusFilter !== '',
                fn ($query) =>
                    $query->where(
                        'status',
                        $this->statusFilter
                    )
            )

            /*
            |--------------------------------------------------------------------------
            | Payment Status
            |--------------------------------------------------------------------------
            */

            ->when(
                $this->paymentStatusFilter !== '',
                fn ($query) =>
                    $query->where(
                        'payment_status',
                        $this->paymentStatusFilter
                    )
            )

            /*
            |--------------------------------------------------------------------------
            | Date From
            |--------------------------------------------------------------------------
            */

            ->when(
                $this->dateFrom !== '',
                fn ($query) =>
                    $query->whereDate(
                        'created_at',
                        '>=',
                        $this->dateFrom
                    )
            )

            /*
            |--------------------------------------------------------------------------
            | Date To
            |--------------------------------------------------------------------------
            */

            ->when(
                $this->dateTo !== '',
                fn ($query) =>
                    $query->whereDate(
                        'created_at',
                        '<=',
                        $this->dateTo
                    )
            )

            ->latest('id');

        /*
        |--------------------------------------------------------------------------
        | Pagination
        |--------------------------------------------------------------------------
        */

        $orders = $ordersQuery->paginate($this->perPage);

        /*
        |--------------------------------------------------------------------------
        | Branches
        |--------------------------------------------------------------------------
        */

        $branches = !$userBranchId && $tenantId
            ? Branch::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name'])
            : collect();

        /*
        |--------------------------------------------------------------------------
        | Summary
        |--------------------------------------------------------------------------
        |
        | Clone the filtered query so the summary always matches
        | the visible filters.
        |
        */

        $summaryQuery = clone $ordersQuery;

        $summary = [
            'count' => (clone $summaryQuery)
                ->toBase()
                ->getCountForPagination(),

            'total' => (float) (clone $summaryQuery)
                ->sum('total'),

            'paid' => (float) (clone $summaryQuery)
                ->sum('paid_amount'),
        ];

        $summary['remaining'] = max(
            0,
            $summary['total'] - $summary['paid']
        );

        return $this->view([
            'orders' => $orders,
            'branches' => $branches,
            'summary' => $summary,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">
    <div
        class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6"
        dir="rtl"
    >

        {{-- ==========================================================
            HEADER
        =========================================================== --}}

        <div class="flex flex-col gap-4 border-b border-zinc-200 pb-6 dark:border-zinc-800 lg:flex-row lg:items-center lg:justify-between">

            <div class="space-y-1">
                <flux:heading size="xl">
                    فواتير مبيعات الجملة
                </flux:heading>

                <flux:subheading>
                    عرض ومراجعة ومتابعة فواتير مبيعات الجملة الصادرة من الفروع.
                </flux:subheading>
            </div>

            <div class="flex items-center gap-2">

                <flux:badge
                    variant="info"
                    icon="document-text"
                >
                    {{ number_format($summary['count']) }} فاتورة
                </flux:badge>

            </div>
        </div>


        {{-- ==========================================================
            SUCCESS MESSAGE
        =========================================================== --}}

        @if (session()->has('message'))
            <flux:badge
                variant="success"
                class="w-full justify-start p-3 text-sm"
            >
                {{ session('message') }}
            </flux:badge>
        @endif


        {{-- ==========================================================
            SUMMARY
        =========================================================== --}}

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">

            {{-- Count --}}
            <flux:card class="relative overflow-hidden">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-medium text-zinc-500">
                            عدد الفواتير
                        </div>

                        <div class="mt-2 text-2xl font-bold">
                            {{ number_format($summary['count']) }}
                        </div>
                    </div>

                    <div class="rounded-xl bg-indigo-50 p-2.5 text-indigo-600 dark:bg-indigo-500/10 dark:text-indigo-400">
                        <flux:icon
                            name="document-text"
                            class="size-5"
                        />
                    </div>
                </div>
            </flux:card>


            {{-- Total --}}
            <flux:card class="relative overflow-hidden">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-medium text-zinc-500">
                            إجمالي المبيعات
                        </div>

                        <div class="mt-2 text-2xl font-bold text-emerald-600">
                            {{ number_format($summary['total'], 2) }}
                        </div>
                    </div>

                    <div class="rounded-xl bg-emerald-50 p-2.5 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400">
                        <flux:icon
                            name="banknotes"
                            class="size-5"
                        />
                    </div>
                </div>
            </flux:card>


            {{-- Paid --}}
            <flux:card class="relative overflow-hidden">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-medium text-zinc-500">
                            إجمالي المدفوع
                        </div>

                        <div class="mt-2 text-2xl font-bold text-blue-600">
                            {{ number_format($summary['paid'], 2) }}
                        </div>
                    </div>

                    <div class="rounded-xl bg-blue-50 p-2.5 text-blue-600 dark:bg-blue-500/10 dark:text-blue-400">
                        <flux:icon
                            name="check-circle"
                            class="size-5"
                        />
                    </div>
                </div>
            </flux:card>


            {{-- Remaining --}}
            <flux:card class="relative overflow-hidden">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-xs font-medium text-zinc-500">
                            إجمالي المتبقي
                        </div>

                        <div class="mt-2 text-2xl font-bold text-amber-600">
                            {{ number_format($summary['remaining'], 2) }}
                        </div>
                    </div>

                    <div class="rounded-xl bg-amber-50 p-2.5 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400">
                        <flux:icon
                            name="clock"
                            class="size-5"
                        />
                    </div>
                </div>
            </flux:card>

        </div>


        {{-- ==========================================================
            FILTERS
        =========================================================== --}}

        <flux:card class="space-y-4">

            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">

                <div>
                    <div class="font-semibold">
                        البحث والتصفية
                    </div>

                    <div class="text-xs text-zinc-500">
                        استخدم الفلاتر للوصول إلى الفواتير المطلوبة بسرعة.
                    </div>
                </div>

                <flux:button
                    wire:click="clearFilters"
                    variant="subtle"
                    icon="arrow-path"
                >
                    مسح الفلاتر
                </flux:button>

            </div>


            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-6">

                {{-- Search --}}
                <div class="xl:col-span-2">

                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        placeholder="رقم الفاتورة، اسم العميل أو الهاتف..."
                        icon="magnifying-glass"
                    />

                </div>


                {{-- Status --}}
                <flux:select
                    wire:model.live="statusFilter"
                >
                    <flux:select.option value="">
                        كل الحالات
                    </flux:select.option>

                    <flux:select.option value="completed">
                        مكتملة
                    </flux:select.option>

                    <flux:select.option value="pending">
                        قيد الانتظار
                    </flux:select.option>

                    <flux:select.option value="processing">
                        قيد المعالجة
                    </flux:select.option>

                    <flux:select.option value="cancelled">
                        ملغاة
                    </flux:select.option>
                </flux:select>


                {{-- Payment --}}
                <flux:select
                    wire:model.live="paymentStatusFilter"
                >
                    <flux:select.option value="">
                        كل الدفعات
                    </flux:select.option>

                    <flux:select.option value="paid">
                        مدفوعة
                    </flux:select.option>

                    <flux:select.option value="partial">
                        دفع جزئي
                    </flux:select.option>

                    <flux:select.option value="unpaid">
                        آجل
                    </flux:select.option>
                </flux:select>


                {{-- Branch --}}
                @if ($branches->isNotEmpty())

                    <flux:select
                        wire:model.live="branchFilter"
                    >
                        <flux:select.option value="">
                            كل الفروع
                        </flux:select.option>

                        @foreach ($branches as $branch)
                            <flux:select.option value="{{ $branch->id }}">
                                {{ $branch->name }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                @else

                    <div></div>

                @endif


                {{-- Per Page --}}
                <flux:select
                    wire:model.live="perPage"
                >
                    <flux:select.option value="15">
                        15 فاتورة
                    </flux:select.option>

                    <flux:select.option value="25">
                        25 فاتورة
                    </flux:select.option>

                    <flux:select.option value="50">
                        50 فاتورة
                    </flux:select.option>

                    <flux:select.option value="100">
                        100 فاتورة
                    </flux:select.option>
                </flux:select>

            </div>


            {{-- Dates --}}

            <div class="grid max-w-2xl grid-cols-1 gap-3 sm:grid-cols-2">

                <flux:input
                    type="date"
                    wire:model.live="dateFrom"
                    label="من تاريخ"
                />

                <flux:input
                    type="date"
                    wire:model.live="dateTo"
                    label="إلى تاريخ"
                />

            </div>

        </flux:card>


        {{-- ==========================================================
            DESKTOP TABLE
        =========================================================== --}}

        <flux:card class="hidden overflow-hidden p-0 lg:block">

            <div class="overflow-x-auto">

                <table class="w-full border-collapse text-right text-sm">

                    <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">

                        <tr>

                            <th class="whitespace-nowrap p-4">
                                الفاتورة
                            </th>

                            <th class="whitespace-nowrap p-4">
                                العميل
                            </th>

                            <th class="whitespace-nowrap p-4">
                                الفرع
                            </th>

                            <th class="whitespace-nowrap p-4">
                                الإجمالي
                            </th>

                            <th class="whitespace-nowrap p-4">
                                المدفوع
                            </th>

                            <th class="whitespace-nowrap p-4">
                                المتبقي
                            </th>

                            <th class="whitespace-nowrap p-4">
                                الدفع
                            </th>

                            <th class="whitespace-nowrap p-4">
                                التاريخ
                            </th>

                            <th class="whitespace-nowrap p-4 text-center">
                                الإجراء
                            </th>

                        </tr>

                    </thead>


                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">

                        @forelse ($orders as $order)

                            @php
                                $total = (float) $order->total;
                                $paid = (float) $order->paid_amount;
                                $remaining = max(0, $total - $paid);
                            @endphp

                            <tr class="transition hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30">

                                {{-- Invoice --}}
                                <td class="p-4">

                                    <div class="font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                        {{ $order->invoice_number }}
                                    </div>

                                    <div class="mt-1 text-xs text-zinc-500">
                                        #{{ $order->id }}
                                    </div>

                                </td>


                                {{-- Customer --}}
                                <td class="p-4">

                                    <div class="font-medium">
                                        {{ $order->customer_name ?: $order->party?->name ?: 'زبون جملة عابر' }}
                                    </div>

                                    @if ($order->customer_phone || $order->party?->phone)

                                        <div
                                            class="mt-1 text-xs text-zinc-500"
                                            dir="ltr"
                                        >
                                            {{ $order->customer_phone ?: $order->party?->phone }}
                                        </div>

                                    @endif

                                </td>


                                {{-- Branch --}}
                                <td class="p-4 text-zinc-600 dark:text-zinc-300">
                                    {{ $order->branch?->name ?? '-' }}
                                </td>


                                {{-- Total --}}
                                <td class="p-4 font-bold">
                                    {{ number_format($total, 2) }}
                                </td>


                                {{-- Paid --}}
                                <td class="p-4 font-semibold text-emerald-600">
                                    {{ number_format($paid, 2) }}
                                </td>


                                {{-- Remaining --}}
                                <td class="p-4">

                                    <span class="{{ $remaining > 0 ? 'font-bold text-amber-600' : 'text-zinc-500' }}">
                                        {{ number_format($remaining, 2) }}
                                    </span>

                                </td>


                                {{-- Payment Status --}}
                                <td class="p-4">

                                    @if ($order->payment_status === 'paid')

                                        <flux:badge variant="success">
                                            مدفوع
                                        </flux:badge>

                                    @elseif ($order->payment_status === 'partial')

                                        <flux:badge variant="warning">
                                            جزئي
                                        </flux:badge>

                                    @else

                                        <flux:badge variant="danger">
                                            آجل
                                        </flux:badge>

                                    @endif

                                </td>


                                {{-- Date --}}
                                <td class="whitespace-nowrap p-4 text-xs text-zinc-500">
                                    {{ $order->created_at?->format('Y-m-d H:i') }}
                                </td>


                                {{-- Action --}}
                                <td class="p-4 text-center">

                                    <flux:button
                                        size="xs"
                                        variant="subtle"
                                        icon="eye"
                                        wire:click="viewOrder({{ $order->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="viewOrder({{ $order->id }})"
                                    >
                                        التفاصيل
                                    </flux:button>

                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td
                                    colspan="9"
                                    class="p-16 text-center"
                                >

                                    <div class="mx-auto max-w-sm">

                                        <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                                            <flux:icon
                                                name="document-magnifying-glass"
                                                class="size-7"
                                            />
                                        </div>

                                        <div class="font-semibold">
                                            لا توجد فواتير
                                        </div>

                                        <div class="mt-1 text-sm text-zinc-500">
                                            لا توجد فواتير جملة مطابقة للفلاتر الحالية.
                                        </div>

                                    </div>

                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>


            {{-- Pagination --}}

            @if ($orders->hasPages())

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                    {{ $orders->links() }}
                </div>

            @endif

        </flux:card>


        {{-- ==========================================================
            MOBILE CARDS
        =========================================================== --}}

        <div class="space-y-3 lg:hidden">

            @forelse ($orders as $order)

                @php
                    $total = (float) $order->total;
                    $paid = (float) $order->paid_amount;
                    $remaining = max(0, $total - $paid);
                @endphp

                <flux:card class="space-y-4">

                    {{-- Top --}}
                    <div class="flex items-start justify-between gap-3">

                        <div>

                            <div class="font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                {{ $order->invoice_number }}
                            </div>

                            <div class="mt-1 text-xs text-zinc-500">
                                {{ $order->created_at?->format('Y-m-d H:i') }}
                            </div>

                        </div>


                        @if ($order->payment_status === 'paid')

                            <flux:badge variant="success">
                                مدفوع
                            </flux:badge>

                        @elseif ($order->payment_status === 'partial')

                            <flux:badge variant="warning">
                                جزئي
                            </flux:badge>

                        @else

                            <flux:badge variant="danger">
                                آجل
                            </flux:badge>

                        @endif

                    </div>


                    {{-- Customer --}}

                    <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">

                        <div class="text-xs text-zinc-500">
                            العميل
                        </div>

                        <div class="mt-1 font-semibold">
                            {{ $order->customer_name ?: $order->party?->name ?: 'زبون جملة عابر' }}
                        </div>

                        @if ($order->customer_phone || $order->party?->phone)

                            <div
                                class="mt-1 text-xs text-zinc-500"
                                dir="ltr"
                            >
                                {{ $order->customer_phone ?: $order->party?->phone }}
                            </div>

                        @endif

                    </div>


                    {{-- Financial Summary --}}

                    <div class="grid grid-cols-3 gap-2">

                        <div class="rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="text-[11px] text-zinc-500">
                                الإجمالي
                            </div>

                            <div class="mt-1 font-bold">
                                {{ number_format($total, 2) }}
                            </div>
                        </div>


                        <div class="rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 dark:border-emerald-900 dark:bg-emerald-500/5">
                            <div class="text-[11px] text-zinc-500">
                                المدفوع
                            </div>

                            <div class="mt-1 font-bold text-emerald-600">
                                {{ number_format($paid, 2) }}
                            </div>
                        </div>


                        <div class="rounded-xl border border-amber-200 bg-amber-50/50 p-3 dark:border-amber-900 dark:bg-amber-500/5">
                            <div class="text-[11px] text-zinc-500">
                                المتبقي
                            </div>

                            <div class="mt-1 font-bold text-amber-600">
                                {{ number_format($remaining, 2) }}
                            </div>
                        </div>

                    </div>


                    {{-- Branch + Action --}}

                    <div class="flex items-center justify-between gap-3">

                        <div class="text-xs text-zinc-500">
                            {{ $order->branch?->name ?? 'بدون فرع' }}
                        </div>

                        <flux:button
                            size="sm"
                            variant="subtle"
                            icon="eye"
                            wire:click="viewOrder({{ $order->id }})"
                            wire:loading.attr="disabled"
                        >
                            عرض التفاصيل
                        </flux:button>

                    </div>

                </flux:card>

            @empty

                <flux:card class="p-10 text-center">

                    <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-500 dark:bg-zinc-800">

                        <flux:icon
                            name="document-magnifying-glass"
                            class="size-7"
                        />

                    </div>

                    <div class="font-semibold">
                        لا توجد فواتير
                    </div>

                    <div class="mt-1 text-sm text-zinc-500">
                        لا توجد فواتير جملة مطابقة للفلاتر الحالية.
                    </div>

                </flux:card>

            @endforelse


            @if ($orders->hasPages())

                <div class="pt-2">
                    {{ $orders->links() }}
                </div>

            @endif

        </div>


        {{-- ==========================================================
            DETAILS MODAL
        =========================================================== --}}

        <flux:modal
            wire:model="showDetailModal"
            name="wholesale-invoice-details"
            class="max-w-5xl space-y-6 md:w-3/4"
        >

            @if ($selectedOrder)

                {{-- Header --}}

                <div class="flex flex-col gap-4 border-b border-zinc-200 pb-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between">

                    <div>

                        <flux:heading size="lg">
                            فاتورة مبيعات جملة
                        </flux:heading>

                        <flux:subheading>
                            {{ $selectedOrder->invoice_number }}

                            <span class="mx-1">
                                ·
                            </span>

                            {{ $selectedOrder->created_at?->format('Y-m-d H:i') }}
                        </flux:subheading>

                    </div>


                    <div class="flex items-center gap-2">

                        <flux:button
                            size="sm"
                            variant="subtle"
                            icon="printer"
                            wire:click="printOrder({{ $selectedOrder->id }})"
                        >
                            طباعة
                        </flux:button>

                        <flux:button
                            size="sm"
                            variant="ghost"
                            icon="x-mark"
                            wire:click="closeModal"
                        />

                    </div>

                </div>


                {{-- Customer / Branch --}}

                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">

                    <flux:card class="md:col-span-2">

                        <div class="text-xs text-zinc-500">
                            العميل
                        </div>

                        <div class="mt-1 text-lg font-bold">
                            {{ $selectedOrder->customer_name ?: $selectedOrder->party?->name ?: 'زبون جملة عابر' }}
                        </div>

                        @if ($selectedOrder->customer_phone || $selectedOrder->party?->phone)

                            <div
                                class="mt-1 text-sm text-zinc-500"
                                dir="ltr"
                            >
                                {{ $selectedOrder->customer_phone ?: $selectedOrder->party?->phone }}
                            </div>

                        @else

                            <div class="mt-1 text-sm text-zinc-500">
                                لا يوجد رقم هاتف
                            </div>

                        @endif

                    </flux:card>


                    <flux:card>

                        <div class="text-xs text-zinc-500">
                            الفرع
                        </div>

                        <div class="mt-1 font-bold">
                            {{ $selectedOrder->branch?->name ?? '-' }}
                        </div>

                        <div class="mt-3 text-xs text-zinc-500">
                            أنشأها
                        </div>

                        <div class="mt-1 text-sm font-medium">
                            {{ $selectedOrder->user?->name ?? '-' }}
                        </div>

                    </flux:card>

                </div>


                {{-- Items --}}

                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800">

                    <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 font-bold dark:border-zinc-800 dark:bg-zinc-800/50">
                        الأصناف
                    </div>

                    <div class="overflow-x-auto">

                        <table class="w-full text-right text-sm">

                            <thead class="bg-zinc-100 dark:bg-zinc-800">

                                <tr>

                                    <th class="whitespace-nowrap p-3">
                                        المنتج
                                    </th>

                                    <th class="whitespace-nowrap p-3">
                                        السعر
                                    </th>

                                    <th class="whitespace-nowrap p-3">
                                        الكمية
                                    </th>

                                    <th class="whitespace-nowrap p-3">
                                        الإجمالي
                                    </th>

                                </tr>

                            </thead>


                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">

                                @forelse ($selectedOrder->items as $item)

                                    <tr>

                                        <td class="p-3 font-medium">
                                            {{ $item->product?->name ?? 'منتج غير محدد' }}
                                        </td>

                                        <td class="p-3">
                                            {{ number_format((float) $item->unit_price, 2) }}
                                        </td>

                                        <td class="p-3 font-bold">
                                            {{ rtrim(rtrim(number_format((float) $item->quantity, 2), '0'), '.') }}
                                        </td>

                                        <td class="p-3 font-bold">
                                            {{ number_format((float) $item->total_price, 2) }}
                                        </td>

                                    </tr>

                                @empty

                                    <tr>

                                        <td
                                            colspan="4"
                                            class="p-8 text-center text-zinc-500"
                                        >
                                            لا توجد أصناف في هذه الفاتورة.
                                        </td>

                                    </tr>

                                @endforelse

                            </tbody>

                        </table>

                    </div>

                </div>


                {{-- Totals + Status --}}

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">

                    <flux:card class="space-y-3">

                        <div class="flex items-center justify-between">
                            <span class="text-zinc-500">
                                المجموع الفرعي
                            </span>

                            <strong>
                                {{ number_format((float) $selectedOrder->subtotal, 2) }}
                            </strong>
                        </div>


                        <div class="flex items-center justify-between">
                            <span class="text-zinc-500">
                                الخصم
                            </span>

                            <strong>
                                {{ number_format((float) $selectedOrder->discount, 2) }}
                            </strong>
                        </div>


                        <div class="flex items-center justify-between border-t border-zinc-200 pt-3 dark:border-zinc-700">
                            <span class="font-bold">
                                الإجمالي
                            </span>

                            <strong class="text-xl text-emerald-600">
                                {{ number_format((float) $selectedOrder->total, 2) }}
                            </strong>
                        </div>


                        <div class="flex items-center justify-between">
                            <span class="text-zinc-500">
                                المدفوع
                            </span>

                            <strong class="text-emerald-600">
                                {{ number_format((float) $selectedOrder->paid_amount, 2) }}
                            </strong>
                        </div>


                        <div class="flex items-center justify-between">

                            <span class="text-zinc-500">
                                المتبقي
                            </span>

                            <strong class="text-amber-600">
                                {{ number_format(max(0, (float) $selectedOrder->total - (float) $selectedOrder->paid_amount), 2) }}
                            </strong>

                        </div>

                    </flux:card>


                    <flux:card>

                        <div class="mb-3 text-xs text-zinc-500">
                            حالة الفاتورة
                        </div>


                        <div class="flex flex-wrap gap-2">

                            @if ($selectedOrder->status === 'completed')

                                <flux:badge variant="success">
                                    مكتملة
                                </flux:badge>

                            @elseif ($selectedOrder->status === 'cancelled')

                                <flux:badge variant="danger">
                                    ملغاة
                                </flux:badge>

                            @elseif ($selectedOrder->status === 'processing')

                                <flux:badge variant="info">
                                    قيد المعالجة
                                </flux:badge>

                            @else

                                <flux:badge variant="warning">
                                    قيد الانتظار
                                </flux:badge>

                            @endif


                            @if ($selectedOrder->payment_status === 'paid')

                                <flux:badge variant="success">
                                    الدفع مكتمل
                                </flux:badge>

                            @elseif ($selectedOrder->payment_status === 'partial')

                                <flux:badge variant="warning">
                                    دفع جزئي
                                </flux:badge>

                            @else

                                <flux:badge variant="danger">
                                    آجل
                                </flux:badge>

                            @endif

                        </div>


                        @if ($selectedOrder->notes)

                            <div class="mt-5 border-t border-zinc-200 pt-4 dark:border-zinc-700">

                                <div class="mb-1 text-xs text-zinc-500">
                                    ملاحظات
                                </div>

                                <div class="whitespace-pre-line text-sm">
                                    {{ $selectedOrder->notes }}
                                </div>

                            </div>

                        @endif

                    </flux:card>

                </div>


                {{-- Customer Balance --}}

                @if ($selectedCustomerBalance)

                    <flux:card>

                        <div class="mb-4 flex items-center justify-between gap-3">

                            <div>
                                <div class="font-bold">
                                    رصيد العميل
                                </div>

                                <div class="mt-1 text-xs text-zinc-500">
                                    ملخص حركات العميل المرتبطة بهذه الحسابات.
                                </div>
                            </div>

                        </div>


                        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">

                            <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">

                                <div class="text-xs text-zinc-500">
                                    الافتتاحي
                                </div>

                                <strong class="mt-1 block">
                                    {{ number_format($selectedCustomerBalance['opening'], 2) }}
                                </strong>

                            </div>


                            <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">

                                <div class="text-xs text-zinc-500">
                                    المبيعات
                                </div>

                                <strong class="mt-1 block">
                                    {{ number_format($selectedCustomerBalance['sales'], 2) }}
                                </strong>

                            </div>


                            <div class="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-500/10">

                                <div class="text-xs text-zinc-500">
                                    المقبوضات
                                </div>

                                <strong class="mt-1 block text-emerald-600">
                                    {{ number_format($selectedCustomerBalance['receipts'], 2) }}
                                </strong>

                            </div>


                            <div class="rounded-xl bg-blue-50 p-3 dark:bg-blue-500/10">

                                <div class="text-xs text-zinc-500">
                                    الدفعات الأخرى
                                </div>

                                <strong class="mt-1 block text-blue-600">
                                    {{ number_format($selectedCustomerBalance['other_payments'], 2) }}
                                </strong>

                            </div>


                            <div class="rounded-xl bg-amber-50 p-3 dark:bg-amber-500/10">

                                <div class="text-xs text-zinc-500">
                                    الرصيد المحسوب
                                </div>

                                <strong class="mt-1 block text-amber-600">
                                    {{ number_format($selectedCustomerBalance['balance'], 2) }}
                                </strong>

                            </div>

                        </div>


                        {{-- Authoritative Party Balance --}}

                        <div class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-800">

                            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">

                                <span class="text-zinc-500">
                                    الرصيد المسجل في حساب العميل
                                </span>

                                <strong class="text-lg">
                                    {{ number_format($selectedCustomerBalance['current_balance'], 2) }}
                                </strong>

                            </div>

                        </div>

                    </flux:card>

                @endif


            @endif

        </flux:modal>

    </div>
</flux:main>


{{-- ================================================================
    PRINT
================================================================= --}}

@script
<script>
    $wire.on('print-wholesale-invoice', ({ data }) => {
        const popup = window.open(
            '',
            '_blank',
            'width=420,height=700'
        );

        if (!popup) {
            return;
        }

        const esc = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const money = (value) => {
            const number = Number(value ?? 0);

            return Number.isFinite(number)
                ? number.toFixed(2)
                : '0.00';
        };

        const quantity = (value) => {
            const number = Number(value ?? 0);

            if (!Number.isFinite(number)) {
                return '0';
            }

            return Number.isInteger(number)
                ? String(number)
                : number.toFixed(2).replace(/\.?0+$/, '');
        };

        const rows = (data.items || [])
            .map(item => `
                <tr>
                    <td>${esc(item.name)}</td>
                    <td>${quantity(item.quantity)}</td>
                    <td>${money(item.unit_price)}</td>
                    <td>${money(item.total)}</td>
                </tr>
            `)
            .join('');

        popup.document.write(`
            <!doctype html>

            <html
                dir="rtl"
                lang="ar"
            >

            <head>

                <meta charset="utf-8">

                <title>
                    ${esc(data.invoice_number)}
                </title>

                <style>

                    * {
                        box-sizing: border-box;
                    }

                    body {
                        font-family: Arial, sans-serif;
                        width: 80mm;
                        margin: 0 auto;
                        padding: 10px;
                        font-size: 12px;
                        color: #000;
                    }

                    h1 {
                        font-size: 18px;
                        text-align: center;
                        margin: 0 0 6px;
                    }

                    .center {
                        text-align: center;
                    }

                    .line {
                        border-top: 1px dashed #000;
                        margin: 8px 0;
                    }

                    .customer {
                        line-height: 1.7;
                    }

                    table {
                        width: 100%;
                        border-collapse: collapse;
                    }

                    th,
                    td {
                        padding: 4px 2px;
                        border-bottom: 1px solid #ddd;
                        text-align: right;
                        vertical-align: top;
                    }

                    th {
                        font-size: 11px;
                    }

                    .totals div {
                        display: flex;
                        justify-content: space-between;
                        gap: 10px;
                        padding: 3px 0;
                    }

                    .grand {
                        font-size: 15px;
                        font-weight: bold;
                    }

                    .note {
                        white-space: pre-line;
                        margin-top: 8px;
                        line-height: 1.6;
                    }

                    .footer {
                        margin-top: 10px;
                        text-align: center;
                    }

                    @media print {

                        body {
                            width: auto;
                        }

                        .no-print {
                            display: none;
                        }

                    }

                </style>

            </head>

            <body>

                <h1>
                    ${esc(data.title)}
                </h1>

                <div class="center">
                    ${esc(data.invoice_number)}
                </div>

                <div class="center">
                    ${esc(data.created_at)}
                </div>

                <div class="line"></div>

                <div class="customer">

                    <div>
                        <strong>العميل:</strong>
                        ${esc(data.customer_name)}
                    </div>

                    ${
                        data.customer_phone
                            ? `<div dir="ltr">${esc(data.customer_phone)}</div>`
                            : ''
                    }

                    ${
                        data.branch_name
                            ? `<div><strong>الفرع:</strong> ${esc(data.branch_name)}</div>`
                            : ''
                    }

                </div>

                <div class="line"></div>

                <table>

                    <thead>

                        <tr>
                            <th>الصنف</th>
                            <th>ك</th>
                            <th>السعر</th>
                            <th>الإجمالي</th>
                        </tr>

                    </thead>

                    <tbody>
                        ${rows}
                    </tbody>

                </table>

                <div class="line"></div>

                <div class="totals">

                    <div>
                        <span>المجموع</span>
                        <span>${money(data.subtotal)}</span>
                    </div>

                    <div>
                        <span>الخصم</span>
                        <span>${money(data.discount)}</span>
                    </div>

                    <div class="grand">
                        <span>الإجمالي</span>
                        <span>${money(data.total)}</span>
                    </div>

                    <div>
                        <span>المدفوع</span>
                        <span>${money(data.paid)}</span>
                    </div>

                    <div>
                        <span>المتبقي</span>
                        <span>${money(data.remaining)}</span>
                    </div>

                </div>

                ${
                    data.notes
                        ? `
                            <div class="line"></div>

                            <div class="note">
                                <strong>ملاحظات:</strong>
                                ${esc(data.notes)}
                            </div>
                        `
                        : ''
                }

                <div class="line"></div>

                <div class="footer">
                    شكراً لتعاملكم معنا
                </div>

                <script>
                    window.onload = () => {
                        window.print();

                        window.onafterprint = () => {
                            window.close();
                        };
                    };
                <\/script>

            </body>

            </html>
        `);

        popup.document.close();
    });
</script>
@endscript
