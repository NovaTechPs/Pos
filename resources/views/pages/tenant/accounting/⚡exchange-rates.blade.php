<?php

use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Exchange Rates')] class extends Component
{
    #[Locked]
    public int $tenantId = 0;

    public string $jodToIlsRate = '';

    public string $usdToIlsRate = '';

    public string $amountToConvert = '100';

    public string $sourceCurrency = 'JOD';

    public string $ratesUpdatedAt = '';

    public function mount(): void
    {
        $tenant = $this->authorizedTenant();

        $this->tenantId = (int) $tenant->id;
        $this->jodToIlsRate = (string) TenantSetting::get($tenant->id, 'currency_rate_jod_to_ils', '');
        $this->usdToIlsRate = (string) TenantSetting::get($tenant->id, 'currency_rate_usd_to_ils', '');
        $this->ratesUpdatedAt = (string) TenantSetting::get($tenant->id, 'currency_rates_updated_at', '');
    }

    public function saveRates(): void
    {
        $tenant = $this->authorizedTenant();
        $validated = $this->validate([
            'jodToIlsRate' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'usdToIlsRate' => ['required', 'numeric', 'gt:0', 'max:100000'],
        ]);

        DB::transaction(function () use ($tenant, $validated): void {
            TenantSetting::set($tenant->id, 'currency_rate_jod_to_ils', number_format((float) $validated['jodToIlsRate'], 4, '.', ''));
            TenantSetting::set($tenant->id, 'currency_rate_usd_to_ils', number_format((float) $validated['usdToIlsRate'], 4, '.', ''));
        });

        session()->flash('message', 'تم حفظ أسعار الصرف / Exchange rates saved.');
    }

    public function updateRatesFromApi(): void
    {
        $tenant = $this->authorizedTenant();
        $appId = config('services.open_exchange_rates.app_id');

        if (! is_string($appId) || trim($appId) === '') {
            session()->flash('error', 'أضف OPEN_EXCHANGE_RATES_APP_ID إلى إعدادات الخادم أولًا / Configure the API key on the server first.');

            return;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->get(rtrim((string) config('services.open_exchange_rates.base_url'), '/').'/latest.json', [
                    'app_id' => $appId,
                    'symbols' => 'ILS,JOD',
                ])
                ->throw()
                ->json();
        } catch (\Throwable) {
            session()->flash('error', 'تعذر جلب أسعار الصرف الآن. بقيت الأسعار المحفوظة كما هي / Could not fetch rates. Saved rates are unchanged.');

            return;
        }

        $usdToIls = data_get($response, 'rates.ILS');
        $usdToJod = data_get($response, 'rates.JOD');
        $timestamp = data_get($response, 'timestamp');

        if (! is_numeric($usdToIls) || (float) $usdToIls <= 0 || ! is_numeric($usdToJod) || (float) $usdToJod <= 0 || ! is_numeric($timestamp)) {
            session()->flash('error', 'استجابة مزود الأسعار غير صالحة. بقيت الأسعار المحفوظة كما هي / Invalid API response. Saved rates are unchanged.');

            return;
        }

        $this->usdToIlsRate = number_format((float) $usdToIls, 4, '.', '');
        $this->jodToIlsRate = number_format((float) $usdToIls / (float) $usdToJod, 4, '.', '');
        $this->ratesUpdatedAt = now()->setTimestamp((int) $timestamp)->toIso8601String();

        DB::transaction(function () use ($tenant): void {
            TenantSetting::set($tenant->id, 'currency_rate_jod_to_ils', $this->jodToIlsRate);
            TenantSetting::set($tenant->id, 'currency_rate_usd_to_ils', $this->usdToIlsRate);
            TenantSetting::set($tenant->id, 'currency_rates_updated_at', $this->ratesUpdatedAt);
            TenantSetting::set($tenant->id, 'currency_rates_provider', 'open_exchange_rates');
        });

        session()->flash('message', 'تم تحديث الأسعار من Open Exchange Rates / Rates updated from Open Exchange Rates.');
    }

    private function authorizedTenant(): Tenant
    {
        $user = auth()->user();
        abort_unless($user?->hasPermission('currency_rates.manage'), 403);

        return Tenant::query()
            ->whereKey($this->tenantId ?: session('active_tenant_id'))
            ->where(function ($query) use ($user): void {
                $query->where('owner_id', $user->id)
                    ->orWhere('id', $user->tenant_id);
            })
            ->firstOrFail();
    }
};
?>

