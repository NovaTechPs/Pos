@if ($showCloseShiftModal)
    <div class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-sm p-4 flex items-center justify-center">
        <div class="w-full max-w-lg rounded-2xl bg-white shadow-2xl overflow-hidden border border-slate-200">
            <div class="p-5 bg-slate-900 text-white flex items-center justify-between"><div><h3 class="font-black">إغلاق الشيفت وتسوية الصندوق</h3><p class="text-[10px] text-slate-400 mt-1">قارن النقد الفعلي بالنقد المتوقع</p></div><button wire:click="$set('showCloseShiftModal', false)" class="w-9 h-9 rounded-lg bg-white/10">✕</button></div>
            <div class="p-5 space-y-4">
                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-xl bg-slate-50 border border-slate-200 p-3"><div class="text-[10px] text-slate-400 font-bold">رصيد البداية</div><div class="mt-1 font-mono font-black">{{ number_format($shift_opening_cash,2) }}</div></div>
                    <div class="rounded-xl bg-emerald-50 border border-emerald-100 p-3"><div class="text-[10px] text-emerald-600 font-bold">إجمالي المبيعات</div><div class="mt-1 font-mono font-black text-emerald-700">{{ number_format($shift_total_sales,2) }}</div></div>
                    <div class="rounded-xl bg-rose-50 border border-rose-100 p-3"><div class="text-[10px] text-rose-600 font-bold">إجمالي المرتجعات</div><div class="mt-1 font-mono font-black text-rose-700">{{ number_format($shift_total_returns,2) }}</div></div>
                    <div class="rounded-xl bg-amber-50 border border-amber-100 p-3"><div class="text-[10px] text-amber-700 font-bold">النقد المتوقع</div><div class="mt-1 font-mono font-black text-amber-800">{{ number_format($shift_expected_cash,2) }}</div></div>
                </div>
                <div><label class="block text-xs font-black text-slate-600 mb-2">النقد الفعلي بعد العد</label><input type="number" step="0.01" wire:model.live.debounce.300ms="actual_cash" class="w-full h-14 rounded-xl border border-slate-200 bg-slate-50 px-4 text-2xl font-black font-mono text-center outline-none focus:border-indigo-500"></div>
                @php $diff = (float)$actual_cash - $shift_expected_cash; @endphp
                <div class="rounded-xl p-3 text-center text-xs font-black {{ $diff == 0 ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : ($diff < 0 ? 'bg-rose-50 text-rose-700 border border-rose-100' : 'bg-amber-50 text-amber-700 border border-amber-100') }}">@if($diff == 0) الصندوق مطابق تمامًا @elseif($diff < 0) عجز: {{ number_format(abs($diff),2) }} @else زيادة: {{ number_format($diff,2) }} @endif</div>
                <textarea wire:model="shift_notes" rows="2" placeholder="ملاحظات الإغلاق (اختياري)" class="w-full rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs font-bold outline-none focus:border-indigo-500"></textarea>
                <button wire:click="closeShift" class="w-full h-12 rounded-xl bg-rose-600 hover:bg-rose-700 text-white text-sm font-black">تأكيد إغلاق الشيفت</button>
            </div>
        </div>
    </div>
@endif
