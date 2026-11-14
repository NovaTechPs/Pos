<?php

use App\Services\OnlineShop;
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

    public string $address = '';

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
            'address' => ['nullable', 'string', 'max:500'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ], [], ['name' => 'الاسم', 'phone' => 'رقم الهاتف', 'address' => 'العنوان', 'password' => 'كلمة المرور']);

        $key = 'shop-register:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('phone', 'محاولات كثيرة. حاول لاحقاً.');

            return;
        }
        RateLimiter::hit($key, 3600);

        $account = app(OnlineShop::class)->register($branch, $this->name, $this->phone, $this->password, $this->address ?: null);
        Auth::guard('customer')->login($account, true);
        session()->regenerate();
        $this->redirectRoute('shop.home', ['shop' => $this->shop], navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->layout('layouts::shop', ['branch' => app(OnlineShop::class)->branch($this->shop), 'title' => 'إنشاء حساب']);
    }
};
?>

<div class="mx-auto max-w-sm rounded-2xl border border-slate-200 bg-white p-6" data-shop-register>
    <h1 class="mb-4 text-xl font-black">إنشاء حساب</h1>
    <form wire:submit="register" class="space-y-3">
        <input type="text" wire:model="name" placeholder="الاسم" aria-label="الاسم" autocomplete="name" class="w-full rounded-lg border border-slate-200 px-3 py-2.5">
        @error('name')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
        <input type="tel" wire:model="phone" placeholder="رقم الهاتف" aria-label="رقم الهاتف" autocomplete="tel" dir="ltr" class="w-full rounded-lg border border-slate-200 px-3 py-2.5">
        @error('phone')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
        <input type="text" wire:model="address" placeholder="العنوان (اختياري)" aria-label="العنوان" autocomplete="street-address" class="w-full rounded-lg border border-slate-200 px-3 py-2.5">
        <input type="password" wire:model="password" placeholder="كلمة المرور (8 أحرف على الأقل)" aria-label="كلمة المرور" autocomplete="new-password" class="w-full rounded-lg border border-slate-200 px-3 py-2.5">
        @error('password')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
        <input type="password" wire:model="password_confirmation" placeholder="تأكيد كلمة المرور" aria-label="تأكيد كلمة المرور" autocomplete="new-password" class="w-full rounded-lg border border-slate-200 px-3 py-2.5">
        <button type="submit" wire:loading.attr="disabled" class="w-full rounded-xl bg-teal-600 py-2.5 font-bold text-white hover:bg-teal-500">إنشاء الحساب</button>
    </form>
    <p class="mt-4 text-center text-sm text-slate-600">لديك حساب؟ <a href="{{ route('shop.login', ['shop' => $shop]) }}" wire:navigate class="font-bold text-teal-700">سجّل الدخول</a></p>
</div>
