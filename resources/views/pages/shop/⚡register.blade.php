<?php

use App\Services\OnlineShop;
use App\Services\StoreCustomerRegistration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $shop = '';

    public string $name = '';

    public string $phone = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $shop): void
    {
        $this->shop = $shop;
        app(OnlineShop::class)->branch($shop);
    }

    public function register(): void
    {
        $branch = app(OnlineShop::class)->branch($this->shop);
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[\d\s+\-()٠-٩]{7,30}$/u'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [], ['name' => 'الاسم', 'phone' => 'رقم الهاتف', 'address' => 'العنوان', 'password' => 'كلمة المرور']);

        $key = 'shop-register:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('phone', 'محاولات كثيرة. حاول لاحقاً.');

            return;
        }
        RateLimiter::hit($key, 3600);

        $account = app(StoreCustomerRegistration::class)->register($branch, $this->name, $this->phone, $this->password);
        Auth::guard('customer')->logout();
        Auth::guard('store_customer')->login($account, true);
        session()->regenerate();
        $this->redirectRoute('shop.home', ['shop' => $this->shop], navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->layout('layouts::shop', ['branch' => app(OnlineShop::class)->branch($this->shop), 'title' => 'إنشاء حساب']);
    }
};
?>

@php $shopBrand = app(\App\Services\OnlineShop::class)->branding(app(\App\Services\OnlineShop::class)->branch($shop)); @endphp
<div class="shop-auth-stage" data-shop-register>
    <div class="shop-auth-card">
        <a class="shop-auth-close" href="{{ route('shop.home', ['shop' => $shop]) }}" wire:navigate aria-label="العودة للمتجر"><x-shop-icon name="close" /></a>
        <div class="shop-auth-form">
            @if($shopBrand['logo'])<img class="shop-auth-logo" src="{{ $shopBrand['logo'] }}" alt="شعار {{ $shopBrand['name'] }}">@else<div class="shop-auth-logo shop-brand-mark"><x-shop-icon name="store" /></div>@endif
            <div class="shop-auth-brand">{{ $shopBrand['name'] }}</div><h1>إنشاء حساب جديد</h1><p class="shop-auth-subtitle">حساب خاص بهذا المتجر لحفظ عناوينك ومتابعة طلباتك</p>
            <form wire:submit="register">
                <label class="shop-auth-input"><x-shop-icon name="user" /><input wire:model="name" placeholder="الاسم الكامل" aria-label="الاسم" autocomplete="name"></label>
                @error('name')<p class="shop-auth-error">{{ $message }}</p>@enderror
                <label class="shop-auth-input"><x-shop-icon name="phone" /><input type="tel" wire:model="phone" placeholder="رقم الهاتف" aria-label="رقم الهاتف" autocomplete="tel"></label>
                @error('phone')<p class="shop-auth-error">{{ $message }}</p>@enderror
                <label class="shop-auth-input"><x-shop-icon name="lock" /><input type="password" wire:model="password" placeholder="كلمة المرور (8 أحرف على الأقل)" aria-label="كلمة المرور" autocomplete="new-password"></label>
                @error('password')<p class="shop-auth-error">{{ $message }}</p>@enderror
                <label class="shop-auth-input"><x-shop-icon name="lock" /><input type="password" wire:model="password_confirmation" placeholder="تأكيد كلمة المرور" aria-label="تأكيد كلمة المرور" autocomplete="new-password"></label>
                <button type="submit" wire:loading.attr="disabled" class="shop-button">إنشاء الحساب <x-shop-icon name="arrow" /></button>
            </form>
            <p class="shop-auth-links">لديك حساب؟ <a href="{{ route('shop.login', ['shop' => $shop]) }}" wire:navigate>تسجيل الدخول</a></p>
        </div>
        <aside class="shop-auth-art" aria-hidden="true">@if($shopBrand['banner'])<img src="{{ $shopBrand['banner'] }}" alt="">@else<x-shop-icon name="store" /><strong>{{ $shopBrand['tagline'] }}</strong>@endif</aside>
    </div>
</div>
