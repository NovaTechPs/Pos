<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\BranchProduct;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public string $barcode = '';
    public ?int $customer_id = null;
    public array $cart = [];
    public float $discount = 0.0;
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
                'wholesale_price' => (float) ($product->wholesale_price ?? $product->retail_price),
                'quantity' => 1,
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

    public function updatePrice($productId, $price)
    {
        $price = (float) $price;
        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['wholesale_price'] = max(0, $price);
            $this->recalculatePrices();
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
        $this->customer_id = null;
        $this->discount = 0.0;
        $this->paid_amount = 0.0;
        $this->errorMessage = null;
        $this->successMessage = 'تم تنظيف محتويات فاتورة الجملة بنجاح.';
    }

    private function recalculatePrices()
    {
        foreach ($this->cart as $id => $item) {
            $this->cart[$id]['subtotal'] = $item['wholesale_price'] * $item['quantity'];
        }
    }

    public function getSubtotalProperty()
    {
        return array_sum(array_column($this->cart, 'subtotal'));
    }

    public function getTotalProperty()
    {
        return max(0, $this->subtotal - $this->discount);
    }

    public function getRemainingProperty()
    {
        return max(0, $this->total - $this->paid_amount);
    }

    public function checkout(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if (empty($this->cart)) {
            $this->errorMessage = 'سلة مبيعات الجملة فارغة حالياً!';
            return;
        }

        if (!$this->customer_id) {
            $this->errorMessage = 'يجب اختيار عميل لتسجيل فاتورة الجملة!';
            return;
        }

        $user = Auth::user();
        $customer = Customer::where('tenant_id', $user->tenant_id)->find($this->customer_id);

        if (!$customer) {
            $this->errorMessage = 'العميل المحدد غير موجود!';
            return;
        }

        $totalAmount = $this->total;
        $remaining = $totalAmount - $this->paid_amount;

        // التحقق من الحد الائتماني
        if ($remaining > 0 && ($customer->balance + $remaining) > $customer->credit_limit) {
            $this->errorMessage = 'تنبيه: تتجاوز هذه الفاتورة الحد الائتماني المسموح به للعميل!';
            return;
        }

        $paymentStatus = 'paid';
        if ($this->paid_amount <= 0) {
            $paymentStatus = 'unpaid';
        } elseif ($this->paid_amount < $totalAmount) {
            $paymentStatus = 'partial';
        }

        try {
            DB::transaction(function () use ($user, $customer, $totalAmount, $paymentStatus, $remaining) {
                $order = Order::create([
                    'tenant_id' => $user->tenant_id,
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->id,
                    'customer_id' => $customer->id,
                    'invoice_number' => 'INV-W-' . time(),
                    'type' => 'wholesale',
                    'subtotal' => $this->subtotal,
                    'discount' => $this->discount,
                    'total' => $totalAmount,
                    'paid_amount' => $this->paid_amount,
                    'payment_status' => $paymentStatus,
                ]);

                foreach ($this->cart as $item) {
                    OrderItem::create([
                        'tenant_id' => $user->tenant_id,
                        'order_id' => $order->id,
                        'product_id' => $item['id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['wholesale_price'],
                        'total_price' => $item['subtotal'],
                    ]);

                    BranchProduct::where('branch_id', $user->branch_id)
                        ->where('product_id', $item['id'])
                        ->decrement('stock_quantity', $item['quantity']);
                }

                if ($remaining > 0) {
                    $customer->increment('balance', $remaining);
                }
            });

            $this->successMessage = 'تمت عملية بيع الجملة وحفظ الفاتورة بنجاح!';
            $this->clearCart();
        } catch (\Exception $e) {
            $this->errorMessage = 'خطأ في عملية بيع الجملة: ' . $e->getMessage();
        }
    }

    public function render()
    {
        return $this->view()->layout('layouts::tenant');
    }
};
?>

