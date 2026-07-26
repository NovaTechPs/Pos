<?php

use Livewire\Component;

new class extends Component {
    public string $customerName = 'زبون نقدي';
    public float $discount = 0.0;
    public float $taxRate = 0.0;

    public array $cart = [];

    // الأصناف التجريبية
    public array $products = [['id' => 1, 'barcode' => '3518646058875', 'name' => 'مشروب غازي 500 مل', 'price' => 2.5], ['id' => 2, 'barcode' => '6251604000093', 'name' => 'أرز بسمتي 1 كغم', 'price' => 8.5], ['id' => 3, 'barcode' => '6251581068208', 'name' => 'زيت عباد الشمس 1 لتر', 'price' => 12.0], ['id' => 4, 'barcode' => '6251581310673', 'name' => 'شوكولاتة داكنة 100 غرام', 'price' => 4.0]];

    // دالة خاصة لمعالجة الباركود المرسل فوراً من JavaScript
    public function scanBarcode(string $code): void
    {
        $code = trim($code);
        if (empty($code)) {
            return;
        }

        $product = collect($this->products)->first(function ($item) use ($code) {
            return $item['barcode'] === $code || str_contains(mb_strtolower($item['name']), mb_strtolower($code));
        });

        if ($product) {
            $this->addToCart($product);
        }
    }

    public function addToCart(array $product): void
    {
        $id = $product['id'];

        if (isset($this->cart[$id])) {
            $this->cart[$id]['qty']++;
        } else {
            $this->cart[$id] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'],
                'barcode' => $product['barcode'],
                'qty' => 1,
            ];
        }
    }

    public function updateQty(int $productId, int $change): void
    {
        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['qty'] += $change;
            if ($this->cart[$productId]['qty'] <= 0) {
                $this->removeFromCart($productId);
            }
        }
    }

    public function removeFromCart(int $productId): void
    {
        unset($this->cart[$productId]);
    }

    public function newInvoice(): void
    {
        $this->cart = [];
        $this->discount = 0.0;
        $this->customerName = 'زبون نقدي';
    }

    public function getSubtotalProperty(): float
    {
        return array_reduce(
            $this->cart,
            function ($carry, $item) {
                return $carry + $item['price'] * $item['qty'];
            },
            0.0,
        );
    }

    public function getTaxProperty(): float
    {
        return ($this->subtotal - $this->discount) * ($this->taxRate / 100);
    }

    public function getTotalProperty(): float
    {
        return max(0, $this->subtotal - $this->discount + $this->tax);
    }
};
?>

