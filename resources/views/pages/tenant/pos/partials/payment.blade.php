<div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm" x-data="{showNumpad:false}">
    <div class="flex items-center justify-between">
        <div>
            <div class="text-xs font-black text-slate-900">الدفع</div>
            <div class="mt-0.5 text-[10px] font-bold text-slate-400">اختر الطريقة وأدخل المبلغ</div>
        </div>
        <span class="rounded-lg px-2 py-1 text-[10px] font-black {{ $isReturnMode ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">{{ $isReturnMode ? 'صرف مرتجع' : 'قبض بيع' }}</span>
    </div>

    <div class="mt-3 grid grid-cols-2 gap-1.5">
        @foreach ([['cash','نقدي','💵'],['card','بطاقة','💳'],['bank_transfer','تحويل','🏦'],['cheque','شيك','🧾']] as [$value,$label,$icon])
            <button wire:click="$set('payment_method', '{{ $value }}')" class="rounded-xl border px-2 py-2 text-xs font-black {{ $payment_method === $value ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100' }}">
                {{ $icon }} {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="mt-3">
        <div class="mb-1 flex items-center justify-between">
            <label class="text-[10px] font-black text-slate-500">المبلغ {{ $isReturnMode ? 'المصروف' : 'المدفوع' }}</label>
            <button @click="showNumpad = !showNumpad" class="text-[10px] font-black text-indigo-600">لوحة أرقام</button>
        </div>
        <input wire:model.live="paid_amount" @focus="showNumpad=true" type="number" step="0.01" class="w-full rounded-xl border-2 border-slate-200 bg-slate-50 px-3 py-3 text-left font-mono text-xl font-black text-indigo-800 focus:border-indigo-500 focus:outline-none">
        <div x-show="showNumpad" x-cloak @click.outside="showNumpad=false" class="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-2">
            <div class="grid grid-cols-3 gap-1">
                @foreach (['7','8','9','4','5','6','1','2','3','0','.','C'] as $num)
                    <button wire:click="appendNumpad('{{ $num }}')" class="rounded-lg bg-white py-2 text-xs font-black shadow-sm hover:bg-indigo-50">{{ $num }}</button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="mt-3 grid grid-cols-2 gap-2 text-center">
        <div class="rounded-xl bg-slate-50 p-2">
            <div class="text-[9px] font-bold text-slate-400">المطلوب</div>
            <div class="mt-1 font-mono text-sm font-black text-slate-900">{{ number_format($this->amountDue, 2) }}</div>
        </div>
        <div class="rounded-xl bg-slate-50 p-2">
            <div class="text-[9px] font-bold text-slate-400">الباقي</div>
            <div class="mt-1 font-mono text-sm font-black text-emerald-700">{{ number_format($this->change, 2) }}</div>
        </div>
    </div>

    <div class="mt-3 grid grid-cols-2 gap-2">
        <button wire:click="checkout" @disabled(empty($cart) || $currentInvoiceId) class="rounded-xl bg-slate-900 px-3 py-3 text-xs font-black text-white shadow-sm disabled:bg-slate-200 disabled:text-slate-400">حفظ <kbd class="mr-1 rounded bg-slate-700 px-1">F3</kbd></button>
        <button wire:click="checkoutAndPrint" @disabled(empty($cart) || $currentInvoiceId) class="rounded-xl {{ $isReturnMode ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700' }} px-3 py-3 text-xs font-black text-white shadow-sm disabled:bg-slate-200 disabled:text-slate-400">حفظ وطباعة <kbd class="mr-1 rounded bg-black/20 px-1">F6</kbd></button>
    </div>
    <button wire:click="clearCart" @disabled(empty($cart)) class="mt-2 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-black text-slate-600 hover:bg-slate-100 disabled:opacity-40">فاتورة جديدة / تنظيف F4</button>
</div>