<flux:main class="h-[calc(100vh-4rem)] p-4 bg-slate-50 font-sans">

    <!-- اختصارات الكيبورد الفعالة F3 و F4 -->
    <div x-data x-on:keydown.window.f3.prevent="$wire.checkout()" x-on:keydown.window.f4.prevent="$wire.clearCart()"
        class="h-full">

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 h-full">

            <!-- ================= القسم الأيمن: جدول المبيعات والباركود (8 أعمدة) ================= -->
            <div class="lg:col-span-8 flex flex-col h-full space-y-4">

                <!-- رأس الصفحة ومسح الباركود -->
                <div
                    class="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
                    <form wire:submit.prevent="scanBarcode" class="flex-1 w-full flex items-center gap-2">
                        <div class="relative w-full">
                            <flux:input wire:model="barcode" id="wholesale-barcode-input" icon="qr-code"
                                placeholder="امسح الباركود هنا لإضافة صنف بالجملة..." autofocus
                                class="!text-lg !py-2.5 bg-slate-50 border-slate-300 focus:border-indigo-600 focus:bg-white text-slate-900 rounded-xl" />
                        </div>
                        <button type="submit"
                            class="py-2.5 px-6 rounded-xl font-bold bg-indigo-600 hover:bg-indigo-700 text-white transition-all shadow-sm">
                            إضافة
                        </button>
                    </form>

                    <!-- أزرار الاختصارات -->
                    <div class="flex items-center gap-2 border-r pr-3 border-slate-200 shrink-0">
                        <button wire:click="checkout" type="button"
                            class="flex items-center gap-1.5 px-3 py-2 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-xl hover:bg-emerald-100 transition-colors text-xs font-bold">
                            <span
                                class="bg-emerald-600 text-white px-1.5 py-0.5 rounded font-mono text-[11px]">F3</span>
                            <span>إتمام الفاتورة</span>
                        </button>

                        <button wire:click="clearCart" type="button"
                            class="flex items-center gap-1.5 px-3 py-2 bg-rose-50 border border-rose-200 text-rose-700 rounded-xl hover:bg-rose-100 transition-colors text-xs font-bold">
                            <span class="bg-rose-600 text-white px-1.5 py-0.5 rounded font-mono text-[11px]">F4</span>
                            <span>تنظيف</span>
                        </button>
                    </div>
                </div>

                <!-- التنبيهات -->
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

                <!-- جدول الاصناف -->
                <div
                    class="flex-1 bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm flex flex-col">
                    <div class="overflow-y-auto flex-1">
                        <table class="w-full text-right text-sm">
                            <thead class="bg-slate-100 sticky top-0 font-bold text-slate-700 border-b border-slate-200">
                                <tr>
                                    <th class="p-4">اسم المنتج</th>
                                    <th class="p-4">سعر الجملة</th>
                                    <th class="p-4 text-center">الكمية</th>
                                    <th class="p-4">الإجمالي</th>
                                    <th class="p-4 text-center">إجراء</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($cart as $item)
                                    <tr class="hover:bg-indigo-50/40 transition-colors"
                                        wire:key="wholesale-item-{{ $item['id'] }}">
                                        <td class="p-4 font-semibold text-slate-900">
                                            <div class="flex flex-col">
                                                <span>{{ $item['name'] }}</span>
                                                <span class="text-[11px] text-slate-400 font-mono">{{ $item['barcode'] }}</span>
                                            </div>
                                        </td>
                                        <td class="p-4 font-mono font-medium text-slate-700">
                                            <input type="number" step="0.5" value="{{ $item['wholesale_price'] }}"
                                                wire:change="updatePrice({{ $item['id'] }}, $event.target.value)"
                                                class="w-24 p-1 bg-slate-50 border border-slate-200 rounded-lg text-center font-bold text-indigo-700 focus:border-indigo-600 focus:outline-none">
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
                                            {{ number_format($item['subtotal'], 2) }} ر.س
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
                                                <flux:icon icon="shopping-bag" class="w-14 h-14 stroke-1 text-slate-300" />
                                                <p class="text-base font-semibold text-slate-500">سلة مبيعات الجملة فارغة، ادخل الاصناف للبدء</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- ================= القسم الأيسر: الحساب والعميل والمبالغ (4 أعمدة) ================= -->
            <div
                class="lg:col-span-4 flex flex-col justify-between bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">

                <div class="space-y-4">
                    <!-- عنوان القائمة -->
                    <div class="flex items-center justify-between border-b border-slate-100 pb-4">
                        <div class="flex items-center gap-2">
                            <flux:icon icon="calculator" class="w-5 h-5 text-indigo-600" />
                            <h3 class="text-lg font-bold text-slate-900">حساب فاتورة الجملة</h3>
                        </div>
                        <span class="text-xs bg-slate-100 text-slate-600 font-bold px-3 py-1 rounded-full">
                            {{ count($cart) }} أصناف
                        </span>
                    </div>

                    <!-- اختيار العميل (إجباري للجملة) -->
                    <div>
                        <label class="text-xs font-bold text-slate-700 block mb-1">العميل (مطلوب):</label>
                        <select wire:model="customer_id" class="w-full p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 font-semibold focus:border-indigo-600 focus:outline-none">
                            <option value="">-- اختر العميل --</option>
                            @foreach(Customer::where('tenant_id', auth()->user()->tenant_id)->get() as $customer)
                                <option value="{{ $customer->id }}">
                                    {{ $customer->name }} (الرصيد: {{ number_format($customer->balance, 2) }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- تفاصيل الحساب والإجمالي -->
                    <div class="bg-indigo-50/70 border border-indigo-200 p-4 rounded-2xl space-y-3">
                        <div class="flex justify-between text-xs font-bold text-slate-600">
                            <span>المجموع الفرعي:</span>
                            <span>{{ number_format($this->subtotal, 2) }} ر.س</span>
                        </div>

                        <div>
                            <label class="text-xs font-bold text-slate-700 block mb-1">الخصم:</label>
                            <input type="number" step="0.5" wire:model.live="discount" placeholder="0.00"
                                class="w-full p-2 bg-white border border-slate-200 rounded-lg text-left font-mono font-bold text-slate-900 focus:outline-none focus:border-indigo-600">
                        </div>

                        <div class="pt-2 border-t border-indigo-200 flex items-baseline justify-between">
                            <span class="text-xs text-indigo-800 font-bold">الإجمالي النهائي:</span>
                            <span class="text-3xl font-black text-indigo-700 font-mono tracking-tight">
                                {{ number_format($this->total, 2) }} <span class="text-xs text-indigo-600 font-bold">ر.س</span>
                            </span>
                        </div>
                    </div>

                    <!-- حقل المدفوع -->
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-700">المبلغ المدفوع (نقداً):</label>
                        <input type="number" step="0.5" wire:model.live="paid_amount"
                            class="w-full text-3xl font-black font-mono p-3 bg-slate-50 border-2 border-slate-200 focus:border-indigo-600 rounded-xl text-slate-900 focus:outline-none text-left tracking-wider"
                            placeholder="0.00">
                    </div>

                    <!-- المتبقي على حساب العميل -->
                    <div class="bg-slate-50 border border-slate-200 p-4 rounded-xl flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-600">المبلغ المتبقي (آجل على العميل):</span>
                        <span
                            class="text-2xl font-black font-mono {{ $this->remaining > 0 ? 'text-amber-600' : 'text-slate-400' }}">
                            {{ number_format($this->remaining, 2) }} <span class="text-xs font-normal">ر.س</span>
                        </span>
                    </div>
                </div>

                <!-- أزرار العمليات الرئيسية -->
                <div class="space-y-3 pt-6 border-t border-slate-100">
                    <button wire:click="checkout" @if (count($cart) === 0) disabled @endif
                        class="w-full py-4 bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-100 disabled:text-slate-400 font-extrabold text-lg rounded-xl transition-all shadow-md text-white flex items-center justify-center gap-3">
                        <span>إتمام وحفظ فاتورة الجملة</span>
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
