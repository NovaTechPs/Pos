<div class="lg:w-full rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden shrink-0" x-data="{ showNumpad: false }">
    <div class="px-4 py-3 bg-slate-900 text-white flex items-center justify-between">
        <div><div class="text-sm font-black">الدفع وإنهاء الفاتورة</div><div class="text-[10px] text-slate-400 mt-0.5">اختر طريقة الدفع ثم أدخل المبلغ</div></div>
        <span class="px-2 py-1 rounded-lg {{ $activeShift ? 'bg-emerald-500/15 text-emerald-300' : 'bg-rose-500/15 text-rose-300' }} text-[10px] font-black">{{ $activeShift ? 'الشيفت مفتوح' : 'الشيفت مغلق' }}</span>
    </div>

    <div class="p-4 space-y-4">
        <div class="grid grid-cols-2 gap-2">
            @foreach ([['cash','💵','نقدي'],['card','💳','بطاقة'],['bank_transfer','🏦','تحويل'],['cheque','🧾','شيك']] as [$value,$icon,$label])
                <button type="button" wire:click="$set('payment_method', '{{ $value }}')" class="h-12 rounded-xl border text-xs font-black flex items-center justify-center gap-2 transition {{ $payment_method === $value ? 'bg-indigo-600 border-indigo-600 text-white shadow-sm' : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50' }}">
                    <span>{{ $icon }}</span>{{ $label }}
                </button>
            @endforeach
        </div>

        <div class="rounded-2xl bg-slate-50 border border-slate-200 p-3">
            <div class="flex items-center justify-between mb-2"><label class="text-xs font-black text-slate-600">المبلغ المدفوع</label><button type="button" @click="showNumpad = !showNumpad" class="text-[10px] font-black text-indigo-600">لوحة الأرقام</button></div>
            <div class="relative">
                <input type="number" step="0.01" wire:model.live="paid_amount" @focus="showNumpad = true" class="w-full h-14 rounded-xl border border-slate-200 bg-white px-4 text-2xl font-black font-mono text-left text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-400 font-bold">المبلغ</span>
            </div>
            <div class="flex flex-wrap gap-1.5 mt-2">
                @foreach ([10,20,50,100] as $quick)
                    <button type="button" wire:click="$set('paid_amount', {{ $quick }})" class="flex-1 min-w-14 h-8 rounded-lg bg-white border border-slate-200 text-[11px] font-black font-mono hover:bg-indigo-50 hover:border-indigo-200">{{ $quick }}</button>
                @endforeach
                <button type="button" wire:click="$set('paid_amount', {{ max(0, $this->total) }})" class="flex-1 min-w-14 h-8 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-700 text-[11px] font-black">المطلوب</button>
            </div>
        </div>

        <div x-show="showNumpad" x-cloak @click.outside="showNumpad = false" class="grid grid-cols-3 gap-1.5">
            @foreach (['7','8','9','4','5','6','1','2','3','0','.','C'] as $num)
                <button type="button" wire:click="appendNumpad('{{ $num }}')" class="h-10 rounded-lg bg-slate-50 border border-slate-200 text-sm font-black font-mono hover:bg-slate-100">{{ $num }}</button>
            @endforeach
        </div>

        <div class="grid grid-cols-2 gap-2">
            <div class="rounded-xl bg-slate-50 border border-slate-200 p-3"><div class="text-[10px] text-slate-400 font-bold">المطلوب</div><div class="mt-1 text-lg font-black font-mono text-slate-900">{{ number_format(abs($this->total), 2) }}</div></div>
            <div class="rounded-xl border p-3 {{ $this->change >= 0 ? 'bg-emerald-50 border-emerald-200' : 'bg-rose-50 border-rose-200' }}"><div class="text-[10px] font-bold {{ $this->change >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ $this->change >= 0 ? 'الباقي' : 'المتبقي' }}</div><div class="mt-1 text-lg font-black font-mono {{ $this->change >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format(abs($this->change), 2) }}</div></div>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <button type="button" wire:click="checkout" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif class="h-12 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:bg-slate-100 disabled:text-slate-400 text-white text-xs font-black shadow-sm">💾 حفظ الفاتورة <span class="font-mono text-[10px]">F3</span></button>
            <button type="button" wire:click="checkoutAndPrint" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif class="h-12 rounded-xl {{ $this->total < 0 ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700' }} disabled:bg-slate-100 disabled:text-slate-400 text-white text-xs font-black shadow-sm">🖨️ {{ $this->total < 0 ? 'حفظ المرتجع' : 'حفظ وطباعة' }} <span class="font-mono text-[10px]">F6</span></button>
        </div>
        <button type="button" wire:click="clearCart" @if (count($cart) === 0) disabled @endif class="w-full h-10 rounded-xl bg-slate-100 hover:bg-slate-200 disabled:opacity-40 text-slate-600 text-xs font-black">تنظيف الفاتورة <span class="font-mono text-[10px]">F4</span></button>
    </div>
</div>
