<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\BranchProduct;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public string $barcode = '';
    public array $cart = [];
    public float $paid_amount = 0.0;

    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    public function scanBarcode()
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        $trimmedBarcode = trim($this->barcode);
        if ($trimmedBarcode === '') {
            return;
        }

        $product = Product::where('tenant_id', Auth::user()->tenant_id)
            ->where('barcode', $trimmedBarcode)
            ->first();

        if ($product) {
            $this->addToCart($product);
            $this->barcode = '';
        } else {
            $this->errorMessage = 'عذراً، لم يتم العثور على منتج بهذا الباركود!';
            $this->barcode = '';
        }
    }

    public function addToCart(Product $product)
    {
        $productId = $product->id;

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity']++;
        } else {
            $this->cart[$productId] = [
                'id' => $product->id,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'price' => (float) $product->retail_price,
                'offer_quantity' => $product->offer_quantity ? (int) $product->offer_quantity : null,
                'offer_price' => $product->offer_price ? (float) $product->offer_price : null,
                'quantity' => 1,
                'subtotal' => (float) $product->retail_price,
                'has_offer' => false,
            ];
        }

        $this->recalculatePrices();
    }

    public function updateQuantity($productId, $qty)
    {
        $qty = (int) $qty;
        if ($qty <= 0) {
            $this->removeFromCart($productId);
        } else {
            if (isset($this->cart[$productId])) {
                $this->cart[$productId]['quantity'] = $qty;
                $this->recalculatePrices();
            }
        }
    }

    public function removeFromCart($productId)
    {
        unset($this->cart[$productId]);
        $this->recalculatePrices();
    }

    public function clearCart()
    {
        $this->cart = [];
        $this->paid_amount = 0.0;
        $this->errorMessage = null;
        $this->successMessage = 'تم تنظيف محتويات الفاتورة بنجاح.';
    }

    /**
     * تقريب للأعلى لأقرب 0.50 أو 1.00
     */
    private function roundUpToNearestHalf(float $amount): float
    {
        return ceil($amount * 2) / 2;
    }

    private function recalculatePrices()
    {
        foreach ($this->cart as $id => $item) {
            $qty = $item['quantity'];
            $unitPrice = $item['price'];
            $offerQty = $item['offer_quantity'];
            $offerPrice = $item['offer_price'];

            if ($offerQty && $offerPrice && $offerQty > 0 && $qty >= $offerQty) {
                $unitOfferPrice = $offerPrice / $offerQty;
                $rawSubtotal = $qty * $unitOfferPrice;
                $this->cart[$id]['has_offer'] = true;
            } else {
                $rawSubtotal = $qty * $unitPrice;
                $this->cart[$id]['has_offer'] = false;
            }

            $this->cart[$id]['subtotal'] = $this->roundUpToNearestHalf($rawSubtotal);
        }
    }

    public function getTotalProperty()
    {
        return array_sum(array_column($this->cart, 'subtotal'));
    }

    // تم تعديل الدالة لمنع ظهور الاستثناء
    public function getChangeProperty()
    {
        $paid = (float) ($this->paid_amount ?? 0.0);
        return max(0, $paid - $this->total);
    }

    public function checkout(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if (empty($this->cart)) {
            $this->errorMessage = 'الفاتورة فارغة حالياً!';
            return;
        }

        $user = Auth::user();
        $branchId = $user->branch_id ?? Branch::where('tenant_id', $user->tenant_id)->value('id');

        if (!$branchId) {
            $this->errorMessage = 'تعذر إتمام العملية: لا يوجد فرع مرتبطة به هذه الجلسة!';
            return;
        }

        $total = $this->total;

        try {
            DB::transaction(function () use ($user, $branchId, $total) {
                $order = Order::create([
                    'tenant_id' => $user->tenant_id,
                    'branch_id' => $branchId,
                    'user_id' => $user->id,
                    'customer_id' => null,
                    'invoice_number' => 'POS-' . time(),
                    'type' => 'pos',
                    'subtotal' => $total,
                    'discount' => 0,
                    'total' => $total,
                    'paid_amount' => $this->paid_amount > 0 ? $this->paid_amount : $total,
                    'payment_status' => 'paid',
                ]);

                foreach ($this->cart as $item) {
                    OrderItem::create([
                        'tenant_id' => $user->tenant_id,
                        'order_id' => $order->id,
                        'product_id' => $item['id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['quantity'] >= ($item['offer_quantity'] ?? PHP_INT_MAX)
                            ? round($item['offer_price'] / $item['offer_quantity'], 4)
                            : $item['price'],
                        'total_price' => $item['subtotal'],
                    ]);

                    BranchProduct::where('branch_id', $branchId)
                        ->where('product_id', $item['id'])
                        ->decrement('stock_quantity', $item['quantity']);
                }
            });

            $this->cart = [];
            $this->paid_amount = 0.0;
            $this->successMessage = 'تمت عملية البيع وحفظ الفاتورة بنجاح!';
        } catch (\Exception $e) {
            $this->errorMessage = 'خطأ في عملية البيع: ' . $e->getMessage();
        }
    }

    public function render()
    {
        return $this->view()->layout('layouts::tenant');
    }
};
?>

