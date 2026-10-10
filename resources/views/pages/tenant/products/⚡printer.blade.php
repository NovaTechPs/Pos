<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\ProductBarcode;

new class extends Component
{
    public ?int $selectedProductId = null;

    public ?Product $product = null;

    public string $selectedBarcode = '';

    public bool $showName = true;
    public bool $showBarcode = true;
    public bool $showPrice = true;
    public bool $showSku = false;

    public string $labelWidth = '50';
    public string $labelHeight = '25';

    public string $productSearch = '';


    /*
    |--------------------------------------------------------------------------
    | المنتجات
    |--------------------------------------------------------------------------
    */

    public function getProductsProperty()
    {
        return Product::query()
            ->with('barcodes')
            ->when(
                trim($this->productSearch) !== '',
                function ($query) {

                    $words = preg_split(
                        '/\s+/',
                        trim($this->productSearch)
                    );

                    foreach ($words as $word) {

                        $query->where(function ($q) use ($word) {

                            $q->where(
                                'name',
                                'ilike',
                                '%' . $word . '%'
                            );

                            $q->orWhere(
                                'product_number',
                                'ilike',
                                '%' . $word . '%'
                            );

                        });
                    }
                }
            )
            ->latest()
            ->limit(100)
            ->get();
    }


    /*
    |--------------------------------------------------------------------------
    | اختيار المنتج
    |--------------------------------------------------------------------------
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


        /*
         * جلب الباركودات الموجودة
         */
        $barcodes = $this->getBarcodesList();


        /*
         * إذا لم يوجد باركود
         * ننشئ باركود جديد ونحفظه
         */
        if (empty($barcodes)) {

            $this->createBarcodeForProduct();

            $this->product->load('barcodes');

            $barcodes = $this->getBarcodesList();
        }


        /*
         * تحديد أول باركود تلقائياً
         */
        $this->selectedBarcode =
            $barcodes[0] ?? '';
    }


    /*
    |--------------------------------------------------------------------------
    | Tenant الحالي
    |--------------------------------------------------------------------------
    */

    private function getCurrentTenantId()
    {
        /*
         * الأفضل أخذ tenant من المنتج نفسه
         */
        if (
            $this->product &&
            !empty($this->product->tenant_id)
        ) {

            return $this->product->tenant_id;
        }


        /*
         * ثم من الـ session
         */
        $tenantId =
            session('active_tenant_id');


        if ($tenantId) {

            return $tenantId;
        }


        return null;
    }


    /*
    |--------------------------------------------------------------------------
    | إنشاء باركود جديد
    |--------------------------------------------------------------------------
    */

    private function createBarcodeForProduct(): string
    {
        if (!$this->product) {

            throw new \RuntimeException(
                'لم يتم اختيار منتج.'
            );
        }


        $tenantId =
            $this->getCurrentTenantId();


        if (!$tenantId) {

            throw new \RuntimeException(
                'لم يتم تحديد المتجر الحالي (Tenant).'
            );
        }


        /*
         * إنشاء باركود فريد
         */
        do {

            /*
             * مثال:
             * 20173347894
             */
            $barcode =
                '20' .
                str_pad(
                    (string) random_int(
                        0,
                        999999999
                    ),
                    9,
                    '0',
                    STR_PAD_LEFT
                );


        } while (

            ProductBarcode::query()
                ->where(
                    'tenant_id',
                    $tenantId
                )
                ->where(
                    'barcode',
                    $barcode
                )
                ->exists()

        );


        /*
         * حفظ الباركود
         */
        ProductBarcode::create([
            'tenant_id'  => $tenantId,
            'product_id' => $this->product->id,
            'barcode'    => $barcode,
        ]);


        return $barcode;
    }


    /*
    |--------------------------------------------------------------------------
    | قائمة الباركودات
    |--------------------------------------------------------------------------
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

            foreach (
                $this->product->barcodes
                as $barcode
            ) {

                if (
                    !empty($barcode->barcode)
                ) {

                    $list[] =
                        (string) $barcode->barcode;
                }
            }
        }


        return array_values(
            array_unique(
                array_filter($list)
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | اختيار باركود
    |--------------------------------------------------------------------------
    */

    public function selectBarcode(
        string $code
    ): void {

        if (!$this->product) {

            return;
        }


        $barcodes =
            $this->getBarcodesList();


        if (
            !in_array(
                $code,
                $barcodes,
                true
            )
        ) {

            return;
        }


        $this->selectedBarcode =
            $code;
    }


    /*
    |--------------------------------------------------------------------------
    | طباعة الملصق
    |--------------------------------------------------------------------------
    */

    public function printLabel(): void
    {
        if (!$this->product) {

            return;
        }


        if (
            empty($this->selectedBarcode)
        ) {

            return;
        }


        $width =
            (float) $this->labelWidth;


        $height =
            (float) $this->labelHeight;


        /*
         * التحقق من العرض
         */
        if (
            $width < 10 || $width > 300
        ) {

            $this->addError(
                'labelWidth',
                'عرض الملصق يجب أن يكون بين 10 و300 مم.'
            );

            return;
        }


        /*
         * التحقق من الارتفاع
         */
        if (
            $height < 10 || $height > 300
        ) {

            $this->addError(
                'labelHeight',
                'ارتفاع الملصق يجب أن يكون بين 10 و300 مم.'
            );

            return;
        }


        $this->resetErrorBag();


        /*
         * إرسال بيانات الطباعة
         */
        $this->dispatch(
            'start-label-print',
            [
                'name' =>
                    $this->showName
                        ? (string) $this->product->name
                        : '',

                'barcode' =>
                    $this->showBarcode
                        ? (string) $this->selectedBarcode
                        : '',

                'price' =>
                    $this->showPrice
                        ? (string) (
                            $this->product->cost_price ?? 0
                        )
                        : '',

                'sku' =>
                    $this->showSku
                        ? (string) (
                            $this->product->product_number
                            ?? $this->product->id
                        )
                        : '',

                'w' => $width,
                'h' => $height,
            ]
        );
    }
};
?>

