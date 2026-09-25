<div class="flex h-full min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="shrink-0 border-b border-slate-100 bg-slate-50 p-2.5">
        <div class="relative">
            <input wire:model="barcode" wire:keydown.enter.prevent="scanBarcode" autofocus type="text"
                placeholder="{{ $isReturnMode ? 'امسح باركود المرتجع هنا...' : 'امسح الباركود أو اكتب للبحث السريع...' }}"
                class="w-full rounded-xl border-2 {{ $isReturnMode ? 'border-rose-300 focus:border-rose-500' : 'border-indigo-200 focus:border-indigo-500' }} bg-white px-4 py-2.5 text-sm font-black text-slate-900 outline-none placeholder:text-slate-400">
            @if ($barcode)
                <button wire:click="$set('barcode', '')" class="absolute left-3 top-2.5 text-slate-400 hover:text-rose-600">✕</button>
            @endif
        </div>

        <div class="relative mt-2">
            <input wire:model.live.debounce.250ms="inlineSearchQuery" type="text" placeholder="بحث مباشر باسم المنتج أو الباركود..."
                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold focus:border-indigo-500 focus:outline-none">
            @if ($inlineSearchResults)
                <div class="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">
                    @foreach ($inlineSearchResults as $result)
                        <button wire:click="selectInlineProduct({{ $result['id'] }})" class="flex w-full items-center justify-between border-b border-slate-100 px-3 py-2 text-right hover:bg-indigo-50">
                            <span class="font-bold text-slate-800">{{ $result['name'] }}</span>
                            <span class="text-[10px] font-mono text-slate-500">{{ number_format($result['price'], 2) }} · مخزون {{ number_format($result['stock'], 0) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-auto">
        <table class="w-full text-right text-xs">
            <thead class="sticky top-0 z-10 bg-slate-100 text-slate-600 shadow-sm">
                <tr>
                    <th class="px-3 py-2">الصنف</th>
                    <th class="px-2 py-2 text-center">الكمية</th>
                    <th class="px-2 py-2 text-center">التكلفة</th>
                    <th class="px-2 py-2 text-center">السعر</th>
                    <th class="px-2 py-2 text-center">الإجمالي</th>
                    <th class="w-10 px-2 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($cart as $item)
                    @php
                        $belowCost = $item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0);
                    @endphp
                    <tr wire:key="pos-cart-{{ $item['id'] }}" class="{{ $item['quantity'] < 0 ? 'bg-rose-50' : ($belowCost ? 'bg-amber-50' : 'bg-white') }} hover:bg-indigo-50">
                        <td class="px-3 py-2">
                            <div class="font-black text-slate-900">{{ $item['name'] }}</div>
                            <div class="mt-0.5 flex gap-1 text-[9px] font-bold">
                                @if ($item['quantity'] < 0)<span class="text-rose-600">مرتجع</span>@endif
                                @if ($belowCost)<span class="text-amber-700">أقل من التكلفة</span>@endif
                            </div>
                        </td>
                        <td class="px-2 py-2 text-center">
                            <div class="inline-flex items-center overflow-hidden rounded-lg border border-slate-200 bg-white">
                                <button wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] - 1 }})" class="px-2 py-1.5 font-black text-rose-600 hover:bg-rose-50">−</button>
                                <span class="min-w-10 px-1 text-center font-mono font-black">{{ $item['quantity'] }}</span>
                                <button wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] + 1 }})" class="px-2 py-1.5 font-black text-emerald-600 hover:bg-emerald-50">＋</button>
                            </div>
                        </td>
                        <td class="px-2 py-2 text-center">
                            <input type="number" step="0.01" value="{{ $item['cost_price'] ?? 0 }}" wire:change="updateCostPrice({{ $item['id'] }}, $event.target.value)" class="w-20 rounded-lg border border-slate-200 bg-slate-50 px-1 py-1 text-center font-mono text-[11px] font-bold text-slate-600 focus:border-indigo-500 focus:outline-none">
                        </td>
                        <td class="px-2 py-2 text-center">
                            <input type="number" step="0.01" value="{{ $item['price'] }}" wire:change="updateUnitPrice({{ $item['id'] }}, $event.target.value)" class="w-20 rounded-lg border {{ $belowCost ? 'border-amber-400 bg-amber-50 text-amber-800' : 'border-slate-200 bg-slate-50 text-slate-800' }} px-1 py-1 text-center font-mono text-[11px] font-black focus:border-indigo-500 focus:outline-none">
                        </td>
                        <td class="px-2 py-2 text-center font-mono font-black {{ $item['subtotal'] < 0 ? 'text-rose-600' : 'text-indigo-700' }}">{{ number_format($item['subtotal'], 2) }}</td>
                        <td class="px-2 py-2 text-center">
                            <button wire:click="removeFromCart({{ $item['id'] }})" class="rounded-lg px-2 py-1 text-lg font-black text-rose-500 hover:bg-rose-50">×</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-24 text-center">
                            <div class="text-4xl opacity-30">🧾</div>
                            <div class="mt-2 text-sm font-black text-slate-400">الفاتورة فارغة</div>
                            <div class="mt-1 text-[11px] font-bold text-slate-300">امسح الباركود أو افتح قائمة الأصناف F10</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="shrink-0 border-t border-slate-200 bg-slate-50 p-2.5">
        <div class="grid grid-cols-12 gap-2">
            <div class="col-span-12 md:col-span-4">
                <label class="mb-1 block text-[10px] font-black text-slate-500">الخصم</label>
                <div class="flex gap-1">
                    <input wire:model.live.debounce.300ms="discount_amount" type="number" step="0.01" class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2 py-2 font-mono text-xs font-black focus:border-indigo-500 focus:outline-none">
                    <button wire:click="toggleDiscountType" class="rounded-lg border border-slate-200 bg-white px-2 text-[10px] font-black">{{ $discount_type === 'percentage' ? '%' : 'مبلغ' }}</button>
                </div>
            </div>
            <div class="col-span-12 md:col-span-4">
                <label class="mb-1 block text-[10px] font-black text-slate-500">إجمالي مخصص</label>
                <input wire:model.live.debounce.300ms="custom_final_total" type="number" step="0.01" placeholder="اتركه فارغاً للحساب التلقائي" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-2 font-mono text-xs font-black focus:border-indigo-500 focus:outline-none">
            </div>
            <div class="col-span-12 md:col-span-4">
                <label class="mb-1 block text-[10px] font-black text-slate-500">ملاحظات</label>
                <input wire:model.live="notes" type="text" placeholder="ملاحظة الفاتورة..." class="w-full rounded-lg border border-slate-200 bg-white px-2 py-2 text-xs font-bold focus:border-indigo-500 focus:outline-none">
            </div>
        </div>
    </div>

    <div class="grid shrink-0 grid-cols-3 gap-px overflow-hidden rounded-b-2xl bg-slate-200">
        <div class="bg-slate-900 p-3 text-center text-white">
            <div class="text-[9px] font-bold text-slate-400">المجموع</div>
            <div class="mt-1 font-mono text-base font-black">{{ number_format($this->subtotal, 2) }}</div>
        </div>
        <div class="bg-slate-900 p-3 text-center text-white">
            <div class="text-[9px] font-bold text-slate-400">{{ $this->total < 0 ? 'المسترد' : 'المطلوب' }}</div>
            <div class="mt-1 font-mono text-xl font-black text-amber-300">{{ number_format($this->amountDue, 2) }}</div>
        </div>
        <div class="bg-slate-900 p-3 text-center text-white">
            <div class="text-[9px] font-bold text-slate-400">{{ $this->remaining > 0 ? 'المتبقي' : 'الباقي' }}</div>
            <div class="mt-1 font-mono text-xl font-black {{ $this->remaining > 0 ? 'text-rose-300' : 'text-emerald-300' }}">{{ number_format($this->remaining > 0 ? $this->remaining : $this->change, 2) }}</div>
        </div>
    </div>
</div>
