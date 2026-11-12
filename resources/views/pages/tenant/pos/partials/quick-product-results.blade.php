<div data-pos-product-results class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table data-pos-product-grid class="w-full table-fixed border-collapse text-right text-[11px]">
        <thead class="sticky top-0 z-[1] bg-slate-800 text-white">
            <tr>
                <th class="w-[56%] px-2 py-2">الصنف / رقم الصنف / الباركود</th>
                <th class="w-[20%] px-1 py-2 text-center">سعر البيع</th>
                <th class="w-[24%] px-1 py-2 text-center">المتوفر</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($quickProducts as $product)
                <tr data-pos-product data-product-index="{{ $loop->index }}"
                    wire:key="pos-product-row-{{ $product['id'] }}"
                    @unless ($this->invoiceIsLocked()) wire:click="selectInlineProduct({{ $product['id'] }})" @endunless
                    @if ($this->invoiceIsLocked()) aria-disabled="true" tabindex="-1" @else role="button" tabindex="0" @endif
                    aria-label="إضافة {{ $product['name'] }} إلى الفاتورة"
                    wire:loading.attr="aria-busy" wire:target="productSearchQuery,loadQuickProducts,selectInlineProduct"
                    class="{{ $this->invoiceIsLocked() ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500' }}">
                    <td class="px-2 py-2">
                        <div class="whitespace-normal break-words font-black leading-4 text-slate-800">{{ $product['name'] }}</div>
                        <div class="mt-1 flex min-w-0 flex-wrap gap-x-2 gap-y-0.5 text-[9px] leading-3 text-slate-500">
                            <span>رقم: <bdi dir="ltr" class="font-mono">{{ $product['product_number'] ?? $product['id'] }}</bdi></span>
                            <span>باركود: <bdi dir="ltr" class="break-all font-mono">{{ $product['barcode_value'] ?: '—' }}</bdi></span>
                        </div>
                        @if (!empty($product['has_offer']))
                            <span class="mt-1 block whitespace-normal text-[9px] font-bold text-amber-700">عرض {{ $product['offer_quantity_value'] + 0 }} بـ {{ number_format($product['offer_price_value'], 2) }}</span>
                        @endif
                    </td>
                    <td class="break-all px-1 py-2 text-center font-mono font-black text-indigo-700">{{ number_format((float) $product['retail_price'], 2) }}</td>
                    <td class="px-1 py-2 text-center">@include('pages.tenant.pos.partials.stock-badge')</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="whitespace-normal px-3 py-10 text-center text-xs font-bold text-slate-400">
                        {{ trim($productSearchQuery) !== '' ? 'لم نجد صنفاً مطابقاً' : 'لا توجد أصناف' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
