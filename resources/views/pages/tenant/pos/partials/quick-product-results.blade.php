<div data-pos-product-results class="w-full overflow-hidden rounded-xl border border-slate-200 bg-white">
    <table data-pos-product-grid class="w-full table-auto border-collapse text-right text-[11px]">
        <thead class="sticky top-0 z-[1] bg-slate-800 text-white">
            <tr>
                {{-- The name column fits the longest name (capped), so price and stock sit right next to it; spare width goes to the last column. --}}
                <th class="w-[1%] whitespace-nowrap px-2 py-2">الصنف / رقم الصنف / الباركود</th>
                <th class="w-[1%] whitespace-nowrap px-3 py-2 text-center">سعر البيع</th>
                <th class="w-[1%] whitespace-nowrap px-3 py-2 text-center">المتوفر</th>
                <th class="w-full p-0" aria-hidden="true"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
            @forelse ($quickProducts as $product)
                @php
                    // Out of stock in this branch, or in all branches when stock is shared.
                    $isOutOfStock = (float) ($unifiedStock && isset($product['shared_stock_quantity']) ? $product['shared_stock_quantity'] : $product['stock_quantity']) <= 0;
                @endphp
                <tr data-pos-product data-product-index="{{ $loop->index }}" @if ($isOutOfStock) data-pos-out-of-stock @endif
                    wire:key="pos-product-row-{{ $product['id'] }}"
                    @unless ($this->invoiceIsLocked()) wire:click="selectInlineProduct({{ $product['id'] }})" @endunless
                    @if ($this->invoiceIsLocked()) aria-disabled="true" tabindex="-1" @else role="button" tabindex="0" @endif
                    aria-label="إضافة {{ $product['name'] }} إلى الفاتورة"
                    wire:loading.attr="aria-busy" wire:target="productSearchQuery,loadQuickProducts,selectInlineProduct"
                    class="{{ $isOutOfStock ? 'bg-rose-50/70' : '' }} {{ $this->invoiceIsLocked() ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500' }}">
                    <td class="px-2 py-2">
                        <div data-pos-product-name-cell class="w-max min-w-[10rem] max-w-[min(24rem,55vw)]">
                        <div class="whitespace-normal break-words font-black leading-4 {{ $isOutOfStock ? 'text-slate-500' : 'text-slate-800' }}">{{ $product['name'] }}@if ($isOutOfStock)<span class="ms-1.5 inline-block rounded-md bg-rose-100 px-1.5 py-0.5 align-middle text-[9px] font-black text-rose-700">نافد</span>@endif</div>
                        <div class="mt-1 flex min-w-0 flex-wrap gap-x-2 gap-y-0.5 text-[9px] leading-3 text-slate-500">
                            <span>رقم: <bdi dir="ltr" class="font-mono">{{ $product['product_number'] ?? $product['id'] }}</bdi></span>
                            <span>باركود: <bdi dir="ltr" class="break-all font-mono">{{ $product['barcode_value'] ?: '—' }}</bdi></span>
                        </div>
                        @if (!empty($product['has_offer']))
                            <span class="mt-1 block whitespace-normal text-[9px] font-bold text-amber-700">عرض {{ $product['offer_quantity_value'] + 0 }} بـ {{ number_format($product['offer_price_value'], 2) }}</span>
                        @endif
                        </div>
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-center font-mono font-black text-indigo-700">{{ number_format((float) $product['retail_price'], 2) }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-center">@include('pages.tenant.pos.partials.stock-badge')</td>
                    <td class="p-0" aria-hidden="true"></td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="whitespace-normal px-3 py-10 text-center text-xs font-bold text-slate-400">
                        {{ trim($productSearchQuery) !== '' ? 'لم نجد صنفاً مطابقاً' : 'لا توجد أصناف' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
