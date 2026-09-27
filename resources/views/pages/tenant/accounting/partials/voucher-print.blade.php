@php
    $isPayment = $mode === 'payment';
@endphp

<div
    class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-950/60 p-4"
    x-data
>
    <div class="w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl">

        <div class="border-b border-slate-200 px-6 py-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-black text-slate-900">
                        {{ $isPayment ? 'سند دفع' : 'سند قبض' }}
                    </h2>

                    <p class="mt-1 text-sm text-slate-500">
                        {{ $voucher->voucher_number }}
                    </p>
                </div>

                <button
                    type="button"
                    wire:click="closePrint"
                    class="flex size-9 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                >
                    <flux:icon name="x-mark" class="size-5" />
                </button>
            </div>
        </div>

        <div id="voucher-print-area" class="p-6">

            <div class="mb-6 text-center">
                <h1 class="text-2xl font-black text-slate-900">
                    {{ $isPayment ? 'سند دفع' : 'سند قبض' }}
                </h1>

                <p class="mt-1 text-sm text-slate-500">
                    رقم السند:
                    <span class="font-bold text-slate-800">
                        {{ $voucher->voucher_number }}
                    </span>
                </p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs text-slate-400">
                        {{ $isPayment ? 'المورد' : 'العميل' }}
                    </p>

                    <p class="mt-1 font-bold text-slate-900">
                        {{ $voucher->payable?->name ?? '-' }}
                    </p>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs text-slate-400">
                        التاريخ
                    </p>

                    <p class="mt-1 font-bold text-slate-900">
                        {{ optional($voucher->payment_date)->format('Y-m-d') }}
                    </p>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs text-slate-400">
                        طريقة الدفع
                    </p>

                    <p class="mt-1 font-bold text-slate-900">
                        @switch($voucher->payment_method)
                            @case('cash')
                                نقدي
                                @break

                            @case('bank')
                                تحويل بنكي
                                @break

                            @case('card')
                                بطاقة
                                @break

                            @case('check')
                                شيك
                                @break

                            @default
                                {{ $voucher->payment_method ?: '-' }}
                        @endswitch
                    </p>
                </div>

                <div class="rounded-xl border border-slate-200 p-4">
                    <p class="text-xs text-slate-400">
                        المبلغ
                    </p>

                    <p class="mt-1 text-xl font-black {{ $isPayment ? 'text-indigo-700' : 'text-emerald-700' }}">
                        {{ number_format((float) $voucher->amount, 2) }}
                    </p>
                </div>
            </div>

            @if($voucher->notes)
                <div class="mt-4 rounded-xl border border-slate-200 p-4">
                    <p class="text-xs text-slate-400">
                        ملاحظات
                    </p>

                    <p class="mt-1 whitespace-pre-line text-sm text-slate-700">
                        {{ $voucher->notes }}
                    </p>
                </div>
            @endif

            <div class="mt-8 border-t border-slate-200 pt-4 text-center text-xs text-slate-400">
                تم إنشاء السند بواسطة
                {{ $voucher->user?->name ?? '-' }}
            </div>
        </div>

        <div class="flex justify-end gap-3 border-t border-slate-100 bg-slate-50 px-6 py-4">
            <button
                type="button"
                wire:click="closePrint"
                class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            >
                إغلاق
            </button>

            <button
                type="button"
                onclick="window.print()"
                class="rounded-xl {{ $isPayment ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-emerald-600 hover:bg-emerald-700' }} px-5 py-3 text-sm font-semibold text-white"
            >
                طباعة
            </button>
        </div>
    </div>
</div>

<style>
    @media print {
        body > * {
            display: none !important;
        }

        #voucher-print-area {
            display: block !important;
        }

        #voucher-print-area * {
            visibility: visible !important;
        }
    }
</style>
