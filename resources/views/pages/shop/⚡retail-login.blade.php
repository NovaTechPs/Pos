<?php

use App\Models\CustomerAccount;
use App\Services\OnlineShop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $shop = '';

    public string $phone = '';

    public string $password = '';

    public bool $remember = true;

    public function mount(string $shop): void
    {
        $this->shop = $shop;
        app(OnlineShop::class)->branch($shop);
    }

    public function login(): void
    {
        $branch = app(OnlineShop::class)->branch($this->shop);
        $this->validate(['phone' => ['required', 'string', 'max:30'], 'password' => ['required', 'string', 'max:255']], [], ['phone' => 'رقم الهاتف', 'password' => 'كلمة المرور']);
        $phone = CustomerAccount::normalizePhone($this->phone);
        $key = 'store-customer-login:'.$branch->tenant_id.':'.$phone.'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('phone', 'محاولات كثيرة. حاول بعد '.RateLimiter::availableIn($key).' ثانية.');

            return;
        }

        $credentials = ['tenant_id' => $branch->tenant_id, 'phone' => $phone, 'password' => $this->password, 'is_active' => true];
        if (! Auth::guard('store_customer')->attempt($credentials, $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->addError('phone', 'رقم الهاتف أو كلمة المرور غير صحيحة.');

            return;
        }

        RateLimiter::clear($key);
        Auth::guard('customer')->logout();
        session()->regenerate();
        Auth::guard('store_customer')->user()->forceFill(['last_login_at' => now()])->save();
        $this->redirectRoute('shop.home', ['shop' => $this->shop], navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->layout('layouts::shop', ['branch' => app(OnlineShop::class)->branch($this->shop), 'title' => 'تسجيل الدخول']);
    }
};
?>

@php $shopBrand = app(\App\Services\OnlineShop::class)->branding(app(\App\Services\OnlineShop::class)->branch($shop)); @endphp
<div class="shop-auth-stage" data-shop-login>
    <div class="shop-auth-card">
        <a class="shop-auth-close" href="{{ route('shop.home', ['shop' => $shop]) }}" wire:navigate aria-label="العودة للمتجر"><x-shop-icon name="close" /></a>
        <div class="shop-auth-form">
            @if($shopBrand['logo'])<img class="shop-auth-logo" src="{{ $shopBrand['logo'] }}" alt="شعار {{ $shopBrand['name'] }}">@else<div class="shop-auth-logo shop-brand-mark"><x-shop-icon name="store" /></div>@endif
            <div class="shop-auth-brand">{{ $shopBrand['name'] }}</div>
            <h1>تسجيل الدخول</h1><p class="shop-auth-subtitle">ادخل إلى حسابك لمتابعة طلباتك</p>
            <form wire:submit="login">
                <label class="shop-auth-input"><x-shop-icon name="phone" /><input type="tel" wire:model="phone" placeholder="رقم الهاتف" aria-label="رقم الهاتف" autocomplete="tel"></label>
                @error('phone')<p class="shop-auth-error">{{ $message }}</p>@enderror
                <div class="shop-auth-input" x-data="{ visible: false }"><x-shop-icon name="lock" /><input :type="visible ? 'text' : 'password'" type="password" wire:model="password" placeholder="كلمة المرور" aria-label="كلمة المرور" autocomplete="current-password"><button type="button" @click="visible = !visible" :aria-label="visible ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'"><x-shop-icon name="eye" /></button></div>
                @error('password')<p class="shop-auth-error">{{ $message }}</p>@enderror
                <label class="shop-auth-remember"><input type="checkbox" wire:model="remember">تذكرني</label>
                <button type="submit" wire:loading.attr="disabled" class="shop-button"><x-shop-icon name="user" /><span wire:loading.remove wire:target="login">تسجيل الدخول</span><span wire:loading wire:target="login">جارٍ تسجيل الدخول…</span></button>
            </form>
            <p class="shop-auth-links">ليس لديك حساب؟ <a href="{{ route('shop.register', ['shop' => $shop]) }}" wire:navigate>إنشاء حساب جديد</a></p>
            <p class="shop-auth-commercial"><a href="{{ route('shop.wholesale.login', ['shop' => $shop]) }}" wire:navigate>دخول زبائن الجملة والحسابات التجارية</a></p>
        </div>
        <aside class="shop-auth-art" aria-hidden="true">@if($shopBrand['banner'])<img src="{{ $shopBrand['banner'] }}" alt="">@else<x-shop-icon name="store" /><strong>{{ $shopBrand['tagline'] }}</strong>@endif</aside>
    </div>
</div>
