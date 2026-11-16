@php
    $isPayment = $mode === 'payment';
    $partyLabel = $isPayment ? 'المورد / الجهة' : 'العميل / الجهة';
    $voucherLabel = $isPayment ? 'سند صرف' : 'سند قبض';
    $emptyTitle = $isPayment ? 'لا توجد سندات صرف' : 'لا توجد سندات قبض';
    $emptyDescription = $isPayment ? 'أنشئ أول سند صرف لتظهر حركات الموردين والجهات هنا.' : 'أنشئ أول سند قبض لتظهر المقبوضات وحركات العملاء هنا.';
    $methodLabels = [
        'cash' => 'نقدي',
        'card' => 'بطاقة',
        'bank_transfer' => 'تحويل بنكي',
        'bank' => 'تحويل بنكي',
        'cheque' => 'شيك',
        'check' => 'شيك',
    ];
@endphp

<div data-voucher-workspace class="mx-auto w-full max-w-[1600px] p-4 sm:p-6 lg:p-8" dir="rtl">
    <header class="mb-5 flex flex-col gap-4 rounded-3xl border border-slate-200 bg-white px-5 py-5 shadow-sm sm:px-7 lg:flex-row lg:items-center lg:justify-between">
        <div class="min-w-0">
            <div class="mb-2 flex items-center gap-2 text-xs font-bold text-slate-400">
                <span>الرئيسية</span><span>/</span><span>السندات المالية</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-700"><flux:icon name="document-text" class="size-6" /></span>
                <div>
                    <h1 class="text-2xl font-black tracking-tight text-slate-950 sm:text-3xl">{{ $title }}</h1>
                    <p class="mt-1 text-sm text-slate-500">{{ $subtitle }}</p>
                </div>
            </div>
        </div>

        <button type="button" wire:click="openCreate" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-black text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-100">
            <flux:icon name="plus" class="size-5" />{{ $createLabel }}
        </button>
    </header>

    <section class="mb-5 rounded-3xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4" aria-label="فلاتر {{ $voucherLabel }}">
        <div class="grid grid-cols-1 items-end gap-3 md:grid-cols-2 xl:grid-cols-[minmax(260px,1.8fr)_repeat(3,minmax(150px,1fr))_auto]">
            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">بحث سريع</span>
                <span class="relative block">
                    <flux:icon name="magnifying-glass" class="pointer-events-none absolute end-3 top-1/2 size-5 -translate-y-1/2 text-blue-600" />
                    <input type="search" wire:model.live.debounce.300ms="tableSearch" placeholder="ابحث برقم السند أو اسم {{ $partyLabel }} أو الهاتف..." class="h-12 w-full rounded-xl border border-slate-200 bg-slate-50 py-3 ps-4 pe-11 text-sm font-medium text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-blue-500 focus:bg-white focus:ring-4 focus:ring-blue-50">
                </span>
            </label>
            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">من تاريخ</span>
                <input type="date" wire:model.live="filterDateFrom" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-50">
            </label>
            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">إلى تاريخ</span>
                <input type="date" wire:model.live="filterDateTo" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-50">
            </label>
            <label class="block">
                <span class="mb-1.5 block text-xs font-bold text-slate-600">طريقة الدفع</span>
                <select wire:model.live="filterMethod" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-blue-500 focus:ring-4 focus:ring-blue-50">
                    <option value="">الكل</option><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="bank_transfer">تحويل بنكي</option><option value="cheque">شيك</option>
                </select>
            </label>
            <details class="relative self-end" data-voucher-advanced-filters>
                <summary class="flex h-12 cursor-pointer list-none items-center justify-center gap-2 rounded-xl border border-blue-100 bg-blue-50 px-4 text-sm font-black text-blue-700 transition hover:bg-blue-100"><span>فلاتر متقدمة</span><span class="text-lg leading-none">⌄</span></summary>
                <div class="absolute end-0 z-20 mt-2 grid w-[min(92vw,420px)] grid-cols-1 gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xl sm:grid-cols-2">
                    <label class="text-xs font-bold text-slate-600">مصدر السند
                        <select wire:model.live="filterSource" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-blue-500"><option value="">الكل</option><option value="invoice">مرتبط بفاتورة</option><option value="manual">سند مستقل</option></select>
                    </label>
                    <label class="text-xs font-bold text-slate-600">المبلغ من<input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="filterMinAmount" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-blue-500"></label>
                    <label class="text-xs font-bold text-slate-600">المبلغ إلى<input type="number" min="0" step="0.01" wire:model.live.debounce.300ms="filterMaxAmount" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm outline-none focus:border-blue-500"></label>
                    <button type="button" wire:click="clearVoucherFilters" class="mt-auto h-11 rounded-xl border border-slate-200 px-3 text-sm font-bold text-slate-600 transition hover:bg-slate-50">إعادة التعيين</button>
                </div>
            </details>
        </div>
    </section>

    <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
        @if ($items->count())
            <div class="app-table-scroll overflow-x-auto" role="region" aria-label="جدول {{ $title }} — اسحب لعرض بقية الأعمدة" tabindex="0">
                <table class="w-full min-w-[980px] text-right">
                    <thead class="border-b border-slate-200 bg-slate-100/90">
                        <tr>
                            <th class="px-5 py-4 text-xs font-black text-slate-600">رقم السند</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-600">{{ $partyLabel }}</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-600">التاريخ</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-600">الطريقة</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-600">المبلغ</th>
                            <th class="px-5 py-4 text-xs font-black text-slate-600">الرصيد الحالي</th>
                            <th class="px-5 py-4 text-center text-xs font-black text-slate-600">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($items as $item)
                            @php
                                $method = $methodLabels[$item->payment_method] ?? ($item->payment_method ?: '-');
                                $linkedDocument = $item->order_id ? ($item->linked_invoice_number ?? ('#'.$item->order_id)) : ($item->purchase_id ? 'فاتورة مشتريات #'.$item->purchase_id : 'سند مستقل');
                            @endphp
                            <tr wire:key="{{ $mode }}-voucher-{{ $item->id }}" class="group transition hover:bg-blue-50/40">
                                <td class="px-5 py-4 align-top"><p class="font-mono text-sm font-black text-slate-900" dir="ltr">{{ $item->voucher_number }}</p><p class="mt-1 text-xs font-medium text-slate-400">{{ $linkedDocument }}</p></td>
                                <td class="px-5 py-4">
                                    <div class="flex min-w-[180px] items-center gap-3">
                                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500"><flux:icon name="user" class="size-4" /></span>
                                        <div class="min-w-0"><p class="truncate text-sm font-bold text-slate-800">{{ $item->party?->name ?? 'غير محدد' }}</p>@if ($item->party?->phone)<p class="mt-0.5 text-xs text-slate-400" dir="ltr">{{ $item->party->phone }}</p>@endif</div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-sm font-semibold text-slate-700">{{ optional($item->payment_date)->format('Y-m-d') ?: '-' }}</td>
                                <td class="px-5 py-4"><span class="inline-flex rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">{{ $method }}</span></td>
                                <td class="px-5 py-4"><span class="text-base font-black text-blue-700" dir="ltr">{{ number_format((float) $item->amount, 2) }}</span></td>
                                <td class="px-5 py-4"><span class="text-sm font-bold text-slate-700" dir="ltr">{{ number_format((float) ($item->party?->current_balance ?? 0), 2) }}</span></td>
                                <td class="px-5 py-4">
                                    <div class="flex items-center justify-center gap-1.5">
                                        @if (!$item->purchase_return_id && $item->order?->type !== 'return')
                                            <button type="button" wire:click="edit({{ $item->id }})" title="تعديل" class="inline-flex size-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-blue-100 hover:text-blue-700"><flux:icon name="pencil-square" class="size-4" /></button>
                                        @endif
                                        <a href="{{ route('tenant.vouchers.pdf', ['type' => $item->type, 'paymentId' => $item->id]) }}" class="inline-flex h-9 items-center gap-1 rounded-lg bg-slate-100 px-2.5 text-xs font-bold text-slate-700 transition hover:bg-slate-200">PDF</a>
                                        <button type="button" wire:click="print({{ $item->id }})" title="طباعة السند" aria-label="طباعة السند" class="inline-flex size-9 items-center justify-center rounded-lg text-slate-600 transition hover:bg-slate-100"><flux:icon name="printer" class="size-4" /></button>
                                        <button type="button" wire:click="sendWhatsapp({{ $item->id }})" title="إرسال واتساب" class="inline-flex size-9 items-center justify-center rounded-lg text-slate-500 transition hover:bg-emerald-50 hover:text-emerald-600"><flux:icon name="chat-bubble-left-right" class="size-4" /></button>
                                        @if (!$item->purchase_return_id && $item->order?->type !== 'return')
                                            <button type="button" wire:click="confirmDelete({{ $item->id }})" title="حذف" class="inline-flex size-9 items-center justify-center rounded-lg text-rose-500 transition hover:bg-rose-50 hover:text-rose-700"><flux:icon name="trash" class="size-4" /></button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-col gap-3 border-t border-slate-100 px-5 py-4 text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between">
                <p>عرض {{ $items->firstItem() }} إلى {{ $items->lastItem() }} من أصل {{ $items->total() }} سند</p><div>{{ $items->links() }}</div>
            </div>
        @else
            <div class="flex min-h-[360px] flex-col items-center justify-center px-6 text-center">
                <span class="inline-flex size-16 items-center justify-center rounded-2xl bg-blue-50 text-blue-600"><flux:icon name="document-text" class="size-8" /></span>
                <h2 class="mt-5 text-lg font-black text-slate-900">{{ $emptyTitle }}</h2>
                <p class="mt-2 max-w-md text-sm leading-6 text-slate-500">{{ $emptyDescription }}</p>
                <button type="button" wire:click="openCreate" class="mt-5 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-black text-white shadow-lg shadow-blue-600/20 transition hover:bg-blue-700"><flux:icon name="plus" class="size-5" />{{ $createLabel }}</button>
            </div>
        @endif
    </section>
</div>
