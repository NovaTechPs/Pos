<flux:modal wire:model="showCashReturnModal" class="md:w-[560px]" dir="rtl">
    <form wire:submit="saveCashReturn" class="space-y-4">
        <flux:heading size="lg">{{ $cashReturnOrderId ? 'تعديل مرتجع نقدي' : 'مرتجع نقدي' }}</flux:heading>
        @if ($errorMessage)
            <p role="alert" class="text-sm font-bold text-rose-700">{{ $errorMessage }}</p>
        @endif
        <flux:text>للزبون النقدي دون حساب أو فاتورة أصلية. أدخل السعر الذي دفعه الزبون، وحدد حالة الصنف.</flux:text>
        <flux:input wire:model.live.debounce.300ms="cashReturnSearch" label="الصنف أو الباركود" placeholder="ابحث عن الصنف المرتجع" />
        @if ($cashReturnSearch !== '' && !$cashReturnProductId)
            <div class="max-h-48 overflow-y-auto rounded-xl border border-slate-200">
                @forelse ($this->cashReturnProducts as $product)
                    <button type="button" wire:key="cash-return-product-{{ $product->id }}" wire:click="selectCashReturnProduct({{ $product->id }})" class="block w-full px-3 py-3 text-right text-sm hover:bg-slate-50">{{ $product->name }}</button>
                @empty
                    <p class="p-3 text-sm text-slate-500">لا توجد أصناف مطابقة في الفرع.</p>
                @endforelse
            </div>
        @endif
        @if ($cashReturnProductId)
            <flux:button type="button" size="sm" wire:click="$set('cashReturnProductId', null)">تغيير الصنف</flux:button>
        @endif
        <flux:error name="cashReturnProductId" />
        <div class="grid grid-cols-2 gap-3">
            <flux:input type="number" min="0.01" step="0.01" wire:model.live.debounce.800ms="cashReturnQuantity" label="الكمية المرتجعة" />
            <flux:input type="number" min="0.01" step="0.01" wire:model.live.debounce.800ms="cashReturnPrice" label="المبلغ المسترد للوحدة" />
        </div>
        <flux:select wire:model="cashReturnCondition" label="حالة الصنف">
            <flux:select.option value="resellable">سليم — يعود للمخزون</flux:select.option>
            <flux:select.option value="damaged">مكسور / تالف — لا يعود للمخزون</flux:select.option>
        </flux:select>
        <flux:input wire:model="cashReturnReason" label="سبب الإرجاع" placeholder="لم يعد يريده / مكسور / سبب آخر" />
        <div class="rounded-xl bg-rose-50 p-4 text-rose-800" role="status">
            <span class="text-sm font-bold">المبلغ المصروف للزبون</span>
            <strong class="block text-2xl tabular-nums">{{ number_format(max(0, (float) $cashReturnQuantity) * max(0, (float) $cashReturnPrice), 2) }}</strong>
        </div>
        <div class="flex justify-end gap-2">
            <flux:button type="button" wire:click="$set('showCashReturnModal', false)">إلغاء</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveCashReturn">{{ $cashReturnOrderId ? 'حفظ تعديل المرتجع' : 'تسجيل المرتجع وصرف المبلغ' }}</flux:button>
        </div>
        @if (!$cashReturnOrderId && auth()->user()?->can('sales_invoices.edit'))
            <details class="rounded-xl border border-slate-200 p-3">
                <summary class="cursor-pointer text-sm font-bold">المرتجعات النقدية الأخيرة — تعديل</summary>
                <div class="mt-2 space-y-2">
                    @forelse ($this->recentCashReturns as $cashReturn)
                        <button type="button" wire:key="cash-return-{{ $cashReturn->id }}" wire:click="editCashReturn({{ $cashReturn->id }})" class="flex w-full items-center justify-between gap-2 rounded-lg bg-slate-50 p-3 text-sm">
                            <span>#{{ $cashReturn->id }} · {{ $cashReturn->created_at->format('Y-m-d H:i') }}</span>
                            <strong>{{ number_format(abs((float) $cashReturn->total), 2) }}</strong>
                        </button>
                    @empty
                        <p class="text-sm text-slate-500">لا توجد مرتجعات نقدية.</p>
                    @endforelse
                </div>
            </details>
        @endif
    </form>
</flux:modal>