<flux:main class="h-[calc(100vh-4rem)] p-4 bg-slate-50 font-sans">

    <div x-data x-on:keydown.window.f3.prevent="$wire.checkout()" x-on:keydown.window.f4.prevent="$wire.clearCart()"
        class="h-full">

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 h-full">

            <!-- القسم الأيمن -->
            <div class="lg:col-span-8 flex flex-col h-full space-y-4">

                <div
                    class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
                    <form wire:submit.prevent="scanBarcode" class="flex-1 w-full flex items-center gap-2">
                        <div class="relative w-full">
                            <flux:input wire:model="barcode" id="pos-barcode-input" icon="qr-code"
                                placeholder="امسح الباركود هنا أو أدخله يدوياً..." autofocus
                                class="!text-lg !py-2.5 bg-slate-50 border-slate-300 focus:border-indigo-600 focus:bg-white text-slate-900 rounded-xl" />
                        </div>
                        <button type="submit"
                            class="py-2.5 px-6 rounded-xl font-bold bg-indigo-600 hover:bg-indigo-700 text-white transition-all shadow-sm">
                            إضافة
                        </button>
                    </form>

                    <div class="flex items-center gap-2 border-r pr-3 border-slate-200 shrink-0">
                        <button wire:click="checkout" type="button"
                            class="flex items-center gap-1.5 px-3 py-2 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl hover:bg-emerald-100 transition-colors text-xs font-bold">
                            <span
                                class="bg-emerald-600 text-white px-1.5 py-0.5 rounded font-mono text-[11px]">F3</span>
                            <span>حفظ واكتمال</span>
                        </button>

                        <button wire:click="clearCart" type="button"
                            class="flex items-center gap-1.5 px-3 py-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl hover:bg-rose-100 transition-colors text-xs font-bold">
                            <span class="bg-rose-600 text-white px-1.5 py-0.5 rounded font-mono text-[11px]">F4</span>
                            <span>تنظيف</span>
                        </button>
                    </div>
                </div>

                @if ($errorMessage)
                    <div
                        class="bg-rose-50 border border-rose-200 text-rose-700 p-3.5 rounded-xl text-sm font-semibold flex items-center justify-between shadow-sm">
                        <span>{{ $errorMessage }}</span>
                        <button wire:click="$set('errorMessage', null)"
                            class="text-xs text-rose-500 hover:underline">إغلاق</button>
                    </div>
                @endif

                @if ($successMessage)
                    <div
                        class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-3.5 rounded-xl text-sm font-semibold flex items-center justify-between shadow-sm">
                        <span>{{ $successMessage }}</span>
                        <button wire:click="$set('successMessage', null)"
                            class="text-xs text-emerald-600 hover:underline">إغلاق</button>
                    </div>
                @endif

                <div
                    class="flex-1 bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm flex flex-col">
                    <div class="overflow-y-auto flex-1">
                        <table class="w-full text-right text-sm">
                            <thead class="bg-slate-100 sticky top-0 font-bold text-slate-700 border-b border-slate-200">
                                <tr>
                                    <th class="p-4">اسم المنتج</th>
                                    <th class="p-4">السعر الأصلي</th>
                                    <th class="p-4 text-center">الكمية</th>
                                    <th class="p-4">الإجمالي</th>
                                    <th class="p-4 text-center">إجراء</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($cart as $item)
                                    <tr class="hover:bg-indigo-50/40 transition-colors"
                                        wire:key="cart-item-{{ $item['id'] }}">
                                        <td class="p-4 font-semibold text-slate-900">
                                            <div class="flex flex-col gap-1">
                                                <span>{{ $item['name'] }}</span>
                                                <div class="flex items-center gap-2">
                                                    <span class="text-xs text-slate-400 font-normal font-mono">{{ $item['barcode'] }}</span>
                                                    @if($item['offer_quantity'] && $item['offer_price'])
                                                        <span class="text-[10px] bg-amber-100 text-amber-800 px-2 py-0.5 rounded-full font-bold">
                                                            عرض: {{ $item['offer_quantity'] }} بـ {{ number_format($item['offer_price'], 2) }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td class="p-4 font-mono font-medium text-slate-700">
                                            {{ number_format($item['price'], 2) }} ر.س
                                        </td>
                                        <td class="p-4">
                                            <div
                                                class="flex items-center justify-center gap-1.5 bg-slate-100 p-1 rounded-xl w-max mx-auto border border-slate-200">
                                                <button
                                                    wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] - 1 }})"
                                                    class="w-7 h-7 flex items-center justify-center rounded-lg bg-white shadow-sm font-bold text-slate-700 hover:bg-slate-200">-</button>
                                                <input type="number" value="{{ $item['quantity'] }}"
                                                    wire:change="updateQuantity({{ $item['id'] }}, $event.target.value)"
                                                    class="w-12 text-center bg-transparent font-extrabold font-mono text-indigo-700 focus:outline-none"
                                                    min="1">
                                                <button
                                                    wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] + 1 }})"
                                                    class="w-7 h-7 flex items-center justify-center rounded-lg bg-white shadow-sm font-bold text-slate-700 hover:bg-slate-200">+</button>
                                            </div>
                                        </td>
                                        <td class="p-4 font-extrabold font-mono text-emerald-600 text-base">
                                            <div class="flex flex-col">
                                                <span>{{ number_format($item['subtotal'], 2) }} ر.س</span>
                                                @if($item['has_offer'])
                                                    <span class="text-[10px] text-amber-600 font-sans font-bold">سعر العرض 🔥</span>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="p-4 text-center">
                                            <button wire:click="removeFromCart({{ $item['id'] }})"
                                                class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition-all">
                                                <flux:icon icon="trash" class="w-4 h-4" />
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="py-24 text-center">
                                            <div
                                                class="flex flex-col items-center justify-center text-slate-400 space-y-3">
                                                <flux:icon icon="qr-code" class="w-14 h-14 stroke-1 text-slate-300" />
                                                <p class="text-base font-semibold text-slate-500">قم بمسح الباركود لإضافة المنتج إلى الفاتورة</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- القسم الأيسر -->
            <div
                class="lg:col-span-4 flex flex-col justify-between bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">

                <div class="space-y-6">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-2">
                            <flux:icon icon="calculator" class="w-5 h-5 text-indigo-600" />
                            <h3 class="text-lg font-bold text-slate-900">تفاصيل الحساب</h3>
                        </div>
                        <span class="text-xs bg-slate-100 text-slate-600 font-bold px-3 py-1 rounded-full">
                            {{ count($cart) }} أصناف
                        </span>
                    </div>

                    <div class="bg-emerald-50/70 border border-emerald-200 p-5 rounded-2xl space-y-1">
                        <span class="text-xs text-emerald-800 font-bold">المبلغ الإجمالي المستحق:</span>
                        <div
                            class="text-4xl font-black text-emerald-700 font-mono tracking-tight flex items-baseline justify-between">
                            <span>{{ number_format($this->total, 2) }}</span>
                            <span class="text-sm font-bold text-emerald-600">ر.س</span>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="text-xs font-bold text-slate-700">المبلغ المدفوع (نقداً):</label>

                        <div class="relative">
                            <input type="number" step="0.5" wire:model.live="paid_amount"
                                class="w-full text-3xl font-black font-mono p-3 bg-slate-50 border-2 border-slate-200 focus:border-indigo-600 rounded-xl text-slate-900 focus:outline-none text-left tracking-wider"
                                placeholder="0.00">
                        </div>

                        <div class="grid grid-cols-4 gap-2 pt-1">
                            @foreach ([10, 20, 50, 100] as $amount)
                                <button type="button" wire:click="$set('paid_amount', {{ $amount }})"
                                    class="py-2 text-sm font-extrabold bg-slate-100 hover:bg-indigo-50 hover:text-indigo-700 hover:border-indigo-200 text-slate-700 border border-slate-200 rounded-xl transition-all shadow-sm">
                                    {{ $amount }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-600">المبلغ المتبقي للعميل:</span>
                        <span
                            class="text-2xl font-black font-mono {{ $this->change > 0 ? 'text-indigo-600' : 'text-slate-400' }}">
                            {{ number_format($this->change, 2) }} <span class="text-xs font-normal">ر.س</span>
                        </span>
                    </div>
                </div>

                <div class="space-y-3 pt-6 border-t border-slate-100">
                    <button wire:click="checkout" @if (count($cart) === 0) disabled @endif
                        class="w-full py-4 bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-100 disabled:text-slate-400 font-extrabold text-lg rounded-xl transition-all shadow-md text-white flex items-center justify-center gap-3">
                        <span>إتمام وحفظ الفاتورة</span>
                        <span class="text-xs bg-emerald-700 text-white px-2 py-0.5 rounded font-mono">F3</span>
                    </button>

                    <button wire:click="clearCart" @if (count($cart) === 0) disabled @endif
                        class="w-full py-2.5 text-xs font-bold text-rose-600 hover:bg-rose-50 disabled:text-slate-300 rounded-xl flex items-center justify-center gap-2 transition-colors">
                        <flux:icon icon="trash" class="w-4 h-4" />
                        <span>إلغاء وتنظيف الفاتورة (F4)</span>
                    </button>
                </div>

            </div>

        </div>
    </div>
</flux:main>
