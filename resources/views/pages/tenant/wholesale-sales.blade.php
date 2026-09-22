<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Party;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $cart = [];

    // بيانات العميل والملاحظات
    public ?int $selectedCustomerId = null;
    public ?string $notes = '';

    // المبلغ المدفوع (للدفع الجزئي أو الكلي)
    public ?float $paidAmount = 0;

    // متغيرات مودال سجل أسعار البيع
    public bool $showPriceHistoryModal = false;
    public ?array $selectedHistoryItem = null;

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
            return $user->tenants()->first()?->id;
        }

        return session('active_tenant_id') ? (int) session('active_tenant_id') : null;
    }

    private function getUserBranchId(): ?int
    {
        return auth()->user()?->branch_id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function searchBarcode(): void
    {
        $this->resetPage();

        $trimmedSearch = trim($this->search);

        if (empty($trimmedSearch)) {
            return;
        }

        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return;
        }

        $matchedProduct = Product::where('products.tenant_id', $tenantId)
            ->whereExists(function ($query) use ($trimmedSearch) {
                $query->select(DB::raw(1))->from('product_barcodes')->whereColumn('product_barcodes.product_id', 'products.id')->where('product_barcodes.barcode', $trimmedSearch);
            })
            ->first();

        if ($matchedProduct) {
            $this->addToCart($matchedProduct->id);
            $this->search = '';
        }
    }

    public function showLastPrice(int $productId): void
    {
        $tenantId = $this->getTenantId();

        $product = Product::find($productId);
        $customer = $this->selectedCustomerId ? Party::find($this->selectedCustomerId) : null;

        $history = [];

        if ($this->selectedCustomerId) {
            $historyItems = OrderItem::whereHas('order', function ($q) {
                $q->where('customer_id', $this->selectedCustomerId)->where('status', 'completed');
            })
                ->where('product_id', $productId)
                ->where('tenant_id', $tenantId)
                ->latest()
                ->take(10)
                ->get();

            foreach ($historyItems as $item) {
                $history[] = [
                    'price' => (float) $item->unit_price,
                    'quantity' => $item->quantity,
                    'date' => $item->created_at ? $item->created_at->format('Y-m-d h:i A') : '-',
                ];
            }
        }

        $this->selectedHistoryItem = [
            'product_name' => $product?->name ?? '',
            'customer_name' => $customer?->name ?? 'زبون عابر (لم يتم تحديد عميل)',
            'history' => $history,
            'has_history' => !empty($history),
        ];

        $this->showPriceHistoryModal = true;
    }

    public function closePriceHistoryModal(): void
    {
        $this->showPriceHistoryModal = false;
        $this->selectedHistoryItem = null;
    }

    public function addToCart(int $productId): void
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return;
        }

        $branchId = $this->getUserBranchId();

        $product = Product::where('products.tenant_id', $tenantId)
            ->leftJoin('branch_products', function ($join) use ($branchId) {
                $join->on('products.id', '=', 'branch_products.product_id')->where('branch_products.branch_id', '=', $branchId);
            })
            ->select('products.*', 'branch_products.retail_price as branch_retail_price', 'branch_products.wholesale_price as branch_wholesale_price')
            ->where('products.id', $productId)
            ->first();

        if (!$product) {
            return;
        }

        $price = (float) ($product->branch_wholesale_price ?? ($product->branch_retail_price ?? ($product->wholesale_price ?? ($product->retail_price ?? ($product->price ?? 0)))));

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity']++;
        } else {
            $this->cart[$productId] = [
                'id' => $product->id,
                'name' => $product->name,
                'image' => $product->image,
                'price' => $price,
                'cost' => (float) ($product->cost_price ?? 0),
                'quantity' => 1,
            ];
        }
    }

    public function updateQuantity(int $productId, int $qty): void
    {
        if ($qty <= 0) {
            unset($this->cart[$productId]);
        } else {
            $this->cart[$productId]['quantity'] = $qty;
        }
    }

    public function updatePrice(int $productId, $newPrice): void
    {
        $price = (float) $newPrice;
        if (isset($this->cart[$productId]) && $price >= 0) {
            $this->cart[$productId]['price'] = $price;
        }
    }

    public function setFullPayment(): void
    {
        $this->paidAmount = array_reduce($this->cart, fn($sum, $item) => $sum + $item['price'] * $item['quantity'], 0);
    }

    public function completeSale(bool $shouldPrint = false): void
    {
        if (empty($this->cart)) {
            return;
        }

        $tenantId = $this->getTenantId();
        if (!$tenantId) {
            return;
        }

        $user = auth()->user();
        $customer = null;

        $previousBalance = 0;
        if ($this->selectedCustomerId) {
            $customer = Party::where('tenant_id', $tenantId)
                ->where(function ($q) {
                    $q->where('type', 'customer')->orWhere('type', 'both');
                })
                ->find($this->selectedCustomerId);

            if ($customer) {
                $openingBalance = $customer->opening_balance ?? 0;
                $ordersSum = $customer->orders()->where('status', 'completed')->sum('total');
                $paidSum = $customer->payments()->where('type', 'payment')->sum('amount');
                $receivedSum = $customer->payments()->where('type', 'receipt')->sum('amount');

                $previousBalance = $openingBalance + $ordersSum + $paidSum - $receivedSum;
            }
        }

        $subtotal = array_reduce($this->cart, fn($sum, $item) => $sum + $item['price'] * $item['quantity'], 0);
        $totalCost = array_reduce($this->cart, fn($sum, $item) => $sum + $item['cost'] * $item['quantity'], 0);

        $paid = is_null($this->paidAmount) ? 0 : (float) $this->paidAmount;

        $paymentStatus = 'unpaid';
        if ($paid >= $subtotal && $subtotal > 0) {
            $paymentStatus = 'paid';
        } elseif ($paid > 0) {
            $paymentStatus = 'partial';
        }

        $order = null;
        $payment = null;

        $activeShift = Shift::where('tenant_id', $tenantId)->where('user_id', $user?->id)->where('status', 'open')->first();

        $savedCart = $this->cart;
        $savedNotes = $this->notes;
        $customerName = $customer?->name ?? 'زبون عابر';

        DB::transaction(function () use ($tenantId, $user, $activeShift, $subtotal, $totalCost, $customer, $customerName, $paid, $paymentStatus, &$order, &$payment) {
            $order = Order::create([
                'tenant_id' => $tenantId,
                'branch_id' => $this->getUserBranchId(),
                'customer_id' => $customer?->id,
                'customer_name' => $customerName,
                'customer_phone' => $customer?->phone,
                'invoice_number' => 'INV-VAN-' . date('Ymd') . '-' . rand(100, 999),
                'type' => 'wholesale',
                'status' => 'completed',
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'total_cost' => $totalCost,
                'total_profit' => $subtotal - $totalCost,
                'paid_amount' => $paid,
                'payment_status' => $paymentStatus,
                'notes' => $this->notes,
            ]);

            foreach ($this->cart as $productId => $item) {
                OrderItem::create([
                    'tenant_id' => $tenantId,
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'total_price' => $item['price'] * $item['quantity'],
                    'cost_price' => $item['cost'],
                    'total_cost' => $item['cost'] * $item['quantity'],
                ]);
            }

            if ($paid > 0 && $customer) {
                $payment = Payment::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $this->getUserBranchId() ?? $activeShift?->branch_id,
                    'shift_id' => $activeShift?->id,
                    'user_id' => $user?->id,
                    'type' => 'receipt',
                    'voucher_number' => 'RCV-' . strtoupper(uniqid()),
                    'payable_type' => Party::class,
                    'payable_id' => $customer->id,
                    'amount' => $paid,
                    'payment_method' => 'cash',
                    'notes' => 'سند قبض تلقائي للفاتورة رقم: #' . $order->invoice_number,
                    'payment_date' => now(),
                ]);
            }
        });

        $currentBalance = $previousBalance + $subtotal - $paid;

        if ($shouldPrint && $order) {
            $printableOrder = [
                'header_title' => 'تسعيرة',
                'invoice_no' => $order->invoice_number,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'date' => $order->created_at->format('Y-m-d h:i A'), // تحويل التاريخ والوقت لتنسيق 12 ساعة
                'items' => array_values($this->cart),
                'total' => $subtotal,
                'paid_amount' => $paid,
                'remaining_amount' => $subtotal - $paid,
                'previous_balance' => $previousBalance,
                'current_balance' => $currentBalance,
                'notes' => $this->notes,
            ];

            $this->dispatch('do-kiosk-print', data: $printableOrder);
        }

        if ($payment) {
            $voucherData = [
                'header_title' => 'تسعيرة',
                'voucher_no' => $payment->voucher_number,
                'type' => 'سند قبض',
                'party_name' => $customerName,
                'amount' => number_format($payment->amount, 2),
                'payment_method' => 'نقداً (كاش)',
                'date' => $payment->payment_date->format('Y-m-d h:i A'), // تحويل التاريخ والوقت لتنسيق 12 ساعة
                'user_name' => $user?->name ?? 'النظام',
                'previous_balance' => $previousBalance,
                'current_balance' => $currentBalance,
                'notes' => $payment->notes,
            ];

            $this->dispatch('do-voucher-print', data: $voucherData);
        }

        $this->triggerWhatsAppSend('+970592700780', $savedCart, $subtotal, $paid, $customerName, $savedNotes, $order?->invoice_number);

        $this->cart = [];
        $this->reset(['selectedCustomerId', 'notes']);
        $this->paidAmount = 0;
        session()->flash('message', 'تم إصدار الفاتورة وإرسالها عبر الواتس بنجاح!');
    }

    private function triggerWhatsAppSend(string $phone, array $cart, float $subtotal, float $paid, string $customerName, ?string $notes, ?string $invNo): void
    {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        $text = 'مرحباً، تفاصيل الفاتورة (' . ($invNo ?? 'جديدة') . "):\n\n";
        $text .= 'الزبون: ' . $customerName . "\n";
        $text .= "------------------------\n";

        foreach ($cart as $item) {
            $itemTotal = $item['price'] * $item['quantity'];
            $text .= "• {$item['name']} (×{$item['quantity']}) = " . number_format($itemTotal, 2) . " شيكل\n";
        }

        $text .= "------------------------\n";
        $text .= 'المجموع: ' . number_format($subtotal, 2) . " شيكل\n";
        $text .= 'المدفوع: ' . number_format($paid, 2) . " شيكل\n";
        $text .= 'المتبقي: ' . number_format($subtotal - $paid, 2) . " شيكل\n";

        if (!empty($notes)) {
            $text .= 'ملاحظات: ' . $notes . "\n";
        }

        $url = "https://wa.me/{$cleanPhone}?text=" . urlencode($text);

        $this->dispatch('open-whatsapp-url', url: $url);
    }

    public function render()
    {
        $tenantId = $this->getTenantId();
        $branchId = $this->getUserBranchId();

        $trimmedSearch = trim($this->search);

        if (empty($trimmedSearch)) {
            $products = Product::whereRaw('1 = 0')->paginate(12);
        } else {
            $products = Product::where('products.tenant_id', $tenantId)
                ->leftJoin('branch_products', function ($join) use ($branchId) {
                    $join->on('products.id', '=', 'branch_products.product_id')->where('branch_products.branch_id', '=', $branchId);
                })
                ->select('products.*', 'branch_products.retail_price as branch_retail_price', 'branch_products.wholesale_price as branch_wholesale_price')
                ->where(function ($sub) use ($trimmedSearch) {
                    $term = "%{$trimmedSearch}%";
                    $sub->where('products.name', 'like', $term)->orWhereExists(function ($query) use ($term) {
                        $query->select(DB::raw(1))->from('product_barcodes')->whereColumn('product_barcodes.product_id', 'products.id')->where('product_barcodes.barcode', 'like', $term);
                    });
                })
                ->paginate(12);
        }

        $customers = Party::when($tenantId, fn($q) => $q->where('tenant_id', $tenantId))
            ->where(function ($query) {
                $query->where('type', 'customer')->orWhere('type', 'both');
            })
            ->withSum(['payments as paid_sum' => fn($q) => $q->where('type', 'payment')], 'amount')
            ->withSum(['payments as received_sum' => fn($q) => $q->where('type', 'receipt')], 'amount')
            ->withSum(['orders as orders_sum' => fn($q) => $q->where('status', 'completed')], 'total')
            ->get();

        $cartTotal = array_reduce($this->cart, fn($sum, $item) => $sum + $item['price'] * $item['quantity'], 0);

        return $this->view([
            'products' => $products,
            'customers' => $customers,
            'cartTotal' => $cartTotal,
        ])->layout('layouts::tenant');
    }
};
?>
<flux:main class="p-2 sm:p-4">

    <div class="min-h-screen lg:h-[calc(100vh-4rem)] flex flex-col bg-zinc-50 dark:bg-zinc-950" dir="rtl">

        <div class="h-full flex flex-col space-y-3">
            @if (session()->has('error'))
                <flux:badge variant="danger" class="mb-2 w-full justify-start p-2 text-xs">
                    {{ session('error') }}
                </flux:badge>
            @endif

            @if (session()->has('message'))
                <flux:badge variant="success" class="mb-2 w-full justify-start p-2 text-xs">
                    {{ session('message') }}
                </flux:badge>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 flex-1 lg:overflow-hidden">
                <div class="lg:col-span-7 xl:col-span-8 flex flex-col space-y-3 lg:h-full lg:overflow-hidden">
                    <div
                        class="bg-white dark:bg-zinc-900 p-2 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm">
                        <flux:input wire:model.live.debounce.150ms="search" wire:keydown.enter="searchBarcode"
                            placeholder="بحث باسم المنتج أو الباركود..." icon="magnifying-glass" class="w-full"
                            autofocus id="barcode-search-input" />
                    </div>

                    <div
                        class="lg:flex-1 lg:overflow-y-auto grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5 p-0.5 content-start max-h-[45vh] lg:max-h-none overflow-y-auto">
                        @forelse($products as $product)
                            @php
                                $effectivePrice =
                                    $product->branch_wholesale_price ??
                                    ($product->branch_retail_price ??
                                        ($product->wholesale_price ??
                                            ($product->retail_price ?? ($product->price ?? 0))));
                            @endphp
                            <button wire:click="addToCart({{ $product->id }})"
                                class="flex flex-col h-24 justify-between p-3 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl hover:border-indigo-500 hover:shadow-md transition text-right group">
                                <div
                                    class="font-semibold text-xs sm:text-sm text-zinc-800 dark:text-zinc-200 line-clamp-2 leading-snug">
                                    {{ $product->name }}
                                </div>
                                <div
                                    class="flex justify-between items-center w-full pt-1.5 border-t border-zinc-100 dark:border-zinc-800/80">
                                    <span class="text-[10px] text-zinc-400">سعر الجملة</span>
                                    <span
                                        class="font-bold text-indigo-600 dark:text-indigo-400 text-xs sm:text-sm font-mono">
                                        {{ number_format($effectivePrice, 2) }}
                                    </span>
                                </div>
                            </button>
                        @empty
                            <div class="col-span-full text-center py-8 text-zinc-400 text-xs">
                                @if (empty(trim($search)))
                                    ابدأ بالكتابة في مربع البحث أو امسح الباركود لعرض المنتجات...
                                @else
                                    لا توجد منتجات مطابقة للبحث.
                                @endif
                            </div>
                        @endforelse
                    </div>

                    <div class="pt-1">{{ $products->links() }}</div>
                </div>

                <div
                    class="lg:col-span-5 xl:col-span-4 flex flex-col bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl p-3 shadow-sm min-h-[350px] lg:h-full lg:overflow-hidden">
                    <div class="flex flex-col h-full justify-between space-y-2">
                        <div class="space-y-2 flex-1 flex flex-col lg:overflow-hidden">
                            <flux:heading size="md" class="border-b border-zinc-100 dark:border-zinc-800 pb-2">
                                فاتورة مبيعات باص</flux:heading>

                            <div class="space-y-1.5">
                                <select wire:model.live="selectedCustomerId"
                                    class="w-full text-xs border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 dark:text-zinc-200 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    <option value="">-- اختر الزبون (اختياري: زبون عابر) --</option>
                                    @foreach ($customers as $customer)
                                        @php
                                            $openingBalance = $customer->opening_balance ?? 0;
                                            $ordersSum = $customer->orders_sum ?? 0;
                                            $paidSum = $customer->paid_sum ?? 0;
                                            $receivedSum = $customer->received_sum ?? 0;

                                            $bal = $openingBalance + $ordersSum + $paidSum - $receivedSum;
                                        @endphp
                                        <option value="{{ $customer->id }}">
                                            {{ $customer->name }} {{ $customer->phone ? "({$customer->phone})" : '' }}
                                            — [الرصيد: {{ number_format($bal, 2) }}]
                                        </option>
                                    @endforeach
                                </select>

                                @if ($selectedCustomerId && ($selectedCustomer = $customers->firstWhere('id', $selectedCustomerId)))
                                    @php
                                        $openingBalance = $selectedCustomer->opening_balance ?? 0;
                                        $ordersSum = $selectedCustomer->orders_sum ?? 0;
                                        $paidSum = $selectedCustomer->paid_sum ?? 0;
                                        $receivedSum = $selectedCustomer->received_sum ?? 0;

                                        $currentBalance = $openingBalance + $ordersSum + $paidSum - $receivedSum;
                                    @endphp
                                    <div
                                        class="flex justify-between items-center bg-zinc-100 dark:bg-zinc-800/80 p-2 rounded-lg text-xs border border-zinc-200 dark:border-zinc-700">
                                        <span class="text-zinc-600 dark:text-zinc-400 font-medium">الرصيد الحالي
                                            للزبون:</span>
                                        <span
                                            class="font-bold font-mono {{ $currentBalance >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                                            {{ number_format($currentBalance, 2) }} شيكل
                                        </span>
                                    </div>
                                @endif
                            </div>

                            <div>
                                <flux:input wire:model="notes" placeholder="ملاحظات الفاتورة..." size="sm" />
                            </div>

                            <div
                                class="flex-1 min-h-[140px] max-h-[220px] lg:max-h-none overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800/60 pr-1">
                                @forelse($cart as $id => $item)
                                    <div class="py-2 flex justify-between items-center text-xs gap-2">
                                        <div
                                            class="w-7 h-7 rounded bg-zinc-100 dark:bg-zinc-800 overflow-hidden flex-shrink-0 border border-zinc-200 dark:border-zinc-700 flex items-center justify-center p-0.5">
                                            @if (!empty($item['image']))
                                                <img src="{{ Storage::url($item['image']) }}"
                                                    alt="{{ $item['name'] }}" class="w-full h-full object-contain">
                                            @else
                                                <flux:icon icon="photo" class="w-3.5 h-3.5 text-zinc-400" />
                                            @endif
                                        </div>

                                        <div class="flex-1 truncate">
                                            <div
                                                class="font-medium truncate text-zinc-800 dark:text-zinc-200 flex items-center gap-1">
                                                <span>{{ $item['name'] }}</span>
                                                <button type="button" wire:click="showLastPrice({{ $id }})"
                                                    title="سجل آخر 10 عمليات بيع لهذا الزبون"
                                                    class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 transition">
                                                    <flux:icon icon="clock" class="w-3.5 h-3.5" />
                                                </button>
                                            </div>

                                            <div class="flex items-center gap-1 mt-0.5">
                                                <span class="text-[10px] text-zinc-400">السعر:</span>
                                                <input type="number" step="0.01"
                                                    wire:change="updatePrice({{ $id }}, $event.target.value)"
                                                    value="{{ $item['price'] }}"
                                                    class="w-16 px-1 py-0.5 text-[11px] font-mono border border-zinc-300 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 rounded focus:ring-1 focus:ring-indigo-500 focus:outline-none" />
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-1">
                                            <flux:button size="xs" variant="subtle"
                                                wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] - 1 }})">
                                                -</flux:button>
                                            <span
                                                class="font-bold text-xs px-1 text-zinc-700 dark:text-zinc-300">{{ $item['quantity'] }}</span>
                                            <flux:button size="xs" variant="subtle"
                                                wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] + 1 }})">
                                                +</flux:button>
                                        </div>
                                    </div>
                                @empty
                                    <div class="text-center py-6 text-zinc-400 text-xs">السلة فارغة</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="pt-2 border-t border-zinc-200 dark:border-zinc-800 space-y-2">
                            <div class="flex justify-between items-center font-bold text-sm">
                                <span class="text-zinc-700 dark:text-zinc-300">المجموع الكلي:</span>
                                <span
                                    class="text-base text-emerald-600 dark:text-emerald-400 font-mono">{{ number_format($cartTotal, 2) }}</span>
                            </div>

                            <div x-data="{ paid: @entangle('paidAmount').live }"
                                class="space-y-1 pt-1 border-t border-zinc-100 dark:border-zinc-800">
                                <div class="flex items-center justify-between">
                                    <label class="text-xs text-zinc-600 dark:text-zinc-400 font-medium">المبلغ
                                        المدفوع:</label>
                                    <button type="button" wire:click="setFullPayment"
                                        class="text-[11px] text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-semibold underline">
                                        دفع كامل
                                    </button>
                                </div>

                                <input type="number" step="0.01" x-model.number="paid" placeholder="0.00"
                                    class="w-full text-xs p-2 border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-800 rounded-lg focus:ring-2 focus:ring-indigo-500 font-mono" />

                                <template
                                    x-if="paid !== null && paid !== '' && parseFloat(paid) < {{ $cartTotal }}">
                                    <div
                                        class="flex justify-between text-[11px] text-rose-600 dark:text-rose-400 font-semibold px-1 pt-0.5">
                                        <span>المتبقي (دين):</span>
                                        <span class="font-mono"
                                            x-text="({{ $cartTotal }} - parseFloat(paid || 0)).toFixed(2)"></span>
                                    </div>
                                </template>
                            </div>

                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <flux:button variant="filled" class="w-full py-2 text-xs"
                                    wire:click="completeSale(false)" :disabled="empty($cart)">
                                    حفظ فقط
                                </flux:button>

                                <flux:button variant="primary" icon="printer" class="w-full py-2 text-xs"
                                    wire:click="completeSale(true)" :disabled="empty($cart)">
                                    حفظ وطباعة
                                </flux:button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @if ($showPriceHistoryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
            dir="rtl">
            <div
                class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-xl max-w-md w-full p-4 space-y-4">
                <div class="flex justify-between items-center border-b border-zinc-100 dark:border-zinc-800 pb-2">
                    <h3 class="font-bold text-sm text-zinc-800 dark:text-zinc-200">
                        سجل آخر 10 عمليات بيع
                    </h3>
                    <button wire:click="closePriceHistoryModal"
                        class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        ✕
                    </button>
                </div>

                <div class="space-y-2 text-xs">
                    <div>
                        <span class="text-zinc-400">المنتج:</span>
                        <span
                            class="font-semibold text-zinc-800 dark:text-zinc-100 mr-1">{{ $selectedHistoryItem['product_name'] ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-zinc-400">الزبون:</span>
                        <span
                            class="font-semibold text-zinc-800 dark:text-zinc-100 mr-1">{{ $selectedHistoryItem['customer_name'] ?? '-' }}</span>
                    </div>

                    <div class="border-t border-zinc-100 dark:border-zinc-800 pt-3 max-h-60 overflow-y-auto">
                        @if ($selectedHistoryItem['has_history'])
                            <table class="w-full text-right text-[11px] border-collapse">
                                <thead>
                                    <tr
                                        class="border-b border-zinc-200 dark:border-zinc-700 text-zinc-400 bg-zinc-50 dark:bg-zinc-800/50">
                                        <th class="p-1.5">السعر</th>
                                        <th class="p-1.5">الكمية</th>
                                        <th class="p-1.5">التاريخ</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($selectedHistoryItem['history'] as $row)
                                        <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                            <td
                                                class="p-1.5 font-bold text-emerald-600 dark:text-emerald-400 font-mono">
                                                {{ number_format($row['price'], 2) }}
                                            </td>
                                            <td class="p-1.5 text-zinc-700 dark:text-zinc-300 font-mono">
                                                {{ $row['quantity'] }}
                                            </td>
                                            <td class="p-1.5 text-zinc-500 dark:text-zinc-400 text-[10px]">
                                                {{ $row['date'] }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <div
                                class="text-center py-6 bg-zinc-50 dark:bg-zinc-800/40 rounded-lg text-zinc-400 text-xs">
                                لا يوجد سجل بيع سابق لهذا المنتج مع العميل المحدد.
                            </div>
                        @endif
                    </div>
                </div>

                <div class="pt-2">
                    <flux:button variant="subtle" class="w-full text-xs" wire:click="closePriceHistoryModal">
                        إغلاق
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

</flux:main>

@script
    <script>
        Livewire.hook('commit', ({
            respond
        }) => {
            respond(() => {
                const activeEl = document.activeElement;
                const isInput = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName ===
                    'TEXTAREA' || activeEl.tagName === 'SELECT');

                if (!isInput) {
                    const searchInput = document.getElementById('barcode-search-input');
                    if (searchInput) {
                        searchInput.focus();
                    }
                }
            });
        });

        // طباعة الفاتورة عبر RawBT
        // طباعة الفاتورة عبر RawBT
        $wire.on('do-kiosk-print', (event) => {
            const inv = event.data;

            function formatLine(leftText, rightText, width = 32) {
                let l = String(leftText || '');
                let r = String(rightText || '');
                let spaceCount = width - (l.length + r.length);
                if (spaceCount < 1) spaceCount = 1;
                return r + " ".repeat(spaceCount) + l + "\n";
            }

            // دالة المساعدة لقص اسم المنتج ليظهر أول كلمتين فقط + نقاط
            function formatProductName(name) {
                if (!name) return '';
                const words = name.trim().split(/\s+/);
                if (words.length > 2) {
                    return words.slice(0, 2).join(' ') + '...';
                }
                return name;
            }

            let text = "";

            // الهيدر الرئيسي
            text += "=============================\n";
            text += "            " + (inv.header_title || "فاتورة") + "            \n";
            text += "=============================\n";

            // تفاصيل الفاتورة والزبون
            text += formatLine(inv.invoice_no, "رقم الفاتورة:");
            text += formatLine(inv.date, "التاريخ:");
            text += formatLine(inv.customer_name, "الزبون:");

            // رأس جدول الأصناف
            text += "-----------------------------\n";
            text += "المنتج             العدد  المجموع\n";
            text += "-----------------------------\n";

            // عرض المنتجات بشكل جدول مرتب
            // عرض المنتجات بشكل جدول مرتب في سطر واحد
            if (inv.items && inv.items.length) {
                inv.items.forEach(item => {
                    let priceNum = Number(item.price);
                    let qtyNum = Number(item.quantity);
                    let totalNum = priceNum * qtyNum;

                    // تنسيق الأرقام: إلغاء الأصفار العشرية إذا كان الرقم صحيحاً
                    let totalStr = (totalNum % 1 === 0) ? totalNum.toString() : totalNum.toFixed(2);
                    let priceStr = (priceNum % 1 === 0) ? priceNum.toString() : priceNum.toFixed(2);
                    let qtyStr = (qtyNum % 1 === 0) ? qtyNum.toString() : qtyNum.toFixed(2);

                    // اختصار اسم المنتج لأول كلمتين فقط
                    let shortName = formatProductName(item.name);

                    // دمج التفاصيل: (الاسم الكمية x السعر)
                    let leftDetails = shortName + " (" + qtyStr + "x" + priceStr + ")";

                    // طباعة التفاصيل على اليمين والمجموع الكلي محاذى لليسار في نفس السطر
                    text += formatLine(totalStr, leftDetails) + "\n";
                });
            }

            // ملخص الحساب المالي
            text += "=============================\n";
            text += formatLine(Number(inv.total).toFixed(2) + " شيكل", "المجموع:");
            text += formatLine(Number(inv.paid_amount).toFixed(2) + " شيكل", "المدفوع:");
            text += formatLine(Number(inv.remaining_amount).toFixed(2) + " شيكل", "المتبقي:");

            // كشف رصيد الحساب
            text += "-----------------------------\n";
            text += formatLine(Number(inv.previous_balance).toFixed(2) + " شيكل", "الرصيد السابق:");
            text += formatLine(Number(inv.current_balance).toFixed(2) + " شيكل", "الرصيد الحالي:");

            // إضافة الملاحظات للطباعة إذا وُجدت
            if (inv.notes && inv.notes.trim() !== '') {
                text += "-----------------------------\n";
                text += "ملاحظات: " + inv.notes + "\n";
            }

            text += "=============================\n\n\n\n";

            const intentUrl = "intent:" + encodeURIComponent(text) +
                "#Intent;" +
                "scheme=rawbt;" +
                "package=ru.a402d.rawbtprinter;" +
                "S.type=text/plain;" +
                "end;";

            window.location.href = intentUrl;
        });
        // طباعة سند القبض عبر RawBT
        $wire.on('do-voucher-print', (event) => {
            const voucher = event.data;

            let text = "";
            text += "-----------------------------\n";
            text += "            " + (voucher.header_title || "تسعيرة") + "            \n";
            text += "           " + voucher.type + "           \n";
            text += "-----------------------------\n";
            text += "رقم السند: " + voucher.voucher_no + "\n";
            text += "التاريخ: " + voucher.date + "\n";
            text += "الزبون: " + voucher.party_name + "\n";
            text += "-----------------------------\n";
            text += "الدفعة الواصلة: " + voucher.amount + " \n";
            text += "طريقة الدفع: " + voucher.payment_method + "\n";
            text += "-----------------------------\n";
            text += "الرصيد السابق: " + Number(voucher.previous_balance).toFixed(2) + " \n";
            text += "الرصيد الحالي: " + Number(voucher.current_balance).toFixed(2) + " \n";

            // إضافة الملاحظات لسند القبض
            if (voucher.notes && voucher.notes.trim() !== '') {
                text += "-----------------------------\n";
                text += "ملاحظات: " + voucher.notes + "\n";
            }
            text += "-----------------------------\n\n\n\n";

            const intentUrl = "intent:" + encodeURIComponent(text) +
                "#Intent;" +
                "scheme=rawbt;" +
                "package=ru.a402d.rawbtprinter;" +
                "S.type=text/plain;" +
                "end;";

            window.location.href = intentUrl;
        });

        $wire.on('open-whatsapp-url', (event) => {
            window.open(event.url, '_blank');
        });
    </script>
@endscript
