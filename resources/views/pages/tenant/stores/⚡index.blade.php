<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Tenant;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;

    public string $slug;

    // بيانات الزبون لطلبات الأونلاين
    public string $customer_name = '';
    public string $customer_phone = '';
    public string $customer_address = '';

    protected array $rules = [
        'customer_name' => 'required|string|max:255',
        'customer_phone' => 'required|string|max:50',
        'customer_address' => 'required|string|max:500',
    ];

    protected array $validationAttributes = [
        'customer_name' => 'الاسم الكامل',
        'customer_phone' => 'رقم الهاتف',
        'customer_address' => 'عنوان التوصيل',
    ];

    public function mount(string $slug)
    {
        $this->slug = $slug;
    }

    // --- إدارة سلة التسوق ---
    public function addToCart(int $productId)
    {
        $tenant = Tenant::where('domain', $this->slug)->firstOrFail();
        $sessionKey = 'cart_' . $tenant->id;
        $cart = session()->get($sessionKey, []);

        if (isset($cart[$productId])) {
            $cart[$productId]['quantity']++;
        } else {
            $product = Product::where('tenant_id', $tenant->id)
                ->where('show_in_website', true)
                ->findOrFail($productId);

            $imagePath = $product->image_url
                ? asset('storage/' . $product->image_url)
                : asset('images/default-product.png');

            $cart[$productId] = [
                'id' => $product->id,
                'name' => $product->name,
                'image' => $imagePath,
                'price' => (float) $product->retail_price,
                'cost' => (float) ($product->cost_price ?? 0),
                'quantity' => 1,
            ];
        }

        session()->put($sessionKey, $cart);
    }

    public function updateQuantity(int $productId, int $quantity)
    {
        $tenant = Tenant::where('domain', $this->slug)->firstOrFail();
        $sessionKey = 'cart_' . $tenant->id;
        $cart = session()->get($sessionKey, []);

        if ($quantity <= 0) {
            unset($cart[$productId]);
        } else {
            $cart[$productId]['quantity'] = $quantity;
        }

        session()->put($sessionKey, $cart);
    }

    public function removeItem(int $productId)
    {
        $this->updateQuantity($productId, 0);
    }

    // --- إنشاء الطلب الإلكتروني والإرسال إلى الواتساب ---
    public function placeOrder()
    {
        $tenant = Tenant::where('domain', $this->slug)->firstOrFail();
        $sessionKey = 'cart_' . $tenant->id;
        $cart = session()->get($sessionKey, []);

        if (empty($cart)) {
            session()->flash('error', 'سلة التسوق فارغة، يرجى إضافة منتجات أولاً.');
            return;
        }

        $this->validate();

        $subtotal = 0;
        $totalCost = 0;

        foreach ($cart as $item) {
            $subtotal += $item['price'] * $item['quantity'];
            $totalCost += $item['cost'] * $item['quantity'];
        }

        $totalProfit = $subtotal - $totalCost;
        $whatsappUrl = '';

        DB::transaction(function () use ($tenant, $cart, $subtotal, $totalCost, $totalProfit, $sessionKey, &$whatsappUrl) {
            $order = Order::create([
                'tenant_id' => $tenant->id,
                'branch_id' => null,
                'user_id' => null,
                'customer_id' => null,
                'customer_name' => $this->customer_name,
                'customer_phone' => $this->customer_phone,
                'customer_address' => $this->customer_address,
                'invoice_number' => 'INV-ON-' . date('Ymd') . '-' . rand(1000, 9999),
                'type' => 'online',
                'status' => 'pending',
                'subtotal' => $subtotal,
                'discount' => 0.00,
                'total' => $subtotal,
                'total_cost' => $totalCost,
                'total_profit' => $totalProfit,
                'paid_amount' => 0.00,
                'payment_status' => 'unpaid',
            ]);

            foreach ($cart as $productId => $item) {
                OrderItem::create([
                    'tenant_id' => $tenant->id,
                    'order_id' => $order->id,
                    'product_id' => $productId,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['price'],
                    'total_price' => $item['price'] * $item['quantity'],
                    'cost_price' => $item['cost'],
                    'total_cost' => $item['cost'] * $item['quantity'],
                ]);
            }

            // --- بناء نص الرسالة وتنسيقها للواتساب ---
            $message = "🛒 *طلب جديد عبر المتجر الإلكتروني*\n";
            $message .= "----------------------------\n";
            $message .= "📄 *رقم الطلب:* " . $order->invoice_number . "\n";
            $message .= "👤 *الاسم:* " . $this->customer_name . "\n";
            $message .= "📞 *الهاتف:* " . $this->customer_phone . "\n";
            $message .= "📍 *العنوان:* " . $this->customer_address . "\n\n";
            $message .= "📦 *المنتجات:* \n";

            foreach ($cart as $item) {
                $itemTotal = number_format($item['price'] * $item['quantity'], 2);
                $message .= "- {$item['name']} (الكمية: {$item['quantity']}) = {$itemTotal}\n";
            }

            $message .= "----------------------------\n";
            $message .= "💰 *المجموع الكلي:* " . number_format($subtotal, 2) . "\n\n";
            $message .= "شُكراً لتسوقكم معنا! 🙏";

            // تحديد رقم الواتساب الخاص بالمتجر (مأخوذ من نموذج Tenant أو رقم افتراضي)
            $storePhone = preg_replace('/[^0-9]/', '', $tenant->phone ?? '970590000000');

            // إنشاء رابط الواتساب الشامل
            $whatsappUrl = "https://wa.me/{$storePhone}?text=" . urlencode($message);

            session()->forget($sessionKey);
            $this->reset(['customer_name', 'customer_phone', 'customer_address']);
        });

        // التوجيه إلى واتساب في نافذة جديدة مباشرة
        return $this->redirect($whatsappUrl, navigate: false);
    }

    public function render()
    {
        $tenant = Tenant::where('domain', $this->slug)->firstOrFail();

        $products = Product::where('tenant_id', $tenant->id)
            ->where('show_in_website', true)
            ->latest()
            ->paginate(8);

        $cart = session()->get('cart_' . $tenant->id, []);
        $total = array_reduce($cart, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);

        return $this->view([
            'tenant' => $tenant,
            'products' => $products,
            'cart' => $cart,
            'total' => $total,
        ]);
    }
};
?>

