<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Order;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

new class extends Component {
    use WithPagination;

    // =========================
    // Filters
    // =========================

    public string $search = '';
    public string $date_from = '';
    public string $date_to = '';
    public string $payment_status = '';

    // =========================
    // Modal
    // =========================

    public ?Order $selectedOrder = null;
    public bool $showDetailsModal = false;

    // =========================
    // Mount
    // =========================

    public function mount()
    {
        $this->date_from = Carbon::today()->format('Y-m-d');
        $this->date_to = Carbon::today()->format('Y-m-d');
    }

    // =========================
    // Pagination reset
    // =========================

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedDateFrom()
    {
        $this->resetPage();
    }

    public function updatedDateTo()
    {
        $this->resetPage();
    }

    public function updatedPaymentStatus()
    {
        $this->resetPage();
    }

    // =========================
    // Reset filters
    // =========================

    public function resetFilters()
    {
        $this->reset([
            'search',
            'payment_status',
        ]);

        $this->date_from = Carbon::today()->format('Y-m-d');
        $this->date_to = Carbon::today()->format('Y-m-d');

        $this->resetPage();
    }

    // =========================
    // View order details
    // =========================

    public function viewOrderDetails($orderId)
    {
        $tenantId = session('active_tenant_id');

        if (!$tenantId) {
            return;
        }

        $this->selectedOrder = Order::with([
            'items.product',
        ])
            ->where('tenant_id', $tenantId)
            ->findOrFail($orderId);

        $this->showDetailsModal = true;
    }

    // =========================
    // Close modal
    // =========================

    public function closeModal()
    {
        $this->showDetailsModal = false;
        $this->selectedOrder = null;
    }

    // =========================
    // Render
    // =========================

    public function render()
    {
        $user = Auth::user();

        $tenantId = session('active_tenant_id');

        // تحديد الفرع الحالي
        $branchId = $user->branch_id
            ?? session('active_branch_id')
            ?? Branch::where('tenant_id', $tenantId)->value('id');

        // =========================
        // Base Query
        // =========================

        $baseQuery = Order::where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('type', 'pos')

            // البحث برقم الفاتورة
            ->when($this->search !== '', function ($query) {
                $search = trim($this->search);

                $query->where(function ($q) use ($search) {
                    $q->where(
                        'invoice_number',
                        'like',
                        '%' . $search . '%'
                    );
                });
            })

            // من تاريخ
            ->when($this->date_from, function ($query) {
                $query->whereDate(
                    'created_at',
                    '>=',
                    $this->date_from
                );
            })

            // إلى تاريخ
            ->when($this->date_to, function ($query) {
                $query->whereDate(
                    'created_at',
                    '<=',
                    $this->date_to
                );
            })

            // حالة الدفع
            ->when(
                $this->payment_status !== ''
                && $this->payment_status !== 'all',
                function ($query) {
                    $query->where(
                        'payment_status',
                        $this->payment_status
                    );
                }
            );

        // =========================
        // Statistics
        // =========================

        $totalSales = (float) (
            clone $baseQuery
        )->sum('total');

        $ordersCount = (int) (
            clone $baseQuery
        )->count();

        $avgOrderValue = $ordersCount > 0
            ? ($totalSales / $ordersCount)
            : 0.0;

        // =========================
        // Orders
        // =========================

        $orders = (clone $baseQuery)
            ->with([
                'items.product',
            ])
            ->latest()
            ->paginate(15);

        return $this->view([
            'orders'        => $orders,
            'totalSales'    => $totalSales,
            'ordersCount'   => $ordersCount,
            'avgOrderValue' => $avgOrderValue,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="p-6 bg-slate-50 font-sans space-y-6">

    {{-- =========================
         Page Header
    ========================== --}}

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">

        <div>
            <h1 class="text-2xl font-black text-slate-900">
                سجل ومبيعات نقاط البيع
            </h1>

            <p class="text-xs text-slate-500 mt-1">
                عرض وتحليل جميع مبيعات الفرع الحالي
            </p>
        </div>

        <div class="flex items-center gap-2">

            <button
                wire:click="resetFilters"
                type="button"
                class="px-4 py-2.5 bg-slate-200 hover:bg-slate-300
                       text-slate-700 font-bold text-xs rounded-xl
                       transition-all flex items-center gap-1.5"
            >
                <flux:icon
                    icon="arrow-path"
                    class="w-4 h-4"
                />

                <span>
                    إعادة ضبط الفلاتر
                </span>
            </button>

        </div>
    </div>


    {{-- =========================
         Filters
    ========================== --}}

    <div
        class="bg-white border border-slate-200
               p-5 rounded-2xl shadow-sm space-y-4"
    >

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">

            {{-- Search --}}

            <div>

                <label
                    class="block text-xs font-bold
                           text-slate-700 mb-1.5"
                >
                    بحث برقم الفاتورة:
                </label>

                <div class="relative">

                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="رقم الفاتورة..."
                        class="w-full text-sm py-2 px-3
                               bg-slate-50 border border-slate-200
                               rounded-xl focus:border-indigo-600
                               focus:outline-none"
                    >

                </div>
            </div>


            {{-- Date From --}}

            <div>

                <label
                    class="block text-xs font-bold
                           text-slate-700 mb-1.5"
                >
                    من تاريخ:
                </label>

                <input
                    type="date"
                    wire:model.live="date_from"
                    class="w-full text-sm py-2 px-3
                           bg-slate-50 border border-slate-200
                           rounded-xl focus:border-indigo-600
                           focus:outline-none"
                >

            </div>


            {{-- Date To --}}

            <div>

                <label
                    class="block text-xs font-bold
                           text-slate-700 mb-1.5"
                >
                    إلى تاريخ:
                </label>

                <input
                    type="date"
                    wire:model.live="date_to"
                    class="w-full text-sm py-2 px-3
                           bg-slate-50 border border-slate-200
                           rounded-xl focus:border-indigo-600
                           focus:outline-none"
                >

            </div>


            {{-- Payment Status --}}

            <div>

                <label
                    class="block text-xs font-bold
                           text-slate-700 mb-1.5"
                >
                    حالة الدفع:
                </label>

                <select
                    wire:model.live="payment_status"
                    class="w-full text-sm py-2 px-3
                           bg-slate-50 border border-slate-200
                           rounded-xl focus:border-indigo-600
                           focus:outline-none"
                >

                    <option value="">
                        الكل
                    </option>

                    <option value="paid">
                        مدفوع
                    </option>

                    <option value="unpaid">
                        غير مدفوع
                    </option>

                    <option value="partial">
                        مدفوع جزئياً
                    </option>

                </select>

            </div>

        </div>

    </div>


    {{-- =========================
         Statistics
    ========================== --}}

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">

        {{-- Total Sales --}}

        <div
            class="bg-white border border-slate-200
                   p-5 rounded-2xl shadow-sm
                   flex items-center justify-between"
        >

            <div class="space-y-1">

                <span
                    class="text-xs font-bold text-slate-500"
                >
                    إجمالي المبيعات المفلترة
                </span>

                <div
                    class="text-2xl font-black
                           text-emerald-600 font-mono"
                >
                    {{ number_format($totalSales, 2) }}

                    <span class="text-xs text-slate-500">
                        ر.س
                    </span>
                </div>

            </div>

            <div
                class="p-3 bg-emerald-50
                       text-emerald-600 rounded-xl"
            >

                <flux:icon
                    icon="banknotes"
                    class="w-7 h-7"
                />

            </div>

        </div>


        {{-- Orders Count --}}

        <div
            class="bg-white border border-slate-200
                   p-5 rounded-2xl shadow-sm
                   flex items-center justify-between"
        >

            <div class="space-y-1">

                <span
                    class="text-xs font-bold text-slate-500"
                >
                    عدد الفواتير
                </span>

                <div
                    class="text-2xl font-black
                           text-indigo-600 font-mono"
                >
                    {{ $ordersCount }}

                    <span class="text-xs text-slate-500">
                        فاتورة
                    </span>
                </div>

            </div>

            <div
                class="p-3 bg-indigo-50
                       text-indigo-600 rounded-xl"
            >

                <flux:icon
                    icon="document-text"
                    class="w-7 h-7"
                />

            </div>

        </div>


        {{-- Average --}}

        <div
            class="bg-white border border-slate-200
                   p-5 rounded-2xl shadow-sm
                   flex items-center justify-between"
        >

            <div class="space-y-1">

                <span
                    class="text-xs font-bold text-slate-500"
                >
                    متوسط الفاتورة
                </span>

                <div
                    class="text-2xl font-black
                           text-slate-800 font-mono"
                >
                    {{ number_format($avgOrderValue, 2) }}

                    <span class="text-xs text-slate-500">
                        ر.س
                    </span>
                </div>

            </div>

            <div
                class="p-3 bg-slate-100
                       text-slate-600 rounded-xl"
            >

                <flux:icon
                    icon="calculator"
                    class="w-7 h-7"
                />

            </div>

        </div>

    </div>


    {{-- =========================
         Orders Table
    ========================== --}}

    <div
        class="bg-white border border-slate-200
               rounded-2xl shadow-sm overflow-hidden"
    >

        <div
            class="p-4 border-b border-slate-100
                   flex items-center justify-between"
        >

            <h3
                class="font-bold text-slate-800 text-base"
            >
                سجل الفواتير المفلترة
            </h3>

            <span
                class="text-xs bg-slate-100
                       text-slate-600 font-bold
                       px-3 py-1 rounded-full"
            >
                {{ $orders->total() }} فاتورة
            </span>

        </div>


        <div class="overflow-x-auto">

            <table
                class="w-full text-right text-sm"
            >

                <thead
                    class="bg-slate-50 border-b
                           border-slate-200
                           font-bold text-slate-600"
                >

                    <tr>

                        <th class="p-4">
                            رقم الفاتورة
                        </th>

                        <th class="p-4">
                            التاريخ والوقت
                        </th>

                        <th class="p-4">
                            المبلغ الإجمالي
                        </th>

                        <th class="p-4">
                            المدفوع
                        </th>

                        <th class="p-4">
                            حالة الدفع
                        </th>

                        <th class="p-4 text-center">
                            التفاصيل
                        </th>

                    </tr>

                </thead>


                <tbody
                    class="divide-y divide-slate-100"
                >

                    @forelse($orders as $order)

                        <tr
                            class="hover:bg-slate-50/80
                                   transition-colors"
                        >

                            {{-- Invoice Number --}}

                            <td
                                class="p-4 font-mono
                                       font-bold text-indigo-600"
                            >
                                {{ $order->invoice_number }}
                            </td>


                            {{-- Date --}}

                            <td
                                class="p-4 font-mono
                                       text-slate-500 text-xs"
                            >
                                {{ $order->created_at?->format('Y-m-d | h:i A') }}
                            </td>


                            {{-- Total --}}

                            <td
                                class="p-4 font-mono
                                       font-black text-slate-900"
                            >
                                {{ number_format($order->total, 2) }}
                                ر.س
                            </td>


                            {{-- Paid --}}

                            <td
                                class="p-4 font-mono
                                       text-emerald-600
                                       font-bold"
                            >
                                {{ number_format($order->paid_amount ?? 0, 2) }}
                                ر.س
                            </td>


                            {{-- Payment Status --}}

                            <td class="p-4">

                                @if($order->payment_status === 'paid')

                                    <span
                                        class="inline-flex items-center
                                               px-2.5 py-1 rounded-full
                                               text-xs font-bold
                                               bg-emerald-50
                                               text-emerald-700
                                               border border-emerald-200"
                                    >
                                        مدفوع
                                    </span>

                                @elseif($order->payment_status === 'unpaid')

                                    <span
                                        class="inline-flex items-center
                                               px-2.5 py-1 rounded-full
                                               text-xs font-bold
                                               bg-rose-50
                                               text-rose-700
                                               border border-rose-200"
                                    >
                                        غير مدفوع
                                    </span>

                                @elseif($order->payment_status === 'partial')

                                    <span
                                        class="inline-flex items-center
                                               px-2.5 py-1 rounded-full
                                               text-xs font-bold
                                               bg-amber-50
                                               text-amber-700
                                               border border-amber-200"
                                    >
                                        مدفوع جزئياً
                                    </span>

                                @else

                                    <span
                                        class="inline-flex items-center
                                               px-2.5 py-1 rounded-full
                                               text-xs font-bold
                                               bg-slate-100
                                               text-slate-600
                                               border border-slate-200"
                                    >
                                        {{ $order->payment_status ?? 'غير محدد' }}
                                    </span>

                                @endif

                            </td>


                            {{-- Details --}}

                            <td
                                class="p-4 text-center"
                            >

                                <button
                                    wire:click="viewOrderDetails({{ $order->id }})"
                                    type="button"
                                    class="p-2 text-slate-500
                                           hover:text-indigo-600
                                           hover:bg-indigo-50
                                           rounded-xl transition-all"
                                >

                                    <flux:icon
                                        icon="eye"
                                        class="w-5 h-5"
                                    />

                                </button>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td
                                colspan="6"
                                class="py-16 text-center
                                       text-slate-400"
                            >

                                <div
                                    class="flex flex-col
                                           items-center
                                           justify-center
                                           space-y-2"
                                >

                                    <flux:icon
                                        icon="document-magnifying-glass"
                                        class="w-12 h-12
                                               stroke-1
                                               text-slate-300"
                                    />

                                    <p
                                        class="font-semibold
                                               text-slate-500"
                                    >
                                        لا توجد مبيعات تتطابق
                                        مع شروط البحث والفلترة
                                    </p>

                                </div>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>


        {{-- Pagination --}}

        @if($orders->hasPages())

            <div
                class="p-4 border-t
                       border-slate-100"
            >
                {{ $orders->links() }}
            </div>

        @endif

    </div>


    {{-- =========================
         Details Modal
    ========================== --}}

    @if($showDetailsModal && $selectedOrder)

        <div
            class="fixed inset-0 z-50
                   flex items-center justify-center
                   bg-slate-900/40
                   backdrop-blur-sm p-4"
        >

            <div
                class="bg-white border
                       border-slate-200
                       rounded-2xl shadow-xl
                       w-full max-w-2xl
                       overflow-hidden
                       flex flex-col
                       max-h-[90vh]"
            >

                {{-- Modal Header --}}

                <div
                    class="p-4 border-b
                           border-slate-100
                           flex items-center
                           justify-between
                           bg-slate-50"
                >

                    <div>

                        <h3
                            class="font-bold
                                   text-slate-900"
                        >
                            تفاصيل الفاتورة
                            #{{ $selectedOrder->invoice_number }}
                        </h3>

                        <span
                            class="text-xs
                                   text-slate-500
                                   font-mono"
                        >
                            {{ $selectedOrder->created_at?->format('Y-m-d h:i A') }}
                        </span>

                    </div>


                    <button
                        wire:click="closeModal"
                        type="button"
                        class="p-1.5
                               text-slate-400
                               hover:text-slate-700
                               rounded-lg"
                    >

                        <flux:icon
                            icon="x-mark"
                            class="w-5 h-5"
                        />

                    </button>

                </div>


                {{-- Modal Content --}}

                <div
                    class="p-6 overflow-y-auto
                           space-y-4"
                >

                    <table
                        class="w-full
                               text-right text-sm"
                    >

                        <thead
                            class="bg-slate-100
                                   font-bold
                                   text-slate-700"
                        >

                            <tr>

                                <th class="p-3 rounded-r-lg">
                                    المنتج
                                </th>

                                <th class="p-3 text-center">
                                    الكمية
                                </th>

                                <th class="p-3">
                                    سعر الوحدة
                                </th>

                                <th
                                    class="p-3 rounded-l-lg
                                           text-left"
                                >
                                    الإجمالي
                                </th>

                            </tr>

                        </thead>


                        <tbody
                            class="divide-y
                                   divide-slate-100"
                        >

                            @foreach($selectedOrder->items as $item)

                                <tr>

                                    <td
                                        class="p-3
                                               font-semibold
                                               text-slate-800"
                                    >
                                        {{ $item->product->name ?? 'منتج محذوف' }}
                                    </td>

                                    <td
                                        class="p-3 text-center
                                               font-mono font-bold"
                                    >
                                        {{ $item->quantity }}
                                    </td>

                                    <td
                                        class="p-3 font-mono"
                                    >
                                        {{ number_format($item->unit_price, 2) }}
                                        ر.س
                                    </td>

                                    <td
                                        class="p-3 font-mono
                                               font-bold
                                               text-emerald-600
                                               text-left"
                                    >
                                        {{ number_format($item->total_price, 2) }}
                                        ر.س
                                    </td>

                                </tr>

                            @endforeach

                        </tbody>

                    </table>


                    {{-- Totals --}}

                    <div
                        class="border-t
                               border-slate-200
                               pt-4 space-y-2
                               text-sm font-semibold"
                    >

                        <div
                            class="flex justify-between
                                   text-slate-600"
                        >

                            <span>
                                المجموع الفرعي:
                            </span>

                            <span class="font-mono">
                                {{ number_format($selectedOrder->subtotal ?? 0, 2) }}
                                ر.س
                            </span>

                        </div>


                        <div
                            class="flex justify-between
                                   text-slate-600"
                        >

                            <span>
                                الخصم:
                            </span>

                            <span class="font-mono">
                                {{ number_format($selectedOrder->discount ?? 0, 2) }}
                                ر.س
                            </span>

                        </div>


                        <div
                            class="flex justify-between
                                   text-lg font-black
                                   text-slate-900
                                   border-t
                                   border-slate-100
                                   pt-2"
                        >

                            <span>
                                المبلغ الإجمالي:
                            </span>

                            <span
                                class="font-mono
                                       text-emerald-600"
                            >
                                {{ number_format($selectedOrder->total ?? 0, 2) }}
                                ر.س
                            </span>

                        </div>

                    </div>

                </div>


                {{-- Modal Footer --}}

                <div
                    class="p-4 border-t
                           border-slate-100
                           bg-slate-50
                           flex justify-end
                           gap-3"
                >

                    <button
                        onclick="window.print()"
                        type="button"
                        class="px-4 py-2
                               bg-slate-800
                               hover:bg-slate-900
                               text-white text-xs
                               font-bold rounded-xl
                               transition-all
                               flex items-center gap-1.5"
                    >

                        <flux:icon
                            icon="printer"
                            class="w-4 h-4"
                        />

                        <span>
                            طباعة
                        </span>

                    </button>


                    <button
                        wire:click="closeModal"
                        type="button"
                        class="px-4 py-2
                               bg-white
                               border
                               border-slate-300
                               text-slate-700
                               text-xs
                               font-bold
                               rounded-xl
                               hover:bg-slate-100
                               transition-all"
                    >
                        إغلاق
                    </button>

                </div>

            </div>

        </div>

    @endif

</flux:main>
