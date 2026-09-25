<div class="flex-1 min-h-0 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden flex flex-col">
    <div class="p-3 border-b border-slate-100 bg-white shrink-0">
        <div class="flex flex-col md:flex-row gap-2">
            <div class="relative flex-1">
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">▣</span>
                <input type="text" wire:model.live.debounce.180ms="inlineSearchQuery"
                    placeholder="ابحث باسم المنتج أو الباركود..."
                    class="w-full h-11 rounded-xl border {{ $isReturnMode ? 'border-rose-300 focus:ring-rose-100' : 'border-indigo-200 focus:ring-indigo-100' }} bg-slate-50 pr-10 pl-4 text-sm font-bold outline-none focus:ring-2 focus:border-indigo-500">
                @if ($inlineSearchQuery !== '')
                    <button type="button" wire:click="$set('inlineSearchQuery', '')" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-rose-600">✕</button>
                @endif
                @if (!empty($inlineSearchResults))
                    <div class="absolute right-0 left-0 top-12 z-30 rounded-xl border border-slate-200 bg-white shadow-xl overflow-hidden">
                        @foreach ($inlineSearchResults as $result)
                            <button type="button" wire:click="selectInlineProduct({{ $result['id'] }})" class="w-full p-3 text-right hover:bg-indigo-50 border-b border-slate-100 last:border-0 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-sm font-black text-slate-800 truncate">{{ $result['name'] }}</div>
                                    <div class="text-[10px] text-slate-400 font-mono">{{ $result['barcode_value'] ?? 'بدون باركود' }}</div>
                                </div>
                                <div class="text-left shrink-0">
                                    <div class="font-mono font-black text-indigo-700">{{ number_format((float) ($result['retail_price'] ?? 0), 2) }}</div>
                                    <div class="text-[10px] {{ ($result['stock_quantity'] ?? 0) > 0 ? 'text-emerald-600' : 'text-rose-600' }}">المخزون: {{ number_format((float) ($result['stock_quantity'] ?? 0), 0) }}</div>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="relative flex-[0.7]">
                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">▦</span>
                <input type="text" wire:model="barcode" wire:keydown.enter.prevent="scanBarcode" autofocus
                    placeholder="امسح الباركود ثم Enter"
                    class="w-full h-11 rounded-xl border {{ $isReturnMode ? 'border-rose-300 bg-rose-50/50 focus:ring-rose-100' : 'border-slate-200 bg-slate-50 focus:ring-indigo-100' }} pr-10 pl-4 text-sm font-bold font-mono outline-none focus:ring-2 focus:border-indigo-500">
            </div>
        </div>
        <div class="flex items-center justify-between mt-2 text-[10px] font-bold">
            <span class="text-slate-400">Enter لإضافة الباركود مباشرة • F10 لفتح قائمة الأصناف</span>
            @if ($isReturnMode)<span class="text-rose-600">وضع المرتجع مفعل — الكميات السالبة تعيد المخزون</span>@endif
        </div>
    </div>

    <div class="flex-1 min-h-0 overflow-auto">
        <table class="w-full text-right text-xs">
            <thead class="sticky top-0 z-10 bg-slate-50 border-b border-slate-200 text-slate-500 font-black">
                <tr>
                    <th class="px-4 py-3">الصنف</th>
                    <th class="px-2 py-3 text-center w-36">الكمية</th>
                    <th class="px-2 py-3 text-center w-28">التكلفة</th>
                    <th class="px-2 py-3 text-center w-28">سعر البيع</th>
                    <th class="px-4 py-3 text-center w-32">الإجمالي</th>
                    <th class="px-3 py-3 text-center w-14"> </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($cart as $item)
                    @php $isBelowCost = $item['quantity'] > 0 && (float)$item['price'] < (float)($item['cost_price'] ?? 0); @endphp
                    <tr wire:key="cart-item-{{ $item['id'] }}" class="group {{ $item['quantity'] < 0 ? 'bg-rose-50/70' : ($isBelowCost ? 'bg-amber-50/70' : 'hover:bg-slate-50') }}">
                        <td class="px-4 py-3">
                            <div class="font-black text-slate-800">{{ $item['name'] }}</div>
                            <div class="mt-1 flex items-center gap-1.5">
                                @if ($item['quantity'] < 0)<span class="px-1.5 py-0.5 rounded bg-rose-100 text-rose-700 text-[9px] font-black">مرتجع</span>@endif
                                @if ($isBelowCost)<span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-[9px] font-black">أقل من التكلفة</span>@endif
                                @if (!empty($item['barcode']))<span class="text-[9px] text-slate-400 font-mono">{{ $item['barcode'] }}</span>@endif
                            </div>
                        </td>
                        <td class="px-2 py-3 text-center">
                            <div class="inline-flex items-center rounded-xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                                <button type="button" wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] - 1 }})" class="w-8 h-9 text-rose-600 hover:bg-rose-50 font-black">−</button>
                                <span class="min-w-10 text-center font-mono font-black {{ $item['quantity'] < 0 ? 'text-rose-600' : 'text-slate-800' }}">{{ $item['quantity'] }}</span>
                                <button type="button" wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] + 1 }})" class="w-8 h-9 text-emerald-600 hover:bg-emerald-50 font-black">+</button>
                            </div>
                        </td>
                        <td class="px-2 py-3 text-center">
                            <input type="number" step="0.01" value="{{ $item['cost_price'] ?? 0 }}" wire:change="updateCostPrice({{ $item['id'] }}, $event.target.value)" class="w-24 h-9 rounded-lg border border-slate-200 bg-slate-50 text-center font-mono font-bold text-slate-600 outline-none focus:border-indigo-400 focus:bg-white">
                        </td>
                        <td class="px-2 py-3 text-center">
                            <input type="number" step="0.01" value="{{ $item['price'] }}" wire:change="updateUnitPrice({{ $item['id'] }}, $event.target.value)" class="w-24 h-9 rounded-lg border {{ $isBelowCost ? 'border-amber-400 bg-amber-50 text-amber-900' : 'border-slate-200 bg-slate-50 text-slate-800' }} text-center font-mono font-black outline-none focus:border-indigo-400 focus:bg-white">
                        </td>
                        <td class="px-4 py-3 text-center font-mono font-black {{ $item['subtotal'] < 0 ? 'text-rose-600' : 'text-indigo-700' }}">{{ number_format($item['subtotal'], 2) }}</td>
                        <td class="px-3 py-3 text-center"><button type="button" wire:click="removeFromCart({{ $item['id'] }})" class="w-8 h-8 rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600 font-black">✕</button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-24 text-center"><div class="w-16 h-16 mx-auto rounded-2xl bg-slate-50 flex items-center justify-center text-2xl">🛒</div><div class="mt-3 text-sm font-black text-slate-600">لا توجد أصناف في الفاتورة</div><div class="mt-1 text-xs text-slate-400">امسح الباركود أو ابحث عن المنتج للبدء</div></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="shrink-0 border-t border-slate-100 bg-slate-50 p-3 space-y-2">
        <div class="grid grid-cols-1 md:grid-cols-12 gap-2">
            <div class="md:col-span-4 flex items-center gap-2">
                <label class="text-xs font-black text-slate-600 shrink-0">الخصم</label>
                <input type="number" step="0.01" wire:model.live.debounce.300ms="discount_amount" placeholder="0" class="min-w-0 flex-1 h-9 rounded-lg border border-slate-200 bg-white px-3 font-mono font-black outline-none focus:border-indigo-400">
                <button type="button" wire:click="toggleDiscountType" class="h-9 px-3 rounded-lg border border-slate-200 bg-white text-xs font-black text-indigo-700">{{ $discount_type === 'percentage' ? '%' : 'مبلغ' }}</button>
            </div>
            <div class="md:col-span-4 flex items-center gap-2">
                <label class="text-xs font-black text-slate-600 shrink-0">الصافي</label>
                <input type="number" step="0.01" wire:model.live.debounce.300ms="custom_final_total" placeholder="{{ number_format($this->total, 2) }}" class="min-w-0 flex-1 h-9 rounded-lg border border-indigo-200 bg-white px-3 font-mono font-black text-indigo-700 outline-none focus:border-indigo-500">
            </div>
            <div class="md:col-span-4 flex items-center gap-2">
                <label class="text-xs font-black text-slate-600 shrink-0">ملاحظة</label>
                <input type="text" wire:model.live="notes" placeholder="ملاحظات الفاتورة..." class="min-w-0 flex-1 h-9 rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold outline-none focus:border-indigo-400">
            </div>
        </div>

        <div class="grid grid-cols-3 gap-2">
            <div class="rounded-xl bg-white border border-slate-200 p-2.5"><div class="text-[10px] text-slate-400 font-bold">المجموع الفرعي</div><div class="mt-0.5 text-base font-black font-mono text-slate-800">{{ number_format($this->subtotal, 2) }}</div></div>
            <div class="rounded-xl bg-white border border-slate-200 p-2.5"><div class="text-[10px] text-slate-400 font-bold">الخصم</div><div class="mt-0.5 text-base font-black font-mono text-rose-600">{{ number_format($this->calculated_discount, 2) }}</div></div>
            <div class="rounded-xl bg-slate-900 p-2.5"><div class="text-[10px] text-slate-400 font-bold">{{ $this->total < 0 ? 'المسترد للزبون' : 'الإجمالي المطلوب' }}</div><div class="mt-0.5 text-xl font-black font-mono {{ $this->total < 0 ? 'text-rose-400' : 'text-amber-300' }}">{{ number_format(abs($this->total), 2) }}</div></div>
        </div>
    </div>
</div>
