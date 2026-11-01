@if ($showExpenseModal)
    <div class="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm" dir="rtl">
        <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="bg-rose-600 p-4 text-white">
                <div class="text-sm font-black">تسجيل مصروف من الصندوق</div>
                <div class="mt-1 text-[10px] font-bold text-rose-100">يسجّل كصرف نقدي ضمن الشيفت ويُنقص المبلغ المتوقع في الدرج.</div>
            </div>
            <div class="space-y-3 p-4">
                <div>
                    <label class="mb-1 block text-xs font-black text-slate-700">نوع المصروف</label>
                    <select wire:model="expense_category" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-bold focus:border-rose-500 focus:outline-none">
                        <option>كهرباء</option><option>مياه</option><option>إيجار</option><option>نثريات</option><option>مواصلات</option><option>أخرى</option>
                    </select>
                    @error('expense_category') <div class="mt-1 text-[10px] font-bold text-rose-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-black text-slate-700">المبلغ النقدي</label>
                    <input wire:model="expense_amount" type="number" min="0.01" max="9999999999.99" step="0.01" inputmode="decimal" autofocus class="w-full rounded-xl border-2 border-rose-200 bg-white px-3 py-3 text-center font-mono text-xl font-black text-rose-700 focus:border-rose-500 focus:outline-none">
                    @error('expense_amount') <div class="mt-1 text-[10px] font-bold text-rose-600">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="mb-1 block text-xs font-black text-slate-700">ملاحظة (اختياري)</label>
                    <textarea wire:model="expense_notes" maxlength="5000" rows="2" placeholder="مثال: فاتورة كهرباء شهر تشرين الأول" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold focus:border-rose-500 focus:outline-none"></textarea>
                    @error('expense_notes') <div class="mt-1 text-[10px] font-bold text-rose-600">{{ $message }}</div> @enderror
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" wire:click="saveExpense" wire:loading.attr="disabled" wire:target="saveExpense" class="rounded-xl bg-rose-600 px-3 py-3 text-xs font-black text-white disabled:opacity-60">حفظ وخصم من الصندوق</button>
                    <button type="button" wire:click="$set('showExpenseModal', false)" class="rounded-xl bg-slate-100 px-3 py-3 text-xs font-black text-slate-700">إلغاء</button>
                </div>
            </div>
        </div>
    </div>
@endif
