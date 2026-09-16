<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $cart = [];

    // بيانات العميل والملاحظات
    public ?int $selectedCustomerId = null;
    public ?string $notes = '';

    // متغيرات مودال سجل أسعار البيع
    public bool $showPriceHistoryModal = false;
    public ?array $selectedHistoryItem = null;

    private function getTenantId(): ?int
    {
        $user = auth()->user();

        if (!$user) return null;
        if (!empty($user->tenant_id)) return (int) $user->tenant_id;
        if (method_exists($user, 'tenants')) return $user->tenants()->first()?->id;

        return session('active_tenant_id') ? (int) session('active_tenant_id') : null;
    }

    private function getUserBranchId(): ?int
    {
        return auth()->user()?->branch_id;
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    // جلب آخر 10 عمليات بيع للمنتج مع العميل المحدد
    public function showLastPrice(int $productId): void
    {
        $tenantId = $this->getTenantId();

        $product = Product::find($productId);
        $customer = $this->selectedCustomerId ? Customer::find($this->selectedCustomerId) : null;

        $history = [];

        if ($this->selectedCustomerId) {
            $historyItems = OrderItem::whereHas('order', function ($q) {
                $q->where('customer_id', $this->selectedCustomerId)
                  ->where('status', 'completed');
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
                    'date' => $item->created_at ? $item->created_at->format('Y-m-d H:i') : '-',
                ];
            }
        }

        $this->selectedHistoryItem = [
            'product_name' => $product?->name ?? '',
            'customer_name' => $customer?->name ?? 'زبون عابر (لم يتم تحديد عميل)',
            'history' => $history,
            'has_history' => !empty($history)
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
        if (!$tenantId) return;

        $branchId = $this->getUserBranchId();

        $product = Product::where('products.tenant_id', $tenantId)
            ->leftJoin('branch_products', function ($join) use ($branchId) {
                $join->on('products.id', '=', 'branch_products.product_id')
                     ->where('branch_products.branch_id', '=', $branchId);
            })
            ->select(
                'products.*',
                'branch_products.retail_price as branch_retail_price',
                'branch_products.wholesale_price as branch_wholesale_price'
            )
            ->where('products.id', $productId)
            ->first();

        if (!$product) return;

        $price = (float) (
            $product->branch_wholesale_price
            ?? $product->branch_retail_price
            ?? $product->wholesale_price
            ?? $product->retail_price
            ?? $product->price
            ?? 0
        );

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

    // تعديل السعر المباشر للمنتج في السلة
    public function updatePrice(int $productId, $newPrice): void
    {
        $price = (float) $newPrice;
        if (isset($this->cart[$productId]) && $price >= 0) {
            $this->cart[$productId]['price'] = $price;
        }
    }

    public function completeSale(bool $shouldPrint = false): void
    {
        if (empty($this->cart)) return;

        $tenantId = $this->getTenantId();
        if (!$tenantId) return;

        $customer = null;
        if ($this->selectedCustomerId) {
            $customer = Customer::where('tenant_id', $tenantId)->find($this->selectedCustomerId);
        }

        $subtotal = array_reduce($this->cart, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);
        $totalCost = array_reduce($this->cart, fn($sum, $item) => $sum + ($item['cost'] * $item['quantity']), 0);

        $order = null;

        DB::transaction(function () use ($tenantId, $subtotal, $totalCost, $customer, &$order) {
            $order = Order::create([
                'tenant_id' => $tenantId,
                'branch_id' => $this->getUserBranchId(),
                'customer_id' => $customer?->id,
                'customer_name' => $customer?->name ?? 'زبون جملة عابر',
                'customer_phone' => $customer?->phone,
                'invoice_number' => 'INV-VAN-' . date('Ymd') . '-' . rand(100, 999),
                'type' => 'wholesale',
                'status' => 'completed',
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'total_cost' => $totalCost,
                'total_profit' => $subtotal - $totalCost,
                'paid_amount' => $subtotal,
                'payment_status' => 'paid',
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
        });

        if ($shouldPrint && $order) {
            $printableOrder = [
                'store_name' => auth()->user()->name ?? 'مبيعات الجملة',
                'invoice_no' => $order->invoice_number,
                'customer_name' => $order->customer_name,
                'customer_phone' => $order->customer_phone,
                'date' => $order->created_at->format('Y-m-d H:i'),
                'items' => array_values($this->cart),
                'total' => $subtotal,
                'notes' => $this->notes,
            ];

            $this->dispatch('do-kiosk-print', data: $printableOrder);
        }

        $this->cart = [];
        $this->reset(['selectedCustomerId', 'notes']);
        session()->flash('message', 'تم إصدار فاتورة الجملة بنجاح!');
    }

    public function render()
    {
        $tenantId = $this->getTenantId();
        $branchId = $this->getUserBranchId();

        $products = Product::where('products.tenant_id', $tenantId)
            ->leftJoin('branch_products', function ($join) use ($branchId) {
                $join->on('products.id', '=', 'branch_products.product_id')
                     ->where('branch_products.branch_id', '=', $branchId);
            })
            ->select(
                'products.*',
                'branch_products.retail_price as branch_retail_price',
                'branch_products.wholesale_price as branch_wholesale_price'
            )
            ->when($this->search, function ($q) {
                $term = "%{$this->search}%";
                $q->where(function ($sub) use ($term) {
                    $sub->where('products.name', 'like', $term)
                        ->orWhereExists(function ($query) use ($term) {
                            $query->select(DB::raw(1))
                                  ->from('product_barcodes')
                                  ->whereColumn('product_barcodes.product_id', 'products.id')
                                  ->where('product_barcodes.barcode', 'like', $term);
                        });
                });
            })
            ->paginate(12);

        $customers = Customer::where('tenant_id', $tenantId)->get(['id', 'name', 'phone']);

        $cartTotal = array_reduce($this->cart, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);

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
        <!-- التنبيهات -->
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
            <!-- قسم المنتجات (يمين) -->
            <div class="lg:col-span-7 xl:col-span-8 flex flex-col space-y-3 lg:h-full lg:overflow-hidden">
                <!-- شريط البحث -->
                <div class="bg-white dark:bg-zinc-900 p-2 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm">
                    <flux:input wire:model.live.debounce.200ms="search" placeholder="بحث باسم المنتج أو الباركود..." icon="magnifying-glass" class="w-full" />
                </div>

                <!-- شبكة المنتجات -->
                <div class="lg:flex-1 lg:overflow-y-auto grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2.5 p-0.5 content-start max-h-[45vh] lg:max-h-none overflow-y-auto">
                    @forelse($products as $product)
                        @php
                            $effectivePrice = $product->branch_wholesale_price
                                ?? $product->branch_retail_price
                                ?? $product->wholesale_price
                                ?? $product->retail_price
                                ?? $product->price
                                ?? 0;
                        @endphp
                        <button wire:click="addToCart({{ $product->id }})"
                                class="flex flex-col h-40 sm:h-48 justify-between p-2 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl hover:border-indigo-500 hover:shadow-md transition text-right group">

                            <div class="w-full h-20 sm:h-24 bg-zinc-50 dark:bg-zinc-800/60 rounded-lg overflow-hidden flex items-center justify-center p-1 border border-zinc-100 dark:border-zinc-800">
                                @if($product->image)
                                    <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}" class="w-full h-full object-contain group-hover:scale-105 transition duration-200">
                                @else
                                    <flux:icon icon="photo" class="w-6 h-6 text-zinc-300 dark:text-zinc-600" />
                                @endif
                            </div>

                            <div class="font-semibold text-xs text-zinc-800 dark:text-zinc-200 line-clamp-2 my-1 leading-tight">
                                {{ $product->name }}
                            </div>

                            <div class="flex justify-between items-center w-full pt-1.5 border-t border-zinc-100 dark:border-zinc-800/80">
                                <span class="text-[9px] text-zinc-400">سعر الجملة</span>
                                <span class="font-bold text-indigo-600 dark:text-indigo-400 text-xs sm:text-sm">
                                    {{ number_format($effectivePrice, 2) }}
                                </span>
                            </div>
                        </button>
                    @empty
                        <div class="col-span-full text-center py-8 text-zinc-400 text-xs">لا توجد منتجات مطابقة.</div>
                    @endforelse
                </div>

                <div class="pt-1">{{ $products->links() }}</div>
            </div>

            <!-- قسم الفاتورة والسلة (يسار) -->
            <div class="lg:col-span-5 xl:col-span-4 flex flex-col bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl p-3 shadow-sm min-h-[350px] lg:h-full lg:overflow-hidden">
                <div class="flex flex-col h-full justify-between space-y-2">
                    <div class="space-y-2 flex-1 flex flex-col lg:overflow-hidden">
                        <flux:heading size="md" class="border-b border-zinc-100 dark:border-zinc-800 pb-2">فاتورة مبيعات باص</flux:heading>

                        <!-- قائمة اختيار العميل -->
                        <div>
                            <select wire:model.live="selectedCustomerId" class="w-full text-xs border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 dark:text-zinc-200 rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">-- اختر العميل (اختياري: زبون عابر) --</option>
                                @foreach($customers as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->name }} {{ $customer->phone ? "({$customer->phone})" : '' }}</option>
                                @endforeach
                            </select>
                        </div>

                        <!-- حقل الملاحظات -->
                        <div>
                            <flux:input wire:model="notes" placeholder="ملاحظات الفاتورة..." size="sm" />
                        </div>

                        <!-- السلة - قائمة الأصناف -->
                        <div class="flex-1 min-h-[140px] max-h-[220px] lg:max-h-none overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800/60 pr-1">
                            @forelse($cart as $id => $item)
                                <div class="py-2 flex justify-between items-center text-xs gap-2">
                                    <div class="w-7 h-7 rounded bg-zinc-100 dark:bg-zinc-800 overflow-hidden flex-shrink-0 border border-zinc-200 dark:border-zinc-700 flex items-center justify-center p-0.5">
                                        @if(!empty($item['image']))
                                            <img src="{{ Storage::url($item['image']) }}" alt="{{ $item['name'] }}" class="w-full h-full object-contain">
                                        @else
                                            <flux:icon icon="photo" class="w-3.5 h-3.5 text-zinc-400" />
                                        @endif
                                    </div>

                                    <div class="flex-1 truncate">
                                        <div class="font-medium truncate text-zinc-800 dark:text-zinc-200 flex items-center gap-1">
                                            <span>{{ $item['name'] }}</span>
                                            <!-- زر رؤية آخر 10 عمليات بيع -->
                                            <button type="button"
                                                    wire:click="showLastPrice({{ $id }})"
                                                    title="سجل آخر 10 عمليات بيع لهذا الزبون"
                                                    class="text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 transition">
                                                <flux:icon icon="clock" class="w-3.5 h-3.5" />
                                            </button>
                                        </div>

                                        <!-- تعديل السعر المباشر -->
                                        <div class="flex items-center gap-1 mt-0.5">
                                            <span class="text-[10px] text-zinc-400">السعر:</span>
                                            <input type="number"
                                                   step="0.01"
                                                   wire:change="updatePrice({{ $id }}, $event.target.value)"
                                                   value="{{ $item['price'] }}"
                                                   class="w-16 px-1 py-0.5 text-[11px] font-mono border border-zinc-300 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 rounded focus:ring-1 focus:ring-indigo-500 focus:outline-none" />
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-1">
                                        <flux:button size="xs" variant="subtle" wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] - 1 }})">-</flux:button>
                                        <span class="font-bold text-xs px-1 text-zinc-700 dark:text-zinc-300">{{ $item['quantity'] }}</span>
                                        <flux:button size="xs" variant="subtle" wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] + 1 }})">+</flux:button>
                                    </div>
                                </div>
                            @empty
                                <div class="text-center py-6 text-zinc-400 text-xs">السلة فارغة</div>
                            @endforelse
                        </div>
                    </div>

                    <!-- المجموع وخيارات الحفظ والطباعة -->
                    <div class="pt-2 border-t border-zinc-200 dark:border-zinc-800 space-y-2">
                        <div class="flex justify-between items-center font-bold text-sm">
                            <span class="text-zinc-700 dark:text-zinc-300">المجموع:</span>
                            <span class="text-base text-emerald-600 dark:text-emerald-400 font-mono">{{ number_format($cartTotal, 2) }}</span>
                        </div>

                        <div class="grid grid-cols-2 gap-2">
                            <flux:button variant="filled" class="w-full py-2 text-xs" wire:click="completeSale(false)" :disabled="empty($cart)">
                                حفظ فقط
                            </flux:button>

                            <flux:button variant="primary" icon="printer" class="w-full py-2 text-xs" wire:click="completeSale(true)" :disabled="empty($cart)">
                                حفظ وطباعة
                            </flux:button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- مودال عرض آخر 10 عمليات بيع -->
