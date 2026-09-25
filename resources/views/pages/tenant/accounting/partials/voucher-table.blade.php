@php
    $isPayment = $mode === 'payment';

    $accent = $isPayment ? 'indigo' : 'emerald';

    $actionMethod = $isPayment ? 'openCreate' : 'openCreate';
    $editMethod = 'edit';
    $deleteMethod = 'confirmDelete';
    $printMethod = 'print';
    $whatsappMethod = 'sendWhatsapp';

    $partyLabel = $isPayment ? 'المورد' : 'العميل';
    $emptyTitle = $isPayment ? 'لا توجد سندات دفع' : 'لا توجد سندات قبض';
    $emptyDescription = $isPayment
        ? 'لم يتم تسجيل أي سند دفع حتى الآن.'
        : 'لم يتم تسجيل أي سند قبض حتى الآن.';
@endphp

<div class="mx-auto w-full max-w-[1600px] p-4 sm:p-6 lg:p-8">

    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <div class="mb-2 flex items-center gap-2">
                <span
                    class="inline-flex size-9 items-center justify-center rounded-xl
                    {{ $isPayment ? 'bg-indigo-50 text-indigo-600' : 'bg-emerald-50 text-emerald-600' }}"
                >
                    <flux:icon
                        :name="$isPayment ? 'arrow-up-right' : 'arrow-down-left'"
                        class="size-5"
                    />
                </span>

                <span class="text-xs font-bold text-slate-400">
                    المحاسبة
                </span>
            </div>

            <h1 class="text-2xl font-black tracking-tight text-slate-900 sm:text-3xl">
                {{ $title }}
            </h1>

            <p class="mt-1 text-sm text-slate-500">
                {{ $subtitle }}
            </p>
        </div>

        <button
            type="button"
            wire:click="{{ $actionMethod }}"
            class="inline-flex items-center justify-center gap-2 rounded-xl px-5 py-3 text-sm font-bold text-white shadow-sm transition
            {{ $isPayment ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-emerald-600 hover:bg-emerald-700' }}"
        >
            <flux:icon name="plus" class="size-5" />
            {{ $createLabel }}
        </button>
    </div>

    {{-- Stats --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-xs font-bold text-slate-400">
                        {{ $isPayment ? 'إجمالي المدفوع اليوم' : 'إجمالي المقبوض اليوم' }}
                    </div>

                    <div class="mt-2 text-2xl font-black text-slate-900">
                        {{ number_format((float) $todayTotal, 2) }}
                    </div>
                </div>

                <div class="flex size-11 items-center justify-center rounded-xl {{ $isPayment ? 'bg-indigo-50 text-indigo-600' : 'bg-emerald-50 text-emerald-600' }}">
                    <flux:icon name="banknotes" class="size-5" />
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-xs font-bold text-slate-400">
                        عدد السندات
                    </div>

                    <div class="mt-2 text-2xl font-black text-slate-900">
                        {{ number_format($voucherCount) }}
                    </div>
                </div>

                <div class="flex size-11 items-center justify-center rounded-xl bg-slate-100 text-slate-600">
                    <flux:icon name="document-text" class="size-5" />
                </div>
            </div>
        </div>

        <div class="hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm lg:block">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-xs font-bold text-slate-400">
                        الحالة
                    </div>

                    <div class="mt-2 flex items-center gap-2 text-sm font-bold text-emerald-700">
                        <span class="size-2 rounded-full bg-emerald-500"></span>
                        النظام يعمل
                    </div>
                </div>

                <div class="flex size-11 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                    <flux:icon name="check-circle" class="size-5" />
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
        <div class="relative">
            <flux:icon
                name="magnifying-glass"
                class="absolute right-3 top-1/2 size-5 -translate-y-1/2 text-slate-400"
            />

            <input
                type="search"
                wire:model.live.debounce.300ms="tableSearch"
                placeholder="ابحث برقم السند أو اسم {{ $partyLabel }} أو الهاتف..."
                class="w-full rounded-xl border border-slate-200 bg-slate-50 py-3 pr-10 pl-4 text-sm font-medium text-slate-700 outline-none transition focus:border-{{ $accent }}-500 focus:bg-white focus:ring-2 focus:ring-{{ $accent }}-100"
            >
        </div>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        @if ($items->count())
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-right">
                    <thead class="border-b border-slate-200 bg-slate-50">
                        <tr>
                            <th class="px-5 py-4 text-xs font-black text-slate-500">رقم السند</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-500">{{ $partyLabel }}</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-500">التاريخ</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-500">الطريقة</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-500">المبلغ</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-500">الرصيد الحالي</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-500"></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        @foreach ($items as $item)
                            <tr
                                wire:key="{{ $mode }}-voucher-{{ $item->id }}"
                                class="group transition hover:bg-slate-50/80"
                            >
                                <td class="px-5 py-4">
                                    <span class="font-mono text-sm font-black text-slate-800" dir="ltr">
                                        {{ $item->voucher_number }}
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500">
                                            <flux:icon name="user" class="size-4" />
                                        </div>

                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-bold text-slate-800">
                                                {{ $item->payable?->name ?? 'غير محدد' }}
                                            </div>

                                            @if ($item->payable?->phone)
                                                <div class="mt-0.5 text-xs text-slate-400" dir="ltr">
                                                    {{ $item->payable->phone }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>

                                <td class="px-5 py-4">
                                    <div class="text-sm font-semibold text-slate-700">
                                        {{ optional($item->payment_date)->format('Y-m-d') ?: '-' }}
                                    </div>
                                </td>

                                <td class="px-5 py-4">
                                    @php
                                        $method = match ($item->payment_method) {
                                            'cash' => 'نقدي',
                                            'bank' => 'تحويل بنكي',
                                            'card' => 'بطاقة',
                                            'check' => 'شيك',
                                            default => $item->payment_method ?: '-',
                                        };
                                    @endphp

                                    <span class="inline-flex rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">
                                        {{ $method }}
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <span class="text-sm font-black {{ $isPayment ? 'text-indigo-700' : 'text-emerald-700' }}">
                                        {{ number_format((float) $item->amount, 2) }}
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <span class="text-sm font-bold text-slate-700">
                                        {{ number_format((float) ($item->payable?->current_balance ?? 0), 2) }}
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-end gap-1 opacity-70 transition group-hover:opacity-100">
                                        <button
                                            type="button"
                                            wire:click="edit({{ $item->id }})"
                                            title="تعديل"
                                            class="flex size-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                                        >
                                            <flux:icon name="pencil-square" class="size-4" />
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="print({{ $item->id }})"
                                            title="طباعة"
                                            class="flex size-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-slate-100 hover:text-slate-900"
                                        >
                                            <flux:icon name="printer" class="size-4" />
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="sendWhatsapp({{ $item->id }})"
                                            title="WhatsApp"
                                            class="flex size-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-emerald-50 hover:text-emerald-600"
                                        >
                                            <flux:icon name="chat-bubble-left-right" class="size-4" />
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $item->id }})"
                                            title="حذف"
                                            class="flex size-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-rose-50 hover:text-rose-600"
                                        >
                                            <flux:icon name="trash" class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-slate-100 px-4 py-4">
                {{ $items->links() }}
            </div>
        @else
            <div class="flex min-h-[360px] flex-col items-center justify-center px-6 text-center">
                <div class="flex size-16 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
                    <flux:icon name="document-text" class="size-8" />
                </div>

                <h3 class="mt-5 text-base font-black text-slate-800">
                    {{ $emptyTitle }}
                </h3>

                <p class="mt-1 max-w-sm text-sm leading-6 text-slate-500">
                    {{ $emptyDescription }}
                </p>

                <button
                    type="button"
                    wire:click="{{ $actionMethod }}"
                    class="mt-5 inline-flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-bold text-white
                    {{ $isPayment ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-emerald-600 hover:bg-emerald-700' }}"
                >
                    <flux:icon name="plus" class="size-4" />
                    {{ $createLabel }}
                </button>
            </div>
        @endif
    </div>
</div>
