<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\ProductBarcode;

new class extends Component
{
    public ?int $selectedProductId = null;

    public ?Product $product = null;

    // الباركود المحدد للطباعة
    public string $selectedBarcode = '';

    // عناصر الملصق
    public bool $showName = true;
    public bool $showBarcode = true;
    public bool $showPrice = true;
    public bool $showSku = false;

    // أبعاد الملصق بالملم
    public string $labelWidth = '50';
    public string $labelHeight = '25';

    // البحث عن المنتج
    public string $productSearch = '';

    /**
     * المنتجات
     */
    public function getProductsProperty()
    {
        return Product::query()
            ->with('barcodes')
            ->when(
                trim($this->productSearch) !== '',
                function ($query) {
                    $search = trim($this->productSearch);

                    $words = preg_split('/\s+/', $search);

                    foreach ($words as $word) {
                        $query->where(function ($q) use ($word) {
                            $q->where('name', 'ilike', '%' . $word . '%')
                                ->orWhere('product_number', 'ilike', '%' . $word . '%');
                        });
                    }
                }
            )
            ->latest()
            ->limit(100)
            ->get();
    }

    /**
     * عند اختيار منتج
     */
    public function updatedSelectedProductId($value): void
    {
        if (!$value) {
            $this->product = null;
            $this->selectedBarcode = '';

            return;
        }

        $this->product = Product::with('barcodes')
            ->find((int) $value);

        if (!$this->product) {
            $this->selectedProductId = null;
            $this->selectedBarcode = '';

            return;
        }

        $barcodes = $this->getBarcodesList();

        /*
         * إذا كان المنتج لا يحتوي على باركود:
         * يتم إنشاء باركود وحفظه مباشرة.
         */
        if (empty($barcodes)) {
            $newBarcode = $this->generateUniqueBarcode();

            ProductBarcode::create([
                'product_id' => $this->product->id,
                'barcode'    => $newBarcode,
            ]);

            // إعادة تحميل العلاقة
            $this->product->load('barcodes');

            $barcodes = $this->getBarcodesList();
        }

        $this->selectedBarcode = $barcodes[0] ?? '';
    }

    /**
     * الحصول على باركودات المنتج
     */
    public function getBarcodesList(): array
    {
        if (!$this->product) {
            return [];
        }

        $list = [];

        if (
            $this->product->relationLoaded('barcodes') &&
            $this->product->barcodes &&
            $this->product->barcodes->count() > 0
        ) {
            foreach ($this->product->barcodes as $barcode) {
                if (!empty($barcode->barcode)) {
                    $list[] = (string) $barcode->barcode;
                }
            }
        }

        /*
         * لا نعتبر product_number باركوداً محفوظاً.
         * فقط إذا كان هناك باركود فعلي في product_barcodes.
         */
        return array_values(
            array_unique(
                array_filter($list)
            )
        );
    }

    /**
     * إنشاء باركود فريد
     */
    private function generateUniqueBarcode(): string
    {
        do {
            // يبدأ بـ 20 + 9 أرقام = 11 رقم
            $barcode = '20' . str_pad(
                (string) random_int(0, 999999999),
                9,
                '0',
                STR_PAD_LEFT
            );
        } while (
            ProductBarcode::where('barcode', $barcode)->exists()
        );

        return $barcode;
    }

    /**
     * اختيار باركود
     */
    public function selectBarcode(string $code): void
    {
        if (!$this->product) {
            return;
        }

        $barcodes = $this->getBarcodesList();

        if (!in_array($code, $barcodes, true)) {
            return;
        }

        $this->selectedBarcode = $code;
    }

    /**
     * طباعة الملصق
     */
    public function printLabel(): void
    {
        if (!$this->product) {
            return;
        }

        if (empty($this->selectedBarcode)) {
            return;
        }

        $width = (float) $this->labelWidth;
        $height = (float) $this->labelHeight;

        if ($width < 10 || $width > 300) {
            $this->addError(
                'labelWidth',
                'عرض الملصق يجب أن يكون بين 10 و300 مم.'
            );

            return;
        }

        if ($height < 10 || $height > 300) {
            $this->addError(
                'labelHeight',
                'ارتفاع الملصق يجب أن يكون بين 10 و300 مم.'
            );

            return;
        }

        $this->resetErrorBag();

        $this->dispatch('start-label-print', [
            'name' => $this->showName
                ? (string) $this->product->name
                : '',

            'barcode' => $this->showBarcode
                ? (string) $this->selectedBarcode
                : '',

            'price' => $this->showPrice
                ? (string) ($this->product->cost_price ?? 0)
                : '',

            'sku' => $this->showSku
                ? (string) ($this->product->product_number ?? $this->product->id)
                : '',

            'w' => $width,
            'h' => $height,
        ]);
    }
};
?>