@if($showPriceHistoryModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" dir="rtl">
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl shadow-xl max-w-md w-full p-4 space-y-4">
            <div class="flex justify-between items-center border-b border-zinc-100 dark:border-zinc-800 pb-2">
                <h3 class="font-bold text-sm text-zinc-800 dark:text-zinc-200">
                    سجل آخر 10 عمليات بيع
                </h3>
                <button wire:click="closePriceHistoryModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                    ✕
                </button>
            </div>

            <div class="space-y-2 text-xs">
                <div>
                    <span class="text-zinc-400">المنتج:</span>
                    <span class="font-semibold text-zinc-800 dark:text-zinc-100 mr-1">{{ $selectedHistoryItem['product_name'] ?? '-' }}</span>
                </div>
                <div>
                    <span class="text-zinc-400">الزبون:</span>
                    <span class="font-semibold text-zinc-800 dark:text-zinc-100 mr-1">{{ $selectedHistoryItem['customer_name'] ?? '-' }}</span>
                </div>

                <div class="border-t border-zinc-100 dark:border-zinc-800 pt-3 max-h-60 overflow-y-auto">
                    @if($selectedHistoryItem['has_history'])
                        <table class="w-full text-right text-[11px] border-collapse">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700 text-zinc-400 bg-zinc-50 dark:bg-zinc-800/50">
                                    <th class="p-1.5">السعر</th>
                                    <th class="p-1.5">الكمية</th>
                                    <th class="p-1.5">التاريخ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($selectedHistoryItem['history'] as $row)
                                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                        <td class="p-1.5 font-bold text-emerald-600 dark:text-emerald-400 font-mono">
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
                        <div class="text-center py-6 bg-zinc-50 dark:bg-zinc-800/40 rounded-lg text-zinc-400 text-xs">
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
    $wire.on('do-kiosk-print', (event) => {
        const inv = event.data || event[0];

        // حساب مجموع أعداد القطع/الكميات
        const totalQuantity = (inv.items || []).reduce((sum, item) => sum + Number(item.quantity || 0), 0);

        // تفكيك التاريخ والوقت
        const fullDate = inv.date || '';
        const dateParts = fullDate.split(' ');
        const dateOnly = dateParts[0] || '';
        const timeOnly = dateParts[1] || '';

        // بناء صفوف المنتجات للجدول
        let itemsHtml = '';
        (inv.items || []).forEach((item, index) => {
            let itemTotal = (Number(item.price || 0) * Number(item.quantity || 0)).toFixed(2);
            let itemPrice = Number(item.price || 0).toFixed(2);
            let itemQty = item.quantity || 0;
            let itemName = item.name || '';

            itemsHtml += `
                <tr>
                    <td style="width: 15%; text-align: center;">${itemTotal}</td>
                    <td style="width: 15%; text-align: center;">${itemPrice}</td>
                    <td style="width: 12%; text-align: center;">${itemQty}</td>
                    <td style="width: 48%; text-align: right; font-weight: bold;">${itemName}</td>
                    <td style="width: 10%; text-align: center;">${index + 1}</td>
                </tr>
            `;
        });

        // قوالب HTML كاملة محددة الأبعاد للطابعة الحرارية (80mm / 58mm)
        const htmlTemplate = `
        <!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head>
            <meta charset="UTF-8">
            <style>
                @page { margin: 0; }
                body {
                    font-family: Arial, sans-serif;
                    width: 100%;
                    max-width: 80mm;
                    margin: 0 auto;
                    padding: 5px;
                    box-sizing: border-box;
                    color: #000;
                    font-size: 13px;
                }
                .text-center { text-align: center; }
                .text-right { text-align: right; }
                .text-left { text-align: left; }

                .header-title { font-size: 18px; font-weight: bold; margin-bottom: 4px; }
                .header-sub { font-size: 12px; margin-bottom: 2px; }

                .meta-table { width: 100%; margin-top: 10px; margin-bottom: 5px; border-collapse: collapse; }
                .meta-table td { padding: 2px 0; font-size: 12px; font-weight: bold; }

                .items-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 5px;
                }
                .items-table th, .items-table td {
                    border: 1px solid #000;
                    padding: 4px 2px;
                    font-size: 11px;
                }
                .items-table th {
                    background-color: #fff;
                    font-weight: bold;
                }

                .box-container {
                    border: 1px solid #000;
                    margin-top: 6px;
                    padding: 4px;
                    font-size: 13px;
                    font-weight: bold;
                }

                .double-box {
                    border: 2px solid #000;
                    margin-top: 8px;
                    padding: 6px;
                    font-size: 15px;
                    font-weight: bold;
                    text-align: center;
                }

                .footer {
                    margin-top: 15px;
                    font-size: 11px;
                }
            </style>
        </head>
        <body>
            <!-- الترويسة العليا -->
            <div class="text-center">
                <div class="header-title">${inv.store_name || "فانوس"}</div>
                <div class="header-sub">راجع فاتورتك وتأكد من مشترياتك قبل مغادرة المعرض</div>
                <div class="header-sub" style="font-weight: bold;">النسخة الأصلية</div>
            </div>

            <!-- معلومات الفاتورة: الوقت - التاريخ - رقم الفاتورة -->
            <table class="meta-table">
                <tr>
                    <td style="width: 25%; text-align: right;">${timeOnly} م</td>
                    <td style="width: 40%; text-align: center;">${dateOnly}</td>
                    <td style="width: 35%; text-align: left;">${inv.invoice_no || ''}</td>
                </tr>
            </table>

            <!-- جدول الأصناف بالإطار -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 15%;">مبلغ</th>
                        <th style="width: 15%;">سعر</th>
                        <th style="width: 12%;">كمية</th>
                        <th style="width: 48%;">البيان</th>
                        <th style="width: 10%;">#</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
            </table>

            <!-- مجموع الكميات -->
            <div class="box-container text-center">
                مجموع الكميات : ${totalQuantity}
            </div>

            <!-- المجموع الكلي -->
            <div class="box-container text-center">
                المجموع : ${Number(inv.total || 0).toFixed(2)}
            </div>

            <!-- الصافي للدفع بالإطار العريض -->
            <div class="double-box">
                الصافي للدفع (ش.ض) : ${Number(inv.total || 0).toFixed(2)}
            </div>

            <!-- الملاحظات إن وجدت -->
            ${inv.notes ? `<div class="box-container text-right">ملاحظات: ${inv.notes}</div>` : ''}

            <!-- التذييل -->
            <div class="footer text-center">
                <div>الشامل لايت للمحاسبة</div>
                <div>تاريخ ووقت الطباعة: ${inv.date || ''}</div>
            </div>
        </body>
        </html>
        `;

        // إرسال كود HTML كـ Data URI عبر Intent إلى RawBT
        const encodedHtml = encodeURIComponent(htmlTemplate);
        const intentUrl = "intent:text/html;utf-8," + encodedHtml +
            "#Intent;" +
            "scheme=rawbt;" +
            "package=ru.a402d.rawbtprinter;" +
            "S.type=text/html;" +
            "end;";

        window.location.href = intentUrl;
    });
</script>
@endscript
