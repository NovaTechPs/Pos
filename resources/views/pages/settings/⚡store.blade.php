<?php

use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

new class extends Component
{
    public ?int $tenantId = null;

    public string $storeName = '';

    public string $storePhone = '';

    public string $storeAddress = '';

    public string $currency = 'ILS';

    public string $receiptFooter = 'شكراً لتعاملكم معنا';

    public string $uiTheme = 'blue';

    public bool $unifiedStock = false;

    public bool $allowNegativeStock = false;

    public bool $mergeSimilarProducts = false;

    public function mount(): void
    {
        $user = Auth::user();
        abort_unless($user?->hasPermission('store.settings.manage'), 403);

        $tenant = Tenant::query()
            ->whereKey(session('active_tenant_id'))
            ->where(function ($query) use ($user): void {
                $query->where('owner_id', $user->id)
                    ->orWhere('id', $user->tenant_id);
            })
            ->firstOrFail();

        $this->tenantId = (int) $tenant->id;
        $this->storeName = (string) $tenant->name;
        $this->storePhone = (string) ($tenant->phone ?? '');
        $this->storeAddress = (string) TenantSetting::get($tenant->id, 'store_address', '');
        $this->currency = (string) TenantSetting::get($tenant->id, 'currency', 'ILS');
        $this->receiptFooter = (string) TenantSetting::get($tenant->id, 'receipt_footer', 'شكراً لتعاملكم معنا');
        $this->uiTheme = (string) TenantSetting::get($tenant->id, 'ui_theme', 'blue');
        $this->unifiedStock = TenantSetting::getBool($tenant->id, 'unified_stock');
        $this->allowNegativeStock = TenantSetting::getBool($tenant->id, 'allow_negative_stock');
        $this->mergeSimilarProducts = TenantSetting::getBool($tenant->id, 'merge_similar_products');
    }

    public function save(): void
    {
        $validated = $this->validate([
            'storeName' => ['required', 'string', 'max:255'],
            'storePhone' => ['nullable', 'string', 'max:50'],
            'storeAddress' => ['nullable', 'string', 'max:500'],
            'currency' => ['required', 'in:ILS,USD,JOD'],
            'receiptFooter' => ['nullable', 'string', 'max:255'],
            'uiTheme' => ['required', 'in:blue,orange,green'],
            'unifiedStock' => ['boolean'],
            'allowNegativeStock' => ['boolean'],
            'mergeSimilarProducts' => ['boolean'],
        ]);

        DB::transaction(function () use ($validated): void {
            Tenant::query()->whereKey($this->tenantId)->update([
                'name' => $validated['storeName'],
                'phone' => $validated['storePhone'] ?: null,
            ]);

            TenantSetting::set($this->tenantId, 'store_address', $validated['storeAddress'] ?: null);
            TenantSetting::set($this->tenantId, 'currency', $validated['currency']);
            TenantSetting::set($this->tenantId, 'receipt_footer', $validated['receiptFooter'] ?: null);
            TenantSetting::set($this->tenantId, 'ui_theme', $validated['uiTheme']);
            TenantSetting::setBool($this->tenantId, 'unified_stock', $validated['unifiedStock']);
            TenantSetting::setBool($this->tenantId, 'allow_negative_stock', $validated['allowNegativeStock']);
            TenantSetting::setBool($this->tenantId, 'merge_similar_products', $validated['mergeSimilarProducts']);
        });

        $this->dispatch('store-theme-updated', theme: $this->uiTheme);
        session()->flash('message', 'تم حفظ إعدادات المتجر بنجاح / Store settings saved.');
    }
};
?>

