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
    // بيانات الفاتورة الأساسية
    public string $invoice_number = '';
    public string $date = '';
    public ?int $customer_id = null;
    public string $currency = 'USD';
    public float $exchange_rate = 1.0;
    public bool $is_cash = true; // فاتورة نقدية (تولد سند قبض)

    // سطر إدخال الصنف السريع (Inline Form)
    public ?int $selected_product_id = null;
    public string $item_barcode = '';
    public string $warehouse = 'الرئيسي';
    public string $unit = 'حبة';
    public float $item_quantity = 1.0;
    public float $item_price = 0.0;
    public float $item_discount = 0.0;

    // الأصناف المضافة للسلة
    public array $cart = [];

    // الخصوم والضرائب الفاتورة
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

    // عند تغيير المنتج المحدد يدوياً أو من خلال الباركود
    public function updatedSelectedProductId($productId)
    {
        if ($productId) {
            $product = Product::where('tenant_id', Auth::user()->tenant_id)->find($productId);
            if ($product) {
                $this->item_barcode = $product->barcode ?? '';
                $this->item_price = (float) $product->retail_price;
            }
        }
    }

    public function scanBarcode()
    {
        if (trim($this->item_barcode) === '') return;

        $product = Product::where('tenant_id', Auth::user()->tenant_id)
            ->where('barcode', trim($this->item_barcode))
            ->first();

        if ($product) {
            $this->selected_product_id = $product->id;
            $this->item_price = (float) $product->retail_price;
        } else {
            $this->errorMessage = 'لم يتم العثور على المنتج برقم الباركود الموضّح';
        }
    }

    // إضافة السطر الحالي إلى جدول الاصناف
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

        $product = Product::find($this->selected_product_id);

        $rowSubtotal = ($this->item_price * $this->item_quantity) - $this->item_discount;

        $this->cart[] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'barcode' => $product->barcode,
            'warehouse' => $this->warehouse,
            'unit' => $this->unit,
            'quantity' => $this->item_quantity,
            'price' => $this->item_price,
            'discount' => $this->item_discount,
            'subtotal' => max(0, $rowSubtotal),
        ];

        // إعادة ضبط سطر الإدخال
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

    // الحسابات الإجمالية
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

                // 1. إنشاء الفاتورة
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

                // 2. إدراج عناصر الفاتورة وتحديث المخزون
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

                // 3. إنشاء سند قبض آلي وتسوية الحساب إذا كانت نقدية
                if ($this->is_cash) {
                    PaymentTransaction::create([
                        'tenant_id' => $user->tenant_id,
                        'branch_id' => $user->branch_id,
                        'customer_id' => $this->customer_id,
                        'type' => 'receipt', // سند قبض
                        'amount' => $totalAmount,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'description' => "سند قبض آلي ناتج عن الفاتورة رقم: {$order->invoice_number}",
                        'payment_date' => $this->date,
                    ]);
                } else {
                    // إضافة المبلغ لديدن/رصيد العميل
                    Customer::where('id', $this->customer_id)->increment('balance', $totalAmount);
                }
            });

            $this->successMessage = $this->is_cash
                ? 'تم حفظ الفاتورة وإنشاء سند القبض الآلي بنجاح!'
                : 'تم حفظ الفاتورة وترحيل المبلغ لحساب العميل بنجاح!';

            $this->reset(['cart', 'customer_id', 'invoice_discount_val', 'invoice_discount_percent']);
            $this->invoice_number = 'INV-' . time();
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

