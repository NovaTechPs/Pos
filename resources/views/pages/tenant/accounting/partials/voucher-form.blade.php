@php
    $isPayment = $mode === 'payment';

    $accent = $isPayment ? 'indigo' : 'emerald';

    $selectedParty = $selectedParty ?? null;
    $partyResults = $partyResults ?? collect();

    $formId = $isPayment ? 'payment-voucher-form' : 'receipt-voucher-form';
@endphp

@if ($showForm)
    <div
        x-data="{ open: @entangle('showForm') }"
        x-show="open"
        x-cloak
        class="fixed inset-0 z-[70] overflow-y-auto"
        aria-modal="true"
        role="dialog"
    >
        <div
            class="fixed inset-0 bg-slate-950/50 backdrop-blur-[2px]"
            x-on:click="open = false"
        ></div>

        <div class="relative flex min-h-full items-start justify-center p-4 sm:p-6">
            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="opacity-0 translate-y-3 scale-[.98]"
                x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                class="relative my-auto w-full max-w-3xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl"
            >
                {{-- Header --}}
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-7">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-11 items-center justify-center rounded-2xl
                            {{ $isPayment ? 'bg-indigo-50 text-indigo-600' : 'bg-emerald-50 text-emerald-600' }}"
                        >
                            <flux:icon
                                :name="$isPayment ? 'arrow-up-right' : 'arrow-down-left'"
                                class="size-5"
                            />
                        </div>

                        <div>
                            <h2 class="text-lg font-bold text-slate-900">
                                {{ $title }}
                            </h2>

                            <p class="mt-0.5 text-xs text-slate-500">
                                أدخل بيانات السند ثم احفظ العملية.
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        x-on:click="open = false"
                        class="flex size-9 items-center justify-center rounded-xl text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                    >
                        <flux:icon name="x-mark" class="size-5" />
                    </button>
                </div>

                {{-- Body --}}
                <form wire:submit=" {{ $saveMethod }}" id="{{ $formId }}">
                    <div class="max-h-[calc(100vh-190px)] overflow-y-auto p-5 sm:p-7">
                        @if ($errors->has('general'))
                            <div class="mb-5 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                                <flux:icon name="exclamation-triangle" class="mt-0.5 size-5 shrink-0" />
                                <span>{{ $errors->first('general') }}</span>
                            </div>
                        @endif

                        {{-- Top info --}}
                        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-slate-600">
                                    رقم السند
                                </label>

                                <input
                                    type="text"
                                    wire:model="voucherNumber"
                                    readonly
                                    class="w-full rounded-xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm font-bold text-slate-600 outline-none"
                                >
                            </div>

                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-slate-600">
                                    التاريخ
                                </label>

                                <input
                                    type="date"
                                    wire:model="paymentDate"
                                    class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                                >

                                @error('paymentDate')
                                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        {{-- Party --}}
                        <div class="mb-6">
                            <div class="mb-1.5 flex items-center justify-between">
                                <label class="text-xs font-bold text-slate-600">
                                    {{ $partyLabel }}
                                </label>

                                @if ($selectedParty)
                                    <button
                                        type="button"
                                        wire:click="clearParty"
                                        class="text-xs font-semibold text-slate-400 hover:text-rose-600"
                                    >
                                        تغيير
                                    </button>
                                @endif
                            </div>

                            @if ($selectedParty)
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex items-center gap-3">
                                            <div class="flex size-11 items-center justify-center rounded-xl bg-white text-slate-500 shadow-sm">
                                                <flux:icon name="user" class="size-5" />
                                            </div>

                                            <div>
                                                <div class="font-bold text-slate-900">
                                                    {{ $selectedParty->name }}
                                                </div>

                                                @if ($selectedParty->phone)
                                                    <div class="mt-0.5 text-xs text-slate-500" dir="ltr">
                                                        {{ $selectedParty->phone }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="flex gap-5">
                                            <div>
                                                <div class="text-[11px] font-semibold text-slate-400">
                                                    الرصيد الحالي
                                                </div>

                                                <div class="mt-1 text-sm font-black text-slate-800">
                                                    {{ number_format((float) $selectedParty->current_balance, 2) }}
                                                </div>
                                            </div>

                                            <div>
                                                <div class="text-[11px] font-semibold text-slate-400">
                                                    الرصيد الافتتاحي
                                                </div>

                                                <div class="mt-1 text-sm font-bold text-slate-500">
                                                    {{ number_format((float) $selectedParty->opening_balance, 2) }}
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    @if ((float) $amount > 0)
                                        <div class="mt-4 border-t border-slate-200 pt-4">
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs font-semibold text-slate-500">
                                                    الرصيد بعد السند
                                                </span>

                                                <span class="text-base font-black {{ $isPayment ? 'text-indigo-700' : 'text-emerald-700' }}">
                                                    {{ number_format((float) $selectedParty->current_balance - (float) $amount, 2) }}
                                                </span>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @else
                                <div class="relative">
                                    <div class="relative">
                                        <flux:icon
                                            name="magnifying-glass"
                                            class="absolute right-3 top-1/2 size-5 -translate-y-1/2 text-slate-400"
                                        />

                                        <input
                                            type="text"
                                            wire:model.live.debounce.250ms="partySearch"
                                            placeholder="ابحث بالاسم أو رقم الهاتف..."
                                            class="w-full rounded-xl border border-slate-200 bg-white py-3 pr-10 pl-4 text-sm font-medium text-slate-700 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                                        >
                                    </div>

                                    @if ($partyResults->count())
                                        <div class="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
                                            @foreach ($partyResults as $party)
                                                <button
                                                    type="button"
                                                    wire:key="party-{{ $mode }}-{{ $party->id }}"
                                                    wire:click="selectParty({{ $party->id }})"
                                                    class="flex w-full items-center justify-between gap-4 border-b border-slate-100 px-4 py-3 text-right transition last:border-0 hover:bg-slate-50"
                                                >
                                                    <div class="min-w-0">
                                                        <div class="truncate text-sm font-bold text-slate-800">
                                                            {{ $party->name }}
                                                        </div>

                                                        @if ($party->phone)
                                                            <div class="mt-0.5 text-xs text-slate-400" dir="ltr">
                                                                {{ $party->phone }}
                                                            </div>
                                                        @endif
                                                    </div>

                                                    <div class="shrink-0 text-left">
                                                        <div class="text-[10px] font-semibold text-slate-400">
                                                            الرصيد
                                                        </div>

                                                        <div class="text-xs font-bold text-slate-700">
                                                            {{ number_format((float) $party->current_balance, 2) }}
                                                        </div>
                                                    </div>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif

                            @error('payableId')
                                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Amount + Method --}}
                        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-slate-600">
                                    المبلغ
                                </label>

                                <div class="relative">
                                    <input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        wire:model.live="amount"
                                        placeholder="0.00"
                                        class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-left text-lg font-black text-slate-900 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                                        dir="ltr"
                                    >

                                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400">
                                        ش.ض
                                    </span>
                                </div>

                                @error('amount')
                                    <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label class="mb-1.5 block text-xs font-bold text-slate-600">
                                    {{ $isPayment ? 'طريقة الدفع' : 'طريقة القبض' }}
                                </label>

                                <select
                                    wire:model="paymentMethod"
                                    class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                                >
                                    <option value="cash">نقدي</option>
                                    <option value="bank">تحويل بنكي</option>
                                    <option value="card">بطاقة</option>
                                    <option value="check">شيك</option>
                                </select>
                            </div>
                        </div>

                        {{-- Notes --}}
                        <div>
                            <label class="mb-1.5 block text-xs font-bold text-slate-600">
                                البيان / الملاحظات
                            </label>

                            <textarea
                                wire:model="notes"
                                rows="3"
                                placeholder="اكتب أي ملاحظات مرتبطة بالسند..."
                                class="w-full resize-none rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                            ></textarea>

                            @error('notes')
                                <p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-6 text-amber-800">
                            <strong>تنبيه:</strong>
                            الرصيد الافتتاحي مرجعي ولا يتغير عند حفظ أو تعديل أو حذف السند.
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="flex flex-col-reverse gap-2 border-t border-slate-100 bg-slate-50 px-5 py-4 sm:flex-row sm:items-center sm:justify-end sm:px-7">
                        <button
                            type="button"
                            x-on:click="open = false"
                            class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-600 transition hover:bg-slate-100"
                        >
                            إلغاء
                        </button>

                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="{{ $saveMethod }}"
                            class="inline-flex items-center justify-center gap-2 rounded-xl px-6 py-2.5 text-sm font-bold text-white shadow-sm transition disabled:cursor-not-allowed disabled:opacity-60
                            {{ $isPayment ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-emerald-600 hover:bg-emerald-700' }}"
                        >
                            <span wire:loading.remove wire:target="{{ $saveMethod }}">
                                {{ $paymentId ? 'حفظ التعديلات' : 'حفظ السند' }}
                            </span>

                            <span wire:loading wire:target="{{ $saveMethod }}" class="inline-flex items-center gap-2">
                                <flux:icon name="arrow-path" class="size-4 animate-spin" />
                                جارٍ الحفظ...
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