<div
    dir="rtl"
    class="p-4 sm:p-6 max-w-5xl mx-auto space-y-6"
>

    <div
        class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 space-y-5"
    >

        {{-- ====================================================== --}}
        {{-- العنوان --}}
        {{-- ====================================================== --}}

        <h1
            class="text-xl font-bold text-gray-800 border-b pb-3"
        >
            طباعة ملصقات الباركود متعددة الأكواد
        </h1>


        {{-- ====================================================== --}}
        {{-- البحث والمنتج --}}
        {{-- ====================================================== --}}

        <div
            class="grid grid-cols-1 md:grid-cols-2 gap-4"
        >

            {{-- البحث --}}

            <div class="md:col-span-2">

                <label
                    class="block text-sm font-medium text-gray-700 mb-1"
                >
                    البحث عن الصنف / المنتج
                </label>

                <input
                    type="text"
                    wire:model.live.debounce.300ms="productSearch"
                    placeholder="اكتب اسم الصنف أو رقم الصنف..."
                    class="w-full rounded-lg border border-gray-300 p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                >

            </div>


            {{-- اختيار المنتج --}}

            <div class="md:col-span-2">

                <label
                    class="block text-sm font-medium text-gray-700 mb-1"
                >
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
                            $firstBarcode =$p->barcodes->first()?->barcode
                                ?? 'لا يوجد';
                        @endphp


                        <option
                            value="{{ $p->id }}"
                        >

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


                @if(
                    $productSearch !== '' &&$this->products->count() === 0
                )

                    <div
                        class="mt-2 text-sm text-red-500"
                    >
                        لا يوجد منتج مطابق للبحث.
                    </div>

                @endif

            </div>


            {{-- أخطاء المقاسات --}}

            @error('labelWidth')

                <div
                    class="md:col-span-2 text-sm text-red-600"
                >
                    {{ $message }}
                </div>

            @enderror


            @error('labelHeight')

                <div
                    class="md:col-span-2 text-sm text-red-600"
                >
                    {{ $message }}
                </div>

            @enderror


            {{-- ====================================================== --}}
            {{-- باركودات المنتج --}}
            {{-- ====================================================== --}}

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


                        <span
                            class="text-blue-600"
                        >

                            عدد الباركودات:

                            {{ count($this->getBarcodesList()) }}

                        </span>

                    </div>


                    <div
                        class="overflow-x-auto max-h-48 overflow-y-auto"
                    >

                        <table
                            class="w-full text-right text-xs bg-white"
                        >

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


                            <tbody
                                class="divide-y divide-gray-200"
                            >

                                @foreach(
                                    $this->getBarcodesList()
                                    as $code
                                )

                                    <tr
                                        wire:key="barcode-row-{{ $product->id }}-{{$code }}"
                                        wire:click="selectBarcode(@js($code))"
                                        class="
                                            hover:bg-blue-50
                                            cursor-pointer
                                            transition
                                            {{
                                                $selectedBarcode ===$code
                                                    ? 'bg-blue-100 font-bold text-blue-900'
                                                    : ''
                                            }}
                                        "
                                    >

                                        <td class="p-2">

                                            {{
                                                $product->product_number
                                                ?? $product->id
                                            }}

                                        </td>


                                        <td
                                            class="p-2 truncate max-w-[200px]"
                                        >

                                            {{ $product->name }}

                                        </td>


                                        <td class="p-2">

                                            {{
                                                $product->cost_price ?? 0
                                            }}

                                        </td>


                                        <td class="p-2">
                                            NIS
                                        </td>


                                        <td
                                            class="p-2 font-mono text-sm text-blue-700"
                                        >

                                            {{ $code }}

                                        </td>


                                        <td
                                            class="p-2 text-center"
                                        >

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


            {{-- ====================================================== --}}
            {{-- خيارات الطباعة --}}
            {{-- ====================================================== --}}

            <div
                class="md:col-span-2 border-t pt-4"
            >

                <label
                    class="block text-sm font-semibold text-gray-800 mb-2"
                >
                    العناصر المراد طباعتها على الملصق:
                </label>


                <div
                    class="flex flex-wrap gap-4 text-sm"
                >

                    <label
                        class="inline-flex items-center gap-2 cursor-pointer"
                    >

                        <input
                            type="checkbox"
                            wire:model.live="showName"
                            class="rounded text-blue-600 focus:ring-blue-500"
                        >

                        <span>
                            اسم الصنف
                        </span>

                    </label>


                    <label
                        class="inline-flex items-center gap-2 cursor-pointer"
                    >

                        <input
                            type="checkbox"
                            wire:model.live="showBarcode"
                            class="rounded text-blue-600 focus:ring-blue-500"
                        >

                        <span>
                            الباركود
                        </span>

                    </label>


                    <label
                        class="inline-flex items-center gap-2 cursor-pointer"
                    >

                        <input
                            type="checkbox"
                            wire:model.live="showPrice"
                            class="rounded text-blue-600 focus:ring-blue-500"
                        >

                        <span>
                            السعر
                        </span>

                    </label>


                    <label
                        class="inline-flex items-center gap-2 cursor-pointer"
                    >

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


            {{-- ====================================================== --}}
            {{-- المقاسات --}}
            {{-- ====================================================== --}}

            <div>

                <label
                    class="block text-sm font-medium text-gray-700 mb-1"
                >
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

                <label
                    class="block text-sm font-medium text-gray-700 mb-1"
                >
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


        {{-- ====================================================== --}}
        {{-- المعاينة --}}
        {{-- ====================================================== --}}

        @if($product)

            <div
                class="border-t pt-4 space-y-4"
            >

                <label
                    class="block text-xs font-semibold text-gray-500"
                >

                    معاينة الباركود المختار:

                    <span
                        class="font-mono text-blue-600"
                    >
                        {{ $selectedBarcode }}
                    </span>

                </label>


                <div
                    class="flex justify-center bg-gray-50 p-6 rounded-xl border border-dashed border-gray-300"
                >

                    <div
                        class="bg-white border border-gray-400 rounded-md shadow-sm overflow-hidden"
                        style="position:relative; width:{{ $labelWidth }}mm; height:{{$labelHeight }}mm; box-sizing:border-box; padding:1.5mm 2.5mm; background:#fff; color:#000; font-family:Arial, Tahoma, sans-serif;"
                    >

                        {{-- اسم الصنف في الأعلى --}}
                        @if($showName)
                            <div
                                class="text-center font-bold text-gray-900 truncate mb-1"
                                style="font-size: 8.5pt; line-height: 1.1;"
                            >
                                {{ $product->name }}
                            </div>
                        @endif

                        {{-- الجانب الأيسر (رقم الصنف + السعر) والجانب الأيمن (الباركود) --}}
                        <div style="display: flex; align-items: flex-end; justify-content: space-between; height: calc(100% - 4mm); gap: 1.5mm;">

                            {{-- العمود الأيسر: رقم الصنف بالأعلى ثم السعر والعملة بالأسفل --}}
                            <div style="display: flex; flex-direction: column; align-items: center; justify-content: flex-end; min-width: 10mm; font-weight: bold; line-height: 1.1; margin-bottom: 0.5mm;">
                                @if($showSku)
                                    <span style="font-size: 7.5pt; color: #333; margin-bottom: 1mm;">#{{ $product->product_number ?? $product->id }}</span>
                                @endif

                                @if($showPrice)
                                    <span style="font-size: 11pt;">{{ $product->cost_price ?? 0 }}</span>
                                    <span style="font-size: 8.5pt;">NIS</span>
                                @endif
                            </div>

                            {{-- الباركود ورقمه على اليمين --}}
                            @if($showBarcode &&$selectedBarcode)
                                <div
                                    id="preview-barcode-wrapper"
                                    data-barcode="{{ $selectedBarcode }}"
                                    style="flex-grow: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; overflow: hidden;"
                                >
                                    <svg
                                        id="preview-barcode-element"
                                        style="display:block; width:100%; height:auto; max-height:18mm;"
                                    ></svg>
                                </div>
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

                    <span
                        wire:loading.remove
                        wire:target="printLabel"
                    >
                        🖨️
                    </span>


                    <span
                        wire:loading
                        wire:target="printLabel"
                    >
                        ⏳
                    </span>


                    <span
                        wire:loading.remove
                        wire:target="printLabel"
                    >
                        طباعة الملصق الآن
                    </span>


                    <span
                        wire:loading
                        wire:target="printLabel"
                    >
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


    {{-- ============================================================ --}}
    {{-- JavaScript --}}
    {{-- ============================================================ --}}

    @script

    <script>

        window.labelPrinter =
            window.labelPrinter || {

                barcodeLibraryPromise: null,


                /*
                |--------------------------------------------------------------------------
                | تحميل JsBarcode
                |--------------------------------------------------------------------------
                */

                loadBarcodeLibrary() {

                    if (
                        typeof window.JsBarcode !==
                        'undefined'
                    ) {

                        return Promise.resolve();

                    }


                    if (
                        this.barcodeLibraryPromise
                    ) {

                        return this.barcodeLibraryPromise;

                    }


                    this.barcodeLibraryPromise =
                        new Promise(
                            (resolve, reject) => {

                                const existing =
                                    document.getElementById(
                                        'label-jsbarcode-library'
                                    );


                                if (existing) {

                                    const timer =
                                        setInterval(
                                            () => {

                                                if (
                                                    typeof window.JsBarcode !==
                                                    'undefined'
                                                ) {

                                                    clearInterval(
                                                        timer
                                                    );

                                                    resolve();

                                                }

                                            },
                                            50
                                        );


                                    setTimeout(
                                        () => {

                                            clearInterval(
                                                timer
                                            );


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

                                        },
                                        10000
                                    );


                                    return;
                                }


                                const script =
                                    document.createElement(
                                        'script'
                                    );


                                script.id =
                                    'label-jsbarcode-library';


                                script.src =
                                    'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js';


                                script.onload =
                                    () => {

                                        resolve();

                                    };


                                script.onerror =
                                    () => {

                                        reject(
                                            new Error(
                                                'Unable to load JsBarcode'
                                            )
                                        );

                                    };


                                document.head.appendChild(
                                    script
                                );

                            }
                        );


                    return this.barcodeLibraryPromise;
                },


                /*
                |--------------------------------------------------------------------------
                | رسم الباركود في المعاينة
                |--------------------------------------------------------------------------
                */

                drawPreview() {

                    const wrapper =
                        document.getElementById(
                            'preview-barcode-wrapper'
                        );


                    const element =
                        document.getElementById(
                            'preview-barcode-element'
                        );


                    if (
                        !wrapper ||
                        !element
                    ) {

                        return;

                    }


                    const code =
                        wrapper.dataset.barcode ||
                        '';


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

                                format:
                                    'CODE128',

                                width:
                                    1.3,

                                height:
                                    28,

                                displayValue:
                                    true,

                                fontSize:
                                    13,

                                margin:
                                    0,

                                textMargin:
                                    2,

                                background:
                                    '#ffffff',

                                lineColor:
                                    '#000000'

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
                |--------------------------------------------------------------------------
                | تحديث المعاينة
                |--------------------------------------------------------------------------
                */

                refreshPreview() {

                    this
                        .loadBarcodeLibrary()
                        .then(() => {

                            setTimeout(
                                () => {

                                    this.drawPreview();

                                },
                                100
                            );

                        })
                        .catch(error => {

                            console.error(
                                'JsBarcode loading error:',
                                error
                            );

                        });

                },


                /*
                |--------------------------------------------------------------------------
                | الطباعة
                |--------------------------------------------------------------------------
                */

                print(data) {

                    if (!data) {

                        return;

                    }


                    const name =
                        String(
                            data.name ?? ''
                        );


                    const barcode =
                        String(
                            data.barcode ?? ''
                        );


                    const price =
                        String(
                            data.price ?? ''
                        );


                    const sku =
                        String(
                            data.sku ?? ''
                        );


                    const width =
                        Number(
                            data.w ?? 50
                        );


                    const height =
                        Number(
                            data.h ?? 25
                        );


                    /*
                    |--------------------------------------------------------------------------
                    | نافذة الطباعة
                    |--------------------------------------------------------------------------
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
                    |--------------------------------------------------------------------------
                    | حماية HTML
                    |--------------------------------------------------------------------------
                    */

                    const escapeHtml =
                        (value) => {

                            return String(value)

                                .replace(
                                    /&/g,
                                    '&amp;'
                                )

                                .replace(
                                    /</g,
                                    '&lt;'
                                )

                                .replace(
                                    />/g,
                                    '&gt;'
                                )

                                .replace(
                                    /"/g,
                                    '&quot;'
                                )

                                .replace(
                                    /'/g,
                                    '&#039;'
                                );

                        };


                    const safeName =
                        escapeHtml(name);


                    const safePrice =
                        escapeHtml(price);


                    const safeSku =
                        escapeHtml(sku);


                    /*
                    |--------------------------------------------------------------------------
                    | صفحة الطباعة
                    |--------------------------------------------------------------------------
                    */

                    const html = `

<!DOCTYPE html>

<html dir="rtl">

<head>

    <meta charset="UTF-8">

    <title>طباعة ملصق</title>


    <style>

        @page {

            size:
                ${width}mm
                ${height}mm;

            margin: 0;

        }


        * {

            box-sizing:
                border-box;

        }


        html,
        body {

            width:
                ${width}mm;

            height:
                ${height}mm;

            margin: 0;

            padding: 0;

            background:
                #ffffff;

            overflow:
                hidden;

            font-family:
                Arial,
                Tahoma,
                sans-serif;

        }


        .label {

            width:
                ${width}mm;

            height:
                ${height}mm;

            padding:
                1.5mm 2.5mm;

            display:
                flex;

            flex-direction:
                column;

            justify-content:
                space-between;

            box-sizing:
                border-box;

        }


        .title {

            text-align:
                center;

            font-size:
                8.5pt;

            font-weight:
                bold;

            white-space:
                nowrap;

            overflow:
                hidden;

            text-overflow:
                ellipsis;

            line-height:
                1.1;

        }


        .content {

            display:
                flex;

            align-items:
                flex-end;

            justify-content:
                space-between;

            gap:
                1.5mm;

            flex-grow:
                1;

        }


        .left-box {

            display:
                flex;

            flex-direction:
                column;

            align-items:
                center;

            justify-content:
                flex-end;

            font-weight:
                bold;

            line-height:
                1.1;

            padding-bottom:
                0.5mm;

            min-width:
                10mm;

        }


        .sku-text {

            font-size:
                7.5pt;

            color:
                #333;

            margin-bottom:
                1mm;

        }


        .price-amount {

            font-size:
                11pt;

        }


        .price-currency {

            font-size:
                8.5pt;

        }


        .barcode-box {

            flex-grow:
                1;

            display:
                flex;

            flex-direction:
                column;

            align-items:
                center;

            justify-content:
                flex-end;

        }


        .barcode-box svg {

            max-width:
                100%;

            height:
                auto;

            max-height:
                18mm;

        }

    </style>

</head>


<body>

    <div class="label">


        ${
            safeName
                ? `
                    <div class="title">
                        ${safeName}
                    </div>
                `
                : ''
        }


        <div class="content">


            <div class="left-box">

                ${
                    safeSku
                        ? `
                            <span class="sku-text">
                                #${safeSku}
                            </span>
                        `
                        : ''
                }


                ${
                    safePrice
                        ? `
                            <span class="price-amount">
                                ${safePrice}
                            </span>

                            <span class="price-currency">
                                NIS
                            </span>
                        `
                        : ''
                }

            </div>


            ${
                barcode
                    ? `
                        <div class="barcode-box">

                            <svg
                                id="print-barcode"
                            ></svg>

                        </div>
                    `
                    : ''
            }


        </div>


    </div>

</body>

</html>

                    `;


                    printWindow.document.open();

                    printWindow.document.write(
                        html
                    );

                    printWindow.document.close();


                    /*
                    |--------------------------------------------------------------------------
                    | تحميل JsBarcode للطباعة
                    |--------------------------------------------------------------------------
                    */

                    const barcodeScript =
                        printWindow.document
                            .createElement(
                                'script'
                            );


                    barcodeScript.src =
                        'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js';


                    barcodeScript.onload =
                        () => {

                            try {

                                if (barcode) {

                                    const svg =
                                        printWindow
                                            .document
                                            .getElementById(
                                                'print-barcode'
                                            );


                                    if (svg) {

                                        printWindow
                                            .JsBarcode(
                                                svg,
                                                barcode,
                                                {

                                                    format:
                                                        'CODE128',

                                                    width:
                                                        1.3,

                                                    height:
                                                        28,

                                                    displayValue:
                                                        true,

                                                    fontSize:
                                                        13,

                                                    margin:
                                                        0,

                                                    textMargin:
                                                        2

                                                }
                                            );

                                    }

                                }

                            } catch (error) {

                                console.error(
                                    'Print barcode error:',
                                    error
                                );

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | الانتظار قبل الطباعة
                            |--------------------------------------------------------------------------
                            */

                            setTimeout(
                                () => {

                                    printWindow
                                        .focus();

                                    printWindow
                                        .print();

                                },
                                500
                            );

                        };


                    barcodeScript.onerror =
                        () => {

                            console.error(
                                'Failed to load JsBarcode'
                            );


                            setTimeout(
                                () => {

                                    printWindow
                                        .focus();

                                    printWindow
                                        .print();

                                },
                                300
                            );

                        };


                    printWindow.document.head
                        .appendChild(
                            barcodeScript
                        );

                }

            };


        /*
        |--------------------------------------------------------------------------
        | تشغيل المعاينة
        |--------------------------------------------------------------------------
        */

        window.labelPrinter
            .refreshPreview();


        /*
        |--------------------------------------------------------------------------
        | بعد تحديث Livewire
        |--------------------------------------------------------------------------
        */

        Livewire.hook(
            'morph.updated',
            () => {

                setTimeout(
                    () => {

                        if (
                            window.labelPrinter
                        ) {

                            window.labelPrinter
                                .refreshPreview();

                        }

                    },
                    150
                );

            }
        );


        /*
        |--------------------------------------------------------------------------
        | استقبال أمر الطباعة
        |--------------------------------------------------------------------------
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

                        window.labelPrinter
                            .print(data);

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
        |--------------------------------------------------------------------------
        | إعادة رسم المعاينة
        |--------------------------------------------------------------------------
        */

        setTimeout(
            () => {

                if (
                    window.labelPrinter
                ) {

                    window.labelPrinter
                        .refreshPreview();

                }

            },
            500
        );

    </script>

    @endscript

</div>
