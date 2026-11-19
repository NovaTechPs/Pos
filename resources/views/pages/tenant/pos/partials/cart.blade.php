<div id="pos-cart-panel" class="pos-cart flex min-w-0 min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    @php $invoiceLocked = $this->invoiceIsLocked(); @endphp
    @if ($isReturnMode && $errors->any())
        <div role="alert" class="shrink-0 bg-rose-50 p-3 text-sm font-bold text-rose-700">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    {{-- =========================================================
        Search
    ========================================================== --}}
    <div class="pos-cart-search shrink-0 border-b border-slate-100 bg-slate-50 p-2.5">

        {{-- Barcode --}}
        <div class="pos-barcode-entry relative flex items-center gap-2" x-data="posBarcodeCamera()" data-pos-barcode-entry>

            <button type="button" data-pos-scanner-button @disabled($invoiceLocked) aria-label="قراءة الباركود بالقارئ" title="قراءة الباركود بالقارئ"
                x-on:click="const input = $refs.barcode; input.setAttribute('inputmode', 'none'); input.focus(); input.addEventListener('blur', () => input.setAttribute('inputmode', 'search'), { once: true })"
                class="pos-scanner-button h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 disabled:opacity-50">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" class="size-5" aria-hidden="true">
                    <path d="M3 8V5h3M18 5h3v3M21 16v3h-3M6 19H3v-3" stroke-width="1.6" stroke-linejoin="round" />
                    <path d="M7 8v8M10 8v8M15 8v8" stroke-width="1.4" /><path d="M12.5 8v8M17.5 8v8" stroke-width="2.2" />
                </svg>
            </button>

            <button data-pos-camera-button type="button" @disabled($invoiceLocked) x-on:click="start()" aria-label="قراءة الباركود بالكاميرا" title="قراءة الباركود بالكاميرا" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-indigo-200 bg-white text-indigo-600 hover:bg-indigo-50 disabled:opacity-50">
                <flux:icon.camera class="size-5" />
            </button>

            <x-barcode-camera-dialog />

            <div class="pos-barcode-field relative flex min-w-0 flex-1 items-center">
            <flux:icon.magnifying-glass class="pos-barcode-magnifier pointer-events-none absolute size-5 text-slate-400" />
            <input x-ref="barcode" wire:keydown.enter.prevent.stop="scanBarcode($event.target.value)"
                x-data="{ mobileSearch: window.matchMedia('(max-width: 1023px)').matches, searchTimer: null }"
                x-on:resize.window="mobileSearch = window.matchMedia('(max-width: 1023px)').matches"
                x-bind:data-pos-mobile-search="mobileSearch ? 'true' : 'false'"
                x-bind:inputmode="mobileSearch ? 'search' : 'none'"
                x-bind:placeholder="mobileSearch ? 'ابحث عن صنف أو امسح الباركود…' : '{{ $isReturnMode ? 'امسح باركود المرتجع هنا...' : 'امسح الباركود · للكمية اكتب 3* ثم امسح' }}'"
                x-on:input="if (mobileSearch) { clearTimeout(searchTimer); searchTimer = setTimeout(() => $wire.set('productSearchQuery', $el.value), 300) }"
                x-on:pos-unified-submitted="clearTimeout(searchTimer)"
                x-on:pos-mobile-search-ready.window="if (mobileSearch &amp;&amp; $event.detail.componentId === $wire.$id &amp;&amp; !$el.value) $el.value = $event.detail.query"
                x-on:pos-search-cleared.window="if (mobileSearch &amp;&amp; $event.detail.componentId === $wire.$id) { clearTimeout(searchTimer); $el.value = '' }"
                aria-label="البحث عن صنف أو قراءة الباركود"
                x-on:pos-barcode-handled.window="if ($event.detail.componentId === $wire.$id &amp;&amp; $el.value.trim() === $event.detail.barcode) $el.value = ''"
                data-pos-barcode-input data-pos-component-id="{{ $this->getId() }}" @disabled($invoiceLocked) type="text"
                autocomplete="off" inputmode="none"
                placeholder="{{ $isReturnMode ? 'امسح باركود المرتجع هنا...' : 'امسح الباركود · للكمية اكتب 3* ثم امسح' }}"
                class="w-full min-w-0 flex-1 rounded-xl border-2
                    {{ $isReturnMode ? 'border-rose-300 focus:border-rose-500' : 'border-indigo-200 focus:border-indigo-500' }}
                    bg-white px-4 py-2.5 text-sm font-black text-slate-900
                    outline-none placeholder:text-slate-400">
            </div>

            <button type="button" data-pos-add-button @disabled($invoiceLocked) aria-label="إضافة" x-on:click="if (window.matchMedia('(max-width: 1023px)').matches) { if (!$refs.barcode.value.trim()) { $wire.set('showProductsModal', true); return } $refs.barcode.dispatchEvent(new Event('pos-unified-submitted')); const value = $refs.barcode.value; $refs.barcode.value = ''; $wire.submitMobileProductSearch(value) } else { $wire.scanBarcode($refs.barcode.value) }"
                wire:loading.attr="disabled" wire:target="scanBarcode" class="pos-add-button h-11 shrink-0 rounded-xl bg-indigo-600 px-3 text-xs font-bold text-white disabled:opacity-50"><span class="pos-add-label">إضافة</span><flux:icon.plus class="pos-add-icon size-6" /></button>

            @if ($barcode)
                <button type="button" wire:click="$set('barcode', '')"
                    class="shrink-0 text-slate-400 hover:text-rose-600">
                    ✕
                </button>
            @endif

        </div>
        <div wire:loading.delay wire:target="scanBarcode" role="status" class="mt-1 text-xs text-indigo-600">جارٍ قراءة الباركود…</div>

        <div data-pos-mobile-search-results class="pos-mobile-search mt-2">
            @error('productPriceSearch')<p role="alert" class="text-xs text-rose-600">{{ $message }}</p>@enderror
            @if(trim($productSearchQuery) !== '' || trim($productPriceSearch) !== '')
                <div class="mt-2 flex items-center justify-between text-xs"><strong>نتائج البحث</strong><button type="button" wire:click="clearMobileProductSearch" class="p-2 text-indigo-600">مسح البحث</button></div>
                <div class="max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white" aria-label="نتائج بحث الأصناف">
                    @forelse($quickProducts as $product)
                        <button type="button" wire:key="mobile-result-{{ $product['id'] }}" wire:click="selectInlineProduct({{ $product['id'] }})" @disabled($invoiceLocked)
                            class="flex min-h-14 w-full items-center justify-between gap-3 border-b border-slate-100 p-3 text-right hover:bg-indigo-50">
                            <span class="min-w-0"><strong class="block break-words text-xs">{{ $product['name'] }}</strong><small class="text-slate-500">{{ $product['barcode_value'] ?: $product['product_number'] }} · المتوفر {{ $product['stock_quantity'] + 0 }}</small></span>
                            <b class="shrink-0 text-sm text-indigo-700">{{ number_format($product['retail_price'], 2) }}</b>
                        </button>
                    @empty<p class="p-4 text-center text-xs text-slate-500">لم نجد صنفاً مطابقاً</p>@endforelse
                </div>
            @endif
        </div>

    </div>


    {{-- =========================================================
        Cart
    ========================================================== --}}
    <div class="pos-cart-options shrink-0 border-b border-slate-100 bg-white px-3 py-2">
        <div class="pos-cart-options-row flex items-center justify-between gap-3" dir="rtl">
            <div data-pos-price-dropdown class="pos-price-dropdown relative" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false">
                <button type="button" x-on:click="open = !open; open && $nextTick(() => $refs.mobilePrice.focus())" x-bind:aria-expanded="open" aria-controls="pos-mobile-price-{{ $this->getId() }}"
                    class="flex h-9 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 text-[11px] font-bold text-slate-700">
                    <span class="truncate">{{ trim($productPriceSearch) !== '' ? 'السعر: '.$productPriceSearch : 'سعر البيع — اختياري' }}</span>
                    <flux:icon.chevron-down class="size-4 shrink-0" />
                </button>
                <div id="pos-mobile-price-{{ $this->getId() }}" x-show="open" x-cloak class="absolute top-full z-30 mt-1 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-lg" style="inset-inline-start: 0">
                    <label class="block text-[10px] font-bold text-slate-600">سعر البيع — اختياري
                        <input x-ref="mobilePrice" type="text" inputmode="decimal" maxlength="12" dir="ltr" wire:model.live.debounce.300ms="productPriceSearch" @disabled($invoiceLocked)
                            x-on:keydown.enter.prevent="open = false"
                            aria-label="البحث بسعر البيع على الجوال" placeholder="75.00" class="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-base">
                    </label>
                    @if (trim($productPriceSearch) !== '')
                        <button type="button" wire:click="$set('productPriceSearch', '')" x-on:click="open = false" class="mt-2 w-full rounded-lg border border-slate-200 py-2 text-xs font-bold text-slate-600">مسح السعر</button>
                    @endif
                </div>
            </div>
            <div class="pos-cart-options-text">
                <div class="pos-cart-options-labels flex items-center gap-2 text-xs font-black text-slate-800">
                    <span class="pos-merge-label">تجميع الأصناف المتشابهة</span>
                    <span data-pos-quantity-chip
                        class="pos-quantity-chip rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-black text-indigo-600">
                        الكمية: {{ collect($cart)->sum(fn($item) => abs((float) ($item['quantity'] ?? 0))) }}
                    </span>
                </div>
                <div class="pos-merge-hint text-[10px] font-bold text-slate-400">
                    {{ $isReturnMode ? 'كل صنف مرتجع في سطر مستقل لتحديد حالته' : ($mergeSimilarProducts ? 'الصنف المتكرر يجمع في سطر واحد' : 'كل إضافة تظهر كسطر مستقل') }}
                </div>
            </div>

            <button type="button" wire:click="toggleMergeSimilarProducts" role="switch" @disabled($invoiceLocked || $isReturnMode)
                aria-checked="{{ $mergeSimilarProducts ? 'true' : 'false' }}" aria-label="تجميع الأصناف المتشابهة"
                class="pos-merge-switch relative h-7 w-12 shrink-0 rounded-full transition
                    {{ $mergeSimilarProducts ? 'bg-indigo-600' : 'bg-slate-300' }}"
                title="{{ $mergeSimilarProducts ? 'إيقاف التجميع' : 'تشغيل التجميع' }}">
                <span
                    class="absolute top-1 h-5 w-5 rounded-full bg-white shadow-sm transition
                    {{ $mergeSimilarProducts ? 'right-1' : 'right-6' }}"></span>
            </button>
        </div>
    </div>

    <div data-pos-mobile-cart-head class="pos-mobile-cart-head" dir="rtl">
        <div class="flex items-center justify-between gap-2">
            <h3 class="flex items-center gap-2 text-sm font-black text-slate-900">
                <flux:icon.shopping-cart class="size-5 text-slate-700" />
                <span>الأصناف ({{ count($cart) }})</span>
            </h3>
            <button type="button" wire:click="clearCart" @disabled(empty($cart)) aria-label="تفريغ الفاتورة" title="تفريغ الفاتورة"
                class="flex size-9 items-center justify-center rounded-lg text-slate-600 hover:bg-rose-50 hover:text-rose-600 disabled:opacity-40">
                <flux:icon.trash class="size-5" />
            </button>
        </div>
        @if (count($cart))
            <div class="pos-mobile-cart-columns" aria-hidden="true">
                <span></span><span>الكمية</span><span>السعر</span><span>الإجمالي</span><span></span>
            </div>
        @endif
    </div>

    <div data-pos-cart-scroll class="pos-cart-scroll min-h-0 flex-1 overflow-auto">

        <table class="pos-cart-table w-full text-right text-xs" dir="rtl">

            <thead class="sticky top-0 z-10 bg-slate-100 text-slate-600 shadow-sm">

                <tr>

                    {{-- عداد الصنف --}}
                    <th class="w-12 px-2 py-2 text-center">
                        #
                    </th>
                    {{-- رقم الصنف ID --}}
                    <th class="w-16 px-2 py-2 text-center">
                        رقم المنتج
                    </th>

                    {{-- الصنف --}}
                    <th class="px-3 py-2">
                        الصنف
                    </th>

                    {{-- الكمية --}}
                    <th class="px-2 py-2 text-center">
                        الكمية
                    </th>

                    {{-- السعر --}}
                    <th class="px-2 py-2 text-center">
                        السعر
                    </th>

                    <th class="px-2 py-2 text-center">المتبقي</th>

                    {{-- الإجمالي --}}
                    <th class="px-2 py-2 text-center">
                        الإجمالي
                    </th>

                    {{-- حذف --}}
                    <th class="w-10 px-2 py-2"></th>

                </tr>

            </thead>


            <tbody class="divide-y divide-slate-300">

                @php
                    $productOccurrences = [];
                    $productColorKeys = [];
                    foreach ($cart as $cartItem) {
                        $colorKey = app(\App\Services\PosProductSearch::class)->normalize((string) ($cartItem['name'] ?? ''));
                        if (!array_key_exists($colorKey, $productColorKeys)) {
                            $productColorKeys[$colorKey] = count($productColorKeys);
                        }
                    }
                    $productColors = [];
                    foreach ($productColorKeys as $colorKey => $colorIndex) {
                        $productColors[$colorKey] = 70 + ($colorIndex * 210) / max(1, count($productColorKeys));
                    }
                @endphp
                @forelse ($cart as $lineKey => $item)
                    @php
                        $productIdentity = (int) $item['id'];
                        $productOccurrences[$productIdentity] = ($productOccurrences[$productIdentity] ?? 0) + 1;
                        $productOccurrence = $productOccurrences[$productIdentity];
                        $productColorKey = app(\App\Services\PosProductSearch::class)->normalize((string) ($item['name'] ?? ''));
                        $productHue = $productColors[$productColorKey];
                        $belowCost =
                            empty($item['is_gift']) && $item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0);
                    @endphp

                    <tr data-pos-cart-line data-row-index="{{ $loop->index }}" tabindex="0" wire:key="pos-cart-{{ $lineKey }}"
                        @if ($item['quantity'] < 0) data-pos-return-line @endif
                        @if (! $mergeSimilarProducts) data-pos-product-color="{{ $productIdentity }}" style="--pos-cart-hue: {{ $productHue }}" @endif
                        class="{{ ! $mergeSimilarProducts ? 'pos-cart-product-row' : (empty($item['is_gift']) && ($item['offer_quantity'] ?? 0) > 0 && isset($item['offer_price']) && $item['offer_price'] < $item['offer_quantity'] * $item['price'] ? 'bg-amber-50' : ($loop->odd ? 'bg-white' : 'bg-slate-50')) }} hover:bg-indigo-50">
                        {{-- Row Number --}}
                        <td class="px-2 py-2 text-center">
                            <span
                                class="pos-line-number inline-flex min-w-[28px] items-center justify-center
                                    rounded-lg border border-indigo-100 bg-indigo-50
                                    px-2 py-1.5 font-mono text-[11px] font-black text-indigo-700">
                                {{ $loop->iteration }}
                            </span>
                            <span class="pos-line-icon" aria-hidden="true"><flux:icon.cube class="size-4" /></span>
                        </td>

                        {{-- Product ID --}}
                        <td class="px-2 py-2 text-center">
                            <span
                                class="inline-flex min-w-[42px] items-center justify-center
                                    rounded-lg border border-slate-200 bg-slate-50
                                    px-2 py-1.5 font-mono text-[11px] font-black text-slate-600">
                                {{ $item['product_number'] ?? '—' }}
                            </span>
                        </td>

                        {{-- Product --}}
                        <td class="px-3 py-2">
                            <div class="font-black text-slate-900 {{ ! $mergeSimilarProducts ? 'dark:text-slate-100' : '' }}">
                                {{ $item['name'] }}
                                <button type="button" wire:click="$dispatch('show-product-history', { productId: {{ $item['id'] }} })" class="rounded px-2 py-1 text-xs text-indigo-700">حركة الصنف</button>
                                <button type="button" data-pos-price-history wire:click="$dispatch('open-product-price-history', { productId: {{ $item['id'] }}, customerId: {{ $selectedCustomerId ?? 'null' }} })" title="آخر سعر للزبون وأسعار كل الزبائن والتكلفة" class="rounded px-2 py-1 text-xs font-bold text-emerald-700 hover:bg-emerald-50">الأسعار</button>
                                @if (! empty($item['customer_price']))
                                    <span data-pos-customer-price class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">آخر سعر للزبون</span>
                                @endif
                                @if (! $mergeSimilarProducts)
                                    <span data-pos-product-occurrence="{{ $productOccurrence }}" class="pos-cart-occurrence"
                                        aria-label="تكرار الصنف في السلة: {{ $productOccurrence }}">{{ $productOccurrence }}</span>
                                @endif
                                @if (! empty($item['is_gift']))
                                    <span class="rounded bg-fuchsia-100 px-2 py-0.5 text-xs text-fuchsia-700">هدية</span>
                                @endif
                                @include('pages.tenant.pos.partials.expiry-badge', ['productId' => (int) $item['id']])
                            </div>

                            <div class="mt-0.5 flex gap-1 text-[9px] font-bold">
                                @if ($item['quantity'] < 0)
                                    <span class="font-bold text-rose-700">
                                        مرتجع
                                    </span>
                                @endif

                                @if ($belowCost)
                                    <span class="text-amber-700">
                                        أقل من التكلفة
                                    </span>
                                @endif
                            </div>
                            @if ($isReturnMode)
                                <label class="mt-2 flex items-center gap-2 text-xs font-bold text-rose-700">
                                    <input type="checkbox" wire:model.live="cart.{{ $lineKey }}.returned_to_stock" @checked($item['returned_to_stock'] ?? true) @disabled($invoiceLocked) class="rounded border-rose-300 text-rose-600">
                                    {{ ($item['returned_to_stock'] ?? true) ? 'سليم — يعود للمخزون' : 'تالف — لا يعود للمخزون' }}
                                </label>
                            @endif
                        </td>

                        {{-- Quantity --}}
                        <td class="px-2 py-2 text-center">
                            @php $displayQuantity = abs((float) $item['quantity']); @endphp
                            <div
                                class="pos-quantity-stepper inline-flex items-center overflow-hidden
                                    rounded-lg border border-slate-200 bg-white">
                                <button type="button" data-pos-quantity-step="up" @disabled($invoiceLocked)
                                    wire:click="updateCartField('{{ $lineKey }}', 'quantity', '{{ $displayQuantity + 1 }}')"
                                    aria-label="زيادة كمية {{ $item['name'] }}" class="pos-quantity-step">
                                    <flux:icon.plus class="size-4" />
                                </button>
                                <input type="number" step="0.01" min="0.01"
                                    value="{{ $isReturnMode ? abs((float) $item['quantity']) : $item['quantity'] }}" data-pos-field="quantity"
                                    data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}" data-row-index="{{ $loop->index }}"
                                    wire:change="updateCartField('{{ $lineKey }}', 'quantity', $event.target.validity.badInput ? 'invalid' : $event.target.value)" @disabled($invoiceLocked)
                                    x-on:pos-restore-cart-field.window="if ($event.detail.lineKey === $el.dataset.lineKey && $event.detail.field === 'quantity') { $el.value = $el.defaultValue; $el.select(); }"
                                    class="pos-cart-field w-16 border-0 bg-transparent px-1 py-1.5 text-center font-mono font-black text-slate-900 outline-none focus:ring-0"
                                    inputmode="decimal" autocomplete="off">
                                <button type="button" data-pos-quantity-step="down" @disabled($invoiceLocked || $displayQuantity <= 1)
                                    wire:click="updateCartField('{{ $lineKey }}', 'quantity', '{{ $displayQuantity - 1 }}')"
                                    aria-label="إنقاص كمية {{ $item['name'] }}" class="pos-quantity-step">
                                    <flux:icon.minus class="size-4" />
                                </button>
                            </div>
                        </td>

                        {{-- Selling Price --}}
                        <td class="px-2 py-2 text-center">
                            @can('pos.price.edit')
                                <input type="number" step="0.01" min="0" value="{{ $item['price'] }}"
                                    data-pos-field="price" data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}"
                                    data-row-index="{{ $loop->index }}"
                                    wire:change="updateCartField('{{ $lineKey }}', 'price', $event.target.value)"
                                    @disabled($invoiceLocked || ! empty($item['is_gift']))
                                    class="pos-cart-field w-20 rounded-lg border {{ $belowCost ? 'border-amber-400 bg-amber-50 text-amber-800' : 'border-slate-200 bg-slate-50 text-slate-800' }} px-1 py-1 text-center font-mono text-[11px] font-black focus:border-indigo-500 focus:outline-none"
                                    inputmode="decimal" autocomplete="off">
                            @else
                                <span data-pos-field="price" class="font-mono font-black">{{ number_format((float) $item['price'], 2) }}</span>
                            @endcan
                        </td>

                        {{-- Subtotal --}}
                        @php
                            $sharedRemainingQuantity = $remainingStock[(int) $item['id']] ?? null;
                            $remainingQuantity = $unifiedStock ? ($localRemainingStock[(int) $item['id']] ?? null) : $sharedRemainingQuantity;
                        @endphp
                        <td class="px-2 py-2 text-center" data-pos-stock-remaining="{{ $item['id'] }}"
                            data-remaining-quantity="{{ $remainingQuantity }}"
                            title="{{ $invoiceLocked ? 'المخزون الحالي:' : 'المتبقي بعد الفاتورة:' }}">
                            <span class="inline-flex items-center justify-center rounded-md px-2 py-1 font-mono text-[11px] font-bold {{ $remainingQuantity === null ? 'bg-slate-50 text-slate-600' : ($remainingQuantity > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700') }}" dir="ltr">
                                {{ $remainingQuantity === null ? 'غير متاح' : rtrim(rtrim(number_format($remainingQuantity, 3, '.', ','), '0'), '.') }}
                            </span>
                            @if ($unifiedStock)
                                <span class="inline-block text-[9px] text-blue-700" data-pos-shared-remaining="{{ $item['id'] }}" data-shared-quantity="{{ $sharedRemainingQuantity }}">
                                    / {{ $sharedRemainingQuantity === null ? 'غير متاح' : rtrim(rtrim(number_format($sharedRemainingQuantity, 3, '.', ','), '0'), '.') }}
                                    <span class="block">كل الفروع</span>
                                </span>
                            @endif
                        </td>

                        <td class="px-2 py-2 text-center">
                            @php
                                $lineSavings = (float) ($item['promotion_savings'] ?? 0);
                                $normalTotal = (float) ($item['normal_total'] ?? ((float) ($item['quantity'] ?? 0) * (float) ($item['price'] ?? 0)));
                                $hasPromotion = $lineSavings > 0.000001;
                                $offerQty = (float) ($item['offer_quantity'] ?? 0);
                                $offerPrice = $item['offer_price'] ?? null;
                            @endphp

                            <div class="flex min-w-[118px] flex-col items-center leading-tight">
                                <div class="relative">
                                    @can('pos.price.edit')
                                        <input type="number" step="0.01" value="{{ $item['subtotal'] }}"
                                            data-pos-field="subtotal" data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}"
                                            data-row-index="{{ $loop->index }}"
                                            wire:change="updateCartField('{{ $lineKey }}', 'subtotal', $event.target.value)"
                                            @disabled($invoiceLocked || ! empty($item['is_gift']))
                                            class="pos-cart-field w-24 rounded-lg border px-1 py-1 text-center font-mono text-[11px] font-black focus:border-indigo-500 focus:outline-none {{ $item['subtotal'] < 0 ? 'border-rose-300 bg-rose-50 text-rose-700' : ($hasPromotion ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-slate-50 text-indigo-700') }}"
                                            inputmode="decimal" autocomplete="off">
                                    @else
                                        <span data-pos-field="subtotal" class="font-mono font-black">{{ number_format((float) $item['subtotal'], 2) }}</span>
                                    @endcan
                                </div>

                                @if ($hasPromotion)
                                    <div class="mt-1 flex flex-col items-center">
                                        <span class="text-[9px] font-bold text-slate-400 line-through">
                                            {{ number_format(abs($normalTotal), 2) }}
                                        </span>

                                        <span class="mt-0.5 inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[9px] font-black text-emerald-700">
                                            <span>✓</span>
                                            وفّرت {{ number_format($lineSavings, 2) }}
                                        </span>

                                        @if ($offerQty > 0 && $offerPrice !== null)
                                            <span class="mt-0.5 text-[8px] font-bold text-emerald-600">
                                                عرض {{ rtrim(rtrim(number_format($offerQty, 2), '0'), '.') }} بـ {{ number_format(abs((float) $offerPrice), 2) }}
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </td>

                        {{-- Remove --}}
                        <td class="px-2 py-2 text-center">
                            @can('products.edit')
                                <button
                                    type="button"
                                    wire:click="editCartProduct({{ $item['id'] }})"
                                    class="rounded-lg px-2 py-1 text-indigo-600 hover:bg-indigo-50"
                                    title="تعديل الصنف أو إضافة باركود"
                                    aria-label="تعديل {{ $item['name'] }}"
                                >
                                    <flux:icon name="pencil-square" class="size-4" />
                                </button>
                            @endcan

                            <button type="button" data-pos-remove-line title="حذف الصنف (F7)"
                                wire:click="removeFromCart('{{ $lineKey }}')"
                                wire:confirm="هل أنت متأكد من حذف هذا الصنف من الفاتورة؟"
                                @disabled($invoiceLocked)
                                class="pos-remove-line rounded-lg px-2 py-1 text-lg font-black text-rose-500 hover:bg-rose-50">
                                <span class="pos-remove-mark">×</span>
                                <flux:icon.trash class="pos-remove-icon size-4" />
                            </button>
                        </td>
                    </tr>

                @empty
                    <tr>
                        <td colspan="8" class="py-24 text-center">
                            <div class="text-4xl opacity-30">🧾</div>
                            <div class="mt-2 text-sm font-black text-slate-400">الفاتورة فارغة</div>
                            <div class="mt-1 text-[11px] font-bold text-slate-300">
                                امسح الباركود أو افتح قائمة الأصناف F10
                            </div>
                        </td>
                    </tr>
                @endforelse

            </tbody>

        </table>

    </div>


    {{-- إجمالي التوفير من العروض --}}
    @if (($this->totalPromotionSavings ?? 0) > 0.000001)
        <div class="shrink-0 border-t border-emerald-100 bg-gradient-to-l from-emerald-50 via-white to-emerald-50 px-3 py-2" dir="rtl">
            <div class="flex items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-white px-3 py-2 shadow-sm">
                <div class="flex min-w-0 items-center gap-2">
                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-black text-emerald-700">✓</span>
                    <div>
                        <div class="text-[11px] font-black text-emerald-800">تم تطبيق عرض على الفاتورة</div>
                        <div class="text-[9px] font-bold text-emerald-600">هذا المبلغ تم توفيره للزبون من عروض الكمية</div>
                    </div>
                </div>
                <div class="shrink-0 text-left">
                    <div class="text-[8px] font-black text-slate-400">إجمالي التوفير</div>
                    <div class="font-mono text-base font-black text-emerald-700">{{ number_format($this->totalPromotionSavings, 2) }}</div>
                </div>
            </div>
        </div>
    @endif


    <style>
        tr[data-pos-return-line], tr[data-pos-return-line]:hover { background: #fff1f2 !important; border-bottom: 1px solid #fecdd3; }
        tr[data-pos-return-line] td, tr[data-pos-return-line] td div, tr[data-pos-return-line] input { color: #be123c !important; }
        tr[data-pos-return-line] input { border-color: #fda4af; }
        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        input[type="number"] {
            -moz-appearance: textfield;
            appearance: none;
        }
    </style>



</div>
