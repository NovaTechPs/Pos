<?php

use App\Models\Branch;
use App\Services\OnlineShop;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $shop = '';

    #[Url]
    public string $search = '';

    public ?int $categoryId = null;

    /** @var array<int, float> product id => quantity, kept in the session per branch */
    public array $cart = [];

    public bool $checkingOut = false;

    public string $name = '';

    public string $phone = '';

    public string $address = '';

    public string $notes = '';

    public ?int $addressId = null;

    public function chooseAddress(int $id): void
    {
        $customer = $this->service()->storeCustomer($this->branch());
        abort_unless($customer, 403);
        $address = $customer->addresses()->findOrFail($id);
        $this->addressId = $address->id;
        $this->name = $address->recipient_name;
        $this->phone = $address->phone;
        $this->address = $address->deliveryAddress();
    }

    public function mount(string $shop): void
    {
        $this->shop = $shop;
        $branch = $this->branch();
        $this->cart = array_map('floatval', (array) session('shop_cart.'.$branch->id, []));
        if ($customer = $this->service()->storeCustomer($branch)) {
            $this->name = $customer->name;
            $this->phone = $customer->phone;
            if ($address = $customer->addresses()->where('is_default', true)->first()) {
                $this->chooseAddress($address->id);
            }
        } elseif ($account = $this->service()->customer($branch)) {
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
        $retailCustomer = $this->service()->storeCustomer($branch);
        if (! $retailCustomer && ! $this->service()->customer($branch)) {
            $this->saveCart();
            $this->redirectRoute('shop.login', ['shop' => $this->shop], navigate: true);
            return;
        }
        if ($this->addressId) {
            $this->chooseAddress($this->addressId);
        }
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[\d\s+\-()٠-٩]{7,30}$/u'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['name' => 'الاسم', 'phone' => 'رقم الهاتف', 'address' => 'العنوان', 'notes' => 'ملاحظات']);

        $key = 'shop-order:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('cart', 'طلبات كثيرة خلال وقت قصير. حاول بعد قليل.');

            return;
        }
        RateLimiter::hit($key, 600);

        $order = $this->service()->placeOrder($branch, $this->cart, $validated, $this->service()->customer($branch), $retailCustomer);
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
        $categories = $this->service()->webCategories($branch);

        return $this->view([
            'branch' => $branch,
            'branding' => $this->service()->branding($branch),
            'account' => $account,
            'retailCustomer' => $this->service()->storeCustomer($branch),
            'addresses' => $this->service()->storeCustomer($branch)?->addresses()->orderByDesc('is_default')->get() ?? collect(),
            'products' => $this->service()->catalog($branch, $priceList, $this->search, $this->categoryId),
            'categories' => $categories,
            'lines' => $lines,
            'cartTotal' => round($lines->sum('total'), 2),
        ])->layout('layouts::shop', ['branch' => $branch, 'title' => null]);
    }
};
?>

