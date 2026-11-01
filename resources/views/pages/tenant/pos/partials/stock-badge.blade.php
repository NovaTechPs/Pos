<span class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-slate-50 px-2 py-1 text-[9px] font-bold" data-pos-product-stock="{{ $product['id'] }}" title="مخزون فرعك{{ $unifiedStock ? ' / إجمالي جميع الفروع' : '' }}">
    <span class="{{ (float) $product['stock_quantity'] > 0 ? 'text-emerald-700' : 'text-rose-700' }}" dir="ltr">{{ rtrim(rtrim(number_format((float) $product['stock_quantity'], 3, '.', ','), '0'), '.') }}</span>
    @if ($unifiedStock)
        <span class="text-blue-700" data-pos-product-shared="{{ $product['shared_stock_quantity'] ?? '' }}">/ {{ isset($product['shared_stock_quantity']) ? rtrim(rtrim(number_format((float) $product['shared_stock_quantity'], 3, '.', ','), '0'), '.') : 'غير متاح' }}</span>
    @endif
</span>
