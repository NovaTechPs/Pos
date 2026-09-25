@if ($showOpenShiftModal)
    <div class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm p-4 flex items-center justify-center">
        <div class="w-full max-w-md rounded-2xl bg-white shadow-2xl overflow-hidden border border-slate-200">
            <div class="p-5 bg-slate-900 text-white"><div class="flex items-center justify-between"><div><h3 class="font-black">فتح الشيفت</h3><p class="text-[10px] text-slate-400 mt-1">ابدأ الصندوق برصيد افتتاحي</p></div><button wire:click="$set('showOpenShiftModal', false)" class="w-9 h-9 rounded-lg bg-white/10">✕</button></div></div>
            <div class="p-5 space-y-4">
                <div class="rounded-xl bg-indigo-50 border border-indigo-100 p-4"><div class="text-[10px] text-indigo-500 font-bold">الكاشير</div><div class="mt-1 text-sm font-black text-indigo-900">{{ Auth::user()?->name }}</div></div>
                <div><label class="block text-xs font-black text-slate-600 mb-2">الرصيد الافتتاحي في الصندوق</label><div class="relative"><input type="number" step="0.01" wire:model="opening_cash" class="w-full h-14 rounded-xl border border-slate-200 bg-slate-50 px-4 text-2xl font-black font-mono text-center outline-none focus:border-indigo-500"><span class="absolute right-4 top-1/2 -translate-y-1/2 text-[10px] text-slate-400">المبلغ</span></div></div>
                <button wire:click="openShift" class="w-full h-12 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-black">فتح الشيفت وبدء العمل</button>
            </div>
        </div>
    </div>
@endif
