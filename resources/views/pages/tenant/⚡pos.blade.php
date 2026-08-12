<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\BranchProduct;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

new class extends Component {
    // --- البيانات والحالة (State) ---
    public string $barcode = '';
    public array $cart = [];
    public float $paid_amount = 0.0;

    public $categories = [];
    public ?int $selectedCategoryId = null;
    public $quickProducts = [];

    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    // --- حالة التنقل بين الفواتير ---
    public ?int $currentInvoiceId = null;

    // --- الدورات والأحداث ---
    public function mount()
    {
        $tenantId = session('active_tenant_id');
        $this->categories = Category::where('tenant_id', $tenantId)->get();
        $this->loadQuickProducts();
    }

    public function selectCategory(?int $categoryId = null)
    {
        $this->selectedCategoryId = $categoryId;
        $this->loadQuickProducts();
    }

    public function loadQuickProducts()
    {
        $tenantId = session('active_tenant_id');
        $query = Product::where('tenant_id', $tenantId);

        if ($this->selectedCategoryId) {
            $query->where('category_id', $this->selectedCategoryId);
        }

        $this->quickProducts = $query->take(30)->get();
    }

    // --- وظائف التنقل بين الفواتير (السابقة والتالية) ---
    public function loadInvoice(int $invoiceId)
    {
        $tenantId = session('active_tenant_id');
        $invoice = Order::where('tenant_id', $tenantId)->with('items.product')->find($invoiceId);

        if (!$invoice) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة المطلوبة.';
            return;
        }

        $this->currentInvoiceId = $invoice->id;
        $this->cart = [];

        foreach ($invoice->items as $item) {
            $product = $item->product;
            $this->cart[$item->product_id] = [
                'id' => $item->product_id,
                'name' => $product->name ?? 'منتج غير محدد',
                'barcode' => $product->barcode ?? '',
                'price' => (float) $item->unit_price,
                'offer_quantity' => $product->offer_quantity ?? null,
                'offer_price' => $product->offer_price ?? null,
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->total_price,
                'has_offer' => false,
            ];
        }

        $this->paid_amount = (float) $invoice->paid_amount;
        $this->errorMessage = null;
        $this->successMessage = "تم عرض الفاتورة رقم #{$invoice->id}";
    }

    public function previousInvoice()
    {
        $tenantId = session('active_tenant_id');

        $previous = Order::where('tenant_id', $tenantId)
            ->when($this->currentInvoiceId, function ($q) {
                $q->where('id', '<', $this->currentInvoiceId);
            })
            ->orderBy('id', 'desc')
            ->first();

        if ($previous) {
            $this->loadInvoice($previous->id);
        } else {
            $this->errorMessage = 'وصلت إلى أول فاتورة، لا توجد فاتورة سابقة.';
        }
    }

    public function nextInvoice()
    {
        $tenantId = session('active_tenant_id');

        if (!$this->currentInvoiceId) {
            return;
        }

        $next = Order::where('tenant_id', $tenantId)
            ->where('id', '>', $this->currentInvoiceId)
            ->orderBy('id', 'asc')
            ->first();

        if ($next) {
            $this->loadInvoice($next->id);
        } else {
            $this->clearCart();
            $this->successMessage = 'تم الانتقال إلى واجهة فاتورة جديدة.';
        }
    }

    // --- العمليات على الفاتورة ---
    public function scanBarcode()
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        $trimmedBarcode = trim($this->barcode);
        if ($trimmedBarcode === '') {
            return;
        }

        $tenantId = session('active_tenant_id');

        $product = Product::where('tenant_id', $tenantId)
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
        // إلغاء ربط الفاتورة الحالية عند البدء بإضافة منتجات جديدة
        if ($this->currentInvoiceId) {
            $this->currentInvoiceId = null;
        }

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
        $this->currentInvoiceId = null;
        $this->errorMessage = null;
        $this->successMessage = 'تم تنظيف محتويات الفاتورة بنجاح.';
    }

    public function appendNumpad(string $val)
    {
        if ($val === 'C') {
            $this->paid_amount = 0.0;
            return;
        }

        $current = (string) $this->paid_amount;
        if ($current === '0') {
            $current = '';
        }

        $newVal = $current . $val;
        $this->paid_amount = (float) $newVal;
    }

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

    // --- Computed Properties ---
    public function getTotalProperty()
    {
        return array_sum(array_column($this->cart, 'subtotal'));
    }

    public function getChangeProperty()
    {
        $paid = (float) ($this->paid_amount ?? 0.0);
        return max(0, $paid - $this->total);
    }

    // --- إتمام وحفظ الفاتورة ---
    public function checkout(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if (empty($this->cart)) {
            $this->errorMessage = 'الفاتورة فارغة حالياً!';
            return;
        }

        $tenantId = session('active_tenant_id');

        if (!$tenantId) {
            $this->errorMessage = 'يرجى اختيار متجر أولاً لتتمكن من إتمام الفاتورة!';
            return;
        }

        $user = Auth::user();
        $branchId = $user->branch_id ?? Branch::where('tenant_id', $tenantId)->value('id');

        if (!$branchId) {
            $this->errorMessage = 'تعذر إتمام العملية: لا يوجد فرع مرتبط بهذا المتجر!';
            return;
        }

        $total = $this->total;

        try {
            DB::transaction(function () use ($user, $tenantId, $branchId, $total) {
                $order = Order::create([
                    'tenant_id' => $tenantId,
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
                        'tenant_id' => $tenantId,
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
            $this->currentInvoiceId = null;
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

<flux:main class="h-[calc(100vh-4rem)] p-2 bg-slate-100 font-sans select-none overflow-hidden">

    <div x-data x-on:keydown.window.f3.prevent="$wire.checkout()" x-on:keydown.window.f4.prevent="$wire.clearCart()" class="h-full">

        <div class="grid grid-cols-12 gap-2 h-full">

            <div class="col-span-12 lg:col-span-7 flex flex-col h-full space-y-2 min-h-0">

                <!-- شريط التنقل بين الفواتير -->
                <div class="bg-white border border-slate-300 rounded-xl p-2 flex items-center justify-between shadow-sm shrink-0">
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="previousInvoice" class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded-lg text-xs font-bold text-slate-700 flex items-center gap-1 active:scale-95 transition-all">
                            <span>➔</span>
                            <span>الفاتورة السابقة</span>
                        </button>
                        <button type="button" wire:click="nextInvoice" @if(!$currentInvoiceId) disabled @endif class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 border border-slate-300 rounded-lg text-xs font-bold text-slate-700 flex items-center gap-1 active:scale-95 transition-all">
                            <span>الفاتورة التالية</span>
                            <span>⬅</span>
                        </button>
                    </div>

                    <div>
                        @if($currentInvoiceId)
                            <span class="bg-amber-100 text-amber-800 border border-amber-300 px-2.5 py-1 rounded-md text-xs font-bold font-mono">
                                عرض فاتورة #{{ $currentInvoiceId }}
                            </span>
                        @else
                            <span class="bg-emerald-100 text-emerald-800 border border-emerald-300 px-2.5 py-1 rounded-md text-xs font-bold">
                                فاتورة جديدة
                            </span>
                        @endif
                    </div>
                </div>

                @if ($errorMessage)
                    <div class="bg-rose-50 border border-rose-200 text-rose-700 p-2 rounded-lg text-xs font-semibold flex items-center justify-between">
                        <span>{{ $errorMessage }}</span>
                        <button wire:click="$set('errorMessage', null)" class="text-rose-500 font-bold">✕</button>
                    </div>
                @endif

                @if ($successMessage)
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-2 rounded-lg text-xs font-semibold flex items-center justify-between">
                        <span>{{ $successMessage }}</span>
                        <button wire:click="$set('successMessage', null)" class="text-emerald-600 font-bold">✕</button>
                    </div>
                @endif

                <div class="flex-1 bg-white border border-slate-300 rounded-xl overflow-hidden shadow-sm flex flex-col min-h-0">
                    <div class="overflow-y-auto flex-1">
                        <table class="w-full text-right text-xs">
                            <thead class="bg-slate-200 sticky top-0 font-bold text-slate-800 border-b border-slate-300">
                                <tr>
                                    <th class="p-2">اسم المنتج</th>
                                    <th class="p-2 text-center">الكمية</th>
                                    <th class="p-2 text-center">السعر</th>
                                    <th class="p-2 text-center">الإجمالي</th>
                                    <th class="p-2 text-center">حذف</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($cart as $item)
                                    <tr class="hover:bg-indigo-50/50" wire:key="cart-item-{{ $item['id'] }}">
                                        <td class="p-2 font-bold text-slate-900">
                                            {{ $item['name'] }}
                                            @if($item['has_offer'])
                                                <span class="block text-[10px] text-amber-600">سعر عرض 🔥</span>
                                            @endif
                                        </td>
                                        <td class="p-2 text-center">
                                            <div class="inline-flex items-center gap-1 border border-slate-300 rounded-md bg-slate-50 px-1">
                                                <button wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] - 1 }})" class="px-1.5 font-bold text-rose-600 hover:bg-slate-200 rounded">-</button>
                                                <span class="font-bold px-1 font-mono text-xs">{{ $item['quantity'] }}</span>
                                                <button wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] + 1 }})" class="px-1.5 font-bold text-emerald-600 hover:bg-slate-200 rounded">+</button>
                                            </div>
                                        </td>
                                        <td class="p-2 text-center font-mono font-medium">{{ number_format($item['price'], 2) }}</td>
                                        <td class="p-2 text-center font-mono font-black text-indigo-700">{{ number_format($item['subtotal'], 2) }}</td>
                                        <td class="p-2 text-center">
                                            <button wire:click="removeFromCart({{ $item['id'] }})" class="text-rose-500 hover:text-rose-700 font-bold text-sm">×</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="py-24 text-center text-slate-400 font-semibold">
                                            الفاتورة فارغة.. اختر منتجات من القائمة أو امسح الباركود
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-slate-900 text-white p-2.5 flex items-center justify-between text-xs font-bold">
                        <div>المطلوب: <span class="text-amber-400 font-mono text-lg mr-1">{{ number_format($this->total, 2) }}</span> ر.س</div>
                        <div>المتبقي: <span class="text-emerald-400 font-mono text-lg mr-1">{{ number_format($this->change, 2) }}</span> ر.س</div>
                    </div>
                </div>

                <div class="grid grid-cols-12 gap-2 shrink-0">
                    <div class="col-span-7 bg-white p-2.5 rounded-xl border border-slate-300 shadow-sm flex flex-col justify-between">
                        <div class="mb-1.5">
                            <label class="text-[11px] font-bold text-slate-600">المبلغ المدفوع (نقداً):</label>
                            <input type="number" wire:model.live="paid_amount" class="w-full text-xl font-black font-mono text-left bg-slate-50 border border-slate-300 rounded-lg p-1.5 focus:outline-none focus:border-indigo-600 text-indigo-900">
                        </div>

                        <div class="grid grid-cols-3 gap-1 font-bold font-mono">
                            @foreach(['7','8','9','4','5','6','1','2','3','0','.','C'] as $num)
                                <button type="button" wire:click="appendNumpad('{{ $num }}')" class="py-1.5 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded text-sm text-slate-800 shadow-sm active:bg-slate-300">
                                    {{ $num }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="col-span-5 flex flex-col justify-between gap-2">
                        <button wire:click="checkout" @if(count($cart) === 0 || $currentInvoiceId) disabled @endif class="flex-1 bg-emerald-600 hover:bg-emerald-700 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-xl font-extrabold flex flex-col items-center justify-center p-2 shadow active:scale-95 transition-all text-center">
                            <span class="text-base">إتمام وحفظ</span>
                            <span class="text-[10px] bg-emerald-800 text-white px-2 py-0.5 rounded font-mono mt-1">F3</span>
                        </button>

                        <button wire:click="clearCart" @if(count($cart) === 0) disabled @endif class="bg-rose-600 hover:bg-rose-700 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-xl font-bold p-2.5 shadow active:scale-95 transition-all text-xs text-center">
                            <span>فاتورة جديدة / تنظيف (F4)</span>
                        </button>
                    </div>
                </div>

            </div>

            <div class="col-span-12 lg:col-span-5 flex flex-col h-full bg-white border border-slate-300 rounded-xl p-2.5 shadow-sm min-h-0 space-y-2">

                <form wire:submit.prevent="scanBarcode" class="shrink-0">
                    <input wire:model="barcode" placeholder="امسح الباركود هنا..." autofocus class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-xs font-semibold focus:bg-white focus:outline-indigo-600">
                </form>

                <div class="flex gap-1 overflow-x-auto pb-1 shrink-0 scrollbar-none">
                    <button wire:click="selectCategory(null)" class="px-2.5 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap {{ is_null($selectedCategoryId) ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                        الكل
                    </button>

                    @foreach($categories as $cat)
                        <button wire:click="selectCategory({{ $cat->id }})" class="px-2.5 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap {{ $selectedCategoryId === $cat->id ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                            {{ $cat->name }}
                        </button>
                    @endforeach
                </div>

                <div class="flex-1 overflow-y-auto min-h-0 border border-slate-200 rounded-lg p-2 bg-slate-50">
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @forelse($quickProducts as $p)
                            <button wire:click="addToCart({{ $p->id }})" class="bg-indigo-700 hover:bg-indigo-800 text-white p-2 rounded-lg shadow-sm flex flex-col justify-between items-start text-right transition-all h-20 active:scale-95 border border-indigo-900">
                                <span class="text-xs font-bold line-clamp-2 leading-tight">{{ $p->name }}</span>
                                <span class="text-xs font-mono font-black text-amber-300 mt-1">{{ number_format($p->retail_price, 2) }} ر.س</span>
                            </button>
                        @empty
                            <div class="col-span-full text-center py-16 text-slate-400 text-xs font-semibold">
                                لا توجد منتجات ضمن هذه المجموعة
                            </div>
                        @endforelse
                    </div>
                </div>

            </div>

        </div>
    </div>
</flux:main>
