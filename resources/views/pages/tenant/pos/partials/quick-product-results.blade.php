<div data-pos-product-results class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
    <table data-pos-product-grid class="w-full min-w-[920px] border-collapse text-right text-[10px]">
        <thead class="sticky top-0 z-[1] bg-slate-800 text-white">
            <tr>
                <th class="px-2 py-2 text-center">#</th>
                <th class="px-2 py-2">رقم الصنف</th>
                <th class="px-2 py-2">اسم الصنف</th>
                <th class="px-2 py-2">الباركود</th>
                <th class="px-2 py-2 text-center">سعر البيع</th>
                <th class="px-2 py-2 text-center">المتوفر</th>
                <th class="px-2 py-2 text-center">الكمية</th>
                <th class="px-2 py-2 text-center">إضافة</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($quickProducts as $product)
                <tr wire:key="pos-product-row-{{ $product['id'] }}" class="hover:bg-indigo-50/70">
                    <td class="px-2 py-2 text-center font-mono text-slate-500">{{ $loop->iteration }}</td>
                    <td class="px-2 py-2 font-mono text-slate-600" dir="ltr">{{ $product['product_number'] ?? $product['id'] }}</td>
                    <td class="max-w-64 px-2 py-2">
                        <button type="button" data-pos-product data-product-index="{{ $loop->index }}"
                            wire:click="selectInlineProduct({{ $product['id'] }})"
                            wire:loading.attr="disabled" wire:target="productSearchQuery,loadQuickProducts,selectInlineProduct,addSearchedProduct"
                            @disabled($this->invoiceIsLocked())
                            class="block w-full truncate text-right text-[11px] font-black text-slate-800 hover:text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                            title="إضافة وحدة واحدة">
                            {{ $product['name'] }}
                        </button>
                        @if (!empty($product['has_offer']))
                            <span class="mt-1 block text-[9px] font-bold text-amber-700">عرض {{ $product['offer_quantity_value'] + 0 }} بـ {{ number_format($product['offer_price_value'], 2) }}</span>
                        @endif
                    </td>
                    <td class="px-2 py-2 font-mono text-slate-500" dir="ltr">{{ $product['barcode_value'] ?: '—' }}</td>
                    <td class="px-2 py-2 text-center font-mono font-black text-indigo-700">{{ number_format((float) $product['retail_price'], 2) }}</td>
                    <td class="px-2 py-2 text-center">
                        @include('pages.tenant.pos.partials.stock-badge')
                    </td>
                    <td class="px-2 py-2">
                        <input type="number" min="0.01" max="999999999.99" step="0.01"
                            wire:model="quickProductQuantities.{{ $product['id'] }}"
                            value="{{ $quickProductQuantities[$product['id']] ?? 1 }}"
                            aria-label="الكمية المطلوبة من {{ $product['name'] }}"
                            @disabled($this->invoiceIsLocked())
                            class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-center font-mono text-xs font-bold outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                    </td>
                    <td class="px-2 py-2 text-center">
                        <button type="button" wire:click="addSearchedProduct({{ $product['id'] }})"
                            wire:loading.attr="disabled" wire:target="productSearchQuery,loadQuickProducts,selectInlineProduct,addSearchedProduct"
                            @disabled($this->invoiceIsLocked())
                            class="rounded-lg bg-emerald-600 px-3 py-1.5 font-black text-white hover:bg-emerald-700 disabled:opacity-50">
                            إضافة
                        </button>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="py-16 text-center text-xs font-bold text-slate-400">
                        {{ trim($productSearchQuery) !== '' ? 'لم نجد صنفاً مطابقاً' : 'لا توجد أصناف' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