<flux:main class="p-4 bg-slate-100 min-h-screen text-slate-800 text-xs font-sans">
    <div class="max-w-7xl mx-auto space-y-3">

        <!-- Header / الرسائل -->
        @if ($errorMessage)
            <div class="bg-rose-100 border border-rose-300 text-rose-800 p-2.5 rounded text-xs font-bold flex justify-between">
                <span>{{ $errorMessage }}</span>
                <button wire:click="$set('errorMessage', null)">✕</button>
            </div>
        @endif

        @if ($successMessage)
            <div class="bg-emerald-100 border border-emerald-300 text-emerald-800 p-2.5 rounded text-xs font-bold flex justify-between">
                <span>{{ $successMessage }}</span>
                <button wire:click="$set('successMessage', null)">✕</button>
            </div>
        @endif

        <div class="bg-white border border-slate-300 shadow-sm rounded-md p-3">

            <!-- 1️⃣ شريط العميل والبيانات الأساسية للفاتورة -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-2 pb-3 border-b border-slate-200 items-center">

                <div class="md:col-span-1 font-bold text-slate-700">رقم الفاتورة:</div>
                <div class="md:col-span-2">
                    <input type="text" wire:model="invoice_number" class="w-full border border-slate-300 p-1 rounded font-mono bg-slate-50 font-bold">
                </div>

                <div class="md:col-span-1 font-bold text-slate-700 text-left">تاريخها:</div>
                <div class="md:col-span-2">
                    <input type="date" wire:model="date" class="w-full border border-slate-300 p-1 rounded">
                </div>

                <div class="md:col-span-1 font-bold text-slate-700 text-left">العميل:</div>
                <div class="md:col-span-5 flex items-center gap-1">
                    <select wire:model="customer_id" class="w-full border border-slate-300 p-1 rounded bg-white font-bold text-slate-800">
                        <option value="">-- اختر العميل --</option>
                        @foreach(Customer::where('tenant_id', auth()->user()->tenant_id)->get() as $cust)
                            <option value="{{ $cust->id }}">{{ $cust->id }} - {{ $cust->name }}</option>
                        @endforeach
                    </select>
                    <span class="bg-emerald-500 text-white px-2 py-1 rounded text-[10px] font-bold shrink-0">جديد</span>
                </div>
            </div>

            <!-- 2️⃣ إعدادات الدفع والسند الآلي (مثل الواجهة في الصورة) -->
            <div class="grid grid-cols-1 md:grid-cols-12 gap-2 py-3 border-b border-slate-200 items-center bg-slate-50/50 px-2 rounded my-2">
                <div class="md:col-span-2 flex items-center gap-2">
                    <label class="font-bold text-slate-700">دفتر السندات:</label>
                    <select class="border border-slate-300 p-1 rounded text-xs"><option>لم تحديد</option></select>
                </div>

                <div class="md:col-span-2 flex items-center gap-2">
                    <label class="font-bold text-slate-700">العملة:</label>
                    <select wire:model="currency" class="border border-slate-300 p-1 rounded font-bold">
                        <option value="USD">USD</option>
                        <option value="ILS">ILS</option>
                        <option value="JOD">JOD</option>
                    </select>
                </div>

                <div class="md:col-span-2 flex items-center gap-2">
                    <label class="font-bold text-slate-700">سعر الصرف:</label>

                    <input type="number" step="0.01" wire:model="exchange_rate" class="w-16 border border-slate-300 p-1 rounded text-center font-mono">
                </div>

                <!-- زر الخيار المظلل لإنشاء سند قبض آلي -->
                <div class="md:col-span-3 flex items-center gap-2 bg-amber-100 p-1.5 border border-amber-300 rounded">
                    <input type="checkbox" id="sales_cash" wire:model="is_cash" class="w-4 h-4 text-indigo-600 rounded">
                    <label for="sales_cash" class="font-bold text-slate-800 cursor-pointer select-none">
                        فاتورة نقدية (سند قبض آلي)
                    </label>
                </div>

                <div class="md:col-span-3 text-left">
                    <span class="font-bold text-slate-500">سند يدوي / مرجعي:</span>
                    <input type="text" placeholder="رقم السند" class="w-24 border border-slate-300 p-1 rounded text-center">
                </div>
            </div>

            <!-- 3️⃣ جدول الإدخال السريع وشريط الأصناف -->
            <div class="overflow-x-auto my-3">
                <table class="w-full text-center border-collapse border border-slate-300 text-xs">
                    <thead>
                        <tr class="bg-indigo-900 text-white font-bold">
                            <th class="p-1.5 border border-indigo-800 w-8">#</th>
                            <th class="p-1.5 border border-indigo-800 w-32">رقم المادة/الباركود</th>
                            <th class="p-1.5 border border-indigo-800">اسم الصنف</th>
                            <th class="p-1.5 border border-indigo-800 w-28">المخزن</th>
                            <th class="p-1.5 border border-indigo-800 w-20">الوحدة</th>
                            <th class="p-1.5 border border-indigo-800 w-20">الكمية</th>
                            <th class="p-1.5 border border-indigo-800 w-24">السعر</th>
                            <th class="p-1.5 border border-indigo-800 w-20">الخصم</th>
                            <th class="p-1.5 border border-indigo-800 w-28">المجموع</th>
                            <th class="p-1.5 border border-indigo-800 w-12">إجراء</th>
                        </tr>

                        <!-- سطر الإدخال المباشر (Inline Input Row) -->
                        <tr class="bg-indigo-50/60 border-b-2 border-indigo-400">
                            <td class="p-1 border border-slate-300 font-bold text-indigo-700">+</td>

                            <!-- الباركود -->
                            <td class="p-1 border border-slate-300">
                                <input type="text" wire:model="item_barcode" wire:keydown.enter.prevent="scanBarcode"
                                    placeholder="الباركود..." class="w-full p-1 border border-slate-300 rounded text-center font-mono focus:border-indigo-600">
                            </td>

                            <!-- اسم الصنف -->
                            <td class="p-1 border border-slate-300">
                                <select wire:model.live="selected_product_id" class="w-full p-1 border border-slate-300 rounded font-bold">
                                    <option value="">-- اختر صنف --</option>
                                    @foreach(Product::where('tenant_id', auth()->user()->tenant_id)->get() as $p)
                                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                                    @endforeach
                                </select>
                            </td>

                            <!-- المخزن -->
                            <td class="p-1 border border-slate-300">
                                <select wire:model="warehouse" class="w-full p-1 border border-slate-300 rounded text-center">
                                    <option value="الرئيسي">الرئيسي</option>
                                    <option value="فرعي 1">فرعي 1</option>
                                </select>
                            </td>

                            <!-- الوحدة -->
                            <td class="p-1 border border-slate-300">
                                <select wire:model="unit" class="w-full p-1 border border-slate-300 rounded text-center">
                                    <option value="حبة">حبة</option>
                                    <option value="كرتونة">كرتونة</option>
                                </select>
                            </td>

                            <!-- الكمية -->
                            <td class="p-1 border border-slate-300">
                                <input type="number" step="1" wire:model="item_quantity" class="w-full p-1 border border-slate-300 rounded text-center font-bold font-mono">
                            </td>

                            <!-- السعر -->
                            <td class="p-1 border border-slate-300">
                                <input type="number" step="0.5" wire:model="item_price" class="w-full p-1 border border-slate-300 rounded text-center font-bold font-mono">
                            </td>

                            <!-- الخصم -->
                            <td class="p-1 border border-slate-300">
                                <input type="number" step="0.5" wire:model="item_discount" class="w-full p-1 border border-slate-300 rounded text-center font-mono">
                            </td>

                            <!-- المجموع للسطر -->
                            <td class="p-1 border border-slate-300 font-bold text-indigo-800 font-mono text-sm">
                                {{ number_format(max(0, ($item_price * $item_quantity) - $item_discount), 2) }}
                            </td>

                            <!-- زر الإضافة -->
                            <td class="p-1 border border-slate-300">
                                <button type="button" wire:click="addItemRow" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold p-1 rounded w-full">
                                    ＋
                                </button>
                            </td>
                        </tr>
                    </thead>

                    <!-- الأصناف المضافة -->
                    <tbody class="divide-y divide-slate-200">
                        @forelse($cart as $index => $row)
                            <tr class="hover:bg-slate-50 font-semibold">
                                <td class="p-1.5 border border-slate-300 font-mono">{{ $index + 1 }}</td>
                                <td class="p-1.5 border border-slate-300 font-mono text-slate-500">{{ $row['barcode'] ?? '-' }}</td>
                                <td class="p-1.5 border border-slate-300 text-right font-bold text-slate-900">{{ $row['name'] }}</td>
                                <td class="p-1.5 border border-slate-300">{{ $row['warehouse'] }}</td>
                                <td class="p-1.5 border border-slate-300">{{ $row['unit'] }}</td>
                                <td class="p-1.5 border border-slate-300 font-mono text-indigo-700 font-bold">{{ $row['quantity'] }}</td>
                                <td class="p-1.5 border border-slate-300 font-mono">{{ number_format($row['price'], 2) }}</td>
                                <td class="p-1.5 border border-slate-300 font-mono text-rose-600">{{ number_format($row['discount'], 2) }}</td>
                                <td class="p-1.5 border border-slate-300 font-mono text-emerald-700 font-extrabold">{{ number_format($row['subtotal'], 2) }}</td>
                                <td class="p-1.5 border border-slate-300">
                                    <button type="button" wire:click="removeItemRow({{ $index }})" class="text-rose-600 font-bold hover:underline">✕</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="p-6 text-slate-400 text-center font-bold">لم يتم إضافة أي أصناف للفاتورة بعد</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- 4️⃣ ذيل الفاتورة والمجاميع المالية (Totals Footer) -->
            <div class="bg-slate-50 border border-slate-300 rounded p-3 grid grid-cols-1 md:grid-cols-12 gap-4 items-center">

                <div class="md:col-span-8 space-y-2">
                    <div class="flex items-center gap-4">
                        <div class="flex items-center gap-1">
                            <span class="font-bold">الخصم:</span>
                            <input type="number" step="0.5" wire:model.live="invoice_discount_val" class="w-20 border border-slate-300 p-1 rounded font-mono text-center">
                        </div>

                        <div class="flex items-center gap-1">
                            <span class="font-bold">الخصم (%):</span>
                            <input type="number" step="0.1" wire:model.live="invoice_discount_percent" class="w-16 border border-slate-300 p-1 rounded font-mono text-center">
                        </div>

                        <div class="flex items-center gap-1">
                            <span class="font-bold">الضريبة (%):</span>
                            <input type="number" step="0.1" wire:model.live="tax_percent" class="w-16 border border-slate-300 p-1 rounded font-mono text-center">
                        </div>
                    </div>
                </div>

                <div class="md:col-span-4 bg-white border border-slate-300 p-2.5 rounded space-y-1">
                    <div class="flex justify-between text-slate-600">
                        <span>المجموع قبل الضريبة:</span>
                        <span class="font-mono font-bold">{{ number_format($this->subtotalBeforeDiscount - $this->finalDiscount, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-slate-600">
                        <span>قيمة الضريبة المضافة:</span>
                        <span class="font-mono font-bold text-amber-700">{{ number_format($this->taxValue, 2) }}</span>
                    </div>
                    <div class="flex justify-between text-slate-900 border-t border-slate-200 pt-1 text-sm font-extrabold">
                        <span>الصافي النهائي:</span>
                        <span class="font-mono text-emerald-700 text-base">{{ number_format($this->total, 2) }} {{ $currency }}</span>
                    </div>
                </div>

            </div>

            <!-- زر الحفظ والإجراءات -->
            <div class="mt-4 flex justify-end gap-2 border-t border-slate-200 pt-3">
                <button type="button" wire:click="saveInvoice" class="bg-indigo-700 hover:bg-indigo-800 text-white font-bold px-6 py-2 rounded shadow flex items-center gap-2">
                    💾 حفظ الفاتورة (F3)
                </button>
            </div>

        </div>
    </div>
</flux:main>
