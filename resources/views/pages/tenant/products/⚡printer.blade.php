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

    public function updatedProductSearch(): void

    {

        // Do not discard a selected product when a delayed search update arrives.

        if ($this->product && trim($this->productSearch) === $this->product->name) {

            return;

        }

        $this->selectedProductId = null;

        $this->product = null;

        $this->selectedBarcode = '';

    }

    /** Search by name, SKU or barcode, including Arabic names and partial codes. */

    public function getProductsProperty()

    {

        $search = trim(preg_replace('/\s+/u', ' ', $this->productSearch) ?? '');

        if (mb_strlen($search) < 2 || mb_strlen($search) > 120) {

            return collect();

        }

        $tenantId = $this->getCurrentTenantId();

        $branchId = $this->getCurrentBranchId();

        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();

        $like = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';

        $escape = static fn (string $value): string => str_replace(

            ['!', '%', '_'], ['!!', '!%', '!_'], $value

        );

        $prefix = $escape($search) . '%';

        $contains = '%' . $escape($search) . '%';

        $barcodeMatch = static function ($query, string $pattern) use ($tenantId, $like): void {

            $query->whereRaw("barcode {$like} ? ESCAPE '!'", [$pattern]);

            if ($tenantId !== null) {

                $query->where('tenant_id', $tenantId);

            }

        };

        // Exact barcode or product number first.

        $products = Product::query()

            ->where(function ($query) use ($search, $tenantId) {

                $query->where('products.product_number', $search)

                    ->orWhereHas('barcodes', function ($b) use ($search, $tenantId) {

                        $b->where('barcode', $search);

                        if ($tenantId !== null) {

                            $b->where('tenant_id', $tenantId);

                        }

                    });

            })->limit(15)->get();

        // Then prefix matches for names, codes and barcodes.

        if ($products->isEmpty()) {

            $products = Product::query()

                ->where(function ($query) use ($prefix, $like, $barcodeMatch) {

                    $query->whereRaw("products.name {$like} ? ESCAPE '!'", [$prefix])

                        ->orWhereRaw("products.product_number {$like} ? ESCAPE '!'", [$prefix])

                        ->orWhereHas('barcodes', fn ($b) => $barcodeMatch($b, $prefix));

                })->limit(15)->get();

        }

        // For names such as "موقد غاز", find "غاز" even if not the first word.

        if ($products->isEmpty()) {

            $products = Product::query()

                ->where(function ($query) use ($contains, $like, $barcodeMatch) {

                    $query->whereRaw("products.name {$like} ? ESCAPE '!'", [$contains])

                        ->orWhereRaw("products.product_number {$like} ? ESCAPE '!'", [$contains])

                        ->orWhereHas('barcodes', fn ($b) => $barcodeMatch($b, $contains));

                })->limit(15)->get();

        }

        $products->load([

            'barcodes',

            'branchProducts' => fn ($query) => $query->where('tenant_id', $tenantId)

                ->when($branchId, fn ($b) => $b->where('branch_id', $branchId)),

        ]);

        return $products;

    }

    private function getCurrentBranchId(): ?int

    {

        $branchId = auth()->user()?->branch_id ?? session('active_branch_id');

        return $branchId ? (int) $branchId : null;

    }

    public function getRetailPrice(?Product $product = null): float

    {

        $branchProduct = ($product ?? $this->product)?->branchProducts?->first();

        return (float) ($branchProduct?->retail_price ?? 0);

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

        $this->product = Product::with([

            'barcodes',

            'branchProducts' => fn ($query) => $query->where('tenant_id', $this->getCurrentTenantId())->when($this->getCurrentBranchId(), fn ($branchQuery) => $branchQuery->where('branch_id', $this->getCurrentBranchId())),

        ])->find((int) $value);

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

    public function selectProduct(int $productId): void

    {

        $this->selectedProductId = $productId;

        $this->updatedSelectedProductId($productId);

        if ($this->product) {

            $this->productSearch = $this->product->name;

        }

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

                            $this->getRetailPrice()

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

            <div class="relative md:col-span-2"

                 x-data="{

                     open: false,

                     activeIndex: -1,

                     results() { return Array.from(this.$refs.resultsList?.querySelectorAll('[data-product-result]') || []); },

                     move(direction) {

                         const items = this.results();

                         if (!items.length) return;

                         this.open = true;

                         this.activeIndex = this.activeIndex < 0

                             ? (direction > 0 ? 0 : items.length - 1)

                             : (this.activeIndex + direction + items.length) % items.length;

                         this.$nextTick(() => items[this.activeIndex]?.scrollIntoView({ block: 'nearest' }));

                     },

                     choose() {

                         const items = this.results();

                         if (!this.open || !items.length) return;

                         const index = this.activeIndex >= 0 && this.activeIndex < items.length ? this.activeIndex : 0;

                         items[index].click();

                     }

                 }"

                 x-on:click.outside="open = false; activeIndex = -1">

                <label

                    class="block text-sm font-medium text-gray-700 mb-1"

                >

                    البحث عن الصنف / المنتج

                </label>

                <input

                    type="text"

                    wire:model.live.debounce.350ms="productSearch"

                    autocomplete="off"

                    x-on:focus="open = true"

                    x-on:input="open = true; activeIndex = -1"

                    x-on:keydown.arrow-down.prevent.stop="move(1)"

                    x-on:keydown.arrow-up.prevent.stop="move(-1)"

                    x-on:keydown.enter.prevent.stop="choose()"

                    x-on:keydown.escape="open = false; activeIndex = -1"

                    placeholder="اكتب اسم الصنف أو رقم الصنف أو الباركود..."

                    class="w-full rounded-lg border border-gray-300 p-2.5 text-sm focus:ring-2 focus:ring-blue-500 outline-none"

                >

                @if(trim($productSearch) !== '')

                    <div x-ref="resultsList" x-show="open" x-transition class="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white shadow-xl">

                        @forelse($this->products as $p)

                            @php

                                $firstBarcode = $p->barcodes->first()?->barcode ?? 'لا يوجد';

                            @endphp

                            <button

                                type="button"

                                wire:key="label-search-result-{{ $p->id }}"

                                data-product-result

                                x-on:mouseenter="activeIndex = results().indexOf($el)"

                                x-bind:class="activeIndex === results().indexOf($el) ? 'bg-blue-100 ring-1 ring-inset ring-blue-400' : ''"

                                wire:click="selectProduct({{ $p->id }})"

                                                                x-on:click.stop="open = false; activeIndex = -1"

                                class="block w-full border-b border-gray-100 px-4 py-3 text-right transition last:border-b-0 hover:bg-blue-50 focus:bg-blue-50 focus:outline-none"

                            >

                                <span class="block text-sm font-semibold text-gray-800">{{ $p->name }}</span>

                                <span class="mt-1 block text-xs text-gray-500">

                                    رقم الصنف: {{ $p->product_number ?? '—' }}

                                    <span class="mx-1">·</span>

                                    الباركود: {{ $firstBarcode }}

                                    <span class="mx-1">·</span>

                                    السعر: {{ $this->getRetailPrice($p) }} NIS

                                </span>

                            </button>

                        @empty

                            <div class="px-4 py-3 text-sm text-gray-500">لا يوجد منتج مطابق للبحث.</div>

                        @endforelse

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

                        <div class="app-table-scroll" role="region" aria-label="جدول البيانات — اسحب لعرض بقية الأعمدة" tabindex="0">

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

                                                $this->getRetailPrice()

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

                        style="position:relative; width:{{ $labelWidth }}mm; height:{{$labelHeight }}mm; box-sizing:border-box; padding:1.2mm 1.6mm; background:#fff; color:#000; font-family:Arial, Tahoma, sans-serif;"

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

                        <div style="display: flex; align-items: flex-start; justify-content: space-between; height: auto; gap: 1.5mm;">

                            {{-- العمود الأيسر: رقم الصنف بالأعلى ثم السعر والعملة بالأسفل --}}

                            <div style="display: flex; flex-direction: column; align-items: center; justify-content: flex-end; min-width: 10mm; font-weight: bold; line-height: 1.1; margin-bottom: 0.5mm;">

                                @if($showSku)

                                    <span style="font-size: 7.5pt; color: #333; margin-bottom: 1mm;">#{{ $product->product_number ?? $product->id }}</span>

                                @endif

                                @if($showPrice)

                                    <span style="font-size: 11pt;">{{ $this->getRetailPrice() }}</span>

                                    <span style="font-size: 8.5pt;">NIS</span>

                                @endif

                            </div>

                            {{-- الباركود ورقمه على اليمين --}}

                            @if($showBarcode &&$selectedBarcode)

                                <div

                                    id="preview-barcode-wrapper"

                                    data-barcode="{{ $selectedBarcode }}"

                        style="flex-grow: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; overflow: visible;"

                                >

                                    <svg

                                        id="preview-barcode-element"

                                        style="display:block; width:100%; height:auto; max-height:15mm;"

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

                                    22,

                                displayValue:

                                    true,

                                fontSize:

                                    10,

                                margin:

                                    0,

                                textMargin:

                                    1,

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

                            '\\_blank',

                            'width=650,height=500'

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
@page { size: ${width}mm ${height}mm; margin: 0; }
* { box-sizing: border-box; }
html, body { width: ${width}mm; height: ${height}mm; margin: 0; padding: 0; background: #fff; overflow: hidden; font-family: Tahoma, Arial, sans-serif; }
.label { width: 100%; height: 100%; padding: 1.2mm 1.6mm; display: flex; flex-direction: column; gap: 0.8mm; overflow: hidden; color: #000; }
.title { width: 100%; text-align: center; direction: rtl; font-size: 9pt; font-weight: 800; line-height: 1.15; min-height: 4mm; max-height: 8mm; overflow: hidden; overflow-wrap: anywhere; }
.content { display: flex; flex: 1; min-height: 0; align-items: stretch; gap: 1mm; direction: rtl; }
.barcode-box { flex: 1 1 auto; min-width: 0; height: 100%; display: flex; justify-content: center; align-items: center; overflow: hidden; }
.barcode-box svg { display: block; width: 100%; max-width: 100%; height: 100%; max-height: 100%; }
.left-box { flex: 0 0 11mm; min-width: 11mm; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; line-height: 1.05; gap: 0.4mm; overflow: hidden; }
.sku-text { font-size: 6pt; overflow-wrap: anywhere; }
.price-amount { font-family: Arial, Tahoma, sans-serif; font-size: 15pt; font-weight: 900; line-height: 1; }
.price-currency { font-size: 9pt; font-weight: 900; line-height: 1; }
@media print { html,body { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
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

                                                        1.0,

                                                    height:

                                                        42,

                                                    displayValue:

                                                        true,

                                                    textPosition:

                                                        'bottom',

                                                    fontSize:

                                                        11,

                                                    margin:

                                                        0,

                                                    textMargin:

                                                        1

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