<div class="mx-auto max-w-6xl space-y-6" dir="rtl">
    <header class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-start gap-4">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                <flux:icon name="currency-dollar" class="size-6" />
            </span>
            <div>
                <flux:heading size="xl">أسعار الصرف / Exchange Rates</flux:heading>
                <flux:subheading class="mt-1">احفظ سعر التحويل الذي تعتمده، واستخدم المحوّل لحساب المبلغ بالشيكل بسرعة.</flux:subheading>
            </div>
        </div>
        @if (auth()->user()?->hasPermission('store.settings.manage'))
            <a href="{{ route('store.settings') }}" wire:navigate class="text-sm font-bold text-indigo-700 underline dark:text-indigo-300">إعدادات المتجر / Store settings</a>
        @endif
    </header>

    @if (session('message'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
            {{ session('message') }}
        </div>
    @endif

    @if (session('error'))
        <div role="alert" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-bold text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
            {{ session('error') }}
        </div>
    @endif

    <div class="grid items-start gap-6 lg:grid-cols-5">
        <form wire:submit="saveRates" class="space-y-5 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 lg:col-span-3">
            <div>
                <h2 class="text-lg font-black text-zinc-900 dark:text-white">أسعار التحويل إلى الشيكل / Rates to ILS</h2>
                <p class="mt-1 text-sm text-zinc-500">حدّث السعر من المصدر أو أدخل السعر المعتمد في المحل يدويًا. آخر سعر محفوظ يعمل دون اتصال.</p>
            </div>

            <label class="block space-y-2 text-sm font-bold text-zinc-700 dark:text-zinc-200">
                <span class="flex items-center justify-between gap-3"><span>1 دينار أردني (JOD) يساوي</span><span class="text-xs text-zinc-500">شيكل (ILS)</span></span>
                <div class="relative">
                    <input type="number" min="0.0001" max="100000" step="0.0001" inputmode="decimal" dir="ltr" wire:model.live.debounce.200ms="jodToIlsRate" class="w-full rounded-xl border-zinc-300 py-3 pe-16 text-left font-mono text-lg font-black dark:border-zinc-700 dark:bg-zinc-800" placeholder="مثال: 5.10">
                    <span class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-sm font-black text-zinc-400">₪ / JD</span>
                </div>
                @error('jodToIlsRate') <span class="block text-xs font-semibold text-rose-600">أدخل سعرًا أكبر من صفر / Enter a rate greater than zero.</span> @enderror
            </label>

            <label class="block space-y-2 text-sm font-bold text-zinc-700 dark:text-zinc-200">
                <span class="flex items-center justify-between gap-3"><span>1 دولار أمريكي (USD) يساوي</span><span class="text-xs text-zinc-500">شيكل (ILS)</span></span>
                <div class="relative">
                    <input type="number" min="0.0001" max="100000" step="0.0001" inputmode="decimal" dir="ltr" wire:model.live.debounce.200ms="usdToIlsRate" class="w-full rounded-xl border-zinc-300 py-3 pe-16 text-left font-mono text-lg font-black dark:border-zinc-700 dark:bg-zinc-800" placeholder="مثال: 3.70">
                    <span class="pointer-events-none absolute inset-y-0 end-4 flex items-center text-sm font-black text-zinc-400">₪ / USD</span>
                </div>
                @error('usdToIlsRate') <span class="block text-xs font-semibold text-rose-600">أدخل سعرًا أكبر من صفر / Enter a rate greater than zero.</span> @enderror
            </label>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
                <p class="max-w-md text-xs leading-5 text-zinc-500">هذه الأسعار مرجعية للمحوّل. حفظها لا يغيّر أسعار المنتجات أو أرصدة الفواتير السابقة.</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="updateRatesFromApi" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-xl border border-indigo-300 px-4 py-3 text-sm font-black text-indigo-800 hover:bg-indigo-50 disabled:opacity-60 dark:border-indigo-700 dark:text-indigo-200 dark:hover:bg-indigo-950">
                        <flux:icon name="arrow-path" class="size-4" />
                        <span wire:loading.remove wire:target="updateRatesFromApi">تحديث من الإنترنت / Update online</span>
                        <span wire:loading wire:target="updateRatesFromApi">جارٍ التحديث… / Updating…</span>
                    </button>
                    <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 disabled:opacity-60">
                    <flux:icon name="check" class="size-4" />
                    <span wire:loading.remove>حفظ الأسعار / Save rates</span>
                    <span wire:loading>جارٍ الحفظ… / Saving…</span>
                    </button>
                </div>
            </div>
            <p class="text-xs text-zinc-500">@if ($ratesUpdatedAt) آخر تحديث من المصدر: {{ \Illuminate\Support\Carbon::parse($ratesUpdatedAt)->format('Y-m-d H:i') }} @else الأسعار لم تُحدّث من المصدر بعد @endif / Last provider update: {{ $ratesUpdatedAt ?: 'Never' }}</p>
        </form>

        <section class="overflow-hidden rounded-2xl border border-indigo-200 bg-indigo-50 shadow-sm dark:border-indigo-900 dark:bg-indigo-950/40 lg:col-span-2">
            <div class="border-b border-indigo-100 p-6 dark:border-indigo-900">
                <span class="text-xs font-black uppercase tracking-wider text-indigo-600 dark:text-indigo-300">محول سريع / Quick converter</span>
                <h2 class="mt-2 text-lg font-black text-zinc-900 dark:text-white">كم يساوي المبلغ بالشيكل؟</h2>
            </div>
            <div class="space-y-4 p-6">
                <label class="block space-y-2 text-sm font-bold text-zinc-700 dark:text-zinc-200">
                    المبلغ / Amount
                    <input type="number" min="0" step="0.01" inputmode="decimal" dir="ltr" wire:model.live.debounce.150ms="amountToConvert" class="w-full rounded-xl border-indigo-200 bg-white py-3 text-left font-mono text-xl font-black dark:border-indigo-900 dark:bg-zinc-900">
                </label>
                <label class="block space-y-2 text-sm font-bold text-zinc-700 dark:text-zinc-200">
                    العملة / Currency
                    <select wire:model.live="sourceCurrency" class="w-full rounded-xl border-indigo-200 bg-white py-3 text-sm font-bold dark:border-indigo-900 dark:bg-zinc-900">
                        <option value="JOD">دينار أردني (JOD)</option>
                        <option value="USD">دولار أمريكي (USD)</option>
                    </select>
                </label>
                @php
                    $conversionRate = (float) ($sourceCurrency === 'JOD' ? $jodToIlsRate : $usdToIlsRate);
                    $convertedAmount = is_numeric($amountToConvert) && (float) $amountToConvert >= 0 && $conversionRate > 0
                        ? (float) $amountToConvert * $conversionRate
                        : null;
                @endphp
                <div class="rounded-2xl bg-white p-5 dark:bg-zinc-900">
                    <p class="text-xs font-bold text-zinc-500">المبلغ بالشيكل / Amount in ILS</p>
                    @if ($convertedAmount !== null)
                        <p class="mt-2 font-mono text-3xl font-black text-indigo-700 dark:text-indigo-300" dir="ltr">₪ {{ number_format($convertedAmount, 2) }}</p>
                        <p class="mt-2 text-xs text-zinc-500">على سعر {{ number_format($conversionRate, 4) }} شيكل لكل {{ $sourceCurrency }}.</p>
                    @else
                        <p class="mt-2 text-sm font-bold text-amber-700 dark:text-amber-300">احفظ سعر هذه العملة أولًا لعرض التحويل.</p>
                    @endif
                </div>
            </div>
        </section>
    </div>
</div>