<div
    dir="rtl"
    class="p-4 sm:p-6 max-w-5xl mx-auto space-y-6"
>

    {{-- لوحة التحكم --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 space-y-5">

        <h1 class="text-xl font-bold text-gray-800 border-b pb-3">
            طباعة ملصقات الباركود متعددة الأكواد
        </h1>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

            {{-- ===================================================== --}}
            {{-- البحث عن المنتج --}}
            {{-- ===================================================== --}}

            <div class="md:col-span-2">

                <label class="block text-sm font-medium text-gray-700 mb-1">
                    البحث عن الصنف / المنتج
                </label>

                <input
                    type="text"
                    wire:model.live.debounce.300ms="productSearch"
                    placeholder="اكتب اسم الصنف أو رقم الصنف..."
                    class="w-full rounded-lg border border-gray-300 p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                >

            </div>


            {{-- ===================================================== --}}
            {{-- اختيار المنتج --}}
            {{-- ===================================================== --}}

            <div class="md:col-span-2">

                <label class="block text-sm font-medium text-gray-700 mb-1">
                    اختر الصنف / المنتج
                </label>

                <select
                    wire:model.live="selectedProductId"
                    class="w-full rounded-lg border border-gray-300 p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                >

                    <option value="">
                        -- اختر صنفاً من القائمة --
                    </option>

                    @foreach($this->products as $p)

                        @php
                            $firstBarcode = $p->barcodes->first()?->barcode ?? 'لا يوجد';
                        @endphp

                        <option value="{{ $p->id }}">
                            {{ $p->name }}
                            -
                            الباركود:
                            {{ $firstBarcode }}
                            -
                            السعر:
                            {{ $p->cost_price ?? 0 }}
                            NIS
                        </option>

                    @endforeach

                </select>

                @if($productSearch !== '' && $this->products->count() === 0)

                    <div class="mt-2 text-sm text-red-500">
                        لا يوجد منتج مطابق للبحث.
                    </div>

                @endif

            </div>


            {{-- ===================================================== --}}
            {{-- أخطاء الأبعاد --}}
            {{-- ===================================================== --}}

            @error('labelWidth')

                <div class="md:col-span-2 text-sm text-red-600">
                    {{ $message }}
                </div>

            @enderror

            @error('labelHeight')

                <div class="md:col-span-2 text-sm text-red-600">
                    {{ $message }}
                </div>

            @enderror


            {{-- ===================================================== --}}
            {{-- جدول الباركودات --}}
            {{-- ===================================================== --}}

            @if($product)

                <div
                    class="md:col-span-2 border rounded-xl overflow-hidden shadow-sm bg-gray-50"
                >

                    <div
                        class="bg-gray-100 px-4 py-2 font-bold text-xs text-gray-700 border-b flex justify-between items-center"
                    >

                        <span>
                            الباركودات المسجلة لهذا الصنف
                        </span>

                        <span class="text-blue-600">
                            عدد الباركودات:
                            {{ count($this->getBarcodesList()) }}
                        </span>

                    </div>


                    <div class="overflow-x-auto max-h-48 overflow-y-auto">

                        <table class="w-full text-right text-xs bg-white">

                            <thead
                                class="bg-gray-200 text-gray-700 sticky top-0"
                            >

                                <tr>

                                    <th class="p-2 border-b">
                                        رقم الصنف
                                    </th>

                                    <th class="p-2 border-b">
                                        الاسم
                                    </th>

                                    <th class="p-2 border-b">
                                        السعر
                                    </th>

                                    <th class="p-2 border-b">
                                        العملة
                                    </th>

                                    <th class="p-2 border-b">
                                        رقم الباركود
                                    </th>

                                    <th class="p-2 border-b text-center">
                                        تحديد
                                    </th>

                                </tr>

                            </thead>


                            <tbody class="divide-y divide-gray-200">

                                @foreach($this->getBarcodesList() as $code)

                                    <tr
                                        wire:key="barcode-row-{{ $product->id }}-{{ $code }}"
                                        wire:click="selectBarcode(@js($code))"
                                        class="
                                            hover:bg-blue-50
                                            cursor-pointer
                                            transition
                                            {{ $selectedBarcode === $code
                                                ? 'bg-blue-100 font-bold text-blue-900'
                                                : ''
                                            }}
                                        "
                                    >

                                        <td class="p-2">
                                            {{ $product->product_number ?? $product->id }}
                                        </td>

                                        <td class="p-2 truncate max-w-[200px]">
                                            {{ $product->name }}
                                        </td>

                                        <td class="p-2">
                                            {{ $product->cost_price ?? 0 }}
                                        </td>

                                        <td class="p-2">
                                            NIS
                                        </td>

                                        <td class="p-2 font-mono text-sm text-blue-700">
                                            {{ $code }}
                                        </td>

                                        <td class="p-2 text-center">

                                            <input
                                                type="radio"
                                                name="barcode_select"
                                                value="{{ $code }}"
                                                wire:model.live="selectedBarcode"
                                                class="text-blue-600 focus:ring-blue-500"
                                            >

                                        </td>

                                    </tr>

                                @endforeach

                            </tbody>

                        </table>

                    </div>

                </div>

            @endif


            {{-- ===================================================== --}}
            {{-- خيارات الملصق --}}
            {{-- ===================================================== --}}

            <div class="md:col-span-2 border-t pt-4">

                <label class="block text-sm font-semibold text-gray-800 mb-2">

                    العناصر المراد طباعتها على الملصق:

                </label>


                <div class="flex flex-wrap gap-4 text-sm">

                    <label class="inline-flex items-center gap-2 cursor-pointer">

                        <input
                            type="checkbox"
                            wire:model.live="showName"
                            class="rounded text-blue-600 focus:ring-blue-500"
                        >

                        <span>
                            اسم الصنف
                        </span>

                    </label>


                    <label class="inline-flex items-center gap-2 cursor-pointer">

                        <input
                            type="checkbox"
                            wire:model.live="showBarcode"
                            class="rounded text-blue-600 focus:ring-blue-500"
                        >

                        <span>
                            الباركود
                        </span>

                    </label>


                    <label class="inline-flex items-center gap-2 cursor-pointer">

                        <input
                            type="checkbox"
                            wire:model.live="showPrice"
                            class="rounded text-blue-600 focus:ring-blue-500"
                        >

                        <span>
                            السعر
                        </span>

                    </label>


                    <label class="inline-flex items-center gap-2 cursor-pointer">

                        <input
                            type="checkbox"
                            wire:model.live="showSku"
                            class="rounded text-blue-600 focus:ring-blue-500"
                        >

                        <span>
                            رقم الصنف
                        </span>

                    </label>

                </div>

            </div>


            {{-- ===================================================== --}}
            {{-- أبعاد الملصق --}}
            {{-- ===================================================== --}}

            <div>

                <label class="block text-sm font-medium text-gray-700 mb-1">
                    عرض الملصق (مم)
                </label>

                <input
                    type="number"
                    min="10"
                    max="300"
                    wire:model.live="labelWidth"
                    class="w-full rounded-lg border border-gray-300 p-2 text-sm"
                >

            </div>


            <div>

                <label class="block text-sm font-medium text-gray-700 mb-1">
                    ارتفاع الملصق (مم)
                </label>

                <input
                    type="number"
                    min="10"
                    max="300"
                    wire:model.live="labelHeight"
                    class="w-full rounded-lg border border-gray-300 p-2 text-sm"
                >

            </div>

        </div>


        {{-- ========================================================= --}}
        {{-- المعاينة والطباعة --}}
        {{-- ========================================================= --}}

        @if($product)

            <div class="border-t pt-4 space-y-4">

                <label class="block text-xs font-semibold text-gray-500">

                    معاينة الباركود المختار:

                    <span class="font-mono text-blue-600">
                        {{ $selectedBarcode }}
                    </span>

                </label>


                <div
                    class="flex justify-center bg-gray-50 p-6 rounded-xl border border-dashed border-gray-300"
                >

                    <div
                        wire:key="label-preview-{{ $product->id }}-{{ $selectedBarcode }}-{{ $labelWidth }}-{{ $labelHeight }}"
                        class="bg-white border border-gray-800 p-2 text-center flex flex-col justify-between items-center shadow-sm overflow-hidden"
                        style="
                            width: {{ $labelWidth }}mm;
                            height: {{ $labelHeight }}mm;
                            box-sizing: border-box;
                        "
                    >

                        {{-- اسم المنتج --}}
                        @if($showName)

                            <div
                                class="text-[10px] font-bold truncate w-full"
                            >
                                {{ $product->name }}
                            </div>

                        @endif


                        {{-- الباركود --}}
                        @if($showBarcode && $selectedBarcode)

                            <div
                                class="w-full flex justify-center my-0.5"
                                wire:ignore
                            >

                                <svg
                                    id="preview-barcode-element"
                                ></svg>

                            </div>

                        @endif


                        {{-- السعر ورقم الصنف --}}
                        <div
                            class="flex justify-between items-center w-full text-[9px] font-bold px-1"
                        >

                            @if($showSku)

                                <span>
                                    #{{ $product->product_number ?? $product->id }}
                                </span>

                            @endif


                            @if($showPrice)

                                <span>
                                    {{ $product->cost_price ?? 0 }}
                                    NIS
                                </span>

                            @endif

                        </div>

                    </div>

                </div>


                {{-- زر الطباعة --}}
                <button
                    type="button"
                    wire:click="printLabel"
                    wire:loading.attr="disabled"
                    class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white font-bold py-3 px-4 rounded-lg shadow transition flex items-center justify-center gap-2 cursor-pointer"
                >

                    <span wire:loading.remove wire:target="printLabel">
                        🖨️
                    </span>

                    <span wire:loading wire:target="printLabel">
                        ⏳
                    </span>

                    <span wire:loading.remove wire:target="printLabel">
                        طباعة الملصق الآن
                    </span>

                    <span wire:loading wire:target="printLabel">
                        تجهيز الطباعة...
                    </span>

                </button>

            </div>

        @else

            <div
                class="text-center py-4 text-sm text-gray-500 border-t"
            >
                يرجى اختيار صنف لعرض خيارات الباركودات المتاحة
            </div>

        @endif

    </div>


    {{-- ============================================================= --}}
    {{-- JavaScript --}}
    {{-- ============================================================= --}}
    @script

    <script>

        /*
         * ============================================================
         * Label Printer
         * ============================================================
         */

        window.labelPrinter = window.labelPrinter || {

            barcodeLibraryPromise: null,


            /*
             * تحميل JsBarcode مرة واحدة
             */
            loadBarcodeLibrary() {

                if (typeof window.JsBarcode !== 'undefined') {
                    return Promise.resolve();
                }

                if (this.barcodeLibraryPromise) {
                    return this.barcodeLibraryPromise;
                }

                this.barcodeLibraryPromise = new Promise((resolve, reject) => {

                    const existing =
                        document.getElementById('label-jsbarcode-library');

                    if (existing) {

                        const timer = setInterval(() => {

                            if (
                                typeof window.JsBarcode !==
                                'undefined'
                            ) {

                                clearInterval(timer);
                                resolve();

                            }

                        }, 50);


                        setTimeout(() => {

                            clearInterval(timer);

                            if (
                                typeof window.JsBarcode ===
                                'undefined'
                            ) {

                                reject(
                                    new Error(
                                        'JsBarcode failed to load'
                                    )
                                );

                            }

                        }, 10000);

                        return;
                    }


                    const script =
                        document.createElement('script');

                    script.id =
                        'label-jsbarcode-library';

                    script.src =
                        'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js';

                    script.onload = () => {

                        resolve();

                    };

                    script.onerror = () => {

                        reject(
                            new Error(
                                'Unable to load JsBarcode'
                            )
                        );

                    };

                    document.head.appendChild(script);

                });

                return this.barcodeLibraryPromise;
            },


            /*
             * رسم المعاينة
             */
            drawPreview() {

                const element =
                    document.getElementById(
                        'preview-barcode-element'
                    );

                if (!element) {
                    return;
                }


                const code =
                    @js($selectedBarcode);


                if (!code) {

                    element.innerHTML = '';

                    return;
                }


                if (
                    typeof window.JsBarcode ===
                    'undefined'
                ) {

                    return;
                }


                try {

                    element.innerHTML = '';

                    window.JsBarcode(
                        element,
                        String(code),
                        {
                            format: 'CODE128',

                            width: 1.2,

                            height: 22,

                            displayValue: true,

                            fontSize: 8,

                            margin: 0
                        }
                    );

                } catch (error) {

                    console.error(
                        'Barcode preview error:',
                        error
                    );

                }

            },


            /*
             * إنشاء نافذة الطباعة
             */
            print(data) {

                if (!data) {

                    console.error(
                        'No label print data'
                    );

                    return;
                }


                const name =
                    String(data.name ?? '');

                const barcode =
                    String(data.barcode ?? '');

                const price =
                    String(data.price ?? '');

                const sku =
                    String(data.sku ?? '');

                const width =
                    Number(data.w ?? 50);

                const height =
                    Number(data.h ?? 25);


                /*
                 * فتح نافذة الطباعة
                 */
                const printWindow =
                    window.open(
                        '',
                        '_blank',
                        'width=500,height=500'
                    );


                if (!printWindow) {

                    alert(
                        'يرجى السماح بالنوافذ المنبثقة للطباعة.'
                    );

                    return;
                }


                /*
                 * حماية HTML
                 */
                const escapeHtml = (value) => {

                    return String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/'/g, '&#039;');

                };


                const safeName =
                    escapeHtml(name);

                const safePrice =
                    escapeHtml(price);

                const safeSku =
                    escapeHtml(sku);


                /*
                 * نستخدم JSON.stringify
                 * حتى لا يكسر الباركود JavaScript
                 */
                const barcodeJS =
                    JSON.stringify(barcode);


                /*
                 * محتوى نافذة الطباعة
                 *
                 * مهم:
                 * لا يوجد <script> داخل document.write
                 */
                const html = `
<!DOCTYPE html>

<html dir="rtl">

<head>

    <meta charset="UTF-8">

    <title>طباعة ملصق</title>

    <style>

        @page {
            size: ${width}mm ${height}mm;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {

            width: ${width}mm;
            height: ${height}mm;

            margin: 0;
            padding: 0;

            background: #ffffff;

            overflow: hidden;

        }

        body {

            font-family:
                Arial,
                Tahoma,
                sans-serif;

        }

        .label {

            width: ${width}mm;
            height: ${height}mm;

            padding: 1.5mm;

            display: flex;

            flex-direction: column;

            justify-content: space-between;

            align-items: center;

            text-align: center;

            overflow: hidden;

        }

        .name {

            width: 100%;

            font-size: 8.5pt;

            font-weight: bold;

            white-space: nowrap;

            overflow: hidden;

            text-overflow: ellipsis;

        }

        .barcode-container {

            width: 100%;

            display: flex;

            justify-content: center;

            align-items: center;

        }

        .barcode-container svg {

            max-width: 100%;

            max-height: 14mm;

        }

        .footer {

            width: 100%;

            display: flex;

            justify-content: space-around;

            align-items: center;

            gap: 2mm;

            font-size: 8.5pt;

            font-weight: bold;

            white-space: nowrap;

        }

    </style>

</head>


<body>

    <div class="label">

        ${
            safeName
                ? `
                    <div class="name">
                        ${safeName}
                    </div>
                  `
                : ''
        }


        ${
            barcode
                ? `
                    <div class="barcode-container">
                        <svg id="print-barcode"></svg>
                    </div>
                  `
                : ''
        }


        <div class="footer">

            ${
                safeSku
                    ? `
                        <span>
                            #${safeSku}
                        </span>
                      `
                    : ''
            }


            ${
                safePrice
                    ? `
                        <span>
                            السعر: ${safePrice} NIS
                        </span>
                      `
                    : ''
            }

        </div>

    </div>

</body>

</html>
                `;


                printWindow.document.open();

                printWindow.document.write(html);

                printWindow.document.close();


                /*
                 * تحميل JsBarcode داخل نافذة الطباعة
                 *
                 * لا نستخدم <script> داخل document.write
                 */
                const barcodeScript =
                    printWindow.document.createElement(
                        'script'
                    );


                barcodeScript.src =
                    'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js';


                barcodeScript.onload = () => {

                    try {

                        if (barcode) {

                            printWindow.JsBarcode(
                                printWindow.document.getElementById(
                                    'print-barcode'
                                ),
                                barcode,
                                {
                                    format: 'CODE128',

                                    width: 1.2,

                                    height: 25,

                                    displayValue: true,

                                    fontSize: 8,

                                    margin: 0
                                }
                            );

                        }

                    } catch (error) {

                        console.error(
                            'Print barcode error:',
                            error
                        );

                    }


                    /*
                     * إعطاء المتصفح وقتاً لرسم الباركود
                     */
                    setTimeout(() => {

                        printWindow.focus();

                        printWindow.print();

                    }, 500);

                };


                barcodeScript.onerror = () => {

                    console.error(
                        'Failed to load JsBarcode in print window'
                    );

                    setTimeout(() => {

                        printWindow.focus();

                        printWindow.print();

                    }, 300);

                };


                printWindow.document.head.appendChild(
                    barcodeScript
                );

            }

        };


        /*
         * ============================================================
         * تحميل المكتبة ومعاينة أولية
         * ============================================================
         */

        window.labelPrinter
            .loadBarcodeLibrary()
            .then(() => {

                setTimeout(() => {

                    window.labelPrinter.drawPreview();

                }, 100);

            })
            .catch(error => {

                console.error(
                    'JsBarcode loading error:',
                    error
                );

            });


        /*
         * ============================================================
         * بعد تحديث Livewire
         * ============================================================
         */

        Livewire.hook(
            'morph.updated',
            () => {

                setTimeout(() => {

                    if (
                        window.labelPrinter
                    ) {

                        window.labelPrinter
                            .drawPreview();

                    }

                }, 100);

            }
        );


        /*
         * ============================================================
         * استقبال أمر الطباعة من PHP
         * ============================================================
         */

        Livewire.on(
            'start-label-print',
            (params) => {

                const data =
                    Array.isArray(params)
                        ? params[0]
                        : params;


                window.labelPrinter
                    .loadBarcodeLibrary()
                    .then(() => {

                        window.labelPrinter.print(
                            data
                        );

                    })
                    .catch(error => {

                        console.error(
                            'Label printing error:',
                            error
                        );

                        alert(
                            'تعذر تحميل مكتبة الباركود.'
                        );

                    });

            }
        );


        /*
         * ============================================================
         * رسم المعاينة بعد تحميل الصفحة
         * ============================================================
         */

        setTimeout(() => {

            if (window.labelPrinter) {

                window.labelPrinter.drawPreview();

            }

        }, 200);

    </script>

    @endscript

</div>
