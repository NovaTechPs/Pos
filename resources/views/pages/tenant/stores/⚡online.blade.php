<?php

use Livewire\Component;
use Livewire\WithPagination;

use App\Models\Order;

use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public string $search = '';

    public string $statusFilter = '';

    public string $sort = 'priority';

    public int $perPage = 10;

    /*
    |--------------------------------------------------------------------------
    | Selected Order
    |--------------------------------------------------------------------------
    */

    public ?Order $selectedOrder = null;

    public bool $showDetailModal = false;

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
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

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */

    protected function tenantId(): int
    {
        $tenantId = session('active_tenant_id');

        abort_unless($tenantId, 404);

        return (int) $tenantId;
    }

    /*
    |--------------------------------------------------------------------------
    | View Order
    |--------------------------------------------------------------------------
    */

    public function viewOrder(int $orderId): void
    {
        $tenantId = $this->tenantId();

        $this->selectedOrder = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'online')
            ->with([
                'items.product',
            ])
            ->findOrFail($orderId);

        $this->showDetailModal = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Update Status
    |--------------------------------------------------------------------------
    */

    public function updateStatus(
        int $orderId,
        string $status
    ): void {
        $allowedStatuses = [
            'pending',
            'processing',
            'completed',
            'cancelled',
        ];

        if (!in_array($status, $allowedStatuses, true)) {
            return;
        }

        $tenantId = $this->tenantId();

        DB::transaction(function () use (
            $tenantId,
            $orderId,
            $status
        ) {
            $order = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('type', 'online')
                ->lockForUpdate()
                ->findOrFail($orderId);

            $updateData = [
                'status' => $status,
            ];

            /*
             * Online orders are unpaid when created.
             * When completed, mark them as paid.
             */
            if ($status === 'completed') {
                $updateData['payment_status'] = 'paid';
                $updateData['paid_amount'] = $order->total;
            }

            /*
             * If cancelled, don't keep it as paid.
             *
             * This is important because an online order
             * is initially unpaid.
             */
            if ($status === 'cancelled') {
                $updateData['payment_status'] = 'unpaid';
                $updateData['paid_amount'] = 0;
            }

            $order->update($updateData);
        });

        /*
         * Refresh selected order.
         */
        if (
            $this->selectedOrder &&
            $this->selectedOrder->id === $orderId
        ) {
            $this->selectedOrder = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('type', 'online')
                ->with([
                    'items.product',
                ])
                ->find($orderId);
        }

        session()->flash(
            'message',
            'تم تحديث حالة الطلب بنجاح.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    */

    public function sendWhatsAppNotification(
        int $orderId
    ): void {
        $tenantId = $this->tenantId();

        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'online')
            ->findOrFail($orderId);

        /*
         * Clean phone.
         */
        $cleanPhone = preg_replace(
            '/[^0-9]/',
            '',
            (string) $order->customer_phone
        );

        if (!$cleanPhone) {
            session()->flash(
                'message',
                'رقم هاتف الزبون غير موجود.'
            );

            return;
        }

        /*
         * Palestine local format:
         * 05xxxxxxxx -> 9705xxxxxxxx
         */
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone =
                '970' .
                substr(
                    $cleanPhone,
                    1
                );
        }

        /*
         * Status message.
         */
        $statusMessage = match (
            $order->status
        ) {
            'pending' =>
                "⏳ طلبكم رقم (*{$order->invoice_number}*) قيد الانتظار حاليًا، وسنوافيكم بالتحديثات فور البدء بتجهيزه.",

            'processing' =>
                "👨‍🍳 مرحبًا {$order->customer_name}، جاري تحضير طلبكم رقم (*{$order->invoice_number}*) الآن.",

            'completed' =>
                "✅ أهلًا {$order->customer_name}، تم إكمال طلبكم رقم (*{$order->invoice_number}*) وهو جاهز للتسليم/التوصيل.",

            'cancelled' =>
                "❌ مرحبًا {$order->customer_name}، نأسف لإبلاغكم بأنه تم إلغاء طلبكم رقم (*{$order->invoice_number}*).",

            default =>
                "تحديث بشأن طلبكم رقم (*{$order->invoice_number}*).",
        };

        $fullMessage =
            "مرحبًا بك! 👋\n\n" .
            $statusMessage .
            "\n\n" .
            "الإجمالي: " .
            number_format(
                (float) $order->total,
                2
            ) .
            " ₪\n\n" .
            "شكرًا لتسوقك معنا! 🌸";

        $whatsappUrl =
            'https://wa.me/' .
            $cleanPhone .
            '?text=' .
            urlencode(
                $fullMessage
            );

        /*
         * Escape URL for JS.
         */
        $encodedUrl =
            json_encode(
                $whatsappUrl,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

        $this->js(
            "window.open({$encodedUrl}, '_blank')"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Close Modal
    |--------------------------------------------------------------------------
    */

    public function closeModal(): void
    {
        $this->showDetailModal = false;

        $this->selectedOrder = null;
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

        $this->sort = 'priority';

        $this->resetPage();
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        $tenantId = $this->tenantId();

        /*
         * Base query.
         */
        $query = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'online');

        /*
         |--------------------------------------------------------------------------
         | Search
         |--------------------------------------------------------------------------
         */

        $query->when(
            trim($this->search) !== '',
            function ($query) {
                $search =
                    trim(
                        $this->search
                    );

                $query->where(
                    function ($q) use (
                        $search
                    ) {
                        $q
                            ->where(
                                'invoice_number',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'customer_name',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'customer_phone',
                                'like',
                                "%{$search}%"
                            )
                            ->orWhere(
                                'customer_address',
                                'like',
                                "%{$search}%"
                            );
                    }
                );
            }
        );

        /*
         |--------------------------------------------------------------------------
         | Status
         |--------------------------------------------------------------------------
         */

        $query->when(
            $this->statusFilter !== '',
            function ($query) {
                $query->where(
                    'status',
                    $this->statusFilter
                );
            }
        );

        /*
         |--------------------------------------------------------------------------
         | Sorting
         |--------------------------------------------------------------------------
         */

        switch ($this->sort) {

            /*
             * Operational priority:
             * pending -> processing -> completed -> cancelled
             *
             * Inside each status:
             * newest first.
             */
            case 'priority':

                $query
                    ->orderByRaw("
                        CASE status
                            WHEN 'pending' THEN 1
                            WHEN 'processing' THEN 2
                            WHEN 'completed' THEN 3
                            WHEN 'cancelled' THEN 4
                            ELSE 5
                        END
                    ")
                    ->latest('created_at');

                break;

            case 'latest':

                $query->latest('created_at');

                break;

            case 'oldest':

                $query->oldest('created_at');

                break;

            case 'highest':

                $query
                    ->orderByDesc('total')
                    ->latest('created_at');

                break;

            case 'lowest':

                $query
                    ->orderBy('total')
                    ->latest('created_at');

                break;

            default:

                $query->latest('created_at');

                break;
        }

        /*
         |--------------------------------------------------------------------------
         | Pagination
         |--------------------------------------------------------------------------
         */

        $orders = $query
            ->paginate(
                $this->perPage
            );

        /*
         |--------------------------------------------------------------------------
         | Statistics
         |--------------------------------------------------------------------------
         */

        $statsBase = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'online');

        $stats = [
            'total' =>
                (clone $statsBase)->count(),

            'pending' =>
                (clone $statsBase)
                    ->where(
                        'status',
                        'pending'
                    )
                    ->count(),

            'processing' =>
                (clone $statsBase)
                    ->where(
                        'status',
                        'processing'
                    )
                    ->count(),

            'completed' =>
                (clone $statsBase)
                    ->where(
                        'status',
                        'completed'
                    )
                    ->count(),

            'cancelled' =>
                (clone $statsBase)
                    ->where(
                        'status',
                        'cancelled'
                    )
                    ->count(),

            'totalSales' =>
                (clone $statsBase)
                    ->where(
                        'status',
                        'completed'
                    )
                    ->sum('total'),

            'pendingSales' =>
                (clone $statsBase)
                    ->where(
                        'status',
                        'pending'
                    )
                    ->sum('total'),
        ];

        return $this->view([
            'orders' => $orders,
            'stats' => $stats,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">

    <div
        class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6"
        dir="rtl"
    >

        {{-- =========================================================
             HEADER
        ========================================================== --}}
        <div class="flex flex-col gap-4 border-b border-zinc-200 pb-6 dark:border-zinc-800 lg:flex-row lg:items-center lg:justify-between">

            <div>

                <div class="flex items-center gap-3">

                    <div class="flex size-11 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400">

                        <flux:icon
                            name="shopping-bag"
                            class="size-5"
                        />

                    </div>

                    <div>

                        <flux:heading size="xl">
                            طلبات الأونلاين
                        </flux:heading>

                        <flux:subheading class="mt-1">
                            متابعة الطلبات الواردة ومعالجة حالتها والتواصل مع الزبائن.
                        </flux:subheading>

                    </div>

                </div>

            </div>

        </div>

        {{-- =========================================================
             FLASH MESSAGE
        ========================================================== --}}
        @if(session()->has('message'))

            <div class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-400">

                <flux:icon
                    name="check-circle"
                    class="size-5 shrink-0"
                />

                <span>
                    {{ session('message') }}
                </span>

            </div>

        @endif

        {{-- =========================================================
             STATISTICS
        ========================================================== --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">

            {{-- Total --}}
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                <div class="flex items-center justify-between">

                    <span class="text-xs font-medium text-zinc-500">
                        كل الطلبات
                    </span>

                    <div class="flex size-8 items-center justify-center rounded-xl bg-zinc-100 text-zinc-500 dark:bg-zinc-800">
                        <flux:icon
                            name="shopping-bag"
                            class="size-4"
                        />
                    </div>

                </div>

                <div class="mt-3 text-2xl font-black text-zinc-900 dark:text-white">
                    {{ number_format($stats['total']) }}
                </div>

            </div>

            {{-- Pending --}}
            <button
                type="button"
                wire:click="$set('statusFilter', 'pending')"
                class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-right transition hover:-translate-y-0.5 hover:shadow-md dark:border-amber-900/60 dark:bg-amber-950/20"
            >

                <div class="flex items-center justify-between">

                    <span class="text-xs font-semibold text-amber-700 dark:text-amber-400">
                        قيد الانتظار
                    </span>

                    <flux:icon
                        name="clock"
                        class="size-4 text-amber-600"
                    />

                </div>

                <div class="mt-3 text-2xl font-black text-amber-700 dark:text-amber-400">
                    {{ number_format($stats['pending']) }}
                </div>

            </button>

            {{-- Processing --}}
            <button
                type="button"
                wire:click="$set('statusFilter', 'processing')"
                class="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-right transition hover:-translate-y-0.5 hover:shadow-md dark:border-blue-900/60 dark:bg-blue-950/20"
            >

                <div class="flex items-center justify-between">

                    <span class="text-xs font-semibold text-blue-700 dark:text-blue-400">
                        جاري التحضير
                    </span>

                    <flux:icon
                        name="arrow-path"
                        class="size-4 text-blue-600"
                    />

                </div>

                <div class="mt-3 text-2xl font-black text-blue-700 dark:text-blue-400">
                    {{ number_format($stats['processing']) }}
                </div>

            </button>

            {{-- Completed --}}
            <button
                type="button"
                wire:click="$set('statusFilter', 'completed')"
                class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-right transition hover:-translate-y-0.5 hover:shadow-md dark:border-emerald-900/60 dark:bg-emerald-950/20"
            >

                <div class="flex items-center justify-between">

                    <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                        مكتملة
                    </span>

                    <flux:icon
                        name="check-circle"
                        class="size-4 text-emerald-600"
                    />

                </div>

                <div class="mt-3 text-2xl font-black text-emerald-700 dark:text-emerald-400">
                    {{ number_format($stats['completed']) }}
                </div>

            </button>

            {{-- Cancelled --}}
            <button
                type="button"
                wire:click="$set('statusFilter', 'cancelled')"
                class="rounded-2xl border border-red-200 bg-red-50 p-4 text-right transition hover:-translate-y-0.5 hover:shadow-md dark:border-red-900/60 dark:bg-red-950/20"
            >

                <div class="flex items-center justify-between">

                    <span class="text-xs font-semibold text-red-700 dark:text-red-400">
                        ملغاة
                    </span>

                    <flux:icon
                        name="x-circle"
                        class="size-4 text-red-600"
                    />

                </div>

                <div class="mt-3 text-2xl font-black text-red-700 dark:text-red-400">
                    {{ number_format($stats['cancelled']) }}
                </div>

            </button>

            {{-- Sales --}}
            <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/20">

                <div class="flex items-center justify-between">

                    <span class="text-xs font-semibold text-indigo-700 dark:text-indigo-400">
                        مبيعات مكتملة
                    </span>

                    <flux:icon
                        name="banknotes"
                        class="size-4 text-indigo-600"
                    />

                </div>

                <div class="mt-3 text-xl font-black text-indigo-700 dark:text-indigo-400">

                    {{ number_format(
                        $stats['totalSales'],
                        2
                    ) }}

                    <span class="text-xs">
                        ₪
                    </span>

                </div>

            </div>

        </div>

        {{-- =========================================================
             FILTERS
        ========================================================== --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <div class="flex flex-col gap-3 lg:flex-row">

                {{-- Search --}}
                <div class="min-w-0 flex-1">

                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        placeholder="ابحث باسم الزبون، الهاتف، العنوان أو رقم الفاتورة..."
                        icon="magnifying-glass"
                    />

                </div>

                {{-- Status --}}
                <div class="lg:w-48">

                    <flux:select
                        wire:model.live="statusFilter"
                    >

                        <flux:select.option value="">
                            جميع الحالات
                        </flux:select.option>

                        <flux:select.option value="pending">
                            قيد الانتظار
                        </flux:select.option>

                        <flux:select.option value="processing">
                            جاري التحضير
                        </flux:select.option>

                        <flux:select.option value="completed">
                            مكتمل
                        </flux:select.option>

                        <flux:select.option value="cancelled">
                            ملغي
                        </flux:select.option>

                    </flux:select>

                </div>

                {{-- Sort --}}
                <div class="lg:w-48">

                    <flux:select
                        wire:model.live="sort"
                    >

                        <flux:select.option value="priority">
                            ترتيب حسب الأولوية
                        </flux:select.option>

                        <flux:select.option value="latest">
                            الأحدث أولًا
                        </flux:select.option>

                        <flux:select.option value="oldest">
                            الأقدم أولًا
                        </flux:select.option>

                        <flux:select.option value="highest">
                            الأعلى قيمة
                        </flux:select.option>

                        <flux:select.option value="lowest">
                            الأقل قيمة
                        </flux:select.option>

                    </flux:select>

                </div>

                {{-- Per page --}}
                <div class="lg:w-32">

                    <flux:select
                        wire:model.live="perPage"
                    >

                        <flux:select.option value="10">
                            10
                        </flux:select.option>

                        <flux:select.option value="25">
                            25
                        </flux:select.option>

                        <flux:select.option value="50">
                            50
                        </flux:select.option>

                    </flux:select>

                </div>

                {{-- Clear --}}
                @if(
                    $search ||
                    $statusFilter ||
                    $sort !== 'priority'
                )

                    <flux:button
                        wire:click="clearFilters"
                        variant="subtle"
                        icon="arrow-path"
                    >
                        مسح
                    </flux:button>

                @endif

            </div>

        </div>

        {{-- =========================================================
             TABLE
        ========================================================== --}}
        <flux:card class="overflow-hidden p-0">

            <div class="overflow-x-auto">

                <table class="w-full min-w-[950px] text-right text-sm">

                    <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800/60">

                        <tr>

                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                الطلب
                            </th>

                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                الزبون
                            </th>

                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                العنوان
                            </th>

                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                الإجمالي
                            </th>

                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                الحالة
                            </th>

                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                التاريخ
                            </th>

                            <th class="px-4 py-3 text-center font-semibold text-zinc-500">
                                الإجراءات
                            </th>

                        </tr>

                    </thead>

                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">

                        @forelse($orders as $order)

                            <tr class="transition hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30">

                                {{-- Order --}}
                                <td class="px-4 py-4">

                                    <div class="flex items-center gap-3">

                                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400">

                                            <flux:icon
                                                name="shopping-bag"
                                                class="size-4"
                                            />

                                        </div>

                                        <div>

                                            <div class="font-mono text-xs font-black text-indigo-600 dark:text-indigo-400">
                                                {{ $order->invoice_number }}
                                            </div>

                                            <div class="mt-1 text-[11px] text-zinc-400">
                                                #{{ $order->id }}
                                            </div>

                                        </div>

                                    </div>

                                </td>

                                {{-- Customer --}}
                                <td class="px-4 py-4">

                                    <div class="font-bold text-zinc-900 dark:text-white">
                                        {{ $order->customer_name ?: 'بدون اسم' }}
                                    </div>

                                    @if($order->customer_phone)

                                        <div
                                            dir="ltr"
                                            class="mt-1 text-xs text-zinc-500"
                                        >
                                            {{ $order->customer_phone }}
                                        </div>

                                    @endif

                                </td>

                                {{-- Address --}}
                                <td class="max-w-xs px-4 py-4">

                                    <div class="line-clamp-2 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
                                        {{ $order->customer_address ?: '—' }}
                                    </div>

                                </td>

                                {{-- Total --}}
                                <td class="px-4 py-4">

                                    <div class="font-black text-emerald-600 dark:text-emerald-400">

                                        {{ number_format(
                                            (float) $order->total,
                                            2
                                        ) }}

                                        <span class="text-[10px]">
                                            ₪
                                        </span>

                                    </div>

                                </td>

                                {{-- Status --}}
                                <td class="px-4 py-4">

                                    @switch($order->status)

                                        @case('pending')

                                            <flux:badge variant="warning">
                                                قيد الانتظار
                                            </flux:badge>

                                            @break

                                        @case('processing')

                                            <flux:badge variant="info">
                                                جاري التحضير
                                            </flux:badge>

                                            @break

                                        @case('completed')

                                            <flux:badge variant="success">
                                                مكتمل
                                            </flux:badge>

                                            @break

                                        @case('cancelled')

                                            <flux:badge variant="danger">
                                                ملغي
                                            </flux:badge>

                                            @break

                                        @default

                                            <flux:badge variant="subtle">
                                                {{ $order->status }}
                                            </flux:badge>

                                    @endswitch

                                </td>

                                {{-- Date --}}
                                <td class="px-4 py-4">

                                    <div class="text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $order->created_at->format('Y-m-d') }}
                                    </div>

                                    <div class="mt-1 text-[11px] text-zinc-400">
                                        {{ $order->created_at->format('H:i') }}
                                    </div>

                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-4">

                                    <div class="flex items-center justify-center gap-2">

                                        <flux:button
                                            size="xs"
                                            variant="subtle"
                                            icon="eye"
                                            wire:click="viewOrder({{ $order->id }})"
                                        >
                                            معاينة
                                        </flux:button>

                                        @if($order->customer_phone)

                                            <flux:button
                                                size="xs"
                                                variant="subtle"
                                                icon="chat-bubble-left-right"
                                                wire:click="sendWhatsAppNotification({{ $order->id }})"
                                                class="text-emerald-600 dark:text-emerald-400"
                                            >
                                                واتساب
                                            </flux:button>

                                        @endif

                                    </div>

                                </td>

                            </tr>

                        @empty

                            <tr>

                                <td
                                    colspan="7"
                                    class="px-6 py-16 text-center"
                                >

                                    <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800">

                                        <flux:icon
                                            name="shopping-bag"
                                            class="size-6"
                                        />

                                    </div>

                                    <h3 class="mt-4 font-bold text-zinc-900 dark:text-white">
                                        لا توجد طلبات
                                    </h3>

                                    <p class="mt-1 text-xs text-zinc-500">
                                        لا توجد طلبات أونلاين مطابقة للفلاتر الحالية.
                                    </p>

                                    @if($search || $statusFilter)

                                        <button
                                            type="button"
                                            wire:click="clearFilters"
                                            class="mt-4 rounded-xl bg-zinc-900 px-4 py-2 text-xs font-bold text-white dark:bg-white dark:text-zinc-900"
                                        >
                                            عرض جميع الطلبات
                                        </button>

                                    @endif

                                </td>

                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

            {{-- Pagination --}}
            @if($orders->hasPages())

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">

                    {{ $orders->links() }}

                </div>

            @endif

        </flux:card>

        {{-- =========================================================
             MOBILE CARDS
        ========================================================== --}}
        <div class="space-y-3 lg:hidden">

            @foreach($orders as $order)

                <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                    <div class="flex items-start justify-between gap-3">

                        <div>

                            <div class="font-mono text-xs font-black text-indigo-600 dark:text-indigo-400">
                                {{ $order->invoice_number }}
                            </div>

                            <div class="mt-1 text-sm font-bold text-zinc-900 dark:text-white">
                                {{ $order->customer_name ?: 'بدون اسم' }}
                            </div>

                            <div
                                dir="ltr"
                                class="mt-1 text-xs text-zinc-500"
                            >
                                {{ $order->customer_phone }}
                            </div>

                        </div>

                        @switch($order->status)

                            @case('pending')

                                <flux:badge variant="warning">
                                    قيد الانتظار
                                </flux:badge>

                                @break

                            @case('processing')

                                <flux:badge variant="info">
                                    جاري التحضير
                                </flux:badge>

                                @break

                            @case('completed')

                                <flux:badge variant="success">
                                    مكتمل
                                </flux:badge>

                                @break

                            @case('cancelled')

                                <flux:badge variant="danger">
                                    ملغي
                                </flux:badge>

                                @break

                        @endswitch

                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">

                        <div>

                            <div class="text-[10px] text-zinc-400">
                                الإجمالي
                            </div>

                            <div class="mt-1 font-black text-emerald-600">
                                {{ number_format($order->total, 2) }}
                                ₪
                            </div>

                        </div>

                        <div>

                            <div class="text-[10px] text-zinc-400">
                                التاريخ
                            </div>

                            <div class="mt-1 text-xs font-bold text-zinc-700 dark:text-zinc-300">
                                {{ $order->created_at->format('Y-m-d H:i') }}
                            </div>

                        </div>

                    </div>

                    <div class="mt-3 flex gap-2">

                        <flux:button
                            size="sm"
                            variant="subtle"
                            icon="eye"
                            wire:click="viewOrder({{ $order->id }})"
                            class="flex-1"
                        >
                            معاينة
                        </flux:button>

                        @if($order->customer_phone)

                            <flux:button
                                size="sm"
                                variant="subtle"
                                icon="chat-bubble-left-right"
                                wire:click="sendWhatsAppNotification({{ $order->id }})"
                                class="flex-1 text-emerald-600"
                            >
                                واتساب
                            </flux:button>

                        @endif

                    </div>

                </div>

            @endforeach

        </div>

        {{-- =========================================================
             ORDER DETAILS MODAL
        ========================================================== --}}
        <flux:modal
            wire:model="showDetailModal"
            name="order-details-modal"
            class="w-full max-w-4xl"
        >

            @if($selectedOrder)

                <div class="space-y-6">

                    {{-- Modal Header --}}
                    <div class="flex items-start justify-between gap-4 border-b border-zinc-200 pb-4 dark:border-zinc-800">

                        <div>

                            <div class="flex items-center gap-2">

                                <flux:heading size="lg">
                                    تفاصيل الطلب
                                </flux:heading>

                                <span class="rounded-lg bg-indigo-50 px-2 py-1 font-mono text-xs font-bold text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400">
                                    {{ $selectedOrder->invoice_number }}
                                </span>

                            </div>

                            <flux:subheading class="mt-1">
                                {{ $selectedOrder->created_at->format('Y-m-d H:i') }}
                            </flux:subheading>

                        </div>

                        <flux:button
                            variant="ghost"
                            icon="x-mark"
                            wire:click="closeModal"
                        />

                    </div>

                    {{-- Customer --}}
                    <div class="grid gap-4 md:grid-cols-2">

                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/50">

                            <div class="mb-3 text-xs font-semibold text-zinc-400">
                                بيانات الزبون
                            </div>

                            <div class="text-base font-black text-zinc-900 dark:text-white">
                                {{ $selectedOrder->customer_name ?: 'بدون اسم' }}
                            </div>

                            <div
                                dir="ltr"
                                class="mt-2 text-sm text-zinc-600 dark:text-zinc-300"
                            >
                                {{ $selectedOrder->customer_phone ?: '—' }}
                            </div>

                            <div class="mt-2 text-sm leading-6 text-zinc-500">
                                {{ $selectedOrder->customer_address ?: 'لا يوجد عنوان' }}
                            </div>

                        </div>

                        {{-- Status --}}
                        <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/50">

                            <div class="mb-3 text-xs font-semibold text-zinc-400">
                                حالة الطلب
                            </div>

                            <div class="flex flex-wrap gap-2">

                                <flux:button
                                    size="xs"
                                    variant="{{ $selectedOrder->status === 'pending' ? 'primary' : 'subtle' }}"
                                    wire:click="updateStatus({{ $selectedOrder->id }}, 'pending')"
                                >
                                    قيد الانتظار
                                </flux:button>

                                <flux:button
                                    size="xs"
                                    variant="{{ $selectedOrder->status === 'processing' ? 'primary' : 'subtle' }}"
                                    wire:click="updateStatus({{ $selectedOrder->id }}, 'processing')"
                                >
                                    جاري التحضير
                                </flux:button>

                                <flux:button
                                    size="xs"
                                    variant="{{ $selectedOrder->status === 'completed' ? 'primary' : 'subtle' }}"
                                    wire:click="updateStatus({{ $selectedOrder->id }}, 'completed')"
                                >
                                    مكتمل
                                </flux:button>

                                <flux:button
                                    size="xs"
                                    variant="{{ $selectedOrder->status === 'cancelled' ? 'danger' : 'subtle' }}"
                                    wire:click="updateStatus({{ $selectedOrder->id }}, 'cancelled')"
                                >
                                    إلغاء
                                </flux:button>

                            </div>

                            <div class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700">

                                <flux:button
                                    size="sm"
                                    variant="subtle"
                                    icon="chat-bubble-left-right"
                                    wire:click="sendWhatsAppNotification({{ $selectedOrder->id }})"
                                    class="w-full text-emerald-600 dark:text-emerald-400"
                                >
                                    إرسال حالة الطلب عبر واتساب
                                </flux:button>

                            </div>

                        </div>

                    </div>

                    {{-- Products --}}
                    <div>

                        <div class="mb-3 flex items-center justify-between">

                            <flux:heading size="sm">
                                المنتجات
                            </flux:heading>

                            <span class="text-xs text-zinc-500">
                                {{ $selectedOrder->items->count() }} منتجات
                            </span>

                        </div>

                        <div class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-800">

                            <div class="overflow-x-auto">

                                <table class="w-full min-w-[600px] text-right text-sm">

                                    <thead class="bg-zinc-50 dark:bg-zinc-800/60">

                                        <tr>

                                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                                المنتج
                                            </th>

                                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                                السعر
                                            </th>

                                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                                الكمية
                                            </th>

                                            <th class="px-4 py-3 font-semibold text-zinc-500">
                                                الإجمالي
                                            </th>

                                        </tr>

                                    </thead>

                                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">

                                        @forelse($selectedOrder->items as $item)

                                            <tr>

                                                <td class="px-4 py-3 font-semibold text-zinc-900 dark:text-white">
                                                    {{ $item->product?->name ?? 'منتج غير محدد' }}
                                                </td>

                                                <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">

                                                    {{ number_format(
                                                        (float) $item->unit_price,
                                                        2
                                                    ) }}

                                                    ₪

                                                </td>

                                                <td class="px-4 py-3 font-bold">
                                                    {{ $item->quantity }}
                                                </td>

                                                <td class="px-4 py-3 font-black text-emerald-600 dark:text-emerald-400">

                                                    {{ number_format(
                                                        (float) $item->total_price,
                                                        2
                                                    ) }}

                                                    ₪

                                                </td>

                                            </tr>

                                        @empty

                                            <tr>

                                                <td
                                                    colspan="4"
                                                    class="px-4 py-8 text-center text-zinc-500"
                                                >
                                                    لا توجد منتجات في الطلب.
                                                </td>

                                            </tr>

                                        @endforelse

                                    </tbody>

                                </table>

                            </div>

                        </div>

                    </div>

                    {{-- Summary --}}
                    <div class="rounded-2xl bg-zinc-900 p-5 text-white dark:bg-zinc-800">

                        <div class="flex items-center justify-between">

                            <span class="text-sm text-zinc-300">
                                الإجمالي
                            </span>

                            <span class="text-2xl font-black">

                                {{ number_format(
                                    (float) $selectedOrder->total,
                                    2
                                ) }}

                                <span class="text-sm text-zinc-400">
                                    ₪
                                </span>

                            </span>

                        </div>

                        <div class="mt-3 flex items-center justify-between border-t border-white/10 pt-3 text-xs">

                            <span class="text-zinc-400">
                                حالة الدفع
                            </span>

                            <span>

                                @if($selectedOrder->payment_status === 'paid')

                                    مدفوع

                                @elseif($selectedOrder->payment_status === 'partial')

                                    مدفوع جزئيًا

                                @else

                                    غير مدفوع

                                @endif

                            </span>

                        </div>

                    </div>

                </div>

            @endif

        </flux:modal>

    </div>

</flux:main>