<div dir="rtl" class="relative min-h-screen bg-slate-100 font-sans text-slate-800 p-4 md:p-6" x-data="{ barcodeInput: '' }"
    @keydown.window.f3.prevent="$wire.newInvoice(); $nextTick(() => $refs.barcodeInput.focus());">
    <!-- FULLSCREEN LOADING LOGO OVERLAY (يظهر فقط أثناء فتح فاتورة جديدة) -->
    <div wire:loading.flex wire:target="newInvoice"
        class="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex flex-col items-center justify-center text-white">
        <div
            class="bg-white/90 p-6 rounded-2xl shadow-2xl flex flex-col items-center gap-3 text-slate-800 animate-bounce">
            <div
                class="w-16 h-16 bg-indigo-600 rounded-2xl flex items-center justify-center text-white font-extrabold text-2xl shadow-lg ring-4 ring-indigo-200 animate-pulse">
                POS
            </div>
            <div class="flex items-center gap-2 font-bold text-sm text-slate-700 mt-2">
                <svg class="animate-spin h-5 w-5 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none"
                    viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                        stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                    </path>
                </svg>
                <span>جاري فتح فاتورة جديدة...</span>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- القسم الأيمن: البحث وشبكة المواد المضافة -->
        <div
            class="lg:col-span-8 bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex flex-col justify-between">
            <div>
                <!-- الهيدر -->
                <div class="flex justify-between items-center mb-6 pb-4 border-b border-slate-100">
                    <div>
                        <h1 class="text-2xl font-bold text-slate-900 tracking-tight">نظام NovoPOS</h1>
                        {{-- زر الخصم في الـ POS --}}
                        ff
                        @can('orders.apply_discount')
                            <button wire:click="openDiscountModal">تطبيق خصم</button>
                        @endcan

                        {{-- رابط تقارير الأرباح في القائمة الجانبية --}}
                        @can('reports.profit')
                            <a href="{{ route('reports.profit') }}">تقارير الأرباح</a>
                        @endcan
                        <p class="text-xs text-slate-500 mt-1">شاشة البيع السريعة</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span
                            class="bg-amber-50 text-amber-700 text-xs font-semibold px-3 py-1.5 rounded-lg border border-amber-200 flex items-center gap-1">
                            <kbd class="bg-amber-200 text-amber-900 px-1.5 py-0.5 rounded text-[10px]">F3</kbd>
                            فاتورة جديدة
                        </span>
                    </div>
                </div>

                <!-- حقل مسح الباركود السريع جدًا -->
                <div class="mb-6">
                    <label class="block text-sm font-medium text-slate-700 mb-2">امسح الباركود (التقاط سريع)</label>
                    <div class="relative">
                        <input x-ref="barcodeInput" x-model="barcodeInput"
                            @keydown.enter.prevent="
                                if (barcodeInput.trim() !== '') {
                                    $wire.scanBarcode(barcodeInput);
                                    barcodeInput = '';
                                }
                            "
                            type="text" placeholder="امسح الباركود بسرعة بالسيستم (مثال: 3518646058875)..."
                            class="w-full pr-10 pl-4 py-3 bg-slate-50 border border-slate-300 rounded-xl focus:ring-2 focus:ring-indigo-500 focus:bg-white focus:outline-none text-slate-800 font-medium transition"
                            autofocus>
                        <div
                            class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none text-slate-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- جدول المواد -->
                <div class="overflow-x-auto rounded-xl border border-slate-200">
                    <table class="w-full text-right border-collapse">
                        <thead>
                            <tr
                                class="bg-slate-50 border-b border-slate-200 text-slate-600 text-xs font-bold uppercase">
                                <th class="py-3.5 px-4">الصنف</th>
                                <th class="py-3.5 px-4">السعر</th>
                                <th class="py-3.5 px-4 text-center">الكمية</th>
                                <th class="py-3.5 px-4 text-left">المجموع</th>
                                <th class="py-3.5 px-4 text-center">إلغاء</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                            @forelse($cart as $item)
                                <tr class="hover:bg-slate-50/80 transition">
                                    <td class="py-3.5 px-4 font-semibold text-slate-800">
                                        {{ $item['name'] }}
                                        <div class="text-xs text-slate-400 font-mono">#{{ $item['barcode'] }}</div>
                                    </td>
                                    <td class="py-3.5 px-4 text-slate-600 font-medium">
                                        {{ number_format($item['price'], 2) }}</td>
                                    <td class="py-3.5 px-4 text-center">
                                        <div
                                            class="inline-flex items-center gap-1 bg-slate-100 rounded-lg p-1 border border-slate-200">
                                            <button wire:click="updateQty({{ $item['id'] }}, -1)"
                                                class="w-7 h-7 bg-white rounded-md shadow-sm hover:bg-slate-200 font-bold text-slate-700 transition">-</button>
                                            <span class="px-3 font-bold text-slate-800">{{ $item['qty'] }}</span>
                                            <button wire:click="updateQty({{ $item['id'] }}, 1)"
                                                class="w-7 h-7 bg-white rounded-md shadow-sm hover:bg-slate-200 font-bold text-slate-700 transition">+</button>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4 text-left font-bold text-slate-900">
                                        {{ number_format($item['price'] * $item['qty'], 2) }}
                                    </td>
                                    <td class="py-3.5 px-4 text-center">
                                        <button wire:click="removeFromCart({{ $item['id'] }})"
                                            class="text-red-400 hover:text-red-600 p-1 transition">
                                            <svg class="w-5 h-5 inline" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                                </path>
                                            </svg>
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-16 text-center text-slate-400">
                                        الفاتورة فارغة حالياً. امسح الباركود أو اضغط <kbd
                                            class="px-1.5 py-0.5 bg-slate-200 text-slate-700 rounded text-xs font-mono">F3</kbd>
                                        لفاتورة جديدة.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6 pt-4 border-t border-slate-100 flex justify-between items-center">
                <button wire:click="newInvoice"
                    class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-bold transition flex items-center gap-2">
                    <span>فاتورة جديدة</span>
                    <kbd class="bg-slate-700 px-1.5 py-0.5 rounded text-[10px] font-mono">F3</kbd>
                </button>
                <span class="text-xs text-slate-400">عدد المواد: {{ count($cart) }}</span>
            </div>
        </div>

        <!-- القسم الأيسر: ملخص الحسابات -->
        <div
            class="lg:col-span-4 bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex flex-col justify-between">
            <div>
                <h2 class="text-lg font-bold text-slate-900 mb-4 pb-3 border-b border-slate-100">تفاصيل الفاتورة</h2>

                <div class="mb-5">
                    <label class="block text-xs font-bold text-slate-600 uppercase mb-2">اسم الزبون / العميل</label>
                    <input type="text" wire:model.live="customerName"
                        class="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm bg-slate-50 focus:bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none transition">
                </div>

                <div class="space-y-3 border-t border-slate-100 pt-4 text-sm">
                    <div class="flex justify-between text-slate-600 font-medium">
                        <span>المجموع الفرعي</span>
                        <span class="font-bold text-slate-800">{{ number_format($this->subtotal, 2) }}</span>
                    </div>

                    <div class="flex justify-between items-center text-slate-600 font-medium">
                        <span>الخصم</span>
                        <input type="number" step="0.5" wire:model.live="discount"
                            class="w-24 px-2 py-1 text-left border border-slate-300 rounded-lg text-sm bg-slate-50 focus:bg-white focus:outline-none font-bold text-slate-800">
                    </div>

                    <div
                        class="border-t border-slate-200 pt-3 mt-3 flex justify-between text-xl font-extrabold text-slate-900">
                        <span>المبلغ الإجمالي</span>
                        <span class="text-indigo-600">{{ number_format($this->total, 2) }}</span>
                    </div>
                </div>
            </div>

            <div class="mt-8">
                <button onclick="window.print()" @if (empty($cart)) disabled @endif
                    class="w-full py-4 px-4 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed text-white font-bold rounded-xl shadow-lg shadow-indigo-600/20 transition flex items-center justify-center gap-2 text-base">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z">
                        </path>
                    </svg>
                    طباعة تجريبية
                </button>
            </div>
        </div>

    </div>
</div>
