<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $cart = [];

    public ?int $selectedCustomerId = null;
    public string $notes = '';
    public float $paidAmount = 0;
    public string $paymentMethod = 'cash';

    public string $discountType = 'fixed';
    public float $discountAmount = 0;
    public float $discountRate = 0;
    public bool $sendWhatsapp = false;

    public bool $showPriceHistoryModal = false;
    public ?array $selectedHistoryItem = null;

    private function tenantId(): ?int
    {
        return auth()->user()?->tenant_id ? (int) auth()->user()->tenant_id : null;
    }

    private function branchId(): ?int
    {
        return auth()->user()?->branch_id ? (int) auth()->user()->branch_id : null;
    }

    private function currentUser(): ?object
    {
        return auth()->user();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDiscountType(): void
    {
        if ($this->discountType === 'percentage') {
            $this->discountAmount = 0;
        } else {
            $this->discountRate = 0;
        }
    }

    public function updatedDiscountAmount($value): void
    {
        $this->discountAmount = max(0, (float) $value);
    }

    public function updatedDiscountRate($value): void
    {
        $this->discountRate = min(100, max(0, (float) $value));
    }

    public function updatedPaidAmount($value): void
    {
        $this->paidAmount = max(0, (float) $value);
    }

    public function searchBarcode(): void
    {
        $term = trim($this->search);
        if ($term === '') {
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->branchId();

        if (!$tenantId || !$branchId) {
            session()->flash('error', 'لا يمكن إصدار فاتورة قبل ربط المستخدم بمتجر وفرع.');
            return;
        }

        $product = Product::query()
            ->where('products.tenant_id', $tenantId)
            ->whereHas('barcodes', fn ($q) => $q->where('barcode', $term))
            ->whereHas('branchProducts', fn ($q) => $q->where('branch_id', $branchId))
            ->first();

        if (!$product) {
            session()->flash('error', 'لم يتم العثور على منتج بهذا الباركود.');
            return;
        }

        $this->addToCart($product->id);
        $this->search = '';
        $this->resetPage();
    }

    public function addToCart(int $productId): void
    {
        $tenantId = $this->tenantId();
        $branchId = $this->branchId();

        if (!$tenantId || !$branchId) {
            session()->flash('error', 'المستخدم غير مرتبط بفرع.');
            return;
        }

        $branchProduct = BranchProduct::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->with('product')
            ->first();

        if (!$branchProduct?->product) {
            session()->flash('error', 'المنتج غير مرتبط بالفرع الحالي.');
            return;
        }

        $stock = (float) $branchProduct->stock_quantity;
        $currentQty = (float) ($this->cart[$productId]['quantity'] ?? 0);

        if ($stock <= $currentQty) {
            session()->flash('error', "الكمية المتوفرة من {$branchProduct->product->name} هي {$stock}. ");
            return;
        }

        $price = (float) ($branchProduct->wholesale_price > 0
            ? $branchProduct->wholesale_price
            : $branchProduct->retail_price);

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity'] = $currentQty + 1;
            return;
        }

        $this->cart[$productId] = [
            'id' => $branchProduct->product->id,
            'name' => $branchProduct->product->name,
            'image' => $branchProduct->product->image,
            'price' => $price,
            'cost' => (float) $branchProduct->product->cost_price,
            'quantity' => 1,
            'stock' => $stock,
        ];
    }

    public function updateQuantity(int $productId, $qty): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $qty = (float) $qty;
        if ($qty <= 0) {
            unset($this->cart[$productId]);
            return;
        }

        $stock = (float) ($this->cart[$productId]['stock'] ?? 0);
        if ($stock > 0 && $qty > $stock) {
            $this->cart[$productId]['quantity'] = $stock;
            session()->flash('error', "الكمية المطلوبة تتجاوز مخزون {$this->cart[$productId]['name']}.");
            return;
        }

        $this->cart[$productId]['quantity'] = $qty;
    }

    public function updatePrice(int $productId, $newPrice): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $price = max(0, (float) $newPrice);
        $this->cart[$productId]['price'] = $price;
    }

    public function removeFromCart(int $productId): void
    {
        unset($this->cart[$productId]);
    }

    public function clearCart(): void
    {
        $this->cart = [];
        $this->paidAmount = 0;
        $this->discountAmount = 0;
        $this->discountRate = 0;
        $this->notes = '';
        $this->selectedCustomerId = null;
        $this->paymentMethod = 'cash';
        $this->sendWhatsapp = false;
    }

    public function setFullPayment(): void
    {
        $this->paidAmount = $this->total;
    }

    public function showLastPrice(int $productId): void
    {
        $tenantId = $this->tenantId();
        $product = Product::query()->where('tenant_id', $tenantId)->find($productId);
        $customer = $this->selectedCustomerId
            ? Party::query()->where('tenant_id', $tenantId)->find($this->selectedCustomerId)
            : null;

        $history = [];

        if ($customer) {
            $items = OrderItem::query()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->whereHas('order', fn ($q) => $q
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $customer->id)
                    ->where('type', 'wholesale')
                    ->where('status', 'completed'))
                ->latest('created_at')
                ->take(10)
                ->get();

            foreach ($items as $item) {
                $history[] = [
                    'price' => (float) $item->unit_price,
                    'quantity' => (float) $item->quantity,
                    'date' => $item->created_at?->format('Y-m-d h:i A') ?? '-',
                ];
            }
        }

        $this->selectedHistoryItem = [
            'product_name' => $product?->name ?? 'منتج غير موجود',
            'customer_name' => $customer?->name ?? 'لم يتم اختيار عميل',
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

    private function customerBalance(?Party $customer): float
    {
        if (!$customer) {
            return 0.0;
        }

        $orders = (float) $customer->orders()
            ->where('tenant_id', $this->tenantId())
            ->where('status', 'completed')
            ->sum('total');

        $payments = (float) $customer->payments()
            ->where('tenant_id', $this->tenantId())
            ->where('type', 'receipt')
            ->sum('amount');

        $supplierPayments = (float) $customer->payments()
            ->where('tenant_id', $this->tenantId())
            ->where('type', 'payment')
            ->sum('amount');

        return (float) $customer->opening_balance + $orders + $supplierPayments - $payments;
    }

    public function completeSale(bool $shouldPrint = false): void
    {
        if (empty($this->cart)) {
            session()->flash('error', 'أضف صنفاً واحداً على الأقل إلى الفاتورة.');
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->branchId();
        $user = $this->currentUser();

        if (!$tenantId || !$branchId || !$user) {
            session()->flash('error', 'لا يمكن إصدار الفاتورة: بيانات المستخدم أو الفرع غير مكتملة.');
            return;
        }

        $customer = null;
        if ($this->selectedCustomerId) {
            $customer = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'both'])
                ->where('is_active', true)
                ->find($this->selectedCustomerId);

            if (!$customer) {
                session()->flash('error', 'العميل المحدد غير صالح.');
                return;
            }
        }

        $subtotal = 0.0;
        $totalCost = 0.0;
        $items = [];
        $previousBalance = 0.0;
        $currentBalance = 0.0;

        try {
            DB::transaction(function () use ($tenantId, $branchId, $user, $customer, &$subtotal, &$totalCost, &$items, &$order, &$payment, &$previousBalance, &$currentBalance) {
                foreach ($this->cart as $rawItem) {
                    $productId = (int) ($rawItem['id'] ?? 0);
                    $quantity = (float) ($rawItem['quantity'] ?? 0);
                    $price = max(0, (float) ($rawItem['price'] ?? 0));

                    if ($productId <= 0 || $quantity <= 0) {
                        throw new \RuntimeException('يوجد صنف أو كمية غير صالحة في الفاتورة.');
                    }

                    $product = Product::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($productId)
                        ->first();

                    if (!$product) {
                        throw new \RuntimeException('أحد المنتجات لم يعد متاحاً في المتجر.');
                    }

                    $branchProduct = BranchProduct::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$branchProduct) {
                        throw new \RuntimeException("المنتج {$product->name} غير مرتبط بالفرع الحالي.");
                    }

                    $available = (float) $branchProduct->stock_quantity;
                    if ($available < $quantity) {
                        throw new \RuntimeException("الكمية المتوفرة من المنتج {$product->name} غير كافية. المتوفر: {$available}.");
                    }

                    $minimumWholesaleQuantity = (float) ($branchProduct->min_wholesale_quantity ?? 1);
                    if ($quantity < $minimumWholesaleQuantity) {
                        throw new \RuntimeException("الحد الأدنى للبيع بالجملة من المنتج {$product->name} هو {$minimumWholesaleQuantity}.");
                    }

                    $lineTotal = round($price * $quantity, 2);
                    $lineCost = round((float) $product->cost_price * $quantity, 2);

                    $subtotal += $lineTotal;
                    $totalCost += $lineCost;

                    $items[] = [
                        'product_id' => $productId,
                        'name' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'cost_price' => (float) $product->cost_price,
                        'total_price' => $lineTotal,
                        'total_cost' => $lineCost,
                    ];
                }

                if (!in_array($this->discountType, ['fixed', 'percentage'], true)) {
                    throw new \RuntimeException('نوع الخصم غير صالح.');
                }

                if (!in_array($this->paymentMethod, ['cash', 'card', 'bank_transfer', 'cheque'], true)) {
                    throw new \RuntimeException('طريقة الدفع غير صالحة.');
                }

                $discount = $this->discountType === 'percentage'
                    ? round($subtotal * min(100, max(0, $this->discountRate)) / 100, 2)
                    : min($subtotal, max(0, $this->discountAmount));

                $total = max(0, round($subtotal - $discount, 2));
                $paid = round(max(0, (float) $this->paidAmount), 2);

                if ($paid > $total) {
                    throw new \RuntimeException('المبلغ المدفوع لا يمكن أن يتجاوز إجمالي الفاتورة.');
                }

                $paymentStatus = $paid >= $total && $total > 0
                    ? 'paid'
                    : ($paid > 0 ? 'partial' : 'unpaid');

                $invoiceNumber = $this->makeInvoiceNumber();

                if ($customer) {
                    $customer = Party::query()
                        ->whereKey($customer->id)
                        ->where('tenant_id', $tenantId)
                        ->lockForUpdate()
                        ->first();

                    if (!$customer) {
                        throw new \RuntimeException('تعذر قفل سجل العميل أثناء حفظ الفاتورة.');
                    }

                    $previousBalance = $this->customerBalance($customer);
                }

                $order = Order::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'shift_id' => null,
                    'created_by' => $user->id,
                    'customer_id' => $customer?->id,
                    'customer_name' => $customer?->name ?? 'زبون عابر',
                    'customer_phone' => $customer?->phone,
                    'invoice_number' => $invoiceNumber,
                    'type' => 'wholesale',
                    'status' => 'completed',
                    'subtotal' => $subtotal,
                    'discount_type' => $this->discountType,
                    'discount_rate' => $this->discountType === 'percentage' ? $this->discountRate : 0,
                    'discount' => $discount,
                    'total' => $total,
                    'total_cost' => $totalCost,
                    'total_profit' => $total - $totalCost,
                    'paid_amount' => $paid,
                    'payment_status' => $paymentStatus,
                    'notes' => trim($this->notes) ?: null,
                ]);

                foreach ($items as $item) {
                    OrderItem::create([
                        'tenant_id' => $tenantId,
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['total_price'],
                        'cost_price' => $item['cost_price'],
                        'total_cost' => $item['total_cost'],
                    ]);

                    BranchProduct::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->where('product_id', $item['product_id'])
                        ->decrement('stock_quantity', $item['quantity']);
                }

                if ($paid > 0) {
                    $payment = Payment::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'shift_id' => null,
                        'created_by' => $user->id,
                        'type' => 'receipt',
                        'voucher_number' => 'RCV-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),
                        'payable_type' => $customer ? Party::class : null,
                        'payable_id' => $customer?->id,
                        'amount' => $paid,
                        'payment_method' => $this->paymentMethod,
                        'order_id' => $order->id,
                        'notes' => 'دفعة على الفاتورة رقم: ' . $order->invoice_number,
                        'payment_date' => now(),
                    ]);
                } else {
                    $payment = null;
                }

                if ($customer) {
                    $currentBalance = round($previousBalance + $total - $paid, 2);
                    $customer->update(['current_balance' => $currentBalance]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
            session()->flash('error', $e instanceof \RuntimeException ? $e->getMessage() : 'تعذر حفظ الفاتورة، لم يتم إجراء أي تغيير.');
            return;
        }

        if ($shouldPrint && $order) {
            $this->dispatch('do-kiosk-print', data: [
                'header_title' => 'فاتورة مبيعات جملة',
                'invoice_no' => $order->invoice_number,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'date' => $order->created_at->format('Y-m-d h:i A'),
                'items' => $items,
                'subtotal' => (float) $order->subtotal,
                'discount' => (float) $order->discount,
                'total' => (float) $order->total,
                'paid_amount' => (float) $order->paid_amount,
                'remaining_amount' => max(0, (float) $order->total - (float) $order->paid_amount),
                'previous_balance' => $previousBalance,
                'current_balance' => $currentBalance,
                'payment_method' => $this->paymentMethodLabel(),
                'notes' => $order->notes,
            ]);
        }

        if ($this->sendWhatsapp && $order && $order->customer_phone) {
            $this->dispatch('open-whatsapp-url', url: $this->whatsappUrl($order, $items));
        }

        $invoiceNumber = $order?->invoice_number;
        $this->clearCart();
        session()->flash('message', "تم حفظ فاتورة الجملة رقم {$invoiceNumber} بنجاح.");
    }

    private function makeInvoiceNumber(): string
    {
        do {
            $number = 'WS-' . now()->format('Ymd') . '-' . now()->format('His') . '-' . Str::upper(Str::random(3));
        } while (Order::query()->where('tenant_id', $this->tenantId())->where('invoice_number', $number)->exists());

        return $number;
    }

    private function paymentMethodLabel(): string
    {
        return match ($this->paymentMethod) {
            'card' => 'بطاقة',
            'bank_transfer' => 'تحويل بنكي',
            'cheque' => 'شيك',
            default => 'نقداً',
        };
    }

    private function whatsappUrl(Order $order, array $items): string
    {
        $phone = preg_replace('/\D+/', '', (string) $order->customer_phone);
        $text = "مرحباً {$order->customer_name}،\n";
        $text .= "تم إصدار فاتورة مبيعات جملة رقم {$order->invoice_number}.\n\n";

        foreach ($items as $item) {
            $text .= "• {$item['name']} × {$item['quantity']} = " . number_format($item['total_price'], 2) . " شيكل\n";
        }

        $text .= "\nالإجمالي: " . number_format($order->total, 2) . " شيكل\n";
        $text .= "المدفوع: " . number_format($order->paid_amount, 2) . " شيكل\n";
        $text .= "المتبقي: " . number_format(max(0, $order->total - $order->paid_amount), 2) . " شيكل\n";

        if ($order->notes) {
            $text .= "ملاحظات: {$order->notes}\n";
        }

        return 'https://wa.me/' . $phone . '?text=' . urlencode($text);
    }

    public function getSubtotalProperty(): float
    {
        return round(array_reduce($this->cart, fn ($sum, $item) => $sum + ((float) $item['price'] * (float) $item['quantity']), 0), 2);
    }

    public function getDiscountProperty(): float
    {
        $subtotal = $this->subtotal;

        return $this->discountType === 'percentage'
            ? round($subtotal * min(100, max(0, $this->discountRate)) / 100, 2)
            : min($subtotal, max(0, $this->discountAmount));
    }

    public function getTotalProperty(): float
    {
        return max(0, round($this->subtotal - $this->discount, 2));
    }

    public function getRemainingProperty(): float
    {
        return max(0, round($this->total - max(0, (float) $this->paidAmount), 2));
    }

    public function getSelectedCustomerBalanceProperty(): float
    {
        if (!$this->selectedCustomerId) {
            return 0;
        }

        $customer = Party::query()
            ->where('tenant_id', $this->tenantId())
            ->find($this->selectedCustomerId);

        return $this->customerBalance($customer);
    }

    public function render()
    {
        $tenantId = $this->tenantId();
        $branchId = $this->branchId();
        $term = trim($this->search);

        $products = Product::query()
            ->where('products.tenant_id', $tenantId ?: 0)
            ->when($branchId, fn ($q) => $q->whereHas('branchProducts', fn ($bp) => $bp->where('branch_id', $branchId)))
            ->when($term !== '', function ($q) use ($term) {
                $q->where(function ($sub) use ($term) {
                    $like = "%{$term}%";
                    $sub->where('name', 'like', $like)
                        ->orWhereHas('barcodes', fn ($barcode) => $barcode->where('barcode', 'like', $like));
                });
            }, fn ($q) => $q->whereRaw('1 = 0'))
            ->with(['branchProducts' => fn ($q) => $q->where('branch_id', $branchId)])
            ->orderBy('name')
            ->paginate(16);

        $customers = Party::query()
            ->where('tenant_id', $tenantId ?: 0)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return $this->view([
            'products' => $products,
            'customers' => $customers,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="p-2 sm:p-4" dir="rtl">
    <div class="min-h-[calc(100vh-5rem)] bg-zinc-50 dark:bg-zinc-950 rounded-2xl">
        <div class="max-w-[1600px] mx-auto space-y-3">
            @if (session()->has('error'))
                <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-300">
                    {{ session('error') }}
                </div>
            @endif
            @if (session()->has('message'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300">
                    {{ session('message') }}
                </div>
            @endif

            @if (!$this->branchId())
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300">
                    هذا المستخدم غير مرتبط بفرع. اربطه بفرع أولاً حتى يتم تسجيل المخزون والفاتورة في الفرع الصحيح.
                </div>
            @endif

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-3">
                <section class="xl:col-span-7 flex min-h-0 flex-col gap-3">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <div class="flex-1">
                                <flux:input
                                    id="wholesale-product-search"
                                    wire:model.live.debounce.180ms="search"
                                    wire:keydown.enter="searchBarcode"
                                    icon="magnifying-glass"
                                    placeholder="ابحث باسم الصنف أو امسح الباركود ثم Enter..."
                                    autofocus
                                />
                            </div>
                            <div class="flex items-center gap-2 rounded-xl bg-zinc-100 px-3 py-2 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                <flux:icon name="building-storefront" class="size-4" />
                                <span>فرع المستخدم: {{ auth()->user()?->branch?->name ?? 'غير محدد' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <h2 class="font-bold text-zinc-900 dark:text-zinc-100">أصناف الجملة</h2>
                                <p class="text-xs text-zinc-500">اختر الصنف لإضافته إلى الفاتورة. الكمية محدودة بمخزون الفرع.</p>
                            </div>
                            @if ($search !== '')
                                <flux:button size="sm" variant="subtle" wire:click="$set('search', '')">مسح البحث</flux:button>
                            @endif
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-2.5">
                            @forelse ($products as $product)
                                @php
                                    $bp = $product->branchProducts->first();
                                    $price = (float) ($bp?->wholesale_price > 0 ? $bp->wholesale_price : ($bp?->retail_price ?? 0));
                                    $stock = (float) ($bp?->stock_quantity ?? 0);
                                @endphp
                                <button
                                    type="button"
                                    wire:click="addToCart({{ $product->id }})"
                                    @disabled(!$bp || $stock <= 0)
                                    class="group rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-right transition hover:-translate-y-0.5 hover:border-indigo-400 hover:bg-white hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-800 dark:bg-zinc-950/60 dark:hover:bg-zinc-900"
                                >
                                    <div class="mb-2 line-clamp-2 min-h-10 text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $product->name }}</div>
                                    <div class="flex items-end justify-between gap-2 border-t border-zinc-200 pt-2 dark:border-zinc-800">
                                        <div>
                                            <div class="text-[10px] text-zinc-400">سعر الجملة</div>
                                            <div class="font-mono font-bold text-indigo-600 dark:text-indigo-400">{{ number_format($price, 2) }}</div>
                                        </div>
                                        <div class="text-left">
                                            <div class="text-[10px] text-zinc-400">المخزون</div>
                                            <div class="font-mono text-xs font-semibold {{ $stock <= 0 ? 'text-rose-500' : 'text-emerald-600 dark:text-emerald-400' }}">{{ rtrim(rtrim(number_format($stock, 2), '0'), '.') }}</div>
                                        </div>
                                    </div>
                                </button>
                            @empty
                                <div class="col-span-full rounded-xl border border-dashed border-zinc-300 py-12 text-center text-sm text-zinc-400 dark:border-zinc-700">
                                    {{ $search !== '' ? 'لا توجد أصناف مطابقة للبحث.' : 'ابدأ بالبحث عن صنف أو امسح الباركود.' }}
                                </div>
                            @endforelse
                        </div>

                        @if ($products->hasPages())
                            <div class="mt-3">{{ $products->links() }}</div>
                        @endif
                    </div>
                </section>

                <aside class="xl:col-span-5">
                    <div class="sticky top-3 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                            <div class="flex items-center justify-between">
                                <div>
                                    <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">فاتورة مبيعات جملة</div>
                                    <div class="text-xs text-zinc-500">إنشاء فاتورة مستقلة عن الشيفتات</div>
                                </div>
                                <flux:button size="sm" variant="subtle" wire:click="clearCart" :disabled="empty($cart)">فاتورة جديدة</flux:button>
                            </div>
                        </div>

                        <div class="space-y-3 p-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-300">العميل</label>
                                <select wire:model.live="selectedCustomerId" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-800 focus:border-indigo-500 focus:ring-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                                    <option value="">زبون عابر — بدون حساب</option>
                                    @foreach ($customers as $customer)
                                        <option value="{{ $customer->id }}">{{ $customer->name }}{{ $customer->phone ? ' — '.$customer->phone : '' }}</option>
                                    @endforeach
                                </select>
                                @if ($selectedCustomerId)
                                    <div class="mt-1 flex justify-between text-xs">
                                        <span class="text-zinc-500">الرصيد السابق</span>
                                        <span class="font-mono font-bold {{ $this->selectedCustomerBalance > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ number_format($this->selectedCustomerBalance, 2) }} شيكل</span>
                                    </div>
                                @endif
                            </div>

                            <div class="max-h-[38vh] overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                                @forelse ($cart as $id => $item)
                                    <div wire:key="wholesale-cart-{{ $id }}" class="border-b border-zinc-100 p-3 last:border-0 dark:border-zinc-800">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="min-w-0 flex-1">
                                                <div class="flex items-center gap-1">
                                                    <div class="truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $item['name'] }}</div>
                                                    <button type="button" wire:click="showLastPrice({{ $id }})" title="سجل أسعار هذا العميل" class="text-indigo-500 hover:text-indigo-700">
                                                        <flux:icon name="clock" class="size-3.5" />
                                                    </button>
                                                </div>
                                                <div class="mt-2 flex items-center gap-2">
                                                    <label class="text-[11px] text-zinc-400">السعر</label>
                                                    <input type="number" min="0" step="0.01" value="{{ $item['price'] }}" wire:change="updatePrice({{ $id }}, $event.target.value)" class="w-24 rounded-lg border border-zinc-300 bg-zinc-50 px-2 py-1 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800" />
                                                </div>
                                            </div>
                                            <button type="button" wire:click="removeFromCart({{ $id }})" class="text-zinc-400 hover:text-rose-500">
                                                <flux:icon name="trash" class="size-4" />
                                            </button>
                                        </div>
                                        <div class="mt-2 flex items-center justify-between">
                                            <div class="flex items-center gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                                                <button type="button" wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] - 1 }})" class="size-7 rounded-md hover:bg-white dark:hover:bg-zinc-700">−</button>
                                                <span class="min-w-8 text-center text-sm font-bold font-mono">{{ rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') }}</span>
                                                <button type="button" wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] + 1 }})" class="size-7 rounded-md hover:bg-white dark:hover:bg-zinc-700">+</button>
                                            </div>
                                            <div class="text-left">
                                                <div class="text-[10px] text-zinc-400">{{ number_format($item['price'], 2) }} × {{ $item['quantity'] }}</div>
                                                <div class="font-mono font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($item['price'] * $item['quantity'], 2) }}</div>
                                            </div>
                                        </div>
                                    </div>
                                @empty
                                    <div class="py-12 text-center text-sm text-zinc-400">لم تتم إضافة أي أصناف.</div>
                                @endforelse
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1 block text-xs text-zinc-500">الخصم</label>
                                    <div class="flex gap-1">
                                        <input type="number" min="0" step="0.01" wire:model.live="{{ $discountType === 'percentage' ? 'discountRate' : 'discountAmount' }}" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800" />
                                        <select wire:model.live="discountType" class="w-20 rounded-xl border border-zinc-300 bg-white px-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                            <option value="fixed">₪</option>
                                            <option value="percentage">%</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-zinc-500">طريقة الدفع</label>
                                    <select wire:model.live="paymentMethod" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                        <option value="cash">نقداً</option>
                                        <option value="card">بطاقة</option>
                                        <option value="bank_transfer">تحويل بنكي</option>
                                        <option value="cheque">شيك</option>
                                    </select>
                                </div>
                            </div>

                            <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                <div class="flex justify-between text-sm"><span class="text-zinc-500">الإجمالي قبل الخصم</span><span class="font-mono">{{ number_format($this->subtotal, 2) }}</span></div>
                                <div class="mt-1 flex justify-between text-sm"><span class="text-zinc-500">الخصم</span><span class="font-mono text-rose-600">- {{ number_format($this->discount, 2) }}</span></div>
                                <div class="mt-2 flex justify-between border-t border-zinc-200 pt-2 text-lg font-black dark:border-zinc-700"><span>الصافي</span><span class="font-mono text-indigo-600 dark:text-indigo-400">{{ number_format($this->total, 2) }} ₪</span></div>
                            </div>

                            <div>
                                <div class="mb-1 flex items-center justify-between">
                                    <label class="text-xs font-medium text-zinc-600 dark:text-zinc-300">المبلغ المدفوع</label>
                                    <button type="button" wire:click="setFullPayment" class="text-xs font-semibold text-indigo-600 hover:underline">دفع كامل</button>
                                </div>
                                <input type="number" min="0" step="0.01" wire:model.live="paidAmount" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-lg font-bold font-mono dark:border-zinc-700 dark:bg-zinc-800" />
                                <div class="mt-1 flex justify-between text-xs"><span class="text-zinc-500">المتبقي على الحساب</span><span class="font-mono font-bold {{ $this->remaining > 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ number_format($this->remaining, 2) }} ₪</span></div>
                            </div>

                            <textarea wire:model.live="notes" rows="2" placeholder="ملاحظات الفاتورة..." class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>

                            <label class="flex cursor-pointer items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300">
                                <input type="checkbox" wire:model.live="sendWhatsapp" class="rounded border-zinc-300 text-indigo-600" />
                                فتح واتساب للعميل بعد الحفظ إذا كان لديه رقم
                            </label>

                            <div class="grid grid-cols-2 gap-2 pt-1">
                                <flux:button variant="filled" class="w-full" wire:click="completeSale(false)" :disabled="empty($cart) || !$this->branchId()">
                                    حفظ الفاتورة
                                </flux:button>
                                <flux:button variant="primary" icon="printer" class="w-full" wire:click="completeSale(true)" :disabled="empty($cart) || !$this->branchId()">
                                    حفظ وطباعة
                                </flux:button>
                            </div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </div>

    @if ($showPriceHistoryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm" dir="rtl">
            <div class="w-full max-w-lg rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">
                    <div>
                        <div class="font-bold">سجل أسعار البيع</div>
                        <div class="text-xs text-zinc-500">{{ $selectedHistoryItem['product_name'] ?? '-' }} — {{ $selectedHistoryItem['customer_name'] ?? '-' }}</div>
                    </div>
                    <button type="button" wire:click="closePriceHistoryModal" class="text-zinc-400 hover:text-zinc-700">✕</button>
                </div>
                <div class="p-5">
                    @if ($selectedHistoryItem['has_history'] ?? false)
                        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800">
                            <table class="w-full text-right text-sm">
                                <thead class="bg-zinc-50 dark:bg-zinc-800/70">
                                    <tr><th class="px-3 py-2">السعر</th><th class="px-3 py-2">الكمية</th><th class="px-3 py-2">التاريخ</th></tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($selectedHistoryItem['history'] as $row)
                                        <tr><td class="px-3 py-2 font-mono font-bold text-emerald-600">{{ number_format($row['price'], 2) }}</td><td class="px-3 py-2 font-mono">{{ $row['quantity'] }}</td><td class="px-3 py-2 text-xs text-zinc-500">{{ $row['date'] }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="rounded-xl bg-zinc-50 py-10 text-center text-sm text-zinc-400 dark:bg-zinc-800/50">لا يوجد بيع سابق لهذا الصنف مع هذا العميل.</div>
                    @endif
                </div>
            </div>
        </div>
    @endif
</flux:main>

@script
<script>
    Livewire.hook('commit', ({ respond }) => {
        respond(() => {
            const active = document.activeElement;
            const isTyping = active && ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName);
            if (!isTyping) document.getElementById('wholesale-product-search')?.focus();
        });
    });

    $wire.on('do-kiosk-print', (event) => {
        const inv = event.data;
        const line = (label, value, width = 32) => {
            const l = String(label ?? '');
            const r = String(value ?? '');
            const spaces = Math.max(1, width - l.length - r.length);
            return r + ' '.repeat(spaces) + l + '\n';
        };
        const shortName = (name) => {
            const words = String(name || '').trim().split(/\s+/);
            return words.length > 3 ? words.slice(0, 3).join(' ') + '...' : String(name || '');
        };

        let text = '';
        text += '================================\n';
        text += '        فاتورة مبيعات جملة\n';
        text += '================================\n';
        text += line('رقم الفاتورة:', inv.invoice_no);
        text += line('التاريخ:', inv.date);
        text += line('العميل:', inv.customer_name);
        text += '--------------------------------\n';
        text += 'الصنف                 الكمية  المجموع\n';
        text += '--------------------------------\n';
        (inv.items || []).forEach(item => {
            const qty = Number(item.quantity || 0);
            const total = Number(item.total_price || 0);
            text += line(`${shortName(item.name)} ×${qty}`, total.toFixed(2));
        });
        text += '================================\n';
        text += line('الإجمالي قبل الخصم:', Number(inv.subtotal || 0).toFixed(2) + ' ₪');
        text += line('الخصم:', Number(inv.discount || 0).toFixed(2) + ' ₪');
        text += line('الصافي:', Number(inv.total || 0).toFixed(2) + ' ₪');
        text += line('المدفوع:', Number(inv.paid_amount || 0).toFixed(2) + ' ₪');
        text += line('المتبقي:', Number(inv.remaining_amount || 0).toFixed(2) + ' ₪');
        text += line('طريقة الدفع:', inv.payment_method || 'نقداً');
        if (Number(inv.previous_balance || 0) !== 0 || Number(inv.current_balance || 0) !== 0) {
            text += '--------------------------------\n';
            text += line('الرصيد السابق:', Number(inv.previous_balance || 0).toFixed(2) + ' ₪');
            text += line('الرصيد الحالي:', Number(inv.current_balance || 0).toFixed(2) + ' ₪');
        }
        if (inv.notes) text += '\nملاحظات: ' + inv.notes + '\n';
        text += '================================\n\n\n';

        const intentUrl = 'intent:' + encodeURIComponent(text) + '#Intent;' +
            'scheme=rawbt;package=ru.a402d.rawbtprinter;S.type=text/plain;end;';
        window.location.href = intentUrl;
    });

    $wire.on('open-whatsapp-url', (event) => window.open(event.url, '_blank', 'noopener,noreferrer'));
</script>
@endscript
