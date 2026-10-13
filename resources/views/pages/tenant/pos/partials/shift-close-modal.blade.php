@if ($showCloseShiftModal)
    @php $difference = round((float) $actual_cash - (float) $shift_expected_cash, 2); @endphp
    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm">
        <div class="w-full max-w-lg overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="bg-slate-900 p-4 text-white">
                <div class="text-sm font-black">🔒 إغلاق الشيفت وتسوية الصندوق</div>
                <div class="mt-1 text-[10px] font-bold text-slate-400">راجع حركة النقد ثم أدخل المبلغ الفعلي الموجود في الدرج.</div>
            </div>
            <div class="space-y-3 p-4">
                @if ($errorMessage)
                    <div class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700">{{ $errorMessage }}</div>
                @endif
                <div class="grid grid-cols-2 gap-2">
                    <div class="rounded-xl bg-slate-50 p-3"><div class="text-[9px] font-bold text-slate-400">افتتاحي</div><div class="mt-1 font-mono font-black">{{ number_format($shift_opening_cash, 2) }}</div></div>
                    <div class="rounded-xl bg-emerald-50 p-3"><div class="text-[9px] font-bold text-emerald-600">قبض نقدي</div><div class="mt-1 font-mono font-black text-emerald-700">{{ number_format($shift_cash_receipts, 2) }}</div></div>
                    <div class="rounded-xl bg-rose-50 p-3"><div class="text-[9px] font-bold text-rose-600">صرف نقدي</div><div class="mt-1 font-mono font-black text-rose-700">{{ number_format($shift_cash_payments, 2) }}</div></div>
                    <div class="rounded-xl bg-indigo-50 p-3"><div class="text-[9px] font-bold text-indigo-600">مبيعات / مرتجعات</div><div class="mt-1 font-mono text-[11px] font-black text-indigo-700">{{ number_format($shift_total_sales, 2) }} / {{ number_format($shift_total_returns, 2) }}</div></div>
                </div>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-center">
                    <div class="text-[10px] font-black text-amber-700">المتوقع في الدرج</div>
                    <div class="mt-1 font-mono text-2xl font-black text-amber-800">{{ number_format($shift_expected_cash, 2) }}</div>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-black text-slate-700">المبلغ الفعلي</label>
                    <input wire:model.live.debounce.250ms="actual_cash" type="number" step="0.01" min="0" max="9999999999.99" inputmode="decimal" class="w-full rounded-xl border-2 border-slate-200 bg-slate-50 px-3 py-3 text-center font-mono text-xl font-black focus:border-indigo-500 focus:outline-none">
                    @error('actual_cash')
                        <div class="mt-1 text-[10px] font-bold text-rose-600">{{ $message }}</div>
                    @enderror
                </div>
                <div class="rounded-xl p-3 text-center text-xs font-black {{ $difference == 0 ? 'bg-emerald-100 text-emerald-800' : ($difference < 0 ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">
                    {{ $difference == 0 ? 'الصندوق مطابق تماماً ✓' : ($difference < 0 ? 'عجز: '.number_format(abs($difference),2) : 'زيادة: '.number_format($difference,2)) }}
                </div>
                <div>
                    <textarea wire:model="shift_notes" maxlength="5000" rows="2" placeholder="ملاحظات الإغلاق..." class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold focus:border-slate-500 focus:outline-none"></textarea>
                    @error('shift_notes')
                        <div class="mt-1 text-[10px] font-bold text-rose-600">{{ $message }}</div>
                    @enderror
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button wire:click="closeShift" wire:loading.attr="disabled" wire:target="closeShift" class="rounded-xl bg-rose-600 px-3 py-3 text-xs font-black text-white disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="closeShift">تأكيد الإغلاق</span>
                        <span wire:loading wire:target="closeShift">جارٍ إغلاق الشيفت...</span>
                    </button>
                    <button wire:click="cancelCloseShift" class="rounded-xl bg-slate-100 px-3 py-3 text-xs font-black text-slate-700">إلغاء</button>
                </div>
            </div>
        </div>
    </div>
@endif
