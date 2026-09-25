@if ($show && $voucher)
    @php
        $isPayment = $mode === 'payment';

        $party = $voucher->payable;

        $method = match ($voucher->payment_method) {
            'cash' => 'نقدي',
            'bank' => 'تحويل بنكي',
            'card' => 'بطاقة',
            'check' => 'شيك',
            default => $voucher->payment_method ?: '-',
        };
    @endphp

    <div
        x-data
        x-show="true"
        class="fixed inset-0 z-[100] overflow-y-auto bg-slate-950/60 p-4"
    >
        <div class="flex min-h-full items-center justify-center">
            <div class="w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl">

                {{-- Toolbar --}}
                <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-5 py-3 print:hidden">
                    <div>
                        <div class="text-sm font-black text-slate-800">
                            معاينة السند
                        </div>

                        <div class="text-xs text-slate-400">
                            {{ $voucher->voucher_number }}
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            onclick="window.print()"
                            class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-bold text-white hover:bg-slate-800"
                        >
                            <flux:icon name="printer" class="size-4" />
                            طباعة
                        </button>

                        <button
                            type="button"
                            wire:click="$set('showPrintModal', false)"
                            class="flex size-9 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-200"
                        >
                            <flux:icon name="x-mark" class="size-5" />
                        </button>
                    </div>
                </div>

                {{-- Printable voucher --}}
                <div id="voucher-print-area" class="p-8 sm:p-12">
                    <div class="text-center">
                        <div class="text-xl font-black text-slate-900">
                            الشامل لايت للمحاسبة
                        </div>

                        <div class="mt-1 text-sm font-bold text-slate-500">
                            {{ $isPayment ? 'سند دفع' : 'سند قبض' }}
                        </div>
                    </div>

                    <div class="my-8 flex items-center justify-between border-y border-slate-200 py-4">
                        <div>
                            <div class="text-[11px] font-semibold text-slate-400">
                                رقم السند
                            </div>

                            <div class="mt-1 font-mono text-base font-black text-slate-900" dir="ltr">
                                {{ $voucher->voucher_number }}
                            </div>
                        </div>

                        <div class="text-left">
                            <div class="text-[11px] font-semibold text-slate-400">
                                التاريخ
                            </div>

                            <div class="mt-1 text-sm font-bold text-slate-800">
                                {{ optional($voucher->payment_date)->format('Y-m-d') ?: '-' }}
                            </div>
                        </div>
                    </div>

                    <div class="space-y-5">
                        <div class="flex items-center justify-between gap-6">
                            <span class="text-sm font-semibold text-slate-500">
                                {{ $isPayment ? 'المورد' : 'العميل' }}
                            </span>

                            <span class="text-sm font-black text-slate-900">
                                {{ $party?->name ?? '-' }}
                            </span>
                        </div>

                        @if ($party?->phone)
                            <div class="flex items-center justify-between gap-6">
                                <span class="text-sm font-semibold text-slate-500">
                                    الهاتف
                                </span>

                                <span class="text-sm font-bold text-slate-800" dir="ltr">
                                    {{ $party->phone }}
                                </span>
                            </div>
                        @endif

                        <div class="flex items-center justify-between gap-6">
                            <span class="text-sm font-semibold text-slate-500">
                                {{ $isPayment ? 'طريقة الدفع' : 'طريقة القبض' }}
                            </span>

                            <span class="text-sm font-bold text-slate-800">
                                {{ $method }}
                            </span>
                        </div>

                        <div class="rounded-2xl bg-slate-50 p-5">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-bold text-slate-500">
                                    المبلغ
                                </span>

                                <span class="text-2xl font-black text-slate-900">
                                    {{ number_format((float) $voucher->amount, 2) }}
                                </span>
                            </div>
                        </div>

                        @if ($voucher->notes)
                            <div>
                                <div class="mb-1 text-xs font-bold text-slate-400">
                                    البيان
                                </div>

                                <div class="rounded-xl border border-slate-200 p-4 text-sm leading-6 text-slate-700">
                                    {{ $voucher->notes }}
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="mt-12 grid grid-cols-2 gap-10 text-center">
                        <div>
                            <div class="mb-8 text-xs font-bold text-slate-400">
                                المستلم / الدافع
                            </div>

                            <div class="border-t border-slate-300 pt-2 text-xs text-slate-500">
                                التوقيع
                            </div>
                        </div>

                        <div>
                            <div class="mb-8 text-xs font-bold text-slate-400">
                                المسؤول
                            </div>

                            <div class="border-t border-slate-300 pt-2 text-xs text-slate-500">
                                التوقيع
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        @media print {
            body * {
                visibility: hidden !important;
            }

            #voucher-print-area,
            #voucher-print-area * {
                visibility: visible !important;
            }

            #voucher-print-area {
                position: absolute;
                inset: 0;
                width: 100%;
                margin: 0;
                padding: 24px;
                background: white;
            }

            @page {
                size: A4;
                margin: 10mm;
            }
        }
    </style>
@endif