<div class="shop-home" data-shop-home>
    @if ($account?->isWholesale())<p class="shop-notice">الأسعار المعروضة هي أسعار الجملة الخاصة بحسابك.</p>@endif
    @if($search === '' && ! $categoryId)
        <section class="shop-hero">
            @if($branding['banner'])<img class="shop-hero-image" src="{{ $branding['banner'] }}" alt="{{ $branding['hero_title'] }}">@endif
            <div class="shop-hero-copy"><span class="shop-eyebrow">أهلاً بك في {{ $branding['name'] }}</span><h1>{{ $branding['hero_title'] }}</h1><p>{{ $branding['hero_text'] }}</p><a href="#shop-products" class="shop-button shop-button-yellow">تسوّق الآن <x-shop-icon name="cart" /></a></div>
            @unless($branding['banner'])
                <div class="shop-hero-art" aria-hidden="true">
                    @forelse($products->whereNotNull('image')->take(3) as $heroProduct)<div><img src="{{ $heroProduct['image'] }}" alt=""></div>@empty<div class="shop-hero-symbol"><x-shop-icon name="store" /><span>{{ $branding['name'] }}</span></div>@endforelse
                </div>
            @endunless
        </section>
    @endif
    <div class="shop-benefits">
        <div><x-shop-icon name="store" /><span><strong>من المحل مباشرة</strong><small>تواصل مع متجرك المفضل</small></span></div>
        <div><x-shop-icon name="box" /><span><strong>طلب بخطوات سهلة</strong><small>اختر منتجاتك وأرسل طلبك</small></span></div>
        <div><x-shop-icon name="clock" /><span><strong>تابع طلباتك</strong><small>تفاصيل وحالة كل طلب</small></span></div>
        <div><x-shop-icon name="user" /><span><strong>حساب خاص بك</strong><small>طلباتك وعناوينك في مكان واحد</small></span></div>
    </div>
    <section id="shop-categories" class="shop-categories-section">
        <div class="shop-section-heading"><h2>تصفّح الأقسام</h2><button type="button" wire:click="$set('categoryId', null)" class="shop-text-link">عرض الكل <x-shop-icon name="arrow" /></button></div>
        <div class="shop-categories">
            <button type="button" wire:click="$set('categoryId', null)" @class(['shop-category', 'is-active' => ! $categoryId])><span class="shop-category-image"><x-shop-icon name="grid" /></span><strong>كل الأقسام</strong></button>
            @foreach($categories as $category)
                <button type="button" wire:key="category-{{ $category->id }}" wire:click="$set('categoryId', {{ $category->id }})" @class(['shop-category', 'is-active' => $categoryId === $category->id])>
                    <span class="shop-category-image">@if($category->image)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($category->image) }}" alt="" loading="lazy">@else<x-shop-icon name="box" />@endif</span><strong>{{ $category->name }}</strong>
                </button>
            @endforeach
        </div>
    </section>
    @if($categories->count() > 1 && $search === '' && ! $categoryId)
        <div class="shop-promos">
            @foreach($categories->take(3) as $category)
                <button type="button" wire:key="promo-{{ $category->id }}" wire:click="$set('categoryId', {{ $category->id }})" class="shop-promo">
                    <span><small>اكتشف مجموعتنا</small><strong>{{ $category->name }}</strong><em>تسوّق الآن <x-shop-icon name="arrow" /></em></span>
                    @if($category->image)<img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($category->image) }}" alt="" loading="lazy">@else<x-shop-icon name="box" />@endif
                </button>
            @endforeach
        </div>
    @endif
    <div class="shop-catalog-layout">
        <section id="shop-products">
            <div class="shop-section-heading"><h2>{{ $categoryId ? ($categories->firstWhere('id', $categoryId)?->name ?? 'المنتجات') : 'منتجات متجرنا' }}</h2><span class="shop-muted">{{ $products->count() }} منتج معروض</span></div>
            <div class="shop-catalog-search"><x-shop-icon name="search" /><input type="search" wire:model.live.debounce.300ms="search" aria-label="بحث" placeholder="ابحث بالاسم أو رقم الصنف…"></div>
            <div class="shop-products">
                @forelse($products as $product)
                    <article wire:key="product-{{ $product['id'] }}" class="shop-product" data-shop-product>
                        <div class="shop-product-image">
                            @if($product['image'])<img src="{{ $product['image'] }}" alt="{{ $product['name'] }}" loading="lazy">@else<x-shop-icon name="box" />@endif
                            <span @class(['shop-availability', 'is-unavailable' => ! $product['available']])>{{ $product['available'] ? 'متوفر' : 'غير متوفر' }}</span>
                        </div>
                        <div class="shop-product-info"><h3>{{ $product['name'] }}</h3>
                            <div class="shop-product-bottom"><strong>{{ number_format($product['price'], 2) }} <small>{{ $branding['currency'] }}</small></strong><button type="button" wire:click="add({{ $product['id'] }})" wire:loading.attr="disabled" @disabled(! $product['available']) aria-label="إضافة {{ $product['name'] }} للسلة"><x-shop-icon name="cart" /></button></div>
                            @if(isset($cart[$product['id']]))<span class="shop-in-cart">في السلة: {{ $cart[$product['id']] }}</span>@endif
                        </div>
                    </article>
                @empty<div class="shop-empty"><x-shop-icon name="search" /><h3>لا توجد منتجات مطابقة</h3><p>جرّب كلمة بحث أخرى أو اختر قسمًا مختلفًا.</p></div>@endforelse
            </div>
        </section>
    <aside id="shop-cart" class="shop-cart" data-shop-cart>
        <h2 class="shop-section-title"><x-shop-icon name="cart" /> سلة التسوق <span>{{ $lines->count() }}</span></h2>
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

            @if (! $account && ! $retailCustomer)
                <a href="{{ route('shop.login', ['shop' => $shop]) }}" wire:navigate class="mt-3 block rounded-xl bg-blue-600 py-2.5 text-center font-bold text-white">سجّل الدخول لإتمام الطلب</a>
                <p class="mt-2 text-center text-xs text-slate-500">سلتك محفوظة حتى تسجيل الدخول.</p>
            @elseif (! $checkingOut)
                <button type="button" wire:click="$set('checkingOut', true)" class="mt-3 w-full rounded-xl bg-blue-600 py-2.5 font-bold text-white hover:bg-blue-500">إتمام الطلب</button>
            @else
                <form wire:submit="placeOrder" class="mt-3 space-y-2" data-shop-checkout>
                    @if ($addresses->isNotEmpty())
                        <select wire:model="addressId" wire:change="chooseAddress($event.target.value)" aria-label="العنوان المحفوظ" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                            <option value="" disabled>اختر عنواناً محفوظاً</option>
                            @foreach ($addresses as $savedAddress)
                                <option value="{{ $savedAddress->id }}">{{ $savedAddress->label }} — {{ $savedAddress->city }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="$set('addressId', null)" class="text-xs text-blue-700">استخدام عنوان آخر لهذا الطلب</button>
                    @endif
                    <input type="text" wire:model="name" placeholder="الاسم" aria-label="الاسم" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    @error('name')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <input type="tel" wire:model="phone" placeholder="رقم الهاتف" aria-label="رقم الهاتف" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm" dir="ltr">
                    @error('phone')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <input type="text" wire:model="address" placeholder="العنوان (اختياري)" aria-label="العنوان" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm">
                    <textarea wire:model="notes" rows="2" placeholder="ملاحظات (اختياري)" aria-label="ملاحظات" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm"></textarea>
                    @error('cart')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <button type="submit" wire:loading.attr="disabled" class="w-full rounded-xl bg-blue-600 py-2.5 font-bold text-white hover:bg-blue-500 disabled:opacity-60">إرسال الطلب</button>
                </form>
            @endif
        @endif
    </aside>

    </div>
</div>
