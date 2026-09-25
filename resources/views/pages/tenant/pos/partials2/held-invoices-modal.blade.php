@if ($showHeldModal)
    <div class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm p-4 flex items-center justify-center">
        <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl overflow-hidden border border-slate-200">
            <div class="px-5 py-4 bg-slate-900 text-white flex items-center justify-between"><div><h3 class="font-black">الفواتير المعلقة</h3><p class="text-[10px] text-slate-400 mt-1">استرجع الفاتورة لمتابعة البيع</p></div><button wire:click="$set('showHeldModal', false)" class="w-9 h-9 rounded-lg bg-white/10 hover:bg-white/20">✕</button></div>
            <div class="p-4 max-h-[65vh] overflow-auto space-y-2">
                @forelse($heldInvoices as $index => $held)
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 flex items-center justify-between gap-3"><div class="min-w-0"><div class="font-black text-slate-800">فاتورة معلقة #{{ $held['id'] }}</div><div class="text-[10px] text-slate-400 font-mono mt-1">{{ $held['time'] }}</div><div class="mt-2 text-sm font-black font-mono text-indigo-700">{{ number_format($held['total'],2) }}</div>@if(!empty($held['notes']))<div class="text-[10px] text-slate-500 mt-1 truncate">{{ $held['notes'] }}</div>@endif</div><div class="flex gap-2 shrink-0"><button wire:click="restoreHeldInvoice({{ $index }})" class="h-10 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-black">استرجاع</button><button wire:click="removeHeldInvoice({{ $index }})" class="h-10 px-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-black">حذف</button></div></div>
                @empty
                    <div class="py-20 text-center"><div class="text-3xl">📋</div><div class="mt-3 text-sm font-black text-slate-600">لا توجد فواتير معلقة</div></div>
                @endforelse
            </div>
        </div>
    </div>
@endif
