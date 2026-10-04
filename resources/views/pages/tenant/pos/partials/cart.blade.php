<div class="flex h-full min-h-0 flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
    @php $invoiceLocked = $this->invoiceIsLocked(); @endphp

    {{-- =========================================================
        Search
    ========================================================== --}}
    <div class="shrink-0 border-b border-slate-100 bg-slate-50 p-2.5">

        {{-- Barcode --}}
        <div class="relative">

            <input data-pos-barcode-input wire:model="barcode" wire:keydown.enter.prevent="scanBarcode" @disabled($invoiceLocked) type="text"
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


        {{-- =====================================================
            Inline Search
        ====================================================== --}}
        {{-- <div class="relative mt-2">

            <input wire:model.live.debounce.250ms="inlineSearchQuery" type="text" autocomplete="off"
                placeholder="بحث مباشر باسم المنتج أو الباركود..."
                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold focus:border-indigo-500 focus:outline-none">

            @if ($inlineSearchResults)

                <div
                    class="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl">

                    @foreach ($inlineSearchResults as $result)
                        <button type="button" wire:click="selectInlineProduct({{ $result['id'] }})"
                            class="flex w-full items-center justify-between border-b border-slate-100 px-3 py-2 text-right hover:bg-indigo-50">

                            <span class="font-bold text-slate-800">
                                {{ $result['name'] }}
                            </span>

                            <span class="text-[10px] font-mono text-slate-500">
                                {{ number_format($result['price'], 2) }}
                                · مخزون
                                {{ number_format($result['stock'], 0) }}
                            </span>

                        </button>
                    @endforeach

                </div>

            @endif

        </div> --}}

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

    <div data-pos-cart-scroll class="min-h-0 flex-1 overflow-auto">

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

                        /*
                        |--------------------------------------------------------------------------
                        | Below Cost
                        |--------------------------------------------------------------------------
                        | التكلفة لا تظهر للمستخدم، ولكن نترك الفحص كما هو
                        | حتى لا نكسر منطق منع/تنبيه البيع تحت التكلفة.
                        */
                        $belowCost =
                            $item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0);
                    @endphp


                    <tr wire:key="pos-cart-{{ $lineKey }}"
                        class="{{ $loop->odd ? 'bg-white' : 'bg-slate-50' }} hover:bg-indigo-50">
                        {{-- =================================================
                            Row Number
                        ================================================== --}}
                        <td class="px-2 py-2 text-center">

                            <span
                                class="inline-flex min-w-[28px] items-center justify-center
                                    rounded-lg border border-indigo-100 bg-indigo-50
                                    px-2 py-1.5 font-mono text-[11px] font-black text-indigo-700">
                                {{ $loop->iteration }}
                            </span>

                        </td>

                        {{-- =================================================
                            Product ID
                        ================================================== --}}
                        <td class="px-2 py-2 text-center">

                            <span
                                class="inline-flex min-w-[42px] items-center justify-center
                                    rounded-lg border border-slate-200 bg-slate-50
                                    px-2 py-1.5 font-mono text-[11px] font-black text-slate-600">
                                {{ $item['id'] }}
                            </span>

                        </td>





                        {{-- =================================================
                            Product
                        ================================================== --}}
                        <td class="px-3 py-2">

                            <div class="font-black text-slate-900">
                                {{ $item['name'] }}
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


                        {{-- =================================================
                            Quantity
                        ================================================== --}}
                        <td class="px-2 py-2 text-center">

                            <div
                                class="inline-flex items-center overflow-hidden
                                    rounded-lg border border-slate-200 bg-white">

                                <input type="number" step="0.01" min="{{ $isReturnMode ? '-999999.99' : '0.01' }}"
                                    value="{{ $item['quantity'] }}" data-pos-field="quantity"
                                    data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}" data-row-index="{{ $loop->index }}"
                                    wire:change="updateCartField('{{ $lineKey }}', 'quantity', $event.target.value)" @disabled($invoiceLocked)
                                    class="pos-cart-field w-16 border-0 bg-transparent px-1 py-1.5 text-center font-mono font-black outline-none focus:ring-0"
                                    inputmode="decimal" autocomplete="off">

                            </div>

                        </td>


                        {{-- =================================================
                            Selling Price
                        ================================================== --}}
                        <td class="px-2 py-2 text-center">

                            <input type="number" step="0.01" min="0" value="{{ $item['price'] }}"
                                data-pos-field="price" data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}"
                                data-row-index="{{ $loop->index }}"
                                wire:change="updateCartField(
                                    '{{ $lineKey }}',
                                    'price',
                                    $event.target.value
                                )"
                                @disabled($invoiceLocked)
                                class="
                                    pos-cart-field w-20 rounded-lg border
                                    {{ $belowCost ? 'border-amber-400 bg-amber-50 text-amber-800' : 'border-slate-200 bg-slate-50 text-slate-800' }}
                                    px-1 py-1 text-center font-mono text-[11px] font-black
                                    focus:border-indigo-500 focus:outline-none
                                "
                                inputmode="decimal" autocomplete="off">

                        </td>


                        {{-- =================================================
                            Subtotal
                        ================================================== --}}
                        <td class="px-2 py-2 text-center">

                            <input type="number" step="0.01" value="{{ $item['subtotal'] }}"
                                data-pos-field="subtotal" data-product-id="{{ $item['id'] }}" data-line-key="{{ $lineKey }}"
                                data-row-index="{{ $loop->index }}"
                                wire:change="updateCartField(
                                    '{{ $lineKey }}',
                                    'subtotal',
                                    $event.target.value
                                )"
                                @disabled($invoiceLocked)
                                class="
                                    pos-cart-field w-24 rounded-lg border
                                    px-1 py-1 text-center font-mono text-[11px] font-black
                                    focus:border-indigo-500 focus:outline-none

                                    {{ $item['subtotal'] < 0
                                        ? 'border-rose-300 bg-rose-50 text-rose-700'
                                        : 'border-slate-200 bg-slate-50 text-indigo-700' }}
                                "
                                inputmode="decimal" autocomplete="off">

                        </td>


                        {{-- =================================================
                            Remove
                        ================================================== --}}
                        <td class="px-2 py-2 text-center">

                            <button type="button" wire:click="removeFromCart('{{ $lineKey }}')" @disabled($invoiceLocked)
                                class="rounded-lg px-2 py-1 text-lg font-black text-rose-500 hover:bg-rose-50">
                                ×
                            </button>

                        </td>

                    </tr>


                @empty

                    <tr>

                        <td colspan="7" class="py-24 text-center">

                            <div class="text-4xl opacity-30">
                                🧾
                            </div>

                            <div class="mt-2 text-sm font-black text-slate-400">
                                الفاتورة فارغة
                            </div>

                            <div class="mt-1 text-[11px] font-bold text-slate-300">
                                امسح الباركود أو افتح قائمة الأصناف F10
                            </div>

                        </td>

                    </tr>
                @endforelse

            </tbody>

        </table>

    </div>


    {{-- =========================================================
        Number Input Style
    ========================================================== --}}
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


    {{-- =========================================================
        Keyboard + Barcode Scanner
    ========================================================== --}}
    <script>
        (function() {

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate initialization
            |--------------------------------------------------------------------------
            */
            if (window.__posCartKeyboardNavigation) {
                return;
            }

            window.__posCartKeyboardNavigation = true;


            /*
            |--------------------------------------------------------------------------
            | Selectors
            |--------------------------------------------------------------------------
            */
            const FIELD_SELECTOR = '[data-pos-field]';
            const BARCODE_SELECTOR = '[data-pos-barcode-input]';


            /*
            |--------------------------------------------------------------------------
            | Return to barcode after editing cart fields
            |--------------------------------------------------------------------------
            | عند تعديل الكمية أو السعر أو الإجمالي، wire:change يرسل طلب Livewire.
            | ننتظر انتهاء تحديث Livewire ثم نعيد التركيز إلى الباركود، حتى لا
            | يقوم Livewire بإرجاع التركيز إلى الحقل القديم.
            |--------------------------------------------------------------------------
            */
            let focusBarcodeAfterCartChange = false;
            let barcodeFocusTimer = null;

            function focusBarcodeNow() {
                const input = document.querySelector(BARCODE_SELECTOR);
                if (!input) return;

                input.focus({ preventScroll: true });
                if (typeof input.select === 'function') {
                    input.select();
                }
            }

            function restoreBarcodeAfterLivewire() {
                if (!focusBarcodeAfterCartChange) return;

                focusBarcodeAfterCartChange = false;

                if (barcodeFocusTimer) {
                    clearTimeout(barcodeFocusTimer);
                }

                barcodeFocusTimer = setTimeout(function() {
                    barcodeFocusTimer = null;
                    focusBarcodeNow();

                    setTimeout(function() {
                        focusBarcodeNow();
                    }, 120);
                }, 40);
            }

            // طلب صريح من Livewire بعد نجاح تعديل الكمية/السعر/الإجمالي.
            window.addEventListener('pos-focus-barcode', function() {
                focusBarcodeAfterCartChange = true;

                // إذا كان الحدث وصل بعد الـ morph بالفعل.
                restoreBarcodeAfterLivewire();
            });

            // لا نعيد التركيز إلى الباركود عند تغيير أي حقل في السلة.
            // التنقل بالأسهم يجب أن يبقى داخل السلة.
            // الانتقال إلى الباركود يتم فقط عند الضغط على Enter أو بطلب صريح.

            function registerLivewireBarcodeFocus() {
                if (!window.Livewire || typeof window.Livewire.hook !== 'function') {
                    return;
                }

                window.Livewire.hook('morph.updated', function() {
                    restoreBarcodeAfterLivewire();
                });

                window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => {
                        restoreBarcodeAfterLivewire();
                    });
                });
            }

            if (window.Livewire) {
                registerLivewireBarcodeFocus();
            } else {
                document.addEventListener('livewire:init', registerLivewireBarcodeFocus, { once: true });
            }


            /*
            |--------------------------------------------------------------------------
            | Scroll to last cart row after adding a product
            |--------------------------------------------------------------------------
            */
            window.addEventListener('pos-scroll-cart-bottom', function () {
                setTimeout(function () {
                    const container = document.querySelector('[data-pos-cart-scroll]');
                    if (container) {
                        container.scrollTop = container.scrollHeight;
                    }
                }, 50);
            });


            /*
            |--------------------------------------------------------------------------
            | Cart rows
            |--------------------------------------------------------------------------
            */
            function rows() {

                const grouped = new Map();


                document.querySelectorAll(FIELD_SELECTOR).forEach(function(field) {

                    const row = Number(field.dataset.rowIndex);

                    if (!Number.isFinite(row)) {
                        return;
                    }


                    if (!grouped.has(row)) {
                        grouped.set(row, {});
                    }


                    grouped.get(row)[field.dataset.posField] = field;

                });


                return Array
                    .from(grouped.entries())
                    .sort(function(a, b) {
                        return a[0] - b[0];
                    })
                    .map(function(entry) {

                        return {
                            index: entry[0],
                            quantity: entry[1].quantity || null,
                            price: entry[1].price || null,
                            subtotal: entry[1].subtotal || null
                        };

                    });

            }


            /*
            |--------------------------------------------------------------------------
            | Focus input
            |--------------------------------------------------------------------------
            */
            function focusInput(input) {

                if (!input || !document.contains(input)) {
                    return;
                }


                input.focus({
                    preventScroll: true
                });


                if (typeof input.select === 'function') {
                    input.select();
                }


                input.scrollIntoView({
                    behavior: 'auto',
                    block: 'nearest',
                    inline: 'nearest'
                });

            }


            /*
            |--------------------------------------------------------------------------
            | Focus barcode
            |--------------------------------------------------------------------------
            */
            function focusBarcode(selectText = false) {

                const input =
                    document.querySelector(BARCODE_SELECTOR);


                if (!input) {
                    return;
                }


                input.focus({
                    preventScroll: true
                });


                if (
                    selectText &&
                    typeof input.select === 'function'
                ) {
                    input.select();
                }

            }


            /*
            |--------------------------------------------------------------------------
            | Get Livewire component
            |--------------------------------------------------------------------------
            */
            function getLivewireComponent() {

                const barcodeInput =
                    document.querySelector(BARCODE_SELECTOR);


                if (!barcodeInput) {
                    return null;
                }


                const root =
                    barcodeInput.closest('[wire\\:id]');


                if (!root) {
                    return null;
                }


                const componentId =
                    root.getAttribute('wire:id');


                if (!componentId) {
                    return null;
                }


                if (
                    window.Livewire &&
                    typeof window.Livewire.find === 'function'
                ) {

                    return window.Livewire.find(componentId);

                }


                return null;

            }


            /*
            |--------------------------------------------------------------------------
            | Keyboard navigation
            |
            | Quantity <-> Price <-> Subtotal
            |
            | ↑ ↓ = rows
            | ← → = fields
            | Enter = next field / next row
            | Home = first
            | End = last
            |--------------------------------------------------------------------------
            */
            document.addEventListener('keydown', function(event) {

                const current =
                    event.target?.closest?.(FIELD_SELECTOR);

                const key = event.key;


                /*
                |--------------------------------------------------------------------------
                | ArrowUp outside cart
                |--------------------------------------------------------------------------
                */
                if (!current && key === 'ArrowUp') {

                    const allRows = rows();


                    if (!allRows.length) {
                        return;
                    }


                    event.preventDefault();


                    focusInput(
                        allRows[allRows.length - 1].quantity ||
                        allRows[allRows.length - 1].price ||
                        allRows[allRows.length - 1].subtotal
                    );


                    return;

                }


                /*
                |--------------------------------------------------------------------------
                | Ignore unrelated keys
                |--------------------------------------------------------------------------
                */
                if (
                    !current ||
                    ![
                        'ArrowUp',
                        'ArrowDown',
                        'ArrowLeft',
                        'ArrowRight',
                        'Enter',
                        'Home',
                        'End'
                    ].includes(key)
                ) {
                    return;
                }


                const allRows = rows();


                if (!allRows.length) {
                    return;
                }


                if (key === 'ArrowUp' || key === 'ArrowDown') {
                    event.preventDefault();
                }


                const rowIndex =
                    Number(current.dataset.rowIndex);

                const type =
                    current.dataset.posField;


                const pos =
                    allRows.findIndex(function(row) {
                        return row.index === rowIndex;
                    });


                if (pos < 0) {
                    return;
                }


                let target = null;


                /*
                |--------------------------------------------------------------------------
                | Left / Right
                |--------------------------------------------------------------------------
                |
                | → الكمية → السعر → الإجمالي → كمية الصنف التالي
                |
                | ← الإجمالي → السعر → الكمية → إجمالي الصنف السابق
                |--------------------------------------------------------------------------
                */
                if (
                    key === 'ArrowLeft' ||
                    key === 'ArrowRight'
                ) {

                    if (key === 'ArrowLeft') {

                        if (type === 'quantity') {

                            target =
                                allRows[pos].price;

                        } else if (type === 'price') {

                            target =
                                allRows[pos].subtotal;

                        } else if (type === 'subtotal') {

                            const nextPos =
                                pos >= allRows.length - 1 ?
                                0 :
                                pos + 1;

                            target =
                                allRows[nextPos].quantity;

                        }

                    } else {

                        if (type === 'subtotal') {

                            target =
                                allRows[pos].price;

                        } else if (type === 'price') {

                            target =
                                allRows[pos].quantity;

                        } else if (type === 'quantity') {

                            const previousPos =
                                pos <= 0 ?
                                allRows.length - 1 :
                                pos - 1;

                            target =
                                allRows[previousPos].subtotal;

                        }

                    }

                }


                /*
                |--------------------------------------------------------------------------
                | Up / Down / Enter / Home / End
                |--------------------------------------------------------------------------
                */
                else {

                    let targetPos = pos;


                    /*
                    | Arrow Up
                    */
                    if (key === 'ArrowUp') {

                        targetPos =
                            pos <= 0 ?
                            allRows.length - 1 :
                            pos - 1;

                        target =
                            allRows[targetPos][type];

                    }


                    /*
                    | Enter
                    |
                    | Enter فقط ينقل التركيز مباشرة إلى الباركود.
                    | لا ينتقل إلى الحقل أو الصنف التالي.
                    */
                    if (key === 'Enter') {

                        const barcode =
                            document.querySelector(BARCODE_SELECTOR);

                        if (barcode) {
                            event.preventDefault();
                            event.stopPropagation();
                            focusInput(barcode);
                            return;
                        }

                        return;
                    }


                    /*
                    | Arrow Down
                    */
                    if (key === 'ArrowDown') {

                        targetPos =
                            pos >= allRows.length - 1 ?
                            0 :
                            pos + 1;

                        target =
                            allRows[targetPos][type];

                    }


                    /*
                    | Home
                    */
                    if (key === 'Home') {

                        targetPos = 0;

                        target =
                            allRows[targetPos][type];

                    }


                    /*
                    | End
                    */
                    if (key === 'End') {

                        targetPos =
                            allRows.length - 1;

                        target =
                            allRows[targetPos][type];

                    }

                }


                if (target) {

                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();

                    focusInput(target);

                }

            }, true);


            /*
            |--------------------------------------------------------------------------
            | Barcode Scanner
            |--------------------------------------------------------------------------
            |
            | إذا كان scanner يكتب داخل quantity أو price أو subtotal:
            |
            | 1. نحفظ القيمة الأصلية.
            | 2. نسمح مؤقتاً للحروف.
            | 3. عند التأكد أنه Scanner نمنع استمرارها.
            | 4. عند Enter نعيد القيمة الأصلية.
            | 5. نرسل الباركود إلى scanBarcode().
            |--------------------------------------------------------------------------
            */

            let scannerBuffer = '';
            let scannerTimer = null;
            let scannerStartedAt = 0;

            let scannerTarget = null;
            let scannerOriginalValue = '';

            let scannerOriginalSelectionStart = null;
            let scannerOriginalSelectionEnd = null;


            /*
            |--------------------------------------------------------------------------
            | Scanner configuration
            |--------------------------------------------------------------------------
            */
            const SCANNER_MAX_GAP = 70;
            const SCANNER_MIN_LENGTH = 3;


            /*
            |--------------------------------------------------------------------------
            | Is cart editable field?
            |--------------------------------------------------------------------------
            */
            function isCartEditableField(target) {

                if (!target) {
                    return false;
                }


                return !!target.closest?.(FIELD_SELECTOR);

            }


            /*
            |--------------------------------------------------------------------------
            | Reset scanner
            |--------------------------------------------------------------------------
            */
            function resetScannerBuffer() {

                scannerBuffer = '';
                scannerStartedAt = 0;

                scannerTarget = null;
                scannerOriginalValue = '';

                scannerOriginalSelectionStart = null;
                scannerOriginalSelectionEnd = null;


                if (scannerTimer) {

                    clearTimeout(scannerTimer);

                    scannerTimer = null;

                }

            }


            /*
            |--------------------------------------------------------------------------
            | Save target state
            |--------------------------------------------------------------------------
            */
            function saveScannerTarget(target) {

                scannerTarget =
                    target?.closest?.(
                        'input, textarea'
                    ) || null;


                if (!scannerTarget) {
                    return;
                }


                scannerOriginalValue =
                    scannerTarget.value ?? '';


                try {

                    scannerOriginalSelectionStart =
                        scannerTarget.selectionStart;

                    scannerOriginalSelectionEnd =
                        scannerTarget.selectionEnd;

                } catch (e) {

                    scannerOriginalSelectionStart = null;
                    scannerOriginalSelectionEnd = null;

                }

            }


            /*
            |--------------------------------------------------------------------------
            | Restore original value
            |--------------------------------------------------------------------------
            */
            function restoreScannerTarget() {

                if (
                    !scannerTarget ||
                    !document.contains(scannerTarget)
                ) {
                    return;
                }


                /*
                | Restore DOM value.
                */
                scannerTarget.value =
                    scannerOriginalValue;


                /*
                | Restore selection.
                */
                try {

                    if (
                        scannerOriginalSelectionStart !== null &&
                        scannerOriginalSelectionEnd !== null &&
                        typeof scannerTarget.setSelectionRange === 'function'
                    ) {

                        scannerTarget.setSelectionRange(
                            scannerOriginalSelectionStart,
                            scannerOriginalSelectionEnd
                        );

                    }

                } catch (e) {

                    // Ignore selection errors.

                }

            }


            /*
            |--------------------------------------------------------------------------
            | Submit scanned barcode
            |--------------------------------------------------------------------------
            */
            function submitScannedBarcode(value) {

                value =
                    String(value || '').trim();


                if (
                    value.length <
                    SCANNER_MIN_LENGTH
                ) {
                    return false;
                }


                const component =
                    getLivewireComponent();


                if (!component) {
                    return false;
                }


                try {

                    /*
                    | Livewire v3
                    */
                    if (
                        component.$wire &&
                        typeof component.$wire.scanBarcode === 'function'
                    ) {

                        component.$wire.scanBarcode(value);

                        return true;

                    }


                    /*
                    | Fallback
                    */
                    if (
                        typeof component.call === 'function'
                    ) {

                        component.call(
                            'scanBarcode',
                            value
                        );

                        return true;

                    }

                } catch (error) {

                    console.error(
                        'POS barcode scanner error:',
                        error
                    );

                }


                return false;

            }


            /*
            |--------------------------------------------------------------------------
            | Global scanner listener
            |--------------------------------------------------------------------------
            */
            document.addEventListener('keydown', function(event) {

                const key =
                    event.key;

                const target =
                    event.target;


                /*
                |--------------------------------------------------------------------------
                | ENTER
                |--------------------------------------------------------------------------
                */
                if (key === 'Enter') {

                    if (
                        scannerBuffer.length >=
                        SCANNER_MIN_LENGTH
                    ) {

                        event.preventDefault();
                        event.stopPropagation();


                        const value =
                            scannerBuffer;


                        /*
                        | إعادة الكمية/السعر/الإجمالي
                        | قبل تنفيذ المسح.
                        */
                        restoreScannerTarget();


                        /*
                        | إرسال الباركود.
                        */
                        const submitted =
                            submitScannedBarcode(value);


                        /*
                        | تنظيف.
                        */
                        resetScannerBuffer();


                        if (submitted) {

                            setTimeout(function() {

                                focusBarcode(false);

                            }, 80);

                        }


                        return;

                    }


                    resetScannerBuffer();

                    return;

                }


                /*
                |--------------------------------------------------------------------------
                | Ignore navigation
                |--------------------------------------------------------------------------
                */
                if (
                    key === 'ArrowUp' ||
                    key === 'ArrowDown' ||
                    key === 'ArrowLeft' ||
                    key === 'ArrowRight' ||
                    key === 'Home' ||
                    key === 'End' ||
                    key === 'Tab' ||
                    key === 'Escape'
                ) {

                    return;

                }


                /*
                |--------------------------------------------------------------------------
                | Ignore shortcuts
                |--------------------------------------------------------------------------
                */
                if (
                    event.ctrlKey ||
                    event.altKey ||
                    event.metaKey
                ) {

                    return;

                }


                /*
                |--------------------------------------------------------------------------
                | Only printable characters
                |--------------------------------------------------------------------------
                */
                if (
                    typeof key !== 'string' ||
                    key.length !== 1
                ) {

                    return;

                }


                const now =
                    Date.now();


                /*
                |--------------------------------------------------------------------------
                | New sequence
                |--------------------------------------------------------------------------
                */
                if (
                    scannerStartedAt === 0 ||
                    now - scannerStartedAt >
                    SCANNER_MAX_GAP
                ) {

                    scannerBuffer = '';

                    scannerStartedAt = now;

                    saveScannerTarget(target);

                }


                /*
                |--------------------------------------------------------------------------
                | Add character
                |--------------------------------------------------------------------------
                */
                scannerBuffer += key;


                /*
                |--------------------------------------------------------------------------
                | إذا كان الحقل كمية أو سعر أو إجمالي:
                |
                | بعد وصول عدة أحرف بسرعة نعرف أنه Scanner.
                |--------------------------------------------------------------------------
                */
                if (
                    isCartEditableField(target) &&
                    scannerBuffer.length >= 2 &&
                    now - scannerStartedAt <= SCANNER_MAX_GAP
                ) {

                    event.preventDefault();
                    event.stopPropagation();


                    /*
                    | أعد الحقل إلى القيمة الأصلية.
                    */
                    restoreScannerTarget();

                }


                /*
                |--------------------------------------------------------------------------
                | Keep scanner alive
                |--------------------------------------------------------------------------
                */
                if (scannerTimer) {

                    clearTimeout(scannerTimer);

                }


                scannerTimer =
                    setTimeout(function() {

                        /*
                        | إذا انتهى الوقت بدون Enter،
                        | نعتبرها كتابة عادية.
                        */
                        resetScannerBuffer();

                    }, SCANNER_MAX_GAP + 30);

            }, true);


            /*
            |--------------------------------------------------------------------------
            | Initial barcode focus
            |--------------------------------------------------------------------------
            */
            function initialBarcodeFocus() {

                setTimeout(function() {

                    const active =
                        document.activeElement;


                    /*
                    | لا نأخذ التركيز إذا المستخدم بالفعل
                    | داخل حقل آخر.
                    */
                    if (
                        active &&
                        (
                            active.matches?.(
                                FIELD_SELECTOR
                            ) ||
                            active.matches?.(
                                'input:not([data-pos-barcode-input])'
                            ) ||
                            active.matches?.('textarea')
                        )
                    ) {

                        return;

                    }


                    focusBarcode(false);

                }, 100);

            }


            /*
            |--------------------------------------------------------------------------
            | Initial load
            |--------------------------------------------------------------------------
            */
            if (
                document.readyState ===
                'loading'
            ) {

                document.addEventListener(
                    'DOMContentLoaded',
                    initialBarcodeFocus, {
                        once: true
                    }
                );

            } else {

                initialBarcodeFocus();

            }


            /*
            |--------------------------------------------------------------------------
            | F9 = Barcode focus
            |--------------------------------------------------------------------------
            */
            document.addEventListener('keydown', function(event) {

                if (event.key !== 'F9') {
                    return;
                }


                event.preventDefault();

                focusBarcode(true);

            });


            /*
            |--------------------------------------------------------------------------
            | لا نعيد التركيز إلى الباركود بعد تعديل حقول السلة تلقائياً.
            |
            | ↑ ↓ ← → = تنقل حر داخل السلة.
            | Enter فقط = انتقال مباشر إلى الباركود.
            |--------------------------------------------------------------------------
            */


            /*
            |--------------------------------------------------------------------------
            | Livewire navigated
            |
            | لا نخطف التركيز من quantity / price / subtotal.
            |--------------------------------------------------------------------------
            */
            document.addEventListener(
                'livewire:navigated',
                function() {

                    setTimeout(function() {

                        const active =
                            document.activeElement;


                        if (
                            active &&
                            (
                                active.matches?.(
                                    FIELD_SELECTOR
                                ) ||
                                active.matches?.(
                                    'input:not([data-pos-barcode-input])'
                                ) ||
                                active.matches?.(
                                    'textarea'
                                )
                            )
                        ) {

                            return;

                        }


                        focusBarcode(false);

                    }, 100);

                }
            );


        })();
    </script>

</div>
