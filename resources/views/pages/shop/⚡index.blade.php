<?php

use App\Models\Branch;
use App\Models\Category;
use App\Services\OnlineShop;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $shop = '';

    public string $search = '';

    public ?int $categoryId = null;

    /** @var array<int, float> product id => quantity, kept in the session per branch */
    public array $cart = [];

    public bool $checkingOut = false;

    public string $name = '';

    public string $phone = '';

    public string $address = '';

    public string $notes = '';

    public function mount(string $shop): void
    {
        $this->shop = $shop;
        $branch = $this->branch();
        $this->cart = array_map('floatval', (array) session('shop_cart.'.$branch->id, []));
        if ($account = $this->service()->customer($branch)) {
            $this->name = (string) $account->party?->name;
            $this->phone = (string) $account->phone;
            $this->address = (string) $account->party?->address;
        }
    }

    private function service(): OnlineShop
    {
        return app(OnlineShop::class);
    }

    private function branch(): Branch
    {
        return $this->service()->branch($this->shop);
    }

    private function saveCart(): void
    {
        $this->cart = array_filter($this->cart, fn (float $quantity): bool => $quantity > 0);
        session(['shop_cart.'.$this->branch()->id => $this->cart]);
    }

    public function add(int $productId): void
    {
        if (count($this->cart) >= 200 && ! isset($this->cart[$productId])) {
            return;
        }
        $this->cart[$productId] = round(($this->cart[$productId] ?? 0) + 1, 3);
        $this->saveCart();
    }

    public function setQuantity(int $productId, mixed $quantity): void
    {
        $this->cart[$productId] = max(0, min(100000, round((float) str_replace(',', '.', (string) $quantity), 3)));
        $this->saveCart();
    }

    public function remove(int $productId): void
    {
        unset($this->cart[$productId]);
        $this->saveCart();
    }

    public function placeOrder(): void
    {
        $branch = $this->branch();
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[\d\s+\-()٠-٩]{7,30}$/u'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['name' => 'الاسم', 'phone' => 'رقم الهاتف', 'address' => 'العنوان', 'notes' => 'ملاحظات']);

        // Guests can order without an account, so each network is limited to a few orders at a time.
        $key = 'shop-order:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('cart', 'طلبات كثيرة خلال وقت قصير. حاول بعد قليل.');

            return;
        }
        RateLimiter::hit($key, 600);

        $order = $this->service()->placeOrder($branch, $this->cart, $validated, $this->service()->customer($branch));
        $this->cart = [];
        $this->saveCart();
        $this->redirectRoute('shop.track', ['shop' => $this->shop, 'token' => $order->tracking_token], navigate: true);
    }

    public function render(): mixed
    {
        $branch = $this->branch();
        $account = $this->service()->customer($branch);
        $priceList = $this->service()->priceList($account);
        $lines = $this->service()->cartLines($branch, $priceList, $this->cart);
        $categories = Category::withoutGlobalScopes()->where('tenant_id', $branch->tenant_id)
            ->whereIn('id', fn ($query) => $query->select('category_id')->from('products')->where('tenant_id', $branch->tenant_id)->where('show_in_website', true)->whereNull('deleted_at'))
            ->orderBy('name')->get(['id', 'name']);

        return $this->view([
            'branch' => $branch,
            'account' => $account,
            'products' => $this->service()->catalog($branch, $priceList, $this->search, $this->categoryId),
            'categories' => $categories,
            'lines' => $lines,
            'cartTotal' => round($lines->sum('total'), 2),
        ])->layout('layouts::shop', ['branch' => $branch, 'title' => null]);
    }
};
?>

