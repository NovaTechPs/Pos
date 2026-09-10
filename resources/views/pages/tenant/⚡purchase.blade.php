<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PaymentTransaction;
use App\Models\BranchProduct;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {
    public string $invoice_number = '';
    public string $date = '';
    public ?int $customer_id = null;
    public string $currency = 'USD';
    public float $exchange_rate = 1.0;
    public bool $is_cash = true;

    // السطر الحالي للإدخال
    public ?int $selected_product_id = null;
    public string $item_barcode = '';
    public string $warehouse = 'الرئيسي';
    public string $unit = 'حبة';
    public float $item_quantity = 1.0;
    public float $item_price = 0.0;
    public float $item_discount = 0.0;

    public array $cart = [];

    public float $invoice_discount_val = 0.0;
    public float $invoice_discount_percent = 0.0;
    public float $tax_percent = 16.0;

    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    public function mount()
    {
        $this->date = date('Y-m-d');
        $this->invoice_number = 'INV-' . time();
    }

    public function getCustomersProperty()
    {
        return Customer::where('tenant_id', Auth::user()->tenant_id)->select('id', 'name')->get();
    }

    public function getProductsProperty()
    {
        return Product::where('tenant_id', Auth::user()->tenant_id)->select('id', 'name', 'barcode', 'retail_price')->get();
    }

    public function scanBarcode()
    {
        if (trim($this->item_barcode) === '') return;

        $product = Product::where('tenant_id', Auth::user()->tenant_id)
            ->where('barcode', trim($this->item_barcode))
            ->first();

        if ($product) {
            $this->pushToCart($product, 1.0, (float) $product->retail_price);
            $this->item_barcode = '';
            $this->errorMessage = null;
            $this->dispatch('focus-barcode');
        } else {
            $this->errorMessage = 'لم يتم العثور على المنتج برقم الباركود الموضّح';
        }
    }

    public function updatedSelectedProductId($productId)
    {
        if ($productId) {
            $product = Product::where('tenant_id', Auth::user()->tenant_id)->find($productId);
            if ($product) {
                $this->item_barcode = $product->barcode ?? '';
                $this->item_price = (float) $product->retail_price;
                $this->dispatch('focus-qty');
            }
        }
    }

    public function addItemRow()
    {
        $this->errorMessage = null;

        if (!$this->selected_product_id) {
            $this->errorMessage = 'يرجى اختيار صنف أولاً';
            return;
        }

        if ($this->item_quantity <= 0) {
            $this->errorMessage = 'الكمية يجب أن تكون أكبر من صفر';
            return;
        }

        $product = Product::where('tenant_id', Auth::user()->tenant_id)->find($this->selected_product_id);
        if (!$product) {
            $this->errorMessage = 'المنتج غير موجود';
            return;
        }

        $this->pushToCart($product, $this->item_quantity, $this->item_price, $this->item_discount);
        $this->resetInputRow();
        $this->dispatch('focus-barcode');
    }

    private function pushToCart($product, $qty, $price, $discount = 0.0)
    {
        foreach ($this->cart as $index => $item) {
            if ($item['product_id'] === $product->id && $item['unit'] === $this->unit) {
                $this->cart[$index]['quantity'] += $qty;
                $this->cart[$index]['subtotal'] = max(0, ($this->cart[$index]['price'] * $this->cart[$index]['quantity']) - $this->cart[$index]['discount']);
                return;
            }
        }

        $rowSubtotal = ($price * $qty) - $discount;

        $this->cart[] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'barcode' => $product->barcode,
            'warehouse' => $this->warehouse,
            'unit' => $this->unit,
            'quantity' => $qty,
            'price' => $price,
            'discount' => $discount,
            'subtotal' => max(0, $rowSubtotal),
        ];
    }

    private function resetInputRow()
    {
        $this->selected_product_id = null;
        $this->item_barcode = '';
        $this->item_quantity = 1.0;
        $this->item_price = 0.0;
        $this->item_discount = 0.0;
    }

    public function removeItemRow($index)
    {
        unset($this->cart[$index]);
        $this->cart = array_values($this->cart);
    }

    public function updateCartQty($index, $qty)
    {
        if ($qty <= 0) return;
        $this->cart[$index]['quantity'] = (float) $qty;
        $this->cart[$index]['subtotal'] = max(0, ($this->cart[$index]['price'] * $this->cart[$index]['quantity']) - $this->cart[$index]['discount']);
    }

    public function getSubtotalBeforeDiscountProperty()
    {
        return array_sum(array_column($this->cart, 'subtotal'));
    }

    public function getFinalDiscountProperty()
    {
        if ($this->invoice_discount_percent > 0) {
            return ($this->subtotalBeforeDiscount * $this->invoice_discount_percent) / 100;
        }
        return $this->invoice_discount_val;
    }

    public function getTaxValueProperty()
    {
        $afterDiscount = max(0, $this->subtotalBeforeDiscount - $this->finalDiscount);
        return ($afterDiscount * $this->tax_percent) / 100;
    }

    public function getTotalProperty()
    {
        return ($this->subtotalBeforeDiscount - $this->finalDiscount) + $this->taxValue;
    }

    public function saveInvoice()
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if (empty($this->cart)) {
            $this->errorMessage = 'لا يمكن حفظ فاتورة فارغة!';
            return;
        }

        if (!$this->customer_id) {
            $this->errorMessage = 'يرجى تحديد العميل أولاً!';
            return;
        }

        $user = Auth::user();

        try {
            DB::transaction(function () use ($user) {
                $totalAmount = $this->total;
                $paidAmount = $this->is_cash ? $totalAmount : 0.0;

                $order = Order::create([
                    'tenant_id' => $user->tenant_id,
                    'branch_id' => $user->branch_id,
                    'user_id' => $user->id,
                    'customer_id' => $this->customer_id,
                    'invoice_number' => $this->invoice_number,
                    'type' => 'retail',
                    'subtotal' => $this->subtotalBeforeDiscount,
                    'discount' => $this->finalDiscount,
                    'tax' => $this->taxValue,
                    'total' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'payment_status' => $this->is_cash ? 'paid' : 'unpaid',
                    'currency' => $this->currency,
                    'exchange_rate' => $this->exchange_rate,
                ]);

                foreach ($this->cart as $item) {
                    OrderItem::create([
                        'tenant_id' => $user->tenant_id,
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['price'],
                        'discount' => $item['discount'],
                        'total_price' => $item['subtotal'],
                    ]);

                    BranchProduct::where('branch_id', $user->branch_id)
                        ->where('product_id', $item['product_id'])
                        ->decrement('stock_quantity', $item['quantity']);
                }

                if ($this->is_cash) {
                    PaymentTransaction::create([
                        'tenant_id' => $user->tenant_id,
                        'branch_id' => $user->branch_id,
                        'customer_id' => $this->customer_id,
                        'type' => 'receipt',
                        'amount' => $totalAmount,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'description' => "سند قبض آلي ناتج عن الفاتورة رقم: {$order->invoice_number}",
                        'payment_date' => $this->date,
                    ]);
                } else {
                    Customer::where('id', $this->customer_id)->increment('balance', $totalAmount);
                }
            });

            $this->successMessage = 'تم حفظ الفاتورة بنجاح!';
            $this->reset(['cart', 'customer_id', 'invoice_discount_val', 'invoice_discount_percent']);
            $this->invoice_number = 'INV-' . time();
            $this->dispatch('focus-barcode');
        } catch (\Exception $e) {
            $this->errorMessage = 'حدث خطأ أثناء الحفظ: ' . $e->getMessage();
        }
    }

    public function render()
    {
        return $this->view()->layout('layouts::tenant');
    }
};
?>