<div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-8" dir="rtl">
    <!-- هيدر المتجر -->
    <div class="pb-6 border-b border-zinc-200 dark:border-zinc-800 flex justify-between items-center">
        <div>
            <flux:heading size="xl">{{ $tenant->name }}</flux:heading>
            <flux:subheading>اختر المنتجات وأكمل طلبك مباشرة</flux:subheading>
        </div>
    </div>

    <!-- التنبيهات -->
    @if (session()->has('message'))
        <flux:badge variant="success" class="w-full justify-start p-4 text-base">
            {{ session('message') }}
        </flux:badge>
    @endif

    @if (session()->has('error'))
        <flux:badge variant="danger" class="w-full justify-start p-4 text-base">
            {{ session('error') }}
        </flux:badge>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
        <!-- معرض المنتجات -->
        <div class="lg:col-span-7 xl:col-span-8 space-y-6">
            <flux:heading size="lg">قائمة المنتجات</flux:heading>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @forelse($products as $product)
                    <flux:card class="flex flex-col justify-between space-y-4 overflow-hidden">
                        <div class="w-full h-48 bg-zinc-100 dark:bg-zinc-800 rounded-lg overflow-hidden flex items-center justify-center">
                            @if($product->image)
                                <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}" class="w-full h-full object-cover">
                            @else
                                <div class="flex flex-col items-center justify-center text-zinc-400">
                                    <flux:icon icon="photo" class="w-12 h-12 mb-1" />
                                    <span class="text-xs">لا توجد صورة</span>
                                </div>
                            @endif
                        </div>

                        <div class="space-y-2">
                            <flux:heading size="lg">{{ $product->name }}</flux:heading>
                            <flux:subheading class="font-bold text-emerald-600 dark:text-emerald-400">
                                {{ number_format($product->retail_price, 2) }}
                            </flux:subheading>
                        </div>

                        <flux:button variant="subtle" icon="plus" class="w-full" wire:click="addToCart({{ $product->id }})">
                            إضافة للسلة
                        </flux:button>
                    </flux:card>
                @empty
                    <div class="col-span-full text-center py-12 text-zinc-500">
                        لا توجد منتجات معروضة حالياً.
                    </div>
                @endforelse
            </div>

            @if($products->hasPages())
                <div>{{ $products->links() }}</div>
            @endif
        </div>

        <!-- السلة وإتمام الطلب -->
        <div class="lg:col-span-5 xl:col-span-4 space-y-6">
            <flux:card class="space-y-4">
                <div class="flex justify-between items-center pb-2 border-b border-zinc-100 dark:border-zinc-800">
                    <flux:heading size="lg">سلة التسوق</flux:heading>
                    <flux:badge variant="subtle">{{ count($cart) }} منتجات</flux:badge>
                </div>

                @if(empty($cart))
                    <div class="text-center py-6 text-zinc-400">
                        السلة فارغة حالياً
                    </div>
                @else
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800 max-h-72 overflow-y-auto pl-1">
                        @foreach($cart as $id => $item)
                            <div class="py-3 flex items-center justify-between text-sm gap-3">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $item['image'] }}" class="w-10 h-10 object-cover rounded-md bg-zinc-100 dark:bg-zinc-800 shrink-0">
                                    <div>
                                        <div class="font-medium line-clamp-1">{{ $item['name'] }}</div>
                                        <div class="text-xs text-zinc-500">{{ number_format($item['price'], 2) }} × {{ $item['quantity'] }}</div>
                                    </div>
                                </div>

                                <div class="flex items-center gap-1 shrink-0">
                                    <flux:button size="xs" variant="subtle" wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] - 1 }})">-</flux:button>
                                    <span class="text-xs font-bold px-1">{{ $item['quantity'] }}</span>
                                    <flux:button size="xs" variant="subtle" wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] + 1 }})">+</flux:button>
                                    <flux:button size="xs" variant="ghost" icon="trash" class="text-red-500" wire:click="removeItem({{ $id }})" />
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="pt-3 border-t border-zinc-200 dark:border-zinc-700 flex justify-between items-center font-bold">
                        <span>المجموع الكلي:</span>
                        <span class="text-xl text-emerald-600 dark:text-emerald-400">{{ number_format($total, 2) }}</span>
                    </div>
                @endif
            </flux:card>

            @if(!empty($cart))
                <flux:card class="space-y-4">
                    <flux:heading size="lg">بيانات التوصيل</flux:heading>

                    <form wire:submit.prevent="placeOrder" class="space-y-4">
                        <flux:field>
                            <flux:label>الاسم الكامل</flux:label>
                            <flux:input wire:model="customer_name" placeholder="أدخل اسمك..." />
                            <flux:error name="customer_name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>رقم الهاتف</flux:label>
                            <flux:input wire:model="customer_phone" placeholder="059xxxxxxx" />
                            <flux:error name="customer_phone" />
                        </flux:field>

                        <flux:field>
                            <flux:label>عنوان التوصيل</flux:label>
                            <flux:textarea wire:model="customer_address" placeholder="المدينة، الشارع..." rows="2" />
                            <flux:error name="customer_address" />
                        </flux:field>

                        <flux:button type="submit" variant="primary" icon="check" class="w-full bg-emerald-600 hover:bg-emerald-700">
                            تأكيد الطلب والمتابعة عبر الواتساب
                        </flux:button>
                    </form>
                </flux:card>
            @endif
        </div>
    </div>
</div>
