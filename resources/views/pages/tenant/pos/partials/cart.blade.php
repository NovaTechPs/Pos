<div id="pos-cart-panel" class="pos-cart flex min-w-0 min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    @php $invoiceLocked = $this->invoiceIsLocked(); @endphp

    {{-- =========================================================
        Search
    ========================================================== --}}
    <div class="shrink-0 border-b border-slate-100 bg-slate-50 p-2.5">

        {{-- Barcode --}}
        <div class="relative flex items-center gap-2" x-data="posBarcodeCamera()">

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

            <input data-pos-barcode-input data-pos-component-id="{{ $this->getId() }}" @disabled($invoiceLocked) type="text"
                autocomplete="off" inputmode="none"
                placeholder="{{ $isReturnMode ? 'امسح باركود المرتجع هنا...' : 'امسح الباركود أو اكتب للبحث السريع...' }}"
                class="w-full rounded-xl border-2
                    {{ $isReturnMode ? 'border-rose-300 focus:border-rose-500' : 'border-indigo-200 focus:border-indigo-500' }}
                    bg-white px-4 py-2.5 text-sm font-black text-slate-900
                    outline-none placeholder:text-slate-400">

            @if ($barcode)
                <button type="button" wire:click="$set('barcode', '')"
                    class="absolute left-3 top-2.5 text-slate-400 hover:text-rose-600">
                    ✕
                </button>
            @endif

        </div>

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
                    {{ $mergeSimilarProducts ? 'الصنف المتكرر يجمع في سطر واحد' : 'كل إضافة تظهر كسطر مستقل' }}
                </div>
            </div>

            <button type="button" wire:click="toggleMergeSimilarProducts" role="switch" @disabled($invoiceLocked)
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

        <table class="w-full text-right text-xs" dir="rtl">

            <thead class="sticky top-0 z-10 bg-slate-100 text-slate-600 shadow-sm">

                <tr>

                    {{-- عداد الصنف --}}
                    <th class="w-12 px-2 py-2 text-center">
                        #
                    </th>
                    {{-- رقم الصنف ID --}}
                    <th class="w-16 px-2 py-2 text-center">
                        ID
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

                    {{-- الإجمالي --}}
                    <th class="px-2 py-2 text-center">
                        الإجمالي
                    </th>

                    {{-- حذف --}}
                    <th class="w-10 px-2 py-2"></th>

                </tr>

            </thead>


            <tbody class="divide-y divide-slate-300">

                @forelse ($cart as $lineKey => $item)
                    @php
                        $belowCost =
                            empty($item['is_gift']) && $item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0);
                    @endphp

                    <tr data-pos-cart-line wire:key="pos-cart-{{ $lineKey }}"
                        class="{{ empty($item['is_gift']) && ($item['offer_quantity'] ?? 0) > 0 && isset($item['offer_price']) && $item['offer_price'] < $item['offer_quantity'] * $item['price'] ? 'bg-amber-50' : ($loop->odd ? 'bg-white' : 'bg-slate-50') }} hover:bg-indigo-50">
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
                                {{ $item['id'] }}
                            </span>
                        </td>

                        {{-- Product --}}
                        <td class="px-3 py-2">
                            <div class="font-black text-slate-900">
                                {{ $item['name'] }}
                                @if (! empty($item['is_gift']))
                                    <span class="rounded bg-fuchsia-100 px-2 py-0.5 text-xs text-fuchsia-700">هدية</span>
                                @endif
                            </div>

                            <div class="mt-0.5 flex gap-1 text-[9px] font-bold">
                                @if ($item['quantity'] < 0)
                                    <span class="text-rose-600">
                                        مرتجع
                                    </span>
                                @endif

                                @if ($belowCost)
                                    <span class="text-amber-700">
                                        أقل من التكلفة
                                    </span>
                                @endif
                            </div>
                        </td>

                        {{-- Quantity --}}
                        <td class="px-2 py-2 text-center">
                            <div
                                class="inline-flex items-center overflow-hidden
                                    rounded-lg border border-slate-200 bg-white">
                                <input type="number" step="0.01" min="{{ $isReturnMode ? '-999999.99' : '0.01' }}"
                                    value="{{ $item['quantity'] }}" data-pos-field="quantity"
                                    data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}" data-row-index="{{ $loop->index }}"
                                    wire:change="updateCartField('{{ $lineKey }}', 'quantity', $event.target.value)" @disabled($invoiceLocked)
                                    class="pos-cart-field w-16 border-0 bg-transparent px-1 py-1.5 text-center font-mono font-black text-slate-900 outline-none focus:ring-0"
                                    inputmode="decimal" autocomplete="off">
                            </div>
                        </td>

                        {{-- Selling Price --}}
                        <td class="px-2 py-2 text-center">
                            <input type="number" step="0.01" min="0" value="{{ $item['price'] }}"
                                data-pos-field="price" data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}"
                                data-row-index="{{ $loop->index }}"
                                wire:change="updateCartField(
                                    '{{ $lineKey }}',
                                    'price',
                                    $event.target.value
                                )"
                                @disabled($invoiceLocked || ! empty($item['is_gift']))
                                class="
                                    pos-cart-field w-20 rounded-lg border
                                    {{ $belowCost ? 'border-amber-400 bg-amber-50 text-amber-800' : 'border-slate-200 bg-slate-50 text-slate-800' }}
                                    px-1 py-1 text-center font-mono text-[11px] font-black
                                    focus:border-indigo-500 focus:outline-none
                                "
                                inputmode="decimal" autocomplete="off">
                        </td>

                        {{-- Subtotal --}}
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
                                    <input type="number" step="0.01" value="{{ $item['subtotal'] }}"
                                        data-pos-field="subtotal" data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}"
                                        data-row-index="{{ $loop->index }}"
                                        wire:change="updateCartField(
                                            '{{ $lineKey }}',
                                            'subtotal',
                                            $event.target.value
                                        )"
                                        @disabled($invoiceLocked || ! empty($item['is_gift']))
                                        class="pos-cart-field w-24 rounded-lg border px-1 py-1 text-center font-mono text-[11px] font-black focus:border-indigo-500 focus:outline-none
                                            {{ $item['subtotal'] < 0
                                                ? 'border-rose-300 bg-rose-50 text-rose-700'
                                                : ($hasPromotion ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-slate-200 bg-slate-50 text-indigo-700') }}"
                                        inputmode="decimal" autocomplete="off">
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
                        <td colspan="7" class="py-24 text-center">
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


  <script>
(function() {
    if (window.__posCartKeyboardNavigation) {
        return;
    }
    window.__posCartKeyboardNavigation = true;

    const FIELD_SELECTOR = '[data-pos-field]';
    const BARCODE_SELECTOR = '[data-pos-barcode-input]';
    const barcodeQueue = [];
    let isProcessingBarcodeQueue = false;
    let externalBarcodeBuffer = '';
    let externalBarcodeLastKeyAt = 0;
    let externalBarcodeCaptured = false;
    let externalBarcodeTarget = null;
    let externalBarcodeOriginalValue = null;

    function rows() {
        const grouped = new Map();
        document.querySelectorAll(FIELD_SELECTOR).forEach(function(field) {
            const row = Number(field.dataset.rowIndex);
            if (!Number.isFinite(row)) return;
            if (!grouped.has(row)) grouped.set(row, {});
            grouped.get(row)[field.dataset.posField] = field;
        });

        return Array.from(grouped.entries())
            .sort((a, b) => a[0] - b[0])
            .map(entry => ({
                index: entry[0],
                quantity: entry[1].quantity || null,
                price: entry[1].price || null,
                subtotal: entry[1].subtotal || null
            }));
    }

    function focusInput(input) {
        if (!input || !document.contains(input)) return;
        input.focus({ preventScroll: true });
        if (typeof input.select === 'function') input.select();

        const cart = input.closest('[data-pos-cart-scroll]');
        const row = input.closest('tr');
        if (!cart || !row) return;

        const cartBounds = cart.getBoundingClientRect();
        const rowBounds = row.getBoundingClientRect();
        const scrollMargin = 32;

        if (rowBounds.top < cartBounds.top) {
            cart.scrollBy({ top: rowBounds.top - cartBounds.top - scrollMargin, behavior: 'smooth' });
        } else if (rowBounds.bottom > cartBounds.bottom) {
            cart.scrollBy({ top: rowBounds.bottom - cartBounds.bottom + scrollMargin, behavior: 'smooth' });
        }
    }

    function focusBarcode() {
        const input = document.querySelector(BARCODE_SELECTOR);
        if (!input) return;
        input.focus({ preventScroll: true });
        if (typeof input.select === 'function') input.select();
    }

    function enqueueBarcode(barcode) {
        const barcodeInput = document.querySelector(BARCODE_SELECTOR);
        const componentId = barcodeInput?.dataset.posComponentId;
        if (!barcodeInput || barcodeInput.disabled) return;
        if (!componentId || !barcode) return;

        barcodeQueue.push({ barcode, componentId });
        processBarcodeQueue();
    }

    function resetExternalBarcodeCapture(restoreTarget = false) {
        if (restoreTarget && externalBarcodeCaptured && externalBarcodeTarget && document.contains(externalBarcodeTarget)) {
            externalBarcodeTarget.value = externalBarcodeOriginalValue ?? '';
            externalBarcodeTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }

        externalBarcodeBuffer = '';
        externalBarcodeLastKeyAt = 0;
        externalBarcodeCaptured = false;
        externalBarcodeTarget = null;
        externalBarcodeOriginalValue = null;
    }

    async function processBarcodeQueue() {
        if (isProcessingBarcodeQueue || barcodeQueue.length === 0) return;

        isProcessingBarcodeQueue = true;

        try {
            while (barcodeQueue.length > 0) {
                const { barcode, componentId } = barcodeQueue.shift();
                const barcodeInput = document.querySelector(BARCODE_SELECTOR);
                if (!barcodeInput || barcodeInput.disabled || barcodeInput.dataset.posComponentId !== componentId) continue;
                const component = window.Livewire?.find(componentId);

                if (!component || !barcode) continue;

                await component.$call('scanBarcode', barcode);
            }
        } catch (error) {
            console.error('تعذر تسجيل أحد الباركودات في نقطة البيع.', error);
        } finally {
            isProcessingBarcodeQueue = false;
            focusBarcode();
        }
    }

    document.addEventListener('keydown', function(event) {
        if (document.querySelector('[data-pos-missing-barcode-dialog]')) {
            resetExternalBarcodeCapture();
            return;
        }

        const barcodeInput = document.querySelector(BARCODE_SELECTOR);
        if (!barcodeInput || barcodeInput.disabled) {
            resetExternalBarcodeCapture();
            return;
        }
        const input = event.target?.closest?.(BARCODE_SELECTOR);
        if (input) {
            if (event.key !== 'Enter') return;

            event.preventDefault();
            event.stopImmediatePropagation();

            const barcode = input.value.trim();
            input.value = '';
            enqueueBarcode(barcode);
            return;
        }

        if (event.target?.closest?.('[data-pos-invoice-search]')) {
            resetExternalBarcodeCapture();
            return;
        }

        if (event.key === 'Enter') {
            if (externalBarcodeCaptured && externalBarcodeBuffer.length >= 4) {
                event.preventDefault();
                event.stopImmediatePropagation();
                const barcode = externalBarcodeBuffer;
                resetExternalBarcodeCapture(true);
                enqueueBarcode(barcode);
            } else {
                resetExternalBarcodeCapture();
            }
            return;
        }

        if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }

        const now = Date.now();
        if (!externalBarcodeLastKeyAt || now - externalBarcodeLastKeyAt > 90) {
            resetExternalBarcodeCapture();
            externalBarcodeBuffer = event.key;
            externalBarcodeTarget = event.target;
            externalBarcodeOriginalValue = typeof event.target?.value === 'string' ? event.target.value : null;
            externalBarcodeLastKeyAt = now;
            return;
        }

        externalBarcodeBuffer += event.key;
        externalBarcodeLastKeyAt = now;

        if (externalBarcodeBuffer.length >= 2) {
            externalBarcodeCaptured = true;
            event.preventDefault();
            event.stopImmediatePropagation();
            if (externalBarcodeTarget && externalBarcodeOriginalValue !== null) {
                externalBarcodeTarget.value = externalBarcodeOriginalValue;
            }
        }
    }, true);

    document.addEventListener('livewire:navigating', function() {
        barcodeQueue.length = 0;
        resetExternalBarcodeCapture();
    });

    window.addEventListener('pos-scroll-cart-bottom', function() {
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                const cart = document.querySelector('[data-pos-cart-scroll]');
                if (cart) cart.scrollTop = cart.scrollHeight;
            });
        });
    });

    document.addEventListener('keydown', function(event) {
        if (document.querySelector('[data-pos-missing-barcode-dialog]')) {
            resetExternalBarcodeCapture();
            return;
        }

        const current = event.target?.closest?.(FIELD_SELECTOR);
        const key = event.key;

        // الضغط على سهم الأعلى خارج السلة يأخذك مباشرة إلى كمية آخر منتج
        if (!current && key === 'ArrowUp') {
            const allRows = rows();
            if (!allRows.length) return;

            event.preventDefault();
            focusInput(
                allRows[allRows.length - 1].quantity ||
                allRows[allRows.length - 1].price ||
                allRows[allRows.length - 1].subtotal
            );
            return;
        }

        if (!current) return;

        // الضغط على Enter داخل حقول السلة للعودة إلى الباركود
        if (key === 'Enter') {
            event.preventDefault();
            event.stopPropagation();
            focusBarcode();
            return;
        }

        if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(key)) {
            return;
        }

        const allRows = rows();
        if (!allRows.length) return;

        const rowIndex = Number(current.dataset.rowIndex);
        const type = current.dataset.posField;
        const pos = allRows.findIndex(r => r.index === rowIndex);

        if (pos < 0) return;

        let target = null;

        // التنقل بالأسهم داخل السلة (اتجاه RTL)
        if (key === 'ArrowLeft') {
            if (type === 'quantity') {
                target = allRows[pos].price;
            } else if (type === 'price') {
                target = allRows[pos].subtotal;
            } else if (type === 'subtotal') {
                const nextPos = pos >= allRows.length - 1 ? 0 : pos + 1;
                target = allRows[nextPos].quantity;
            }
        } else if (key === 'ArrowRight') {
            if (type === 'subtotal') {
                target = allRows[pos].price;
            } else if (type === 'price') {
                target = allRows[pos].quantity;
            } else if (type === 'quantity') {
                const previousPos = pos <= 0 ? allRows.length - 1 : pos - 1;
                target = allRows[previousPos].subtotal;
            }
        } else if (key === 'ArrowUp') {
            const targetPos = pos <= 0 ? allRows.length - 1 : pos - 1;
            target = allRows[targetPos][type];
        } else if (key === 'ArrowDown') {
            const targetPos = pos >= allRows.length - 1 ? 0 : pos + 1;
            target = allRows[targetPos][type];
        } else if (key === 'Home') {
            target = allRows[0][type];
        } else if (key === 'End') {
            target = allRows[allRows.length - 1][type];
        }

        if (target) {
            event.preventDefault();
            event.stopPropagation();
            focusInput(target);
        }
    }, true);
})();
</script>
</div>