<flux:main class="p-3 bg-slate-900 min-h-screen text-slate-100 font-sans text-xs"
           x-data="{ focusBarcode() { $nextTick(() => $refs.barcodeInput.focus()); } }"
           x-init="focusBarcode()"
           @focus-barcode.window="focusBarcode()"
           @focus-qty.window="$nextTick(() => $refs.qtyInput.focus())"
           @keydown.window.f3.prevent="$wire.saveInvoice()"
           @keydown.window.f2.prevent="focusBarcode()">

    <div class="max-w-[1600px] mx-auto space-y-3">

        <!-- التنبيهات -->
        @if ($errorMessage)
            <div class="bg-rose-950 border border-rose-700 text-rose-200 px-4 py-2 rounded-lg text-xs font-bold flex justify-between items-center shadow-lg animate-fade-in">
                <span class="flex items-center gap-2">⚠️ {{ $errorMessage }}</span>
                <button wire:click="$set('errorMessage', null)" class="text-rose-400 hover:text-white font-bold">✕</button>
            </div>
        @endif

        @if ($successMessage)
            <div class="bg-emerald-950 border border-emerald-700 text-emerald-200 px-4 py-2 rounded-lg text-xs font-bold flex justify-between items-center shadow-lg animate-fade-in">
                <span class="flex items-center gap-2">✓ {{ $successMessage }}</span>
                <button wire:click="$set('successMessage', null)" class="text-emerald-400 hover:text-white font-bold">✕</button>
            </div>
        @endif

        <!-- الهيكل الرئيسي: قسمين -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-3">

            <!-- 🟩 القسم الأيمن (الجدول والإدخال) -->
            <div class="lg:col-span-8 bg-slate-800 border border-slate-700 rounded-xl p-3 shadow-xl flex flex-col justify-between space-y-3">

                <div>
                    <!-- هيدر معلومات العميل والسند -->
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 pb-3 border-b border-slate-700/80 items-center">
                        <div>
                            <label class="block text-slate-400 text-[10px] mb-0.5">العميل</label>
                            <select wire:model.live="customer_id" class="w-full bg-slate-900 border border-slate-600 rounded-lg p-1.5 font-bold text-slate-100 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                                <option value="">-- اختر العميل --</option>
                                @foreach($this->customers as $cust)
                                    <option value="{{ $cust->id }}">{{ $cust->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="block text-slate-400 text-[10px] mb-0.5">نوع الدفع</label>
                            <div class="flex items-center gap-2 bg-slate-900 border border-slate-600 rounded-lg p-1.5">
                                <input type="checkbox" id="sales_cash" wire:model.live="is_cash" class="w-4 h-4 accent-indigo-500 rounded cursor-pointer">
                                <label for="sales_cash" class="font-bold text-slate-200 cursor-pointer select-none">فاتورة نقدية (سند آلي)</label>
                            </div>
                        </div>

                        <div>
                            <label class="block text-slate-400 text-[10px] mb-0.5">العملة وسعر الصرف</label>
                            <div class="flex items-center gap-1">
                                <select wire:model.live="currency" class="bg-slate-900 border border-slate-600 rounded-lg p-1.5 font-bold text-slate-100">
                                    <option value="USD">USD</option>
                                    <option value="ILS">ILS</option>
                                    <option value="JOD">JOD</option>
                                </select>
                                <input type="number" step="0.01" wire:model.live="exchange_rate" class="w-full bg-slate-900 border border-slate-600 rounded-lg p-1.5 font-mono text-center text-slate-100">
                            </div>
                        </div>
                    </div>

                    <!-- شريط الإدخال السريع بالأعلى -->
                    <div class="bg-slate-900 border border-slate-700 rounded-lg p-2 my-3 grid grid-cols-12 gap-2 items-center">
                        <div class="col-span-12 sm:col-span-3">
                            <label class="block text-slate-400 text-[10px] mb-0.5">مسح الباركود (F2)</label>
                            <input type="text"
                                   x-ref="barcodeInput"
                                   wire:model="item_barcode"
                                   wire:keydown.enter.prevent="scanBarcode"
                                   placeholder="أمحي واكسح..."
                                   class="w-full bg-slate-800 border border-indigo-500/50 rounded-lg p-1.5 text-center font-mono font-bold text-indigo-300 focus:ring-2 focus:ring-indigo-500 focus:outline-none placeholder-slate-500">
                        </div>

                        <div class="col-span-12 sm:col-span-4">
                            <label class="block text-slate-400 text-[10px] mb-0.5">اختر الصنف بالاسم</label>
                            <select wire:model.live="selected_product_id" class="w-full bg-slate-800 border border-slate-600 rounded-lg p-1.5 font-bold text-slate-100 focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                                <option value="">-- اختار صنف --</option>
                                @foreach($this->products as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-span-4 sm:col-span-2">
                            <label class="block text-slate-400 text-[10px] mb-0.5">الكمية</label>
                            <input type="number" step="1" x-ref="qtyInput" wire:model.live="item_quantity" wire:keydown.enter.prevent="addItemRow" class="w-full bg-slate-800 border border-slate-600 rounded-lg p-1.5 text-center font-bold font-mono text-slate-100">
                        </div>

                        <div class="col-span-4 sm:col-span-2">
                            <label class="block text-slate-400 text-[10px] mb-0.5">السعر</label>
                            <input type="number" step="0.5" wire:model.live="item_price" wire:keydown.enter.prevent="addItemRow" class="w-full bg-slate-800 border border-slate-600 rounded-lg p-1.5 text-center font-bold font-mono text-slate-100">
                        </div>

                        <div class="col-span-4 sm:col-span-1 flex items-end">
                            <button type="button" wire:click="addItemRow" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-extrabold p-1.5 rounded-lg transition shadow-md hover:shadow-indigo-500/20">
                                ＋
                            </button>
                        </div>
                    </div>

                    <!-- جدول المحتويات -->
                    <div class="overflow-x-auto rounded-lg border border-slate-700">
                        <table class="w-full text-center border-collapse text-xs">
                            <thead>
                                <tr class="bg-slate-900 text-slate-300 font-bold border-b border-slate-700">
                                    <th class="p-2 w-8">#</th>
                                    <th class="p-2 text-right">الصنف</th>
                                    <th class="p-2 w-20">الوحدة</th>
                                    <th class="p-2 w-24">الكمية</th>
                                    <th class="p-2 w-24">السعر</th>
                                    <th class="p-2 w-20">الخصم</th>
                                    <th class="p-2 w-28">الإجمالي</th>
                                    <th class="p-2 w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700/60 bg-slate-800/50">
                                @forelse($cart as $index => $row)
                                    <tr class="hover:bg-slate-700/40 transition">
                                        <td class="p-2 font-mono text-slate-400">{{ $index + 1 }}</td>
                                        <td class="p-2 text-right font-bold text-slate-100">{{ $row['name'] }}</td>
                                        <td class="p-2 text-slate-400">{{ $row['unit'] }}</td>
                                        <td class="p-2">
                                            <input type="number"
                                                   value="{{ $row['quantity'] }}"
                                                   wire:change="updateCartQty({{ $index }}, $event.target.value)"
                                                   class="w-16 p-1 text-center bg-slate-900 border border-slate-600 rounded font-mono font-bold text-indigo-400">
                                        </td>
                                        <td class="p-2 font-mono text-slate-300">{{ number_format($row['price'], 2) }}</td>
                                        <td class="p-2 font-mono text-rose-400">{{ number_format($row['discount'], 2) }}</td>
                                        <td class="p-2 font-mono text-emerald-400 font-extrabold">{{ number_format($row['subtotal'], 2) }}</td>
                                        <td class="p-2">
                                            <button type="button" wire:click="removeItemRow({{ $index }})" class="text-slate-500 hover:text-rose-400 font-bold transition">✕</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="p-10 text-slate-500 text-center font-bold">
                                            السلة فارغة - قم بمسح الباركود أو اختيار صنف للبدء
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- معلومات الاختصارات والعدّاد -->
                <div class="flex justify-between items-center text-[11px] text-slate-400 pt-2 border-t border-slate-700/60">
                    <div>الأصناف في السلة: <span class="font-bold text-indigo-400">{{ count($cart) }}</span></div>
                    <div class="flex gap-3 font-mono">
                        <span class="bg-slate-900 px-2 py-0.5 rounded border border-slate-700">F2: التركيز على الباركود</span>
                        <span class="bg-slate-900 px-2 py-0.5 rounded border border-slate-700">F3: حفظ الفاتورة</span>
                    </div>
                </div>

            </div>

            <!-- 🟦 القسم الأيسر (تفاصيل الحساب والحفظ) -->
            <div class="lg:col-span-4 bg-slate-800 border border-slate-700 rounded-xl p-4 shadow-xl flex flex-col justify-between space-y-4">

                <div class="space-y-4">
                    <div class="flex justify-between items-center border-b border-slate-700 pb-2">
                        <span class="text-slate-400 font-bold">ملخص الفاتورة</span>
                        <input type="text" wire:model.blur="invoice_number" class="bg-slate-900 border border-slate-700 text-slate-300 p-1 rounded font-mono text-center text-xs w-32">
                    </div>

                    <!-- إعدادات الخصم والضريبة -->
                    <div class="bg-slate-900/60 border border-slate-700 rounded-lg p-3 space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-slate-300 font-semibold">خصم مالي:</span>
                            <input type="number" step="0.5" wire:model.live="invoice_discount_val" class="w-24 bg-slate-800 border border-slate-600 p-1 rounded text-center font-mono text-slate-100">
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-300 font-semibold">خصم مئوي (%):</span>
                            <input type="number" step="0.1" wire:model.live="invoice_discount_percent" class="w-24 bg-slate-800 border border-slate-600 p-1 rounded text-center font-mono text-slate-100">
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-slate-300 font-semibold">الضريبة المضافة (%):</span>
                            <input type="number" step="0.1" wire:model.live="tax_percent" class="w-24 bg-slate-800 border border-slate-600 p-1 rounded text-center font-mono text-slate-100">
                        </div>
                    </div>

                    <!-- تفاصيل المبالغ -->
                    <div class="space-y-2 px-1">
                        <div class="flex justify-between text-slate-400">
                            <span>المجموع الجزئي:</span>
                            <span class="font-mono text-slate-200 font-bold">{{ number_format($this->subtotalBeforeDiscount, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-slate-400">
                            <span>إجمالي الخصم:</span>
                            <span class="font-mono text-rose-400 font-bold">-{{ number_format($this->finalDiscount, 2) }}</span>
                        </div>
                        <div class="flex justify-between text-slate-400">
                            <span>قيمة الضريبة:</span>
                            <span class="font-mono text-amber-400 font-bold">+{{ number_format($this->taxValue, 2) }}</span>
                        </div>
                    </div>
                </div>

                <!-- الإجمالي النهائي وزر الحفظ -->
                <div class="space-y-3 pt-3 border-t border-slate-700">
                    <div class="bg-slate-900 border border-emerald-500/30 p-4 rounded-xl text-center shadow-inner">
                        <span class="text-slate-400 text-xs font-bold block mb-1">المبلغ الإجمالي المستحق</span>
                        <div class="text-3xl font-extrabold font-mono text-emerald-400">
                            {{ number_format($this->total, 2) }} <span class="text-sm text-slate-300">{{ $currency }}</span>
                        </div>
                    </div>

                    <button type="button"
                            wire:click="saveInvoice"
                            wire:loading.attr="disabled"
                            class="w-full bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white font-extrabold py-3 rounded-xl shadow-lg transition-all flex items-center justify-center gap-2 text-sm">
                        <span wire:loading.remove>💾 حفظ وترحيل (F3)</span>
                        <span wire:loading>جاري الحفظ...</span>
                    </button>
                </div>

            </div>

        </div>

    </div>
</flux:main>
