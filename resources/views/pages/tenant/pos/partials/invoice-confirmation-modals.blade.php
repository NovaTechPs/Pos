@if ($showClearInvoiceModal)
    <div class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-3" x-data x-trap.inert.noscroll="true" x-on:keydown.escape.prevent.stop="$wire.cancelInvoiceDialogs()">
        <section role="dialog" aria-modal="true" aria-labelledby="clear-invoice-title" x-on:keydown.stop class="w-full max-w-lg rounded-2xl border border-amber-200 bg-white p-6 shadow-2xl" dir="rtl">
            <h2 id="clear-invoice-title" class="text-lg font-black text-amber-800">تأكيد إفراغ الفاتورة</h2>
            <p class="mt-4 text-sm text-slate-700">هل تريد إفراغ الفاتورة الحالية وبدء فاتورة جديدة؟</p>
            <div class="mt-6 flex gap-3">
                <button type="button" wire:click="cancelInvoiceDialogs" class="flex-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700">إلغاء</button>
                <button type="button" wire:click="confirmClearCart" wire:loading.attr="disabled" class="flex-1 rounded-xl bg-rose-600 px-4 py-3 text-sm font-bold text-white">إفراغ الفاتورة</button>
            </div>
        </section>
    </div>
@endif

@if ($showHoldNotesModal)
    <div class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/60 p-3" x-data x-trap.inert.noscroll="true" x-on:keydown.escape.prevent.stop="$wire.cancelInvoiceDialogs()">
        <section role="dialog" aria-modal="true" aria-labelledby="hold-note-title" x-on:keydown.stop class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl" dir="rtl">
            <h2 id="hold-note-title" class="text-lg font-black text-slate-900">ملاحظة الفاتورة المعلقة</h2>
            <form wire:submit="confirmHoldInvoice">
                <label for="hold-invoice-note" class="mt-4 block text-sm text-slate-700">اكتب ملاحظة لتمييز الفاتورة في قائمة المعلقات</label>
                <textarea id="hold-invoice-note" wire:model="holdNotes" maxlength="500" rows="3" autofocus class="mt-2 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="اسم الزبون أو وصف الفاتورة..."></textarea>
                @error('holdNotes') <p role="alert" class="mt-2 text-sm text-rose-700">{{ $message }}</p> @enderror
                <div class="mt-6 flex gap-3">
                    <button type="button" wire:click="cancelInvoiceDialogs" class="flex-1 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-bold text-slate-700">إلغاء</button>
                    <button type="submit" wire:loading.attr="disabled" class="flex-1 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white">تعليق الفاتورة</button>
                </div>
            </form>
        </section>
    </div>
@endif
