<div class="shrink-0 rounded-2xl border border-slate-200 bg-white p-2.5 shadow-sm">
    <div class="flex flex-wrap items-center gap-2">
        @if (!Auth::user()->branch_id)
            <div class="flex items-center gap-1.5 rounded-xl bg-slate-50 px-2 py-1.5">
                <span class="text-[10px] font-black text-slate-500">الفرع</span>
                <select wire:model.live="selectedBranchId" class="rounded-lg border border-slate-300 bg-white px-2 py-1 text-xs font-bold focus:border-indigo-500 focus:outline-none">
                    <option value="">اختر الفرع</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif

        <form wire:submit.prevent="searchInvoice" class="flex min-w-[230px] flex-1 items-center gap-1.5">
            <div class="relative flex-1">
                <input wire:model="searchInvoiceQuery" type="text" placeholder="ابحث برقم الفاتورة أو ID..." class="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold focus:border-indigo-500 focus:bg-white focus:outline-none">
            </div>
            <button type="submit" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black text-white shadow-sm hover:bg-indigo-700">بحث</button>
        </form>

        <div class="flex items-center gap-1">
            <button wire:click="previousInvoice" class="rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-2 text-[10px] font-black text-slate-700 hover:bg-slate-100">← السابقة</button>
            <button wire:click="nextInvoice" @disabled(!$currentInvoiceId) class="rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-2 text-[10px] font-black text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40">التالية →</button>
        </div>

        <button wire:click="startNewInvoice" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-black text-white shadow-sm hover:bg-emerald-700">＋ فاتورة جديدة</button>

        @if ($this->activeShift())
            <button wire:click="prepareCloseShift" class="rounded-xl bg-slate-900 px-3 py-2 text-xs font-black text-white shadow-sm hover:bg-slate-800">🔒 إغلاق الشيفت</button>
        @else
            <button wire:click="triggerOpenShiftModal" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-black text-white shadow-sm hover:bg-emerald-700">🔓 فتح الشيفت</button>
        @endif

        <button wire:click="toggleReturnMode" class="rounded-xl border px-3 py-2 text-xs font-black {{ $isReturnMode ? 'border-rose-500 bg-rose-600 text-white' : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100' }}">
            ↩ {{ $isReturnMode ? 'وضع المرتجع مفعل' : 'مرتجع' }}
        </button>

        <button wire:click="openCostModal" @disabled(empty($cart)) class="rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-700 disabled:opacity-40">التكلفة</button>
        <button wire:click="holdInvoice" @disabled(empty($cart) || $currentInvoiceId) class="rounded-xl bg-amber-500 px-3 py-2 text-xs font-black text-white disabled:opacity-40">تعليق <kbd class="mr-1 rounded bg-amber-700 px-1">F2</kbd></button>
        <button wire:click="$set('showHeldModal', true)" class="relative rounded-xl bg-slate-800 px-3 py-2 text-xs font-black text-white">معلقة <kbd class="mr-1 rounded bg-slate-950 px-1">F1</kbd>
            @if (count($heldInvoices))
                <span class="absolute -right-1.5 -top-1.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[9px] font-black">{{ count($heldInvoices) }}</span>
            @endif
        </button>
        <button wire:click="$set('showProductsModal', true)" class="rounded-xl bg-indigo-700 px-3 py-2 text-xs font-black text-white shadow-sm hover:bg-indigo-800">📦 الأصناف <kbd class="mr-1 rounded bg-indigo-900 px-1">F10</kbd></button>
    </div>

    <div class="mt-2 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2">
        <span class="rounded-lg px-2.5 py-1 text-[10px] font-black {{ $currentInvoiceId ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700' }}">
            {{ $currentInvoiceId ? 'عرض فاتورة محفوظة — للقراءة فقط' : 'فاتورة جديدة جاهزة' }}
        </span>
        <span class="rounded-lg bg-slate-50 px-2.5 py-1 text-[10px] font-bold text-slate-500">📅 {{ $this->invoiceDate }}</span>
        <span class="rounded-lg bg-slate-50 px-2.5 py-1 text-[10px] font-bold text-slate-500">👤 {{ $this->invoiceCreator }}</span>
        @if ($this->activeShift())
            <span class="rounded-lg bg-emerald-50 px-2.5 py-1 text-[10px] font-black text-emerald-700">● شيفت #{{ $this->activeShift()->id }} مفتوح</span>
        @else
            <span class="rounded-lg bg-rose-50 px-2.5 py-1 text-[10px] font-black text-rose-700">● لا يوجد شيفت مفتوح</span>
        @endif
        @if ($isReturnMode)
            <span class="rounded-lg bg-rose-50 px-2.5 py-1 text-[10px] font-black text-rose-700">↩ وضع المرتجع</span>
        @endif
    </div>
</div>
