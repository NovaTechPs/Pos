@php
    $isPayment = $mode === 'payment';

    $accent = $isPayment ? 'indigo' : 'emerald';

    $selectedParty = $selectedParty ?? null;
    $partyResults = $partyResults ?? collect();

    $formId = $isPayment
        ? 'payment-voucher-form'
        : 'receipt-voucher-form';

    $partyInputId = $isPayment
        ? 'payment-party-search'
        : 'receipt-party-search';

    $partyOptionsId = $isPayment
        ? 'payment-party-options'
        : 'receipt-party-options';

    $balanceLabel = $isPayment
        ? 'رصيد المورد الحالي'
        : 'رصيد العميل الحالي';

    $balanceAfterLabel = $isPayment
        ? 'الرصيد بعد سند الدفع'
        : 'الرصيد بعد سند القبض';
@endphp

@if($showForm)

    <div
        x-data="{ open: @entangle('showForm') }"
        x-cloak
        class="fixed inset-0 z-[90]"
    >

        {{-- Backdrop --}}
        <div
            class="absolute inset-0 bg-slate-950/50 backdrop-blur-sm"
            x-show="open"
            x-transition.opacity
            wire:click="$set('showForm', false)"
        ></div>

        {{-- Drawer --}}
        <div
            class="absolute inset-y-0 end-0 flex w-full max-w-xl flex-col bg-white shadow-2xl"
            x-show="open"
            x-transition:enter="transform transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transform transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
        >

            {{-- Header --}}
            <div class="shrink-0 border-b border-slate-100 bg-white px-5 py-4 sm:px-6">
                <div class="flex items-start justify-between gap-4">

                    <div>
                        <div class="flex items-center gap-3">

                            <div
                                class="
                                    flex h-11 w-11 items-center justify-center rounded-xl
                                    {{ $isPayment
                                        ? 'bg-indigo-50 text-indigo-600'
                                        : 'bg-emerald-50 text-emerald-600' }}
                                "
                            >
                                @if($isPayment)

                                    <svg
                                        class="h-5 w-5"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 6v12m-4-8h8m-9.5 8.5L18 7"
                                        />
                                    </svg>

                                @else

                                    <svg
                                        class="h-5 w-5"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M12 18V6m4 8H8m9.5-8.5L6 17"
                                        />
                                    </svg>

                                @endif
                            </div>

                            <div>
                                <h2 class="text-lg font-bold text-slate-900">
                                    {{ $paymentId ? 'تعديل ' : 'إضافة ' }}
                                    {{ $isPayment ? 'سند دفع' : 'سند قبض' }}
                                </h2>

                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $isPayment
                                        ? 'تسجيل دفعة للمورد وتحديث رصيده.'
                                        : 'تسجيل قبض من العميل وتحديث رصيده.' }}
                                </p>
                            </div>

                        </div>
                    </div>

                    <button
                        type="button"
                        wire:click="$set('showForm', false)"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                        aria-label="إغلاق"
                    >
                        <svg
                            class="h-5 w-5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M6 6l12 12M18 6L6 18"
                            />
                        </svg>
                    </button>

                </div>
            </div>

            {{-- Body --}}
            <div class="min-h-0 flex-1 overflow-y-auto">

                <form
                    wire:submit="{{ $saveMethod }}"
                    id="{{ $formId }}"
                    class="space-y-5 p-5 sm:p-6"
                >

                    {{-- Voucher number / date --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">
                                رقم السند
                            </label>

                            <input
                                type="text"
                                wire:model="voucherNumber"
                                readonly
                                class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 outline-none"
                            />

                            @error('voucherNumber')
                                <p class="mt-1.5 text-xs text-rose-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-sm font-semibold text-slate-700">
                                التاريخ
                            </label>

                            <input
                                type="date"
                                wire:model="paymentDate"
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                            />

                            @error('paymentDate')
                                <p class="mt-1.5 text-xs text-rose-600">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>

                    </div>

                    {{-- Party --}}
                    <div>

                        <label class="mb-2 block text-sm font-semibold text-slate-700">
                            {{ $partyLabel }}
                            <span class="text-rose-500">*</span>
                        </label>

                        @if($selectedParty)

                            {{-- Selected party --}}
                            <div class="overflow-hidden rounded-2xl border border-{{ $accent }}-200 bg-{{ $accent }}-50/60">

                                <div class="flex items-start justify-between gap-3 p-4">

                                    <div class="flex min-w-0 items-center gap-3">

                                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-{{ $accent }}-600 shadow-sm">
                                            <svg
                                                class="h-5 w-5"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="1.8"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M16 20v-1.5a4.5 4.5 0 00-9 0V20m4.5-9a3.5 3.5 0 100-7 3.5 3.5 0 000 7zm5.5-1.5a3 3 0 012.5 2.96V14m-1.5-7a3 3 0 11-1 5.83"
                                                />
                                            </svg>
                                        </div>

                                        <div class="min-w-0">

                                            <p class="truncate text-sm font-bold text-slate-900">
                                                {{ $selectedParty->name }}
                                            </p>

                                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">

                                                @if($selectedParty->phone)
                                                    <span>
                                                        {{ $selectedParty->phone }}
                                                    </span>
                                                @endif

                                                @if($selectedParty->tax_number)
                                                    <span>
                                                        الرقم الضريبي:
                                                        {{ $selectedParty->tax_number }}
                                                    </span>
                                                @endif

                                            </div>

                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        wire:click="clearParty"
                                        class="shrink-0 rounded-lg bg-white px-3 py-2 text-xs font-semibold text-slate-600 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50"
                                    >
                                        تغيير
                                    </button>

                                </div>

                                <div class="grid grid-cols-2 border-t border-{{ $accent }}-100 bg-white/70">

                                    <div class="p-3">

                                        <p class="text-[11px] font-medium text-slate-500">
                                            {{ $balanceLabel }}
                                        </p>

                                        <p class="mt-1 text-sm font-bold text-slate-900">
                                            {{ number_format((float) $selectedParty->current_balance, 2) }}
                                        </p>

                                    </div>

                                    <div class="border-s-{{ $accent }}-100 border-s p-3">

                                        <p class="text-[11px] font-medium text-slate-500">
                                            {{ $balanceAfterLabel }}
                                        </p>

                                        <p class="mt-1 text-sm font-bold text-{{ $accent }}-600">
                                            {{ number_format(
                                                (float) $selectedParty->current_balance
                                                - (float) ($amount ?: 0),
                                                2
                                            ) }}
                                        </p>

                                    </div>

                                </div>
                            </div>

                        @else

                            {{-- Party dropdown --}}
                            <div
                                class="relative"
                                x-data="{
                                    open: false,
                                    activeIndex: 0,

                                    get items() {
                                        return this.$refs.partyList
                                            ? this.$refs.partyList.querySelectorAll('[data-party-option]')
                                            : [];
                                    },

                                    get count() {
                                        return this.items.length;
                                    },

                                    openList() {
                                        this.open = true;
                                        this.activeIndex = 0;

                                        this.$nextTick(() => {
                                            const input = this.$refs.partySearchInput;

                                            if (input && document.activeElement !== input) {
                                                input.focus();
                                            }

                                            this.scrollActiveIntoView();
                                        });
                                    },

                                    closeList() {
                                        this.open = false;
                                        this.activeIndex = 0;
                                    },

                                    moveDown() {
                                        if (!this.open) {
                                            this.openList();
                                            return;
                                        }

                                        if (!this.count) {
                                            return;
                                        }

                                        this.activeIndex =
                                            this.activeIndex >= this.count - 1
                                                ? 0
                                                : this.activeIndex + 1;

                                        this.scrollActiveIntoView();
                                    },

                                    moveUp() {
                                        if (!this.open) {
                                            this.openList();
                                            return;
                                        }

                                        if (!this.count) {
                                            return;
                                        }

                                        this.activeIndex =
                                            this.activeIndex <= 0
                                                ? this.count - 1
                                                : this.activeIndex - 1;

                                        this.scrollActiveIntoView();
                                    },

                                    goFirst() {
                                        if (!this.open) {
                                            this.openList();
                                            return;
                                        }

                                        if (!this.count) {
                                            return;
                                        }

                                        this.activeIndex = 0;
                                        this.scrollActiveIntoView();
                                    },

                                    goLast() {
                                        if (!this.open) {
                                            this.openList();
                                            return;
                                        }

                                        if (!this.count) {
                                            return;
                                        }

                                        this.activeIndex = this.count - 1;
                                        this.scrollActiveIntoView();
                                    },

                                    chooseCurrent() {
                                        if (!this.open || !this.count) {
                                            return;
                                        }

                                        const item = this.items[this.activeIndex];

                                        if (item) {
                                            item.click();
                                        }
                                    },

                                    scrollActiveIntoView() {
                                        this.$nextTick(() => {
                                            const item = this.items[this.activeIndex];

                                            if (item) {
                                                item.scrollIntoView({
                                                    block: 'nearest',
                                                    behavior: 'smooth'
                                                });
                                            }
                                        });
                                    }
                                }"
                                x-on:click.outside="closeList()"
                            >

                                {{-- Trigger --}}
                                <button
                                    type="button"
                                    x-on:click="openList()"
                                    x-on:keydown.arrow-down.prevent="openList()"
                                    x-on:keydown.arrow-up.prevent="openList()"
                                    x-on:keydown.enter.prevent="openList()"
                                    x-on:keydown.space.prevent="openList()"
                                    :aria-expanded="open"
                                    aria-haspopup="listbox"
                                    class="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-start text-sm text-slate-600 outline-none transition hover:border-{{ $accent }}-300 focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                                >

                                    <span class="flex items-center gap-3">

                                        <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-slate-100 text-slate-500">
                                            <svg
                                                class="h-4 w-4"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                stroke-width="1.8"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    d="M16 20v-1.5a4.5 4.5 0 00-9 0V20m4.5-9a3.5 3.5 0 100-7 3.5 3.5 0 000 7zm5.5-1.5a3 3 0 012.5 2.96V14m-1.5-7a3 3 0 11-1 5.83"
                                                />
                                            </svg>
                                        </span>

                                        <span>
                                            اختر {{ $partyLabel }}
                                        </span>

                                    </span>

                                    <svg
                                        class="h-4 w-4 shrink-0 text-slate-400 transition-transform"
                                        :class="{ 'rotate-180': open }"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.8"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            d="M6 9l6 6 6-6"
                                        />
                                    </svg>

                                </button>

                                {{-- Dropdown --}}
                                <div
                                    x-show="open"
                                    x-cloak
                                    x-transition.origin.top
                                    class="absolute inset-x-0 top-full z-50 mt-2 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                                >

                                    {{-- Search --}}
                                    <div class="border-b border-slate-100 bg-slate-50/80 p-3">

                                        <div class="relative">

                                            <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3">
                                                <svg
                                                    class="h-4 w-4 text-slate-400"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    stroke-width="1.8"
                                                >
                                                    <circle cx="11" cy="11" r="7"/>
                                                    <path
                                                        stroke-linecap="round"
                                                        d="M20 20l-4-4"
                                                    />
                                                </svg>
                                            </div>

                                            <input
                                                x-ref="partySearchInput"
                                                id="{{ $partyInputId }}"
                                                type="text"
                                                role="combobox"
                                                aria-autocomplete="list"
                                                aria-controls="{{ $partyOptionsId }}"
                                                :aria-expanded="open"
                                                wire:model.live.debounce.250ms="partySearch"
                                                x-on:focus="openList()"
                                                x-on:input="activeIndex = 0"
                                                x-on:keydown.arrow-down.prevent.stop="moveDown()"
                                                x-on:keydown.arrow-up.prevent.stop="moveUp()"
                                                x-on:keydown.enter.prevent.stop="chooseCurrent()"
                                                x-on:keydown.escape.prevent.stop="closeList()"
                                                x-on:keydown.home.prevent.stop="goFirst()"
                                                x-on:keydown.end.prevent.stop="goLast()"
                                                placeholder="ابحث بالاسم أو الهاتف أو الرقم الضريبي..."
                                                autocomplete="off"
                                                class="w-full rounded-xl border border-slate-200 bg-white py-3 ps-10 pe-4 text-sm text-slate-800 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                                            />

                                        </div>

                                        <div class="mt-2 flex items-center justify-between text-[11px] text-slate-400">
                                            <span>
                                                ↑ ↓ للتنقل
                                            </span>

                                            <span>
                                                Enter للاختيار · Esc للإغلاق
                                            </span>
                                        </div>

                                    </div>

                                    {{-- Results --}}
                                    <div
                                        id="{{ $partyOptionsId }}"
                                        x-ref="partyList"
                                        role="listbox"
                                        class="max-h-72 overflow-y-auto overscroll-contain"
                                    >

                                        @forelse($partyResults as $party)

                                            <button
                                                type="button"
                                                data-party-option
                                                role="option"
                                                :aria-selected="activeIndex === {{ $loop->index }}"
                                                wire:key="{{ $formId }}-party-option-{{ $party->id }}"
                                                wire:click="selectParty({{ $party->id }})"
                                                x-on:click="closeList()"
                                                x-on:mouseenter="activeIndex = {{ $loop->index }}"
                                                x-bind:class="activeIndex === {{ $loop->index }}
                                                    ? 'bg-' + '{{ $accent }}' + '-50 border-s-4 border-' + '{{ $accent }}' + '-500'
                                                    : 'border-s-4 border-transparent'"
                                                class="flex w-full items-center gap-3 border-b border-slate-100 px-4 py-3 text-start transition hover:bg-slate-50"
                                            >

                                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
                                                    <svg
                                                        class="h-4 w-4"
                                                        viewBox="0 0 24 24"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="1.8"
                                                    >
                                                        <path
                                                            stroke-linecap="round"
                                                            stroke-linejoin="round"
                                                            d="M16 20v-1.5a4.5 4.5 0 00-9 0V20m4.5-9a3.5 3.5 0 100-7 3.5 3.5 0 000 7zm5.5-1.5a3 3 0 012.5 2.96V14m-1.5-7a3 3 0 11-1 5.83"
                                                        />
                                                    </svg>
                                                </div>

                                                <div class="min-w-0 flex-1">

                                                    <p class="truncate text-sm font-semibold text-slate-800">
                                                        {{ $party->name }}
                                                    </p>

                                                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-slate-400">

                                                        @if($party->phone)
                                                            <span>
                                                                {{ $party->phone }}
                                                            </span>
                                                        @endif

                                                        @if($party->tax_number)
                                                            <span>
                                                                {{ $party->tax_number }}
                                                            </span>
                                                        @endif

                                                    </div>

                                                </div>

                                                <div class="shrink-0 text-end">

                                                    <p class="text-[10px] text-slate-400">
                                                        الرصيد
                                                    </p>

                                                    <p class="mt-0.5 text-xs font-bold text-slate-700">
                                                        {{ number_format((float) $party->current_balance, 2) }}
                                                    </p>

                                                </div>

                                            </button>

                                        @empty

                                            <div class="px-5 py-8 text-center">

                                                <div class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-slate-400">
                                                    <svg
                                                        class="h-5 w-5"
                                                        viewBox="0 0 24 24"
                                                        fill="none"
                                                        stroke="currentColor"
                                                        stroke-width="1.8"
                                                    >
                                                        <circle
                                                            cx="11"
                                                            cy="11"
                                                            r="7"
                                                        />

                                                        <path
                                                            stroke-linecap="round"
                                                            d="M20 20l-4-4"
                                                        />
                                                    </svg>
                                                </div>

                                                @if(trim($partySearch) !== '')

                                                    <p class="mt-3 text-sm font-semibold text-slate-700">
                                                        لا توجد نتائج مطابقة
                                                    </p>

                                                    <p class="mt-1 text-xs text-slate-400">
                                                        جرّب البحث باسم آخر أو رقم هاتف مختلف.
                                                    </p>

                                                @else

                                                    <p class="mt-3 text-sm font-semibold text-slate-700">
                                                        لا يوجد {{ $partyLabel }} متاح
                                                    </p>

                                                    <p class="mt-1 text-xs text-slate-400">
                                                        لا توجد أطراف نشطة من النوع المطلوب.
                                                    </p>

                                                @endif

                                            </div>

                                        @endforelse

                                    </div>
                                </div>
                            </div>

                        @endif

                        @error('payableId')
                            <p class="mt-1.5 text-xs text-rose-600">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>

                    {{-- Amount / payment method --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                        <div>

                            <label class="mb-2 block text-sm font-semibold text-slate-700">
                                المبلغ
                                <span class="text-rose-500">*</span>
                            </label>

                            <div class="relative">

                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    wire:model.live="amount"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                    class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pe-16 text-lg font-bold text-slate-900 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                                />

                                <span class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-4 text-xs font-medium text-slate-400">
                                    المبلغ
                                </span>

                            </div>

                            @error('amount')
                                <p class="mt-1.5 text-xs text-rose-600">
                                    {{ $message }}
                                </p>
                            @enderror

                        </div>

                        <div>

                            <label class="mb-2 block text-sm font-semibold text-slate-700">
                                طريقة الدفع
                                <span class="text-rose-500">*</span>
                            </label>

                            <select
                                wire:model="paymentMethod"
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                            >
                                <option value="cash">
                                    نقدي
                                </option>

                                <option value="bank">
                                    تحويل بنكي
                                </option>

                                <option value="card">
                                    بطاقة
                                </option>

                                <option value="check">
                                    شيك
                                </option>
                            </select>

                            @error('paymentMethod')
                                <p class="mt-1.5 text-xs text-rose-600">
                                    {{ $message }}
                                </p>
                            @enderror

                        </div>

                    </div>

                    {{-- Balance preview --}}
                    @if($selectedParty)

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">

                            <div class="flex items-center justify-between gap-4">

                                <div>
                                    <p class="text-xs font-medium text-slate-500">
                                        ملخص الرصيد
                                    </p>

                                    <p class="mt-1 text-sm font-bold text-slate-800">
                                        {{ $selectedParty->name }}
                                    </p>
                                </div>

                                <div class="text-end">

                                    <p class="text-[11px] text-slate-400">
                                        بعد العملية
                                    </p>

                                    <p class="mt-1 text-base font-bold text-{{ $accent }}-600">
                                        {{ number_format(
                                            (float) $selectedParty->current_balance
                                            - (float) ($amount ?: 0),
                                            2
                                        ) }}
                                    </p>

                                </div>

                            </div>

                            <div class="mt-3 h-px bg-slate-200"></div>

                            <div class="mt-3 flex items-center justify-between text-xs">

                                <span class="text-slate-500">
                                    الرصيد الحالي
                                </span>

                                <span class="font-semibold text-slate-700">
                                    {{ number_format(
                                        (float) $selectedParty->current_balance,
                                        2
                                    ) }}
                                </span>

                            </div>

                            <div class="mt-2 flex items-center justify-between text-xs">

                                <span class="text-slate-500">
                                    قيمة السند
                                </span>

                                <span class="font-semibold text-{{ $accent }}-600">
                                    - {{ number_format(
                                        (float) ($amount ?: 0),
                                        2
                                    ) }}
                                </span>

                            </div>

                        </div>

                    @endif

                    {{-- Notes --}}
                    <div>

                        <label class="mb-2 block text-sm font-semibold text-slate-700">
                            ملاحظات
                        </label>

                        <textarea
                            wire:model="notes"
                            rows="4"
                            placeholder="أضف أي ملاحظات مرتبطة بالسند..."
                            class="w-full resize-none rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition focus:border-{{ $accent }}-500 focus:ring-2 focus:ring-{{ $accent }}-100"
                        ></textarea>

                        @error('notes')
                            <p class="mt-1.5 text-xs text-rose-600">
                                {{ $message }}
                            </p>
                        @enderror

                    </div>

                </form>
            </div>

            {{-- Footer --}}
            <div class="shrink-0 border-t border-slate-100 bg-white px-5 py-4 sm:px-6">

                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">

                    <button
                        type="button"
                        wire:click="$set('showForm', false)"
                        class="w-full rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 sm:w-auto"
                    >
                        إلغاء
                    </button>

                    <button
                        type="submit"
                        form="{{ $formId }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $saveMethod }}"
                        class="
                            flex w-full items-center justify-center gap-2 rounded-xl px-5 py-3
                            text-sm font-semibold text-white shadow-sm transition
                            disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto
                            {{ $isPayment
                                ? 'bg-indigo-600 hover:bg-indigo-700'
                                : 'bg-emerald-600 hover:bg-emerald-700' }}
                        "
                    >

                        <span
                            wire:loading.remove
                            wire:target="{{ $saveMethod }}"
                        >
                            {{ $paymentId ? 'حفظ التعديلات' : 'حفظ السند' }}
                        </span>

                        <span
                            wire:loading
                            wire:target="{{ $saveMethod }}"
                            class="flex items-center gap-2"
                        >
                            <svg
                                class="h-4 w-4 animate-spin"
                                viewBox="0 0 24 24"
                                fill="none"
                            >
                                <circle
                                    class="opacity-25"
                                    cx="12"
                                    cy="12"
                                    r="10"
                                    stroke="currentColor"
                                    stroke-width="4"
                                ></circle>

                                <path
                                    class="opacity-75"
                                    fill="currentColor"
                                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"
                                ></path>
                            </svg>

                            جارٍ الحفظ...
                        </span>

                    </button>

                </div>
            </div>

        </div>
    </div>

@endif