<div class="grid gap-5 lg:grid-cols-[1fr_22rem]" data-shop-home>

    <section>
        @if ($account?->isWholesale())
            <div class="mb-3 rounded-xl border border-teal-200 bg-teal-50 px-4 py-2 text-sm font-bold text-teal-800">الأسعار المعروضة هي أسعار الجملة الخاصة بحسابك.</div>
        @endif

        <div class="mb-4 flex flex-col gap-2 sm:flex-row">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="ابحث عن صنف بالاسم أو الباركود..." aria-label="بحث"
                class="h-11 flex-1 rounded-xl border border-slate-200 bg-white px-4 text-sm outline-none focus:border-teal-500">
            @if ($categories->isNotEmpty())
                <select wire:model.live="categoryId" aria-label="التصنيف" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="">كل التصنيفات</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
            @forelse ($products as $product)
                <article wire:key="product-{{ $product['id'] }}" class="flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white" data-shop-product>
                    <div class="flex aspect-square items-center justify-center bg-slate-100">
                        @if ($product['image'])
                            <img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy" class="size-full object-cover">
                        @else
                            <span class="text-3xl text-slate-300">📦</span>
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col gap-1 p-3">
                        <h2 class="line-clamp-2 text-sm font-bold">{{ $product['name'] }}</h2>
                        <div class="mt-auto flex items-center justify-between gap-2">
                            <span class="font-mono text-base font-black text-teal-700">{{ number_format($product['price'], 2) }}</span>
                            <span @class(['text-[11px] font-bold', 'text-emerald-600' => $product['available'], 'text-rose-500' => ! $product['available']])>{{ $product['available'] ? 'متوفر' : 'غير متوفر' }}</span>
                        </div>
                        <button type="button" wire:click="add({{ $product['id'] }})" @disabled(! $product['available'])
                            class="mt-2 rounded-xl bg-teal-600 py-2 text-sm font-bold text-white hover:bg-teal-500 disabled:cursor-not-allowed disabled:bg-slate-300">
                            {{ isset($cart[$product['id']]) ? 'أضف واحداً آخر ('.rtrim(rtrim(number_format($cart[$product['id']], 3, '.', ''), '0'), '.').')' : 'أضف للسلة' }}
                        </button>
                    </div>
                </article>
            @empty
                <p class="col-span-full rounded-2xl border border-dashed border-slate-300 p-10 text-center text-slate-500">لا توجد أصناف مطابقة.</p>
            @endforelse
        </div>
    </section>

    <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-4 lg:sticky lg:top-20" data-shop-cart>
        <h2 class="mb-3 font-black">السلة</h2>
        @forelse ($lines as $line)
            <div wire:key="line-{{ $line['id'] }}" class="flex items-center gap-2 border-b border-slate-100 py-2 text-sm">
                <div class="min-w-0 flex-1">
                    <div class="truncate font-bold">{{ $line['name'] }}</div>
                    <div class="text-xs text-slate-500">{{ number_format($line['price'], 2) }} × </div>
                </div>
                <input type="number" min="0" step="1" inputmode="decimal" value="{{ rtrim(rtrim(number_format($line['quantity'], 3, '.', ''), '0'), '.') }}"
                    wire:change="setQuantity({{ $line['id'] }}, $event.target.value)" aria-label="الكمية"
                    class="w-16 rounded-lg border border-slate-200 px-2 py-1 text-center font-mono">
                <span class="w-16 text-left font-mono font-bold">{{ number_format($line['total'], 2) }}</span>
                <button type="button" wire:click="remove({{ $line['id'] }})" aria-label="حذف" class="text-rose-500">×</button>
            </div>
        @empty
            <p class="py-6 text-center text-sm text-slate-500">السلة فارغة.</p>
        @endforelse

        @if ($lines->isNotEmpty())
            <div class="mt-3 flex items-center justify-between text-base font-black">
                <span>المجموع التقديري</span><span class="font-mono">{{ number_format($cartTotal, 2) }}</span>
            </div>
            <p class="mt-1 text-[11px] text-slate-500">يؤكد المتجر الطلب والسعر النهائي قبل التجهيز.</p>

            @if (! $checkingOut)
                <button type="button" wire:click="$set('checkingOut', true)" class="mt-3 w-full rounded-xl bg-teal-600 py-2.5 font-bold text-white hover:bg-teal-500">إتمام الطلب</button>
            @else
                <form wire:submit="placeOrder" class="mt-3 space-y-2" data-shop-checkout>
                    <input type="text" wire:model="name" placeholder="الاسم" aria-label="الاسم" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    @error('name')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <input type="tel" wire:model="phone" placeholder="رقم الهاتف" aria-label="رقم الهاتف" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" dir="ltr">
                    @error('phone')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <input type="text" wire:model="address" placeholder="العنوان (اختياري)" aria-label="العنوان" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    <textarea wire:model="notes" rows="2" placeholder="ملاحظات (اختياري)" aria-label="ملاحظات" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></textarea>
                    @error('cart')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <button type="submit" wire:loading.attr="disabled" class="w-full rounded-xl bg-teal-600 py-2.5 font-bold text-white hover:bg-teal-500 disabled:opacity-60">إرسال الطلب</button>
                    @guest('customer')
                        <p class="text-center text-[11px] text-slate-500">لديك حساب؟ <a href="{{ route('shop.login', ['shop' => $shop]) }}" wire:navigate class="font-bold text-teal-700">سجّل الدخول</a> لمتابعة طلباتك وفواتيرك.</p>
                    @endguest
                </form>
            @endif
        @endif
    </aside>

</div>
