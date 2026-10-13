@if ($showOpenShiftModal)
    <div class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm">
        <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="bg-emerald-600 p-4 text-white">
                <div class="text-sm font-black">🔓 فتح الشيفت</div>
                <div class="mt-1 text-[10px] font-bold text-emerald-100">الشيفت مرتبط بالمستخدم والفرع الحالي وسيبقى معروفاً بعد تحديث الصفحة.</div>
            </div>
            <div class="space-y-4 p-4">
                <div>
                    <label class="mb-1 block text-xs font-black text-slate-700">الرصيد الافتتاحي للصندوق</label>
                    <input wire:model="opening_cash" type="number" step="0.01" min="0" max="9999999999.99" inputmode="decimal" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-center font-mono text-xl font-black focus:border-emerald-500 focus:outline-none">
                    @error('opening_cash')
                        <div class="mt-1 text-[10px] font-bold text-rose-600">{{ $message }}</div>
                    @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-black text-slate-700">ملاحظة</label>
                    <textarea wire:model="shift_notes" maxlength="5000" rows="2" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold focus:border-emerald-500 focus:outline-none"></textarea>
                    @error('shift_notes')
                        <div class="mt-1 text-[10px] font-bold text-rose-600">{{ $message }}</div>
                    @enderror
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button wire:click="openShift" wire:loading.attr="disabled" wire:target="openShift" class="rounded-xl bg-emerald-600 px-3 py-3 text-xs font-black text-white disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="openShift">بدء الشيفت</span>
                        <span wire:loading wire:target="openShift">جارٍ فتح الشيفت...</span>
                    </button>
                    <button wire:click="cancelOpenShift" class="rounded-xl bg-slate-100 px-3 py-3 text-xs font-black text-slate-700">إلغاء</button>
                </div>
            </div>
        </div>
    </div>
@endif
