<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public array $cart = [];
    public ?string $customerName = '';
    public ?string $customerPhone = '';

    private function getTenantId(): ?int
    {
        $user = auth()->user();

        if (!$user) return null;
        if (!empty($user->tenant_id)) return (int) $user->tenant_id;
        if (method_exists($user, 'tenants')) return $user->tenants()->first()?->id;

        return session('active_tenant_id') ? (int) session('active_tenant_id') : null;
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function addToCart(int $productId): void
    {
        $tenantId = $this->getTenantId();
        if (!$tenantId) return;

        $product = Product::where('tenant_id', $tenantId)->find($productId);
        if (!$product) return;

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity']++;
        } else {
            $this->cart[$productId] = [
                'id' => $product->id,
                'name' => $product->name,
                'image' => $product->image,
                'price' => (float) ($product->wholesale_price ?? $product->retail_price ?? 0),
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

    public function completeSale(): void
    {
        if (empty($this->cart)) return;

        $tenantId = $this->getTenantId();
        if (!$tenantId) return;

        $subtotal = array_reduce($this->cart, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);
        $totalCost = array_reduce($this->cart, fn($sum, $item) => $sum + ($item['cost'] * $item['quantity']), 0);

        DB::transaction(function () use ($tenantId, $subtotal, $totalCost) {
            $order = Order::create([
                'tenant_id' => $tenantId,
                'customer_name' => $this->customerName ?: 'زبون جملة عابر',
                'customer_phone' => $this->customerPhone,
                'invoice_number' => 'INV-VAN-' . date('Ymd') . '-' . rand(100, 999),
                'type' => 'wholesale',
                'status' => 'completed',
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'total_cost' => $totalCost,
                'total_profit' => $subtotal - $totalCost,
                'paid_amount' => $subtotal,
                'payment_status' => 'paid',
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

            $this->cart = [];
            $this->reset(['customerName', 'customerPhone']);
        });

        session()->flash('message', 'تم إصدار فاتورة الجملة بنجاح!');
    }

    public function render()
    {
        $tenantId = $this->getTenantId();

        $products = Product::where('tenant_id', $tenantId)
            ->when($this->search, fn($q) => $q->where(fn($sub) => $sub->where('name', 'like', "%{$this->search}%")->orWhere('barcode', 'like', "%{$this->search}%")))
            ->paginate(12);

        $cartTotal = array_reduce($this->cart, fn($sum, $item) => $sum + ($item['price'] * $item['quantity']), 0);

        return $this->view([
            'products' => $products,
            'cartTotal' => $cartTotal,
        ]);
    }
};
?>

<div class="h-[calc(100vh-4rem)] flex flex-col p-4 bg-zinc-50 dark:bg-zinc-950" dir="rtl">
    <!-- التنبيهات -->
    @if (session()->has('error'))
        <flux:badge variant="danger" class="mb-3 w-full justify-start p-2.5 text-xs">
            {{ session('error') }}
        </flux:badge>
    @endif

    @if (session()->has('message'))
        <flux:badge variant="success" class="mb-3 w-full justify-start p-2.5 text-xs">
            {{ session('message') }}
        </flux:badge>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 flex-1 overflow-hidden">
        <!-- قسم المنتجات (يمين) -->
        <div class="lg:col-span-8 flex flex-col space-y-3 h-full overflow-hidden">
            <!-- شريط البحث -->
            <div class="bg-white dark:bg-zinc-900 p-2 rounded-xl border border-zinc-200 dark:border-zinc-800 shadow-sm">
                <flux:input wire:model.live.debounce.200ms="search" placeholder="بحث باسم المنتج أو الباركود..." icon="magnifying-glass" class="w-full" />
            </div>

            <!-- شبكة المنتجات متناسقة الارتفاع -->
            <div class="flex-1 overflow-y-auto grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 p-0.5 content-start">
                @forelse($products as $product)
                    <button wire:click="addToCart({{ $product->id }})"
                            class="flex flex-col h-56 justify-between p-2.5 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl hover:border-indigo-500 hover:shadow-md transition text-right group">

                        <!-- إطار الصورة الثابت -->
                        <div class="w-full h-28 bg-zinc-50 dark:bg-zinc-800/60 rounded-lg overflow-hidden flex items-center justify-center p-1 border border-zinc-100 dark:border-zinc-800">
                            @if($product->image)
                                <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}" class="w-full h-full object-contain group-hover:scale-105 transition duration-200">
                            @else
                                <flux:icon icon="photo" class="w-7 h-7 text-zinc-300 dark:text-zinc-600" />
                            @endif
                        </div>

                        <!-- اسم المنتج -->
                        <div class="font-semibold text-xs text-zinc-800 dark:text-zinc-200 line-clamp-2 my-1 leading-tight">
                            {{ $product->name }}
                        </div>

                        <!-- السعر -->
                        <div class="flex justify-between items-center w-full pt-2 border-t border-zinc-100 dark:border-zinc-800/80">
                            <span class="text-[10px] text-zinc-400">سعر الجملة</span>
                            <span class="font-bold text-indigo-600 dark:text-indigo-400 text-sm">
                                {{ number_format($product->wholesale_price ?? $product->retail_price, 2) }}
                            </span>
                        </div>
                    </button>
                @empty
                    <div class="col-span-full text-center py-12 text-zinc-400 text-sm">لا توجد منتجات مطابقة.</div>
                @endforelse
            </div>

            <div class="pt-1">{{ $products->links() }}</div>
        </div>

        <!-- قسم الفاتورة والسلة (يسار) -->
        <div class="lg:col-span-4 flex flex-col bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-xl p-4 shadow-sm h-full overflow-hidden">
            <div class="flex flex-col h-full justify-between space-y-3">
                <div class="space-y-3 flex-1 flex flex-col overflow-hidden">
                    <flux:heading size="md" class="border-b border-zinc-100 dark:border-zinc-800 pb-2">فاتورة مبيعات باص</flux:heading>

                    <!-- بيانات الزبون -->
                    <div class="grid grid-cols-2 gap-2">
                        <flux:input wire:model="customerName" placeholder="اسم المحل/الزبون" size="sm" />
                        <flux:input wire:model="customerPhone" placeholder="رقم الهاتف" size="sm" />
                    </div>

                    <!-- السلة -->
                    <div class="flex-1 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800/60 pr-1">
                        @forelse($cart as $id => $item)
                            <div class="py-2 flex justify-between items-center text-xs gap-2">
                                <div class="w-8 h-8 rounded bg-zinc-100 dark:bg-zinc-800 overflow-hidden flex-shrink-0 border border-zinc-200 dark:border-zinc-700 flex items-center justify-center p-0.5">
                                    @if(!empty($item['image']))
                                        <img src="{{ Storage::url($item['image']) }}" alt="{{ $item['name'] }}" class="w-full h-full object-contain">
                                    @else
                                        <flux:icon icon="photo" class="w-3.5 h-3.5 text-zinc-400" />
                                    @endif
                                </div>

                                <div class="flex-1 truncate">
                                    <div class="font-medium truncate text-zinc-800 dark:text-zinc-200">{{ $item['name'] }}</div>
                                    <div class="text-[10px] text-zinc-400">{{ number_format($item['price'], 2) }} × {{ $item['quantity'] }}</div>
                                </div>

                                <div class="flex items-center gap-1">
                                    <flux:button size="xs" variant="subtle" wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] - 1 }})">-</flux:button>
                                    <span class="font-bold text-xs px-1 text-zinc-700 dark:text-zinc-300">{{ $item['quantity'] }}</span>
                                    <flux:button size="xs" variant="subtle" wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] + 1 }})">+</flux:button>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-12 text-zinc-400 text-xs">السلة فارغة</div>
                        @endforelse
                    </div>
                </div>

                <!-- المجموع والإصدار -->
                <div class="pt-3 border-t border-zinc-200 dark:border-zinc-800 space-y-2">
                    <div class="flex justify-between items-center font-bold text-base">
                        <span class="text-zinc-700 dark:text-zinc-300">المجموع:</span>
                        <span class="text-lg text-emerald-600 dark:text-emerald-400 font-mono">{{ number_format($cartTotal, 2) }}</span>
                    </div>

                    <flux:button variant="primary" icon="check" class="w-full py-2.5 text-sm" wire:click="completeSale" :disabled="empty($cart)">
                        حفظ وإصدار الفاتورة
                    </flux:button>
                </div>
            </div>
        </div>
    </div>
</div>
