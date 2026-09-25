@if ($showHeldModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm">
        <div class="w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between bg-slate-900 p-4 text-white">
                <div><div class="text-sm font-black">الفواتير المعلقة</div><div class="mt-0.5 text-[10px] text-slate-400">تبقى محفوظة في جلسة المستخدم والفرع</div></div>
                <button wire:click="$set('showHeldModal', false)" class="text-slate-400 hover:text-white">✕</button>
            </div>
            <div class="max-h-[65vh] space-y-2 overflow-y-auto bg-slate-50 p-3">
                @forelse ($heldInvoices as $index => $held)
                    <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="min-w-0">
                            <div class="truncate text-xs font-black text-slate-900">فاتورة معلقة #{{ $index + 1 }}</div>
                            <div class="mt-1 text-[10px] font-mono text-slate-400">{{ $held['time'] }}</div>
                            <div class="mt-1 font-mono text-sm font-black text-indigo-700">{{ number_format((float) $held['total'], 2) }}</div>
                        </div>
                        <div class="flex gap-1.5">
                            <button wire:click="restoreHeldInvoice({{ $index }})" class="rounded-lg bg-emerald-600 px-3 py-2 text-[10px] font-black text-white">استرجاع</button>
                            <button wire:click="removeHeldInvoice({{ $index }})" class="rounded-lg bg-rose-50 px-3 py-2 text-[10px] font-black text-rose-700">حذف</button>
                        </div>
                    </div>
                @empty
                    <div class="py-16 text-center text-xs font-black text-slate-400">لا توجد فواتير معلقة.</div>
                @endforelse
            </div>
        </div>
    </div>
@endif
