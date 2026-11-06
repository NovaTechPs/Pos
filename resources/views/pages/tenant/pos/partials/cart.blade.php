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
    <div class="shrink-0 border-b border-slate-100 bg-slate-50 p-2.5">

        {{-- Barcode --}}
        <div class="relative flex items-center gap-2" x-data="posBarcodeCamera()" data-pos-barcode-entry>

            <button data-pos-camera-button type="button" @disabled($invoiceLocked) x-on:click="start()" aria-label="قراءة الباركود بالكاميرا" title="قراءة الباركود بالكاميرا" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-indigo-200 bg-white text-indigo-600 hover:bg-indigo-50 disabled:opacity-50">
                <flux:icon.camera class="size-5" />
            </button>

            <template x-teleport="body">
                <div x-cloak x-show="open" x-on:keydown.escape.window="if (open) { $event.stopPropagation(); close(); }" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-4" role="dialog" aria-modal="true" aria-label="قراءة الباركود بالكاميرا" dir="rtl">
                    <section x-on:click.outside="close()" class="w-full max-w-lg rounded-2xl bg-white p-4 shadow-xl">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <h2 class="font-bold text-slate-900">قراءة الباركود بالكاميرا</h2>
                            <button type="button" x-on:click="close()" aria-label="إغلاق الكاميرا" class="h-10 w-10 rounded-lg bg-slate-100 text-slate-700">✕</button>
                        </div>
                        <video x-ref="preview" wire:ignore autoplay muted playsinline class="aspect-video w-full rounded-xl bg-slate-950 object-cover"></video>
                        <p x-show="loading" role="status" class="mt-3 text-sm text-indigo-600">جارٍ تشغيل الكاميرا...</p>
                        <p x-show="!error" class="mt-3 text-sm text-slate-600">وجّه الكاميرا إلى الباركود؛ سيُضاف الصنف تلقائيًا بعد قراءته.</p>
                        <p x-show="error" x-text="error" role="alert" class="mt-3 text-sm text-rose-600"></p>
                        <button x-show="error" type="button" x-on:click="start()" class="mt-3 rounded-xl bg-indigo-600 px-4 py-2 text-sm text-white">إعادة المحاولة</button>
                    </section>
                </div>
            </template>

            <input x-ref="barcode" wire:keydown.enter.prevent.stop="scanBarcode($event.target.value)"
                x-on:pos-barcode-handled.window="if ($event.detail.componentId === $wire.$id &amp;&amp; $el.value.trim() === $event.detail.barcode) $el.value = ''"
                data-pos-barcode-input data-pos-component-id="{{ $this->getId() }}" @disabled($invoiceLocked) type="text"
                autocomplete="off" inputmode="none"
                placeholder="{{ $isReturnMode ? 'امسح باركود المرتجع هنا...' : 'امسح الباركود أو اكتب للبحث السريع...' }}"
                class="min-w-0 flex-1 rounded-xl border-2
                    {{ $isReturnMode ? 'border-rose-300 focus:border-rose-500' : 'border-indigo-200 focus:border-indigo-500' }}
                    bg-white px-4 py-2.5 text-sm font-black text-slate-900
                    outline-none placeholder:text-slate-400">

            <button type="button" @disabled($invoiceLocked) x-on:click="$wire.scanBarcode($refs.barcode.value)"
                wire:loading.attr="disabled" wire:target="scanBarcode" class="h-11 shrink-0 rounded-xl bg-indigo-600 px-3 text-xs font-bold text-white disabled:opacity-50">إضافة</button>

            @if ($barcode)
                <button type="button" wire:click="$set('barcode', '')"
                    class="shrink-0 text-slate-400 hover:text-rose-600">
                    ✕
                </button>
            @endif

        </div>
        <div wire:loading.delay wire:target="scanBarcode" role="status" class="mt-1 text-xs text-indigo-600">جارٍ قراءة الباركود…</div>

    </div>


    {{-- =========================================================
        Cart
    ========================================================== --}}
    <div class="shrink-0 border-b border-slate-100 bg-white px-3 py-2">
        <div class="flex items-center justify-between gap-3" dir="rtl">
            <div>
                <div class="flex items-center gap-2 text-xs font-black text-slate-800">
                    <span>تجميع الأصناف المتشابهة</span>
                    <span
                        class="rounded-md border border-slate-200 bg-slate-50 px-2 py-0.5 text-[10px] font-black text-indigo-600">
                        الكمية: {{ collect($cart)->sum(fn($item) => abs((float) ($item['quantity'] ?? 0))) }}
                    </span>
                </div>
                <div class="text-[10px] font-bold text-slate-400">
                    {{ $isReturnMode ? 'كل صنف مرتجع في سطر مستقل لتحديد حالته' : ($mergeSimilarProducts ? 'الصنف المتكرر يجمع في سطر واحد' : 'كل إضافة تظهر كسطر مستقل') }}
                </div>
            </div>

            <button type="button" wire:click="toggleMergeSimilarProducts" role="switch" @disabled($invoiceLocked || $isReturnMode)
                aria-checked="{{ $mergeSimilarProducts ? 'true' : 'false' }}"
                class="relative h-7 w-12 shrink-0 rounded-full transition
                    {{ $mergeSimilarProducts ? 'bg-indigo-600' : 'bg-slate-300' }}"
                title="{{ $mergeSimilarProducts ? 'إيقاف التجميع' : 'تشغيل التجميع' }}">
                <span
                    class="absolute top-1 h-5 w-5 rounded-full bg-white shadow-sm transition
                    {{ $mergeSimilarProducts ? 'right-1' : 'right-6' }}"></span>
            </button>
        </div>
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
                                class="inline-flex min-w-[28px] items-center justify-center
                                    rounded-lg border border-indigo-100 bg-indigo-50
                                    px-2 py-1.5 font-mono text-[11px] font-black text-indigo-700">
                                {{ $loop->iteration }}
                            </span>
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
                                @if (! $mergeSimilarProducts)
                                    <span data-pos-product-occurrence="{{ $productOccurrence }}" class="pos-cart-occurrence"
                                        aria-label="تكرار الصنف في السلة: {{ $productOccurrence }}">{{ $productOccurrence }}</span>
                                @endif
                                @if (! empty($item['is_gift']))
                                    <span class="rounded bg-fuchsia-100 px-2 py-0.5 text-xs text-fuchsia-700">هدية</span>
                                @endif
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
                            <div
                                class="inline-flex items-center overflow-hidden
                                    rounded-lg border border-slate-200 bg-white">
                                <input type="number" step="0.01" min="0.01"
                                    value="{{ $isReturnMode ? abs((float) $item['quantity']) : $item['quantity'] }}" data-pos-field="quantity"
                                    data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}" data-row-index="{{ $loop->index }}"
                                    wire:change="updateCartField('{{ $lineKey }}', 'quantity', $event.target.value)" @disabled($invoiceLocked)
                                    class="pos-cart-field w-16 border-0 bg-transparent px-1 py-1.5 text-center font-mono font-black text-slate-900 outline-none focus:ring-0"
                                    inputmode="decimal" autocomplete="off">
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

                            <button type="button"
                                wire:click="removeFromCart('{{ $lineKey }}')"
                                wire:confirm="هل أنت متأكد من حذف هذا الصنف من الفاتورة؟"
                                @disabled($invoiceLocked)
                                class="rounded-lg px-2 py-1 text-lg font-black text-rose-500 hover:bg-rose-50">
                                ×
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
                        <div class="text-[9px] font-bold text-emerald-600">هذا المبلغ تم توفيره للعميل من عروض الكمية</div>
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
