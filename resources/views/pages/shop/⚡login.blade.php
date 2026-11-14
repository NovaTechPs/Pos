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
        $key = 'shop-login:'.$branch->tenant_id.':'.$phone.'|'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('phone', 'محاولات كثيرة. حاول بعد '.RateLimiter::availableIn($key).' ثانية.');

            return;
        }

        $credentials = ['tenant_id' => $branch->tenant_id, 'phone' => $phone, 'password' => $this->password, 'is_active' => true];
        if (! Auth::guard('customer')->attempt($credentials, $this->remember)) {
            RateLimiter::hit($key, 60);
            $this->addError('phone', 'رقم الهاتف أو كلمة المرور غير صحيحة.');

            return;
        }

        RateLimiter::clear($key);
        session()->regenerate();
        Auth::guard('customer')->user()->forceFill(['last_login_at' => now()])->save();
        $this->redirectRoute('shop.home', ['shop' => $this->shop], navigate: true);
    }

    public function render(): mixed
    {
        return $this->view()->layout('layouts::shop', ['branch' => app(OnlineShop::class)->branch($this->shop), 'title' => 'تسجيل الدخول']);
    }
};
?>

<div class="mx-auto max-w-sm rounded-2xl border border-slate-200 bg-white p-6" data-shop-login>
    <h1 class="mb-4 text-xl font-black">تسجيل الدخول</h1>
    <form wire:submit="login" class="space-y-3">
        <input type="tel" wire:model="phone" placeholder="رقم الهاتف" aria-label="رقم الهاتف" autocomplete="tel" dir="ltr" class="w-full rounded-lg border border-slate-200 px-3 py-2.5">
        @error('phone')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
        <input type="password" wire:model="password" placeholder="كلمة المرور" aria-label="كلمة المرور" autocomplete="current-password" class="w-full rounded-lg border border-slate-200 px-3 py-2.5">
        @error('password')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="remember"> تذكرني</label>
        <button type="submit" wire:loading.attr="disabled" class="w-full rounded-xl bg-teal-600 py-2.5 font-bold text-white hover:bg-teal-500">دخول</button>
    </form>
    <p class="mt-4 text-center text-sm text-slate-600">ليس لديك حساب؟ <a href="{{ route('shop.register', ['shop' => $shop]) }}" wire:navigate class="font-bold text-teal-700">أنشئ حساباً</a></p>
    <p class="mt-2 text-center text-xs text-slate-500">نسيت كلمة المرور؟ تواصل مع المتجر لإعادة تعيينها.</p>
</div>
