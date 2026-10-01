<?php

use App\Models\TenantSetting;
use Livewire\Component;

new class extends Component
{
    /*
    |--------------------------------------------------------------------------
    | Properties
    |--------------------------------------------------------------------------
    */

    public ?int $tenantId = null;

    public bool $unifiedStock = false;

    public bool $allowNegativeStock = false;

    /*
    |--------------------------------------------------------------------------
    | Mount
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $tenantId = session('active_tenant_id');

        if (!$tenantId) {
            abort(
                403,
                'لم يتم تحديد المتجر الحالي.'
            );
        }

        $this->tenantId = (int) $tenantId;

        $this->loadSettings();
    }

    /*
    |--------------------------------------------------------------------------
    | Load Settings
    |--------------------------------------------------------------------------
    */

    private function loadSettings(): void
    {
        if (!$this->tenantId) {
            return;
        }

        $this->unifiedStock = TenantSetting::getBool(
            $this->tenantId,
            'unified_stock',
            false
        );

        $this->allowNegativeStock = TenantSetting::getBool(
            $this->tenantId,
            'allow_negative_stock',
            false
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Save
    |--------------------------------------------------------------------------
    */

    public function save(): void
    {
        if (!$this->tenantId) {
            $this->addError(
                'settings',
                'لم يتم تحديد المتجر الحالي.'
            );

            return;
        }

        TenantSetting::setBool(
            $this->tenantId,
            'unified_stock',
            $this->unifiedStock
        );

        TenantSetting::setBool(
            $this->tenantId,
            'allow_negative_stock',
            $this->allowNegativeStock
        );

        session()->flash(
            'message',
            'تم حفظ إعدادات المتجر بنجاح.'
        );
    }
};
?>

<div
    class="space-y-6"
    dir="rtl"
>

    {{-- =========================================================
         Header
    ========================================================== --}}

    <div>

        <flux:heading size="xl">
            إعدادات المتجر
        </flux:heading>

        <flux:subheading class="mt-1">
            إدارة إعدادات المخزون وطريقة عمل المبيعات في المتجر.
        </flux:subheading>

    </div>

    <flux:separator />

    {{-- =========================================================
         Success Message
    ========================================================== --}}

    @if (session('message'))

        <div
            class="rounded-xl border border-emerald-200
                   bg-emerald-50 px-4 py-3
                   text-sm font-bold text-emerald-700"
        >
            {{ session('message') }}
        </div>

    @endif

    {{-- =========================================================
         Error
    ========================================================== --}}

    @error('settings')

        <div
            class="rounded-xl border border-red-200
                   bg-red-50 px-4 py-3
                   text-sm font-bold text-red-700"
        >
            {{ $message }}
        </div>

    @enderror

    {{-- =========================================================
         Settings Card
    ========================================================== --}}

    <div class="max-w-2xl">

        <div
            class="rounded-2xl border border-zinc-200
                   bg-white p-5 shadow-sm
                   dark:border-zinc-700
                   dark:bg-zinc-900"
        >

            {{-- =================================================
                 Section Header
            ================================================== --}}

            <div>

                <div
                    class="text-base font-black
                           text-zinc-900
                           dark:text-white"
                >
                    إدارة المخزون
                </div>

                <div
                    class="mt-1 text-sm leading-6
                           text-zinc-500
                           dark:text-zinc-400"
                >
                    هذه الإعدادات تخص المتجر الحالي ويمكن تغييرها
                    حسب طريقة إدارة المخزون والمبيعات.
                </div>

            </div>

            {{-- =================================================
                 Unified Stock
            ================================================== --}}

            <div
                class="mt-6 rounded-xl
                       border border-zinc-200
                       p-4
                       dark:border-zinc-700"
            >

                <div
                    class="flex items-start
                           justify-between gap-5"
                >

                    <div class="min-w-0">

                        <div
                            class="font-black
                                   text-zinc-900
                                   dark:text-white"
                        >
                            المخزون الموحد
                        </div>

                        <p
                            class="mt-1 text-sm leading-6
                                   text-zinc-500
                                   dark:text-zinc-400"
                        >
                            عند تفعيل هذا الخيار يمكن للنظام استخدام
                            مخزون الفروع أو المخازن الأخرى عند عدم
                            كفاية الكمية في الفرع الحالي.
                        </p>

                    </div>

                    {{-- Toggle --}}

                    <label
                        class="relative inline-flex
                               shrink-0 cursor-pointer
                               items-center"
                    >

                        <input
                            type="checkbox"
                            wire:model.live="unifiedStock"
                            class="peer sr-only"
                        >

                        <span
                            class="relative block h-7 w-12
                                   rounded-full
                                   bg-zinc-300
                                   transition-colors
                                   duration-200
                                   peer-checked:bg-emerald-600
                                   dark:bg-zinc-700"
                        >

                            <span
                                class="absolute start-1 top-1
                                       h-5 w-5 rounded-full
                                       bg-white shadow-sm
                                       transition-all
                                       duration-200
                                       peer-checked:start-6"
                            ></span>

                        </span>

                    </label>

                </div>

                {{-- Status --}}

                <div
                    class="mt-4 rounded-lg px-3 py-2
                    text-xs font-bold
                    {{
                        $unifiedStock
                            ? 'bg-emerald-50 text-emerald-700'
                            : 'bg-zinc-50 text-zinc-600
                               dark:bg-zinc-800
                               dark:text-zinc-300'
                    }}"
                >

                    {{
                        $unifiedStock
                            ? 'المخزون الموحد مفعل'
                            : 'المخزون المنفصل مفعل'
                    }}

                </div>

            </div>

            {{-- =================================================
                 Negative Stock
            ================================================== --}}

            <div
                class="mt-4 rounded-xl
                       border border-zinc-200
                       p-4
                       dark:border-zinc-700"
            >

                <div
                    class="flex items-start
                           justify-between gap-5"
                >

                    <div class="min-w-0">

                        <div
                            class="font-black
                                   text-zinc-900
                                   dark:text-white"
                        >
                            السماح بالبيع بالسالب
                        </div>

                        <p
                            class="mt-1 text-sm leading-6
                                   text-zinc-500
                                   dark:text-zinc-400"
                        >
                            يسمح ببيع المنتج حتى إذا كانت الكمية
                            المتوفرة في المخزون غير كافية أو وصلت
                            إلى الصفر.
                        </p>

                    </div>

                    {{-- Toggle --}}

                    <label
                        class="relative inline-flex
                               shrink-0 cursor-pointer
                               items-center"
                    >

                        <input
                            type="checkbox"
                            wire:model.live="allowNegativeStock"
                            class="peer sr-only"
                        >

                        <span
                            class="relative block h-7 w-12
                                   rounded-full
                                   bg-zinc-300
                                   transition-colors
                                   duration-200
                                   peer-checked:bg-emerald-600
                                   dark:bg-zinc-700"
                        >

                            <span
                                class="absolute start-1 top-1
                                       h-5 w-5 rounded-full
                                       bg-white shadow-sm
                                       transition-all
                                       duration-200
                                       peer-checked:start-6"
                            ></span>

                        </span>

                    </label>

                </div>

                {{-- Status --}}

                <div
                    class="mt-4 rounded-lg px-3 py-2
                    text-xs font-bold
                    {{
                        $allowNegativeStock
                            ? 'bg-amber-50 text-amber-700'
                            : 'bg-zinc-50 text-zinc-600
                               dark:bg-zinc-800
                               dark:text-zinc-300'
                    }}"
                >

                    {{
                        $allowNegativeStock
                            ? 'البيع بالسالب مسموح'
                            : 'البيع بالسالب غير مسموح'
                    }}

                </div>

            </div>

            {{-- =================================================
                 Save Button
            ================================================== --}}

            <div class="mt-6 flex justify-start">

                <flux:button
                    type="button"
                    variant="primary"
                    wire:click="save"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >

                    <span
                        wire:loading.remove
                        wire:target="save"
                    >
                        حفظ الإعدادات
                    </span>

                    <span
                        wire:loading
                        wire:target="save"
                    >
                        جارٍ الحفظ...
                    </span>

                </flux:button>

            </div>

        </div>

    </div>

</div>