<div class="space-y-6" dir="rtl" x-on:store-theme-updated.window="document.documentElement.dataset.theme = $event.detail.theme">
    <header class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-black uppercase tracking-wide text-indigo-600">إدارة المتجر / Store management</p>
                <flux:heading size="xl" class="mt-1">إعدادات المتجر / Store settings</flux:heading>
                <flux:subheading class="mt-1">إعدادات مرتبة لهوية المحل، البيع والمخزون، الإيصال والمظهر.</flux:subheading>
            </div>
            <div class="rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-2 text-sm font-bold text-indigo-800 dark:border-indigo-900 dark:bg-indigo-950 dark:text-indigo-200">
                {{ $storeName ?: 'المتجر الحالي' }}
            </div>
        </div>
        <nav aria-label="أقسام إعدادات المتجر" class="mt-5 flex flex-wrap gap-2">
            <a href="#store-profile" class="rounded-full bg-zinc-100 px-3 py-2 text-xs font-bold text-zinc-700 hover:bg-indigo-100 dark:bg-zinc-800 dark:text-zinc-200">بيانات المتجر / Store profile</a>
            <a href="#sales-inventory" class="rounded-full bg-zinc-100 px-3 py-2 text-xs font-bold text-zinc-700 hover:bg-indigo-100 dark:bg-zinc-800 dark:text-zinc-200">البيع والمخزون / Sales & inventory</a>
            <a href="#receipts" class="rounded-full bg-zinc-100 px-3 py-2 text-xs font-bold text-zinc-700 hover:bg-indigo-100 dark:bg-zinc-800 dark:text-zinc-200">الفواتير والإيصال / Receipts</a>
            <a href="#store-appearance" class="rounded-full bg-zinc-100 px-3 py-2 text-xs font-bold text-zinc-700 hover:bg-indigo-100 dark:bg-zinc-800 dark:text-zinc-200">مظهر المتجر / Appearance</a>
        </nav>
    </header>

    @if (session('message'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">{{ session('message') }}</div>
    @endif

    <form wire:submit="save" class="space-y-5">
        <section id="store-profile" class="scroll-mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
            <div class="mb-5 flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-sm font-black text-blue-700 dark:bg-blue-950 dark:text-blue-200">01</span>
                <div><h2 class="font-black text-zinc-900 dark:text-white">بيانات المتجر / Store profile</h2><p class="mt-1 text-sm text-zinc-500">معلومات المحل التي تظهر على الفواتير والإيصالات.</p></div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm font-bold">اسم المتجر / Store name
                    <input wire:model="storeName" autocomplete="organization" class="w-full rounded-xl border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('storeName') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </label>
                <label class="space-y-1 text-sm font-bold">رقم الهاتف / Phone
                    <input wire:model="storePhone" type="tel" dir="ltr" autocomplete="tel" class="w-full rounded-xl border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('storePhone') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </label>
                <label class="space-y-1 text-sm font-bold md:col-span-2">عنوان المتجر / Address
                    <input wire:model="storeAddress" autocomplete="street-address" class="w-full rounded-xl border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('storeAddress') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </label>
            </div>
        </section>

        <section id="sales-inventory" class="scroll-mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
            <div class="mb-5 flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-sm font-black text-amber-700 dark:bg-amber-950 dark:text-amber-200">02</span>
                <div><h2 class="font-black text-zinc-900 dark:text-white">البيع والمخزون / Sales & inventory</h2><p class="mt-1 text-sm text-zinc-500">حدد كيف يتعامل النظام مع أرصدة الفروع وأصناف سلة البيع.</p></div>
            </div>
            <div class="space-y-3">
                <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <span><strong class="block">مشاركة المخزون بين الفروع / Shared branch stock</strong><span class="mt-1 block text-sm leading-6 text-zinc-500">يتيح استخدام المخزون المتاح في فروع المتجر وفق آلية البيع المعتمدة.</span></span>
                    <input type="checkbox" wire:model="unifiedStock" class="mt-1 size-5 rounded border-zinc-300 text-indigo-600">
                </label>
                <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <span><strong class="block">السماح بالمخزون السالب / Allow negative stock</strong><span class="mt-1 block text-sm leading-6 text-zinc-500">يسمح بإتمام البيع عندما تصبح الكمية المتاحة أقل من الكمية المباعة.</span></span>
                    <input type="checkbox" wire:model="allowNegativeStock" class="mt-1 size-5 rounded border-zinc-300 text-amber-600">
                </label>
                <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <span><strong class="block">دمج الصنف المكرر في السلة / Merge duplicate items</strong><span class="mt-1 block text-sm leading-6 text-zinc-500">يجمع الصنف نفسه في سطر واحد داخل سلة نقطة البيع.</span></span>
                    <input type="checkbox" wire:model="mergeSimilarProducts" class="mt-1 size-5 rounded border-zinc-300 text-indigo-600">
                </label>
            </div>
        </section>

        <section id="receipts" class="scroll-mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
            <div class="mb-5 flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-sm font-black text-emerald-700 dark:bg-emerald-950 dark:text-emerald-200">03</span>
                <div><h2 class="font-black text-zinc-900 dark:text-white">الفواتير والإيصال / Invoices & receipts</h2><p class="mt-1 text-sm text-zinc-500">اختر العملة الأساسية المعروضة واكتب رسالة الإيصال.</p></div>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="space-y-1 text-sm font-bold">عملة العرض الأساسية / Display currency
                    <select wire:model="currency" class="w-full rounded-xl border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <option value="ILS">شيكل (₪) — ILS</option>
                        <option value="USD">دولار ($) — USD</option>
                        <option value="JOD">دينار أردني (د.أ) — JOD</option>
                    </select>
                    @error('currency') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </label>
                <label class="space-y-1 text-sm font-bold">رسالة أسفل الإيصال / Receipt footer
                    <input wire:model="receiptFooter" maxlength="255" class="w-full rounded-xl border-zinc-300 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    @error('receiptFooter') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </label>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                <p>تغيير عملة العرض لا يعيد تسعير المنتجات أو يعدّل الفواتير السابقة.</p>
                @if (Auth::user()?->hasPermission('currency_rates.manage'))
                    <a href="{{ route('tenant.currency-rates') }}" wire:navigate class="font-black underline">أسعار الصرف والمحوّل / Exchange rates</a>
                @endif
            </div>
        </section>

        <section id="store-appearance" class="scroll-mt-6 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
            <div class="mb-5 flex items-start gap-3">
                <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-sm font-black text-violet-700 dark:bg-violet-950 dark:text-violet-200">04</span>
                <div><h2 class="font-black text-zinc-900 dark:text-white">مظهر المتجر / Store appearance</h2><p class="mt-1 text-sm text-zinc-500">يُطبّق اللون المختار على جميع مستخدمي وفروع هذا المتجر.</p></div>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ([
                    'blue' => ['label' => 'أزرق / Blue', 'color' => 'bg-blue-600'],
                    'orange' => ['label' => 'برتقالي / Orange', 'color' => 'bg-orange-600'],
                    'green' => ['label' => 'أخضر / Green', 'color' => 'bg-emerald-600'],
                ] as $theme => $option)
                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition {{ $uiTheme === $theme ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-100 dark:bg-zinc-800' : 'border-zinc-200 dark:border-zinc-700' }}">
                        <input type="radio" wire:model="uiTheme" value="{{ $theme }}" class="size-4 border-zinc-300 text-indigo-600">
                        <span class="size-6 rounded-full {{ $option['color'] }}"></span>
                        <span class="text-sm font-bold">{{ $option['label'] }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        <div class="sticky bottom-3 flex justify-end rounded-2xl border border-zinc-200 bg-white/95 p-3 shadow-lg backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95">
            <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60">
                <span wire:loading.remove>حفظ إعدادات المتجر / Save store settings</span>
                <span wire:loading>جارٍ الحفظ… / Saving…</span>
            </button>
        </div>
    </form>
</div>
