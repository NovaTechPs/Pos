<div class="shrink-0 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-100 bg-gradient-to-l from-slate-50 to-white">
        <div class="flex flex-col xl:flex-row xl:items-center gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-11 h-11 rounded-xl bg-slate-900 text-white flex items-center justify-center text-xl shadow-sm">🧾</div>
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-base font-black text-slate-900">نقطة البيع</h1>
                        @if ($currentInvoiceId)
                            <span class="px-2 py-1 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-bold">عرض فاتورة #{{ $currentInvoiceId }}</span>
                        @else
                            <span class="px-2 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-[11px] font-bold">فاتورة جديدة</span>
                        @endif
                        @if ($isReturnMode)
                            <span class="px-2 py-1 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-[11px] font-black">وضع المرتجع</span>
                        @endif
                    </div>
                    <p class="text-[11px] text-slate-500 mt-0.5">إدارة البيع والدفع والمخزون من شاشة واحدة</p>
                </div>
            </div>

            <div class="flex-1 min-w-0">
                <form wire:submit.prevent="searchInvoice" class="relative">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">🔎</span>
                    <input type="text" wire:model="searchInvoiceQuery"
                        placeholder="بحث سريع عن فاتورة برقم الفاتورة أو المعرّف..."
                        class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 pr-10 pl-20 text-sm font-bold text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 outline-none">
                    <button type="submit" class="absolute left-1.5 top-1.5 h-8 px-3 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-black transition">بحث</button>
                </form>
            </div>

            <div class="flex items-center gap-2 shrink-0">
                @if ($activeShift)
                    <button type="button" wire:click="prepareCloseShift" class="h-10 px-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 hover:bg-emerald-100 text-xs font-black flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span> الشيفت مفتوح
                    </button>
                @else
                    <button type="button" wire:click="triggerOpenShiftModal" class="h-10 px-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 hover:bg-rose-100 text-xs font-black">🔓 فتح الشيفت</button>
                @endif

                @if (!Auth::user()->branch_id)
                    <select wire:model.live="selectedBranchId" class="h-10 max-w-40 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 focus:border-indigo-500 outline-none">
                        <option value="">اختر الفرع</option>
                        @foreach ($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
        </div>
    </div>

    <div class="px-3 py-2 bg-slate-50 flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-1.5 flex-wrap">
            <button type="button" wire:click="$set('showHeldModal', true)" class="h-9 px-3 rounded-lg bg-slate-900 text-white hover:bg-slate-800 text-xs font-black flex items-center gap-2 relative">
                المعلقة <span class="font-mono text-[10px] bg-white/10 px-1.5 rounded">F1</span>
                @if (count($heldInvoices) > 0)<span class="absolute -top-1.5 -left-1.5 min-w-5 h-5 px-1 rounded-full bg-rose-500 text-white text-[10px] flex items-center justify-center">{{ count($heldInvoices) }}</span>@endif
            </button>
            <button type="button" wire:click="holdInvoice" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif class="h-9 px-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 hover:bg-amber-100 disabled:opacity-40 text-xs font-black">تعليق <span class="font-mono text-[10px]">F2</span></button>
            <button type="button" wire:click="checkout" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif class="h-9 px-3 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 disabled:opacity-40 text-xs font-black">حفظ <span class="font-mono text-[10px]">F3</span></button>
            <button type="button" wire:click="clearCart" @if (count($cart) === 0) disabled @endif class="h-9 px-3 rounded-lg bg-white border border-slate-200 text-slate-700 hover:bg-slate-100 disabled:opacity-40 text-xs font-black">مسح <span class="font-mono text-[10px]">F4</span></button>
            <button type="button" wire:click="checkoutAndPrint" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif class="h-9 px-3 rounded-lg bg-slate-900 text-white hover:bg-slate-800 disabled:opacity-40 text-xs font-black">حفظ وطباعة <span class="font-mono text-[10px]">F6</span></button>
            <button type="button" wire:click="$set('showProductsModal', true)" class="h-9 px-3 rounded-lg bg-violet-600 text-white hover:bg-violet-700 text-xs font-black">الأصناف <span class="font-mono text-[10px]">F10</span></button>
        </div>

        <div class="flex items-center gap-1.5 flex-wrap text-[11px] font-bold">
            <button type="button" wire:click="previousInvoice" class="h-9 px-3 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100">السابق ‹</button>
            <button type="button" wire:click="nextInvoice" @if (!$currentInvoiceId) disabled @endif class="h-9 px-3 rounded-lg border border-slate-200 bg-white text-slate-700 hover:bg-slate-100 disabled:opacity-40">التالي ›</button>
            <button type="button" wire:click="toggleReturnMode" class="h-9 px-3 rounded-lg border text-xs font-black {{ $isReturnMode ? 'bg-rose-600 border-rose-600 text-white' : 'bg-white border-rose-200 text-rose-700 hover:bg-rose-50' }}">↩ {{ $isReturnMode ? 'إلغاء المرتجع' : 'مرتجع' }}</button>
            <button type="button" wire:click="openCostModal" @if (count($cart) === 0) disabled @endif class="h-9 px-3 rounded-lg border border-indigo-200 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 disabled:opacity-40">التكلفة</button>
            <div class="h-9 px-3 rounded-lg bg-white border border-slate-200 text-slate-600 flex items-center gap-2">🕐 {{ $this->invoiceDate }}</div>
            <div class="h-9 px-3 rounded-lg bg-white border border-slate-200 text-slate-600 flex items-center gap-2">👤 {{ $this->invoiceCreator }}</div>
        </div>
    </div>
</div>
