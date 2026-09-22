<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Product;
use App\Models\Party;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    // =========================================================
    // حقول السطر النشط للإدخال السريع
    // =========================================================

    public string $rowSearch = '';
    public ?int $selectedProductId = null;
    public string $selectedProductName = '';
    public float $selectedPrice = 0;
    public float $selectedCost = 0;
    public int $selectedQuantity = 1;

    public array $cart = [];

    // =========================================================
    // بيانات الفاتورة
    // =========================================================

    public ?int $currentOrderId = null;
    public ?string $invoiceNumber = null;

    // يبقى customer_id في orders حاليًا
    public ?int $selectedCustomerId = null;

    public ?string $notes = '';
    public float $discount = 0;
    public float $taxPercent = 16;

    public string $searchInvoiceQuery = '';

    // =========================================================
    // مودال استعلام الفواتير
    // =========================================================

    public bool $showInvoicesModal = false;
    public string $modalSearch = '';

    // =========================================================
    // Tenant
    // =========================================================

    private function getTenantId(): ?int
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        if (!empty($user->tenant_id)) {
            return (int) $user->tenant_id;
        }

        if (method_exists($user, 'tenants')) {
            $tenant = $user->tenants()->first();

            if ($tenant) {
                return (int) $tenant->id;
            }
        }

        $sessionTenantId = session('active_tenant_id');

        return $sessionTenantId
            ? (int) $sessionTenantId
            : null;
    }

    // =========================================================
    // Invoice Modal
    // =========================================================

    public function openInvoicesModal(): void
    {
        $this->showInvoicesModal = true;
    }

    public function closeInvoicesModal(): void
    {
        $this->showInvoicesModal = false;
    }

    // =========================================================
    // تحميل فاتورة
    // =========================================================

    public function loadOrder(Order $order): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            session()->flash(
                'error',
                'لم يتم تحديد المتجر الحالي.'
            );

            return;
        }

        // حماية من تحميل فاتورة من Tenant آخر
        if ((int) $order->tenant_id !== $tenantId) {
            session()->flash(
                'error',
                'لا يمكنك تحميل هذه الفاتورة.'
            );

            return;
        }

        $order->load([
            'items.product',
        ]);

        $this->currentOrderId = $order->id;
        $this->invoiceNumber = $order->invoice_number;

        // يبقى customer_id لأننا لم نغيّر تصميم orders بعد
        $this->selectedCustomerId = $order->customer_id;

        $this->notes = $order->notes;
        $this->discount = (float) ($order->discount ?? 0);

        $this->cart = [];

        foreach ($order->items as $item) {
            $cartKey = $item->product_id . '_' . microtime(true);

            $this->cart[$cartKey] = [
                'id'        => $item->product_id,
                'name'      => $item->product?->name ?? 'مادة محذوفة',
                'price'     => (float) $item->unit_price,
                'cost'      => (float) $item->cost_price,
                'quantity'  => (int) $item->quantity,
                'warehouse' => 'الرئيسي',
                'unit'      => 'حبة',
                'discount'  => (float) ($item->discount ?? 0),
            ];
        }

        $this->closeInvoicesModal();
    }

    // =========================================================
    // البحث عن فاتورة
    // =========================================================

    public function searchInvoice(): void
    {
        $search = trim($this->searchInvoiceQuery);

        if ($search === '') {
            return;
        }

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            session()->flash(
                'error',
                'لم يتم تحديد المتجر الحالي.'
            );

            return;
        }

        $order = Order::where('tenant_id', $tenantId)
            ->where(
                'invoice_number',
                'like',
                '%' . $search . '%'
            )
            ->latest()
            ->first();

        if ($order) {
            $this->loadOrder($order);

            $this->searchInvoiceQuery = '';
        } else {
            session()->flash(
                'error',
                'لم يتم العثور على فاتورة بهذا الرقم.'
            );
        }
    }

    // =========================================================
    // الفاتورة السابقة
    // =========================================================

    public function previousInvoice(): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            return;
        }

        $query = Order::where('tenant_id', $tenantId);

        if ($this->currentOrderId) {
            $query->where(
                'id',
                '<',
                $this->currentOrderId
            );
        }

        $order = $query
            ->orderBy('id', 'desc')
            ->first();

        if ($order) {
            $this->loadOrder($order);
        } else {
            session()->flash(
                'error',
                'هذه هي أول فاتورة، لا توجد فاتورة سابقة.'
            );
        }
    }

    // =========================================================
    // الفاتورة التالية
    // =========================================================

    public function nextInvoice(): void
    {
        if (!$this->currentOrderId) {
            return;
        }

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            return;
        }

        $order = Order::where('tenant_id', $tenantId)
            ->where(
                'id',
                '>',
                $this->currentOrderId
            )
            ->orderBy('id', 'asc')
            ->first();

        if ($order) {
            $this->loadOrder($order);
        } else {
            $this->resetInvoice();
        }
    }

    // =========================================================
    // فاتورة جديدة
    // =========================================================

    public function resetInvoice(): void
    {
        $this->currentOrderId = null;
        $this->invoiceNumber = null;

        $this->cart = [];

        $this->selectedCustomerId = null;
        $this->notes = '';
        $this->discount = 0;

        $this->rowSearch = '';
        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPrice = 0;
        $this->selectedCost = 0;
        $this->selectedQuantity = 1;

        $this->dispatch('focus-search');
    }

    // =========================================================
    // اختيار منتج
    // =========================================================

    public function selectProduct(int $productId): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            return;
        }

        $product = Product::where('tenant_id', $tenantId)
            ->find($productId);

        if (!$product) {
            return;
        }

        $this->selectedProductId = $product->id;
        $this->selectedProductName = $product->name;

        $this->selectedPrice = (float) (
            $product->wholesale_price
            ?? $product->retail_price
            ?? 0
        );

        $this->selectedCost = (float) (
            $product->cost_price
            ?? 0
        );

        $this->selectedQuantity = 1;
        $this->rowSearch = $product->name;

        $this->dispatch('focus-quantity');
    }

    // =========================================================
    // إضافة السطر للسلة
    // =========================================================

    public function commitRowToCart(): void
    {
        if (!$this->selectedProductId) {
            $this->reset([
                'selectedProductId',
                'selectedProductName',
                'selectedPrice',
                'selectedCost',
                'selectedQuantity',
                'rowSearch',
            ]);

            $this->dispatch('focus-search');

            return;
        }

        $quantity = max(
            1,
            (int) $this->selectedQuantity
        );

        $price = max(
            0,
            (float) $this->selectedPrice
        );

        $cost = max(
            0,
            (float) $this->selectedCost
        );

        $cartKey = $this->selectedProductId
            . '_'
            . microtime(true);

        $this->cart[$cartKey] = [
            'id'        => $this->selectedProductId,
            'name'      => $this->selectedProductName,
            'price'     => $price,
            'cost'      => $cost,
            'quantity'  => $quantity,
            'warehouse' => 'الرئيسي',
            'unit'      => 'حبة',
            'discount'  => 0,
        ];

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPrice = 0;
        $this->selectedCost = 0;
        $this->selectedQuantity = 1;
        $this->rowSearch = '';

        $this->dispatch('focus-search');
    }

    // =========================================================
    // حذف منتج
    // =========================================================

    public function removeItem(string $cartKey): void
    {
        unset($this->cart[$cartKey]);
    }

    // =========================================================
    // تعديل الكمية
    // =========================================================

    public function updateItemQuantity(
        string $cartKey,
        $quantity
    ): void {
        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $qty = (int) $quantity;

        if ($qty <= 0) {
            unset($this->cart[$cartKey]);

            return;
        }

        $this->cart[$cartKey]['quantity'] = $qty;
    }

    // =========================================================
    // تعديل السعر
    // =========================================================

    public function updateItemPrice(
        string $cartKey,
        $price
    ): void {
        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $this->cart[$cartKey]['price'] = max(
            0,
            (float) $price
        );
    }

    // =========================================================
    // تعديل خصم السطر
    // =========================================================

    public function updateItemDiscount(
        string $cartKey,
        $discount
    ): void {
        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $this->cart[$cartKey]['discount'] = max(
            0,
            (float) $discount
        );
    }

    // =========================================================
    // حفظ الفاتورة
    // =========================================================

    public function completeSale(
        bool $shouldPrint = false
    ): void {
        if (empty($this->cart)) {
            session()->flash(
                'error',
                'لا توجد مواد في الفاتورة.'
            );

            return;
        }

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            session()->flash(
                'error',
                'لم يتم تحديد المتجر الحالي.'
            );

            return;
        }

        // =====================================================
        // العميل / الطرف
        // =====================================================

        $customer = null;

        if ($this->selectedCustomerId) {
            $customer = Party::where(
                'tenant_id',
                $tenantId
            )
                ->whereIn(
                    'type',
                    ['customer', 'both']
                )
                ->where(
                    'is_active',
                    true
                )
                ->find(
                    $this->selectedCustomerId
                );
        }

        // =====================================================
        // حساب المجاميع
        // =====================================================

        $subtotal = 0;
        $totalCost = 0;

        foreach ($this->cart as $item) {

            $price = (float) $item['price'];
            $quantity = (int) $item['quantity'];
            $lineDiscount = (float) $item['discount'];
            $cost = (float) $item['cost'];

            $itemTotal =
                ($price * $quantity)
                - $lineDiscount;

            $subtotal += $itemTotal;

            $totalCost +=
                $cost * $quantity;
        }

        $invoiceDiscount = max(
            0,
            (float) $this->discount
        );

        $taxableAmount = max(
            0,
            $subtotal - $invoiceDiscount
        );

        $taxAmount =
            $taxableAmount
            * ($this->taxPercent / 100);

        $grandTotal =
            $taxableAmount
            + $taxAmount;

        $order = null;

        // =====================================================
        // Database Transaction
        // =====================================================

        DB::transaction(
            function () use (
                $tenantId,
                $subtotal,
                $grandTotal,
                $taxAmount,
                $totalCost,
                $customer,
                &$order
            ) {

                $order = Order::updateOrCreate(
                    [
                        'id' => $this->currentOrderId,
                    ],
                    [
                        'tenant_id'      => $tenantId,

                        /*
                         * ملاحظة:
                         * يبقى customer_id حاليًا لأننا لم
                         * نغيّر Migration الخاصة بـ orders.
                         */
                        'customer_id'    => $customer?->id,

                        'customer_name'  =>
                            $customer?->name
                            ?? 'زبون عام',

                        'customer_phone' =>
                            $customer?->phone,

                        'invoice_number' =>
                            $this->invoiceNumber
                            ?? (
                                'INV-'
                                . date('Ymd')
                                . '-'
                                . rand(100, 999)
                            ),

                        'type'           => 'wholesale',

                        'status'         => 'completed',

                        'subtotal'       => $subtotal,

                        'tax_amount'     => $taxAmount,

                        'discount'       =>
                            (float) $this->discount,

                        'total'          => $grandTotal,

                        'total_cost'     => $totalCost,

                        'total_profit'   =>
                            $grandTotal
                            - $totalCost,

                        'paid_amount'    => $grandTotal,

                        'payment_status' => 'paid',

                        'notes'          => $this->notes,
                    ]
                );

                // =================================================
                // حذف البنود القديمة عند تعديل الفاتورة
                // =================================================

                if ($this->currentOrderId) {
                    OrderItem::where(
                        'order_id',
                        $order->id
                    )->delete();
                }

                // =================================================
                // إضافة البنود
                // =================================================

                foreach ($this->cart as $item) {

                    $itemTotal =
                        (
                            (float) $item['price']
                            * (int) $item['quantity']
                        )
                        - (float) $item['discount'];

                    $itemTotalCost =
                        (float) $item['cost']
                        * (int) $item['quantity'];

                    OrderItem::create([
                        'tenant_id'   => $tenantId,

                        'order_id'    => $order->id,

                        'product_id'  => $item['id'],

                        'quantity'    => $item['quantity'],

                        'unit_price'  => $item['price'],

                        'discount'    => $item['discount'],

                        'total_price' => $itemTotal,

                        'cost_price'  => $item['cost'],

                        'total_cost'  => $itemTotalCost,
                    ]);
                }
            }
        );

        // =========================================================
        // الطباعة
        // =========================================================

        if ($shouldPrint && $order) {

            $printableOrder = [
                'store_name' =>
                    auth()->user()->name
                    ?? 'فاتورة مبيعات',

                'invoice_no' =>
                    $order->invoice_number,

                'customer_name' =>
                    $order->customer_name,

                'date' =>
                    $order->created_at
                        ->format('Y-m-d H:i'),

                'items' =>
                    array_values($this->cart),

                'subtotal' =>
                    $subtotal,

                'tax' =>
                    $taxAmount,

                'discount' =>
                    $this->discount,

                'total' =>
                    $grandTotal,

                'notes' =>
                    $this->notes,
            ];

            $this->dispatch(
                'do-kiosk-print',
                data: $printableOrder
            );
        }

        $this->resetInvoice();

        session()->flash(
            'message',
            'تم حفظ الفاتورة بنجاح!'
        );
    }

    // =========================================================
    // Render
    // =========================================================

    public function render()
    {
        $tenantId = $this->getTenantId();

        $searchResults = collect();

        // =====================================================
        // البحث عن المنتجات
        // =====================================================

        if (
            $tenantId
            && trim($this->rowSearch) !== ''
            && !$this->selectedProductId
        ) {

            $search = trim(
                $this->rowSearch
            );

            $searchResults = Product::where(
                'tenant_id',
                $tenantId
            )
                ->where(
                    function ($sub) use ($search) {

                        $sub->where(
                            'name',
                            'like',
                            "%{$search}%"
                        )
                        ->orWhereHas(
                            'barcodes',
                            function ($b) use ($search) {
                                $b->where(
                                    'barcode',
                                    'like',
                                    "%{$search}%"
                                );
                            }
                        );

                        if (is_numeric($search)) {
                            $sub->orWhere(
                                'id',
                                (int) $search
                            );
                        }
                    }
                )
                ->limit(6)
                ->get();
        }

        // =====================================================
        // قائمة الفواتير
        // =====================================================

        $invoicesList = collect();

        if ($tenantId && $this->showInvoicesModal) {

            $q = Order::where(
                'tenant_id',
                $tenantId
            );

            if (
                trim($this->modalSearch) !== ''
            ) {

                $term = trim(
                    $this->modalSearch
                );

                $q->where(
                    function ($sub) use ($term) {

                        $sub->where(
                            'invoice_number',
                            'like',
                            "%{$term}%"
                        )
                        ->orWhere(
                            'customer_name',
                            'like',
                            "%{$term}%"
                        );
                    }
                );
            }

            $invoicesList = $q
                ->orderBy('id', 'desc')
                ->limit(30)
                ->get();
        }

        // =====================================================
        // العملاء من جدول parties
        // =====================================================

        $customers = collect();

        if ($tenantId) {

            $customers = Party::where(
                'tenant_id',
                $tenantId
            )
                ->whereIn(
                    'type',
                    ['customer', 'both']
                )
                ->where(
                    'is_active',
                    true
                )
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'phone',
                ]);
        }

        // =====================================================
        // الحسابات
        // =====================================================

        $subtotal = array_reduce(
            $this->cart,
            function ($sum, $item) {

                return $sum
                    + (
                        (
                            (float) $item['price']
                            * (int) $item['quantity']
                        )
                        - (float) $item['discount']
                    );
            },
            0
        );

        $taxableAmount = max(
            0,
            $subtotal - $this->discount
        );

        $taxAmount =
            $taxableAmount
            * ($this->taxPercent / 100);

        $grandTotal =
            $taxableAmount
            + $taxAmount;

        return $this->view([
            'searchResults' => $searchResults,
            'customers'     => $customers,
            'invoicesList'  => $invoicesList,
            'subtotal'      => $subtotal,
            'taxAmount'     => $taxAmount,
            'grandTotal'    => $grandTotal,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">

<div
    class="min-h-screen bg-zinc-100 text-zinc-900
           dark:bg-zinc-950 dark:text-zinc-100
           p-2 font-mono text-xs"
    dir="rtl"
    x-data="gridNavigation()"
>

    <div class="max-w-7xl mx-auto space-y-2">

        {{-- =====================================================
             Messages
        ====================================================== --}}

        @if (session()->has('message'))

            <div
                class="p-2 bg-emerald-100
                       text-emerald-800
                       border border-emerald-300
                       rounded"
            >
                {{ session('message') }}
            </div>

        @endif


        @if (session()->has('error'))

            <div
                class="p-2 bg-red-100
                       text-red-800
                       border border-red-300
                       rounded"
            >
                {{ session('error') }}
            </div>

        @endif


        {{-- =====================================================
             شريط التحكم والتنقل
        ====================================================== --}}

        <div
            class="bg-indigo-950 text-white p-2
                   rounded flex flex-wrap
                   items-center justify-between
                   gap-2 shadow"
        >

            <div class="flex items-center gap-2">

                <span class="font-bold text-amber-400">

                    {{
                        $currentOrderId
                            ? "عرض/تعديل فاتورة رقم: {$invoiceNumber}"
                            : "جديد: فاتورة مبيعات جديدة"
                    }}

                </span>


                @if($currentOrderId)

                    <button
                        wire:click="resetInvoice"
                        type="button"
                        class="bg-emerald-600
                               hover:bg-emerald-700
                               text-white px-2 py-1
                               rounded text-[11px]
                               font-bold"
                    >
                        + فاتورة جديدة
                    </button>

                @endif

            </div>


            <div class="flex items-center gap-1.5">

                <button
                    wire:click="openInvoicesModal"
                    type="button"
                    class="bg-amber-500
                           hover:bg-amber-600
                           text-zinc-950
                           px-2.5 py-1
                           rounded font-bold
                           text-[11px]
                           flex items-center
                           gap-1 shadow"
                >
                    🔍 استعلام الفواتير
                </button>


                <div
                    class="h-4 w-px
                           bg-indigo-700 mx-0.5"
                ></div>


                <form
                    wire:submit.prevent="searchInvoice"
                    class="flex items-center gap-1"
                >

                    <input
                        type="text"
                        wire:model="searchInvoiceQuery"
                        placeholder="رقم الفاتورة..."
                        class="bg-zinc-800
                               border border-zinc-700
                               text-white px-2 py-1
                               rounded text-xs
                               focus:outline-none
                               focus:border-amber-400
                               w-32"
                    />

                    <button
                        type="submit"
                        class="bg-indigo-700
                               hover:bg-indigo-600
                               px-2 py-1
                               rounded font-bold
                               text-[11px]"
                    >
                        بحث
                    </button>

                </form>


                <div
                    class="h-4 w-px
                           bg-indigo-700 mx-0.5"
                ></div>


                <button
                    wire:click="previousInvoice"
                    type="button"
                    title="الفاتورة السابقة"
                    class="bg-zinc-800
                           hover:bg-zinc-700
                           text-white px-2.5 py-1
                           rounded border
                           border-zinc-700
                           font-bold"
                >
                    ◀ السابقة
                </button>


                <button
                    wire:click="nextInvoice"
                    type="button"
                    title="الفاتورة اللاحقة"
                    class="bg-zinc-800
                           hover:bg-zinc-700
                           text-white px-2.5 py-1
                           rounded border
                           border-zinc-700
                           font-bold"
                >
                    اللاحقة ▶
                </button>

            </div>

        </div>


        {{-- =====================================================
             Modal استعلام الفواتير
        ====================================================== --}}

        @if($showInvoicesModal)

            <div
                class="fixed inset-0 z-50
                       flex items-center justify-center
                       bg-black/60
                       backdrop-blur-sm p-4"
            >

                <div
                    class="bg-white dark:bg-zinc-900
                           border-2 border-indigo-600
                           rounded-lg shadow-2xl
                           w-full max-w-3xl
                           overflow-hidden
                           flex flex-col
                           max-h-[85vh]"
                >

                    <div
                        class="bg-indigo-950
                               text-white p-3
                               flex justify-between
                               items-center"
                    >

                        <h3
                            class="font-bold text-sm
                                   text-amber-400
                                   flex items-center gap-2"
                        >
                            <span>🔍</span>
                            جدول استعلام الفواتير
                        </h3>


                        <button
                            wire:click="closeInvoicesModal"
                            type="button"
                            class="text-zinc-400
                                   hover:text-white
                                   font-bold text-base"
                        >
                            ✕
                        </button>

                    </div>


                    <div
                        class="p-2
                               bg-zinc-100
                               dark:bg-zinc-800/60
                               border-b
                               border-zinc-200
                               dark:border-zinc-700"
                    >

                        <input
                            type="text"
                            wire:model.live.debounce.200ms="modalSearch"
                            placeholder="ابحث برقم الفاتورة أو اسم الزبون..."
                            class="w-full
                                   bg-white
                                   dark:bg-zinc-900
                                   border border-zinc-300
                                   dark:border-zinc-700
                                   p-1.5 rounded text-xs
                                   focus:ring-1
                                   focus:ring-indigo-500
                                   focus:outline-none"
                        />

                    </div>


                    <div
                        class="overflow-y-auto
                               p-2 flex-1"
                    >

                        <table
                            class="w-full text-right
                                   border-collapse"
                        >

                            <thead>

                                <tr
                                    class="bg-indigo-900
                                           text-white
                                           text-[11px]"
                                >

                                    <th
                                        class="p-2
                                               border
                                               border-indigo-800
                                               text-center w-12"
                                    >
                                        #
                                    </th>

                                    <th
                                        class="p-2
                                               border
                                               border-indigo-800
                                               text-center"
                                    >
                                        رقم الفاتورة
                                    </th>

                                    <th
                                        class="p-2
                                               border
                                               border-indigo-800"
                                    >
                                        اسم الزبون
                                    </th>

                                    <th
                                        class="p-2
                                               border
                                               border-indigo-800
                                               text-center"
                                    >
                                        التاريخ والوقت
                                    </th>

                                    <th
                                        class="p-2
                                               border
                                               border-indigo-800
                                               text-center"
                                    >
                                        القيمة الإجمالية
                                    </th>

                                    <th
                                        class="p-2
                                               border
                                               border-indigo-800
                                               text-center w-16"
                                    >
                                        إجراء
                                    </th>

                                </tr>

                            </thead>


                            <tbody
                                class="divide-y
                                       divide-zinc-200
                                       dark:divide-zinc-800
                                       text-xs"
                            >

                                @forelse($invoicesList as $inv)

                                    <tr
                                        class="hover:bg-indigo-50
                                               dark:hover:bg-indigo-900/30
                                               transition-colors"
                                    >

                                        <td
                                            class="p-2 border
                                                   text-center
                                                   font-mono
                                                   text-zinc-500"
                                        >
                                            {{ $loop->iteration }}
                                        </td>


                                        <td
                                            class="p-2 border
                                                   text-center
                                                   font-bold
                                                   text-indigo-600
                                                   dark:text-indigo-400
                                                   font-mono"
                                        >
                                            {{ $inv->invoice_number }}
                                        </td>


                                        <td
                                            class="p-2 border
                                                   font-semibold"
                                        >
                                            {{ $inv->customer_name ?? 'زبون عام' }}
                                        </td>


                                        <td
                                            class="p-2 border
                                                   text-center
                                                   dir-ltr
                                                   text-zinc-500"
                                        >
                                            {{ $inv->created_at?->format('Y-m-d H:i') }}
                                        </td>


                                        <td
                                            class="p-2 border
                                                   text-center
                                                   font-bold
                                                   font-mono
                                                   text-emerald-600
                                                   dark:text-emerald-400"
                                        >
                                            {{ number_format($inv->total, 2) }}
                                        </td>


                                        <td
                                            class="p-2 border
                                                   text-center"
                                        >

                                            <button
                                                wire:click="loadOrder({{ $inv->id }})"
                                                type="button"
                                                class="bg-indigo-600
                                                       hover:bg-indigo-700
                                                       text-white px-2
                                                       py-0.5 rounded
                                                       font-bold
                                                       text-[10px]"
                                            >
                                                عرض
                                            </button>

                                        </td>

                                    </tr>

                                @empty

                                    <tr>

                                        <td
                                            colspan="6"
                                            class="p-4
                                                   text-center
                                                   text-zinc-500"
                                        >
                                            لا توجد فواتير مطابقة للبحث
                                        </td>

                                    </tr>

                                @endforelse

                            </tbody>

                        </table>

                    </div>


                    <div
                        class="p-2
                               bg-zinc-100
                               dark:bg-zinc-800/80
                               border-t
                               border-zinc-200
                               dark:border-zinc-700
                               text-left"
                    >

                        <button
                            wire:click="closeInvoicesModal"
                            type="button"
                            class="bg-zinc-600
                                   hover:bg-zinc-700
                                   text-white px-3 py-1
                                   rounded text-xs
                                   font-bold"
                        >
                            إغلاق
                        </button>

                    </div>

                </div>

            </div>

        @endif


        {{-- =====================================================
             بيانات الفاتورة
        ====================================================== --}}

        <div
            class="bg-white dark:bg-zinc-900
                   border border-zinc-300
                   dark:border-zinc-800
                   p-2 grid grid-cols-1
                   md:grid-cols-4 gap-2"
        >

            {{-- العميل --}}

            <div>

                <label
                    class="block text-[11px]
                           text-zinc-500 mb-0.5"
                >
                    العميل / الحساب
                </label>

                <select
                    wire:model="selectedCustomerId"
                    class="w-full text-xs
                           bg-zinc-50
                           dark:bg-zinc-800
                           border border-zinc-300
                           dark:border-zinc-700
                           p-1 rounded"
                >

                    <option value="">
                        -- زبون نقدي عابر --
                    </option>

                    @foreach($customers as $customer)

                        <option
                            value="{{ $customer->id }}"
                        >
                            {{ $customer->name }}

                            {{
                                $customer->phone
                                    ? "({$customer->phone})"
                                    : ''
                            }}
                        </option>

                    @endforeach

                </select>

            </div>


            {{-- المخزن --}}

            <div>

                <label
                    class="block text-[11px]
                           text-zinc-500 mb-0.5"
                >
                    مركز التكلفة / المخزن
                </label>

                <input
                    type="text"
                    value="الفرع الرئيسي"
                    disabled
                    class="w-full text-xs
                           bg-zinc-100
                           dark:bg-zinc-800/50
                           border border-zinc-300
                           dark:border-zinc-700
                           p-1 rounded
                           text-zinc-500"
                />

            </div>


            {{-- نوع الفاتورة --}}

            <div>

                <label
                    class="block text-[11px]
                           text-zinc-500 mb-0.5"
                >
                    نوع الفاتورة
                </label>

                <select
                    class="w-full text-xs
                           bg-zinc-50
                           dark:bg-zinc-800
                           border border-zinc-300
                           dark:border-zinc-700
                           p-1 rounded"
                >

                    <option>
                        فاتورة مبيعات جملة
                    </option>

                    <option>
                        فاتورة نقدية
                    </option>

                </select>

            </div>


            {{-- الضريبة --}}

            <div>

                <label
                    class="block text-[11px]
                           text-zinc-500 mb-0.5"
                >
                    التصنيف الضريبي
                </label>

                <div
                    class="flex items-center
                           gap-1 pt-1"
                >

                    <input
                        type="checkbox"
                        checked
                        disabled
                        class="rounded
                               border-zinc-300
                               text-indigo-600"
                    >

                    <span>
                        خاضع للضريبة (16%)
                    </span>

                </div>

            </div>

        </div>


        {{-- =====================================================
             جدول المنتجات
        ====================================================== --}}

        <div
            class="bg-white dark:bg-zinc-900
                   border border-zinc-300
                   dark:border-zinc-800
                   overflow-x-auto
                   min-h-[350px]"
        >

            <table
                class="w-full text-right
                       border-collapse
                       min-w-[850px]"
            >

                <thead>

                    <tr
                        class="bg-indigo-900
                               text-white
                               text-[11px]"
                    >

                        <th
                            class="p-1 border
                                   border-indigo-800
                                   w-8 text-center"
                        >
                            #
                        </th>

                        <th
                            class="p-1 border
                                   border-indigo-800
                                   w-24 text-center"
                        >
                            رقم المادة
                        </th>

                        <th
                            class="p-1 border
                                   border-indigo-800"
                        >
                            اسم المادة / الباركود
                        </th>

                        <th
                            class="p-1 border
                                   border-indigo-800
                                   w-24 text-center"
                        >
                            المخزن
                        </th>

                        <th
                            class="p-1 border
                                   border-indigo-800
                                   w-20 text-center"
                        >
                            الوحدة
                        </th>

                        <th
                            class="p-1 border
                                   border-indigo-800
                                   w-20 text-center"
                        >
                            الكمية
                        </th>

                        <th
                            class="p-1 border
                                   border-indigo-800
                                   w-24 text-center"
                        >
                            السعر
                        </th>

                        <th
                            class="p-1 border
                                   border-indigo-800
                                   w-20 text-center"
                        >
                            الخصم
                        </th>

                        <th
                            class="p-1 border
                                   border-indigo-800
                                   w-28 text-center"
                        >
                            المجموع
                        </th>

                        <th
                            class="p-1 border
                                   border-indigo-800
                                   w-10 text-center"
                        >
                            حذف
                        </th>

                    </tr>

                </thead>


                <tbody
                    class="divide-y
                           divide-zinc-200
                           dark:divide-zinc-800"
                >

                    {{-- المنتجات الموجودة --}}

                    @foreach($cart as $key => $item)

                        @php
                            $lineTotal =
                                ($item['price'] * $item['quantity'])
                                - $item['discount'];
                        @endphp

                        <tr
                            class="hover:bg-zinc-50
                                   dark:hover:bg-zinc-800/40"
                        >

                            <td
                                class="p-1 border
                                       text-center
                                       bg-zinc-50
                                       dark:bg-zinc-800/50"
                            >
                                {{ $loop->iteration }}
                            </td>


                            <td
                                class="p-1 border
                                       text-center
                                       font-mono
                                       text-zinc-600
                                       dark:text-zinc-400"
                            >
                                {{ $item['id'] }}
                            </td>


                            <td
                                class="p-1 border
                                       font-semibold
                                       text-zinc-800
                                       dark:text-zinc-200"
                            >
                                {{ $item['name'] }}
                            </td>


                            <td
                                class="p-1 border
                                       text-center
                                       text-zinc-500"
                            >
                                الرئيسي
                            </td>


                            <td
                                class="p-1 border
                                       text-center
                                       text-zinc-500"
                            >
                                حبة
                            </td>


                            <td
                                class="p-1 border
                                       text-center"
                            >

                                <input
                                    type="number"
                                    min="1"
                                    value="{{ $item['quantity'] }}"
                                    wire:change="updateItemQuantity('{{ $key }}', $event.target.value)"
                                    class="w-16 text-center
                                           bg-zinc-50
                                           dark:bg-zinc-800
                                           border border-zinc-300
                                           dark:border-zinc-700
                                           rounded p-0.5
                                           font-bold"
                                />

                            </td>


                            <td
                                class="p-1 border
                                       text-center"
                            >

                                <input
                                    type="number"
                                    step="0.01"
                                    value="{{ $item['price'] }}"
                                    wire:change="updateItemPrice('{{ $key }}', $event.target.value)"
                                    class="w-20 text-center
                                           bg-zinc-50
                                           dark:bg-zinc-800
                                           border border-zinc-300
                                           dark:border-zinc-700
                                           rounded p-0.5
                                           font-bold
                                           text-indigo-600
                                           dark:text-indigo-400"
                                />

                            </td>


                            <td
                                class="p-1 border
                                       text-center"
                            >

                                <input
                                    type="number"
                                    step="0.01"
                                    value="{{ $item['discount'] }}"
                                    wire:change="updateItemDiscount('{{ $key }}', $event.target.value)"
                                    class="w-16 text-center
                                           bg-zinc-50
                                           dark:bg-zinc-800
                                           border border-zinc-300
                                           dark:border-zinc-700
                                           rounded p-0.5"
                                />

                            </td>


                            <td
                                class="p-1 border
                                       text-center
                                       font-bold
                                       bg-zinc-50/50
                                       dark:bg-zinc-800/30"
                            >
                                {{ number_format($lineTotal, 2) }}
                            </td>


                            <td
                                class="p-1 border
                                       text-center"
                            >

                                <button
                                    wire:click="removeItem('{{ $key }}')"
                                    type="button"
                                    class="text-red-600
                                           hover:text-red-800
                                           font-bold px-1"
                                >
                                    ✕
                                </button>

                            </td>

                        </tr>

                    @endforeach


                    {{-- =================================================
                         سطر الإدخال السريع
                    ================================================== --}}

                    <tr
                        class="bg-indigo-50/50
                               dark:bg-indigo-950/30
                               border-2 border-indigo-400
                               dark:border-indigo-700
                               relative"
                    >

                        <td
                            class="p-1 border
                                   text-center
                                   font-bold
                                   text-indigo-600"
                        >
                            +
                        </td>


                        <td
                            class="p-1 border
                                   text-center
                                   font-mono
                                   text-zinc-500"
                        >
                            {{ $selectedProductId ?? '---' }}
                        </td>


                        <td
                            class="p-1 border relative"
                        >

                            <input
                                type="text"
                                x-ref="searchInput"
                                wire:model.live.debounce.150ms="rowSearch"
                                @keydown.enter.prevent="selectFirstResultOrNext()"
                                placeholder="ابحث أو امسح الباركود واضغط Enter..."
                                class="w-full
                                       bg-white
                                       dark:bg-zinc-800
                                       border border-indigo-400
                                       dark:border-indigo-600
                                       p-1 rounded text-xs
                                       font-bold
                                       focus:outline-none
                                       focus:ring-1
                                       focus:ring-indigo-500"
                                autofocus
                            />


                            @if(count($searchResults) > 0)

                                <div
                                    class="absolute z-50
                                           top-full right-0 left-0
                                           bg-white
                                           dark:bg-zinc-900
                                           border-2
                                           border-indigo-500
                                           shadow-xl
                                           max-h-52
                                           overflow-y-auto
                                           rounded-b"
                                >

                                    <table
                                        class="w-full text-right
                                               border-collapse"
                                    >

                                        <tbody
                                            class="divide-y
                                                   divide-zinc-200
                                                   dark:divide-zinc-800"
                                        >

                                            @foreach($searchResults as $p)

                                                <tr
                                                    wire:click="selectProduct({{ $p->id }})"
                                                    class="hover:bg-indigo-100
                                                           dark:hover:bg-indigo-900/50
                                                           cursor-pointer
                                                           border-b
                                                           text-xs p-1"
                                                >

                                                    <td
                                                        class="p-1
                                                               font-mono
                                                               text-zinc-500"
                                                    >
                                                        {{ $p->id }}
                                                    </td>

                                                    <td
                                                        class="p-1
                                                               font-bold
                                                               text-zinc-800
                                                               dark:text-zinc-200"
                                                    >
                                                        {{ $p->name }}
                                                    </td>

                                                    <td
                                                        class="p-1
                                                               font-bold
                                                               text-indigo-600
                                                               text-left"
                                                    >
                                                        {{
                                                            number_format(
                                                                $p->wholesale_price
                                                                ?? $p->retail_price
                                                                ?? 0,
                                                                2
                                                            )
                                                        }}
                                                    </td>

                                                </tr>

                                            @endforeach

                                        </tbody>

                                    </table>

                                </div>

                            @endif

                        </td>


                        <td
                            class="p-1 border
                                   text-center
                                   text-zinc-500"
                        >
                            الرئيسي
                        </td>


                        <td
                            class="p-1 border
                                   text-center
                                   text-zinc-500"
                        >
                            حبة
                        </td>


                        <td
                            class="p-1 border
                                   text-center"
                        >

                            <input
                                type="number"
                                min="1"
                                x-ref="qtyInput"
                                wire:model="selectedQuantity"
                                @keydown.enter.prevent="handleQtyEnter()"
                                class="w-16 text-center
                                       bg-white
                                       dark:bg-zinc-800
                                       border border-indigo-400
                                       dark:border-indigo-600
                                       rounded p-1
                                       font-bold"
                            />

                        </td>


                        <td
                            class="p-1 border
                                   text-center"
                        >

                            <input
                                type="number"
                                step="0.01"
                                x-ref="priceInput"
                                wire:model="selectedPrice"
                                @keydown.enter.prevent="handlePriceEnter()"
                                class="w-20 text-center
                                       bg-white
                                       dark:bg-zinc-800
                                       border border-indigo-400
                                       dark:border-indigo-600
                                       rounded p-1
                                       font-bold
                                       text-indigo-600
                                       dark:text-indigo-400"
                            />

                        </td>


                        <td
                            class="p-1 border
                                   text-center
                                   text-zinc-400"
                        >
                            0.00
                        </td>


                        <td
                            class="p-1 border
                                   text-center
                                   text-indigo-700
                                   dark:text-indigo-300
                                   font-bold"
                        >
                            {{
                                number_format(
                                    $selectedPrice
                                    * $selectedQuantity,
                                    2
                                )
                            }}
                        </td>


                        <td
                            class="p-1 border
                                   text-center"
                        >

                            <button
                                wire:click="commitRowToCart"
                                type="button"
                                class="bg-indigo-600
                                       hover:bg-indigo-700
                                       text-white
                                       font-bold px-2
                                       py-0.5 rounded
                                       text-[10px]"
                            >
                                +
                            </button>

                        </td>

                    </tr>

                </tbody>

            </table>

        </div>


        {{-- =====================================================
             أسفل الفاتورة
        ====================================================== --}}

        <div
            class="grid grid-cols-1
                   md:grid-cols-12 gap-2"
        >

            <div
                class="md:col-span-6
                       bg-white dark:bg-zinc-900
                       border border-zinc-300
                       dark:border-zinc-800
                       p-2
                       flex flex-col
                       justify-between
                       space-y-2"
            >

                <div>

                    <label
                        class="block text-[11px]
                               text-zinc-500 mb-1"
                    >
                        ملاحظات الفاتورة:
                    </label>

                    <textarea
                        wire:model="notes"
                        rows="3"
                        class="w-full
                               bg-zinc-50
                               dark:bg-zinc-800
                               border border-zinc-300
                               dark:border-zinc-700
                               p-1 rounded text-xs"
                        placeholder="أدخل الملاحظات هنا..."
                    ></textarea>

                </div>


                <div class="flex gap-2">

                    <button
                        wire:click="completeSale(false)"
                        type="button"
                        @disabled(empty($cart))
                        class="flex-1
                               bg-zinc-700
                               hover:bg-zinc-800
                               text-white py-2
                               rounded font-bold
                               disabled:opacity-50"
                    >
                        {{
                            $currentOrderId
                                ? 'تحديث الفاتورة (F9)'
                                : 'حفظ الفاتورة (F9)'
                        }}
                    </button>


                    <button
                        wire:click="completeSale(true)"
                        type="button"
                        @disabled(empty($cart))
                        class="flex-1
                               bg-emerald-600
                               hover:bg-emerald-700
                               text-white py-2
                               rounded font-bold
                               disabled:opacity-50"
                    >
                        {{
                            $currentOrderId
                                ? 'تحديث وطباعة (F10)'
                                : 'حفظ وطباعة (F10)'
                        }}
                    </button>

                </div>

            </div>


            <div
                class="md:col-span-6
                       bg-white dark:bg-zinc-900
                       border border-zinc-300
                       dark:border-zinc-800
                       p-2"
            >

                <div
                    class="space-y-1.5
                           border-b
                           border-zinc-200
                           dark:border-zinc-800
                           pb-2"
                >

                    <div
                        class="flex
                               justify-between
                               items-center"
                    >

                        <span
                            class="text-zinc-600
                                   dark:text-zinc-400"
                        >
                            المجموع قبل الخصم والضريبة:
                        </span>

                        <span
                            class="font-bold
                                   font-mono"
                        >
                            {{ number_format($subtotal, 2) }}
                        </span>

                    </div>


                    <div
                        class="flex
                               justify-between
                               items-center"
                    >

                        <span
                            class="text-zinc-600
                                   dark:text-zinc-400"
                        >
                            خصم إضافي على الفاتورة:
                        </span>

                        <input
                            type="number"
                            step="0.01"
                            wire:model.live="discount"
                            class="w-24 text-center
                                   bg-zinc-50
                                   dark:bg-zinc-800
                                   border border-zinc-300
                                   dark:border-zinc-700
                                   rounded p-0.5
                                   font-bold"
                        />

                    </div>


                    <div
                        class="flex
                               justify-between
                               items-center"
                    >

                        <span
                            class="text-zinc-600
                                   dark:text-zinc-400"
                        >
                            قيمة الضريبة المضافة (16%):
                        </span>

                        <span
                            class="font-bold font-mono
                                   text-amber-600"
                        >
                            {{ number_format($taxAmount, 2) }}
                        </span>

                    </div>

                </div>


                <div
                    class="flex
                           justify-between
                           items-center
                           pt-2
                           bg-indigo-50
                           dark:bg-indigo-950/40
                           p-2 rounded mt-2
                           border border-indigo-200
                           dark:border-indigo-900"
                >

                    <span
                        class="font-bold text-sm
                               text-indigo-900
                               dark:text-indigo-200"
                    >
                        المجموع النهائـي:
                    </span>

                    <span
                        class="font-extrabold
                               text-lg font-mono
                               text-indigo-600
                               dark:text-indigo-400"
                    >
                        {{ number_format($grandTotal, 2) }}
                    </span>

                </div>

            </div>

        </div>

    </div>

</div>

</flux:main>


@script

<script>

    Alpine.data('gridNavigation', () => ({

        selectFirstResultOrNext() {

            let firstResult =
                document.querySelector(
                    '.absolute.z-50 tbody tr'
                );

            if (firstResult) {

                firstResult.click();

            } else if (
                this.$wire.selectedProductId
            ) {

                this.$refs.qtyInput.focus();
                this.$refs.qtyInput.select();

            } else {

                this.$refs.searchInput.focus();
                this.$refs.searchInput.select();

            }
        },


        handleQtyEnter() {

            if (!this.$wire.selectedProductId) {

                this.$refs.searchInput.focus();
                this.$refs.searchInput.select();

            } else {

                this.$refs.priceInput.focus();
                this.$refs.priceInput.select();

            }
        },


        handlePriceEnter() {

            if (!this.$wire.selectedProductId) {

                this.$refs.searchInput.focus();
                this.$refs.searchInput.select();

            } else {

                this.$wire.commitRowToCart();

            }
        },


        init() {

            Livewire.on(
                'focus-quantity',
                () => {

                    setTimeout(() => {

                        if (this.$refs.qtyInput) {

                            this.$refs.qtyInput.focus();
                            this.$refs.qtyInput.select();

                        }

                    }, 50);

                }
            );


            Livewire.on(
                'focus-search',
                () => {

                    setTimeout(() => {

                        if (this.$refs.searchInput) {

                            this.$refs.searchInput.focus();
                            this.$refs.searchInput.select();

                        }

                    }, 50);

                }
            );

        }

    }));


    $wire.on(
        'do-kiosk-print',
        (event) => {

            const inv = event.data;

            let text = "";

            text +=
                "--------------------------------\n";

            text +=
                "        "
                + (inv.store_name || "المتجر")
                + "        \n";

            text +=
                "--------------------------------\n";

            text +=
                "رقم الفاتورة: "
                + inv.invoice_no
                + "\n";

            text +=
                "التاريخ: "
                + inv.date
                + "\n";

            text +=
                "العميل: "
                + inv.customer_name
                + "\n";

            text +=
                "--------------------------------\n";


            inv.items.forEach(
                item => {

                    let total =
                        (
                            (
                                item.price
                                * item.quantity
                            )
                            - item.discount
                        ).toFixed(2);

                    text +=
                        item.name
                        + "\n";

                    text +=
                        "   "
                        + item.quantity
                        + " x "
                        + Number(
                            item.price
                        ).toFixed(2)
                        + " = "
                        + total
                        + " \n";

                }
            );


            text +=
                "--------------------------------\n";

            text +=
                "المجموع: "
                + Number(
                    inv.subtotal
                ).toFixed(2)
                + " \n";

            text +=
                "الخصم: "
                + Number(
                    inv.discount
                ).toFixed(2)
                + " \n";

            text +=
                "الضريبة: "
                + Number(
                    inv.tax
                ).toFixed(2)
                + " \n";

            text +=
                "الإجمالي النهائي: "
                + Number(
                    inv.total
                ).toFixed(2)
                + " \n";


            if (inv.notes) {

                text +=
                    "ملاحظات: "
                    + inv.notes
                    + "\n";

            }


            text +=
                "--------------------------------\n\n\n\n";


            const intentUrl =
                "intent:"
                + encodeURIComponent(text)
                + "#Intent;"
                + "scheme=rawbt;"
                + "package=ru.a402d.rawbtprinter;"
                + "S.type=text/plain;"
                + "end;";


            window.location.href =
                intentUrl;

        }
    );

</script>

@endscript
