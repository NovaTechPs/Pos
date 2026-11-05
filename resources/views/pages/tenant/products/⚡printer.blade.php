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

                                        style="display:block; width:100%; height:100%; max-height:100%;"

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
(() => {
    // One printer controller per browser page, without accumulating Livewire listeners.
    if (!window.labelPrinter) {
        window.labelPrinter = {
            libraryPromise: null,
            loadBarcodeLibrary() {
                if (typeof window.JsBarcode === 'function') return Promise.resolve();
                if (this.libraryPromise) return this.libraryPromise;
                this.libraryPromise = new Promise((resolve, reject) => {
                    let script = document.getElementById('label-jsbarcode-library');
                    if (!script) {
                        script = document.createElement('script');
                        script.id = 'label-jsbarcode-library';
                        script.src = 'https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js';
                        document.head.appendChild(script);
                    }
                    if (typeof window.JsBarcode === 'function') return resolve();
                    script.addEventListener('load', () => {
                        typeof window.JsBarcode === 'function' ? resolve() : reject(new Error('JsBarcode unavailable'));
                    }, {once: true});
                    script.addEventListener('error', () => reject(new Error('تعذر تحميل مكتبة الباركود')), {once: true});
                    setTimeout(() => {
                        if (typeof window.JsBarcode !== 'function') reject(new Error('انتهت مهلة تحميل مكتبة الباركود'));
                    }, 12000);
                }).catch(err => { this.libraryPromise = null; throw err; });
                return this.libraryPromise;
            },
            drawPreview() {
                const wrapper = document.getElementById('preview-barcode-wrapper');
                const svg = document.getElementById('preview-barcode-element');
                if (!wrapper || !svg || typeof window.JsBarcode !== 'function') return;
                const barcode = String(wrapper.dataset.barcode || '');
                if (!barcode) return;
                try {
                    window.JsBarcode(svg, barcode, {
                        format: 'CODE128', width: 1.2, height: 36,
                        displayValue: true, textPosition: 'bottom',
                        fontSize: 12, textMargin: 3, margin: 5,
                        background: '#fff', lineColor: '#000'
                    });
                } catch (error) { console.error('Barcode preview error:', error); }
            },
            refreshPreview() {
                if (!document.getElementById('preview-barcode-wrapper')) return;
                this.loadBarcodeLibrary().then(() => this.drawPreview()).catch(console.error);
            },
            escapeHtml(value) {
                return String(value ?? '').replace(/[&<>"']/g, c => ({
                    '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'
                })[c]);
            },
            async print(data) {
                if (!data) return;
                const barcode = String(data.barcode ?? '').trim();
                if (!barcode) { alert('لا يوجد باركود محدد للطباعة.'); return; }
                const w = Number(data.w || 50), h = Number(data.h || 25);
                if (!Number.isFinite(w) || !Number.isFinite(h) || w < 10 || h < 10 || w > 300 || h > 300) return;
                // Render the barcode ONCE with the library already loaded in the parent.
                // Its human-readable value is inside the SVG and must not be clipped.
                try { await this.loadBarcodeLibrary(); }
                catch (err) { alert('تعذر تحميل مكتبة الباركود: ' + err.message); return; }
                const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                try {
                    window.JsBarcode(svg, barcode, {
                        format: 'CODE128', width: 1.5, height: 40,
                        displayValue: true, textPosition: 'bottom',
                        font: 'Arial', fontSize: 13, textMargin: 4,
                        margin: 7, background: '#fff', lineColor: '#000'
                    });
                } catch (err) { alert('الباركود غير صالح للطباعة: ' + err.message); return; }
                const barcodeSvg = new XMLSerializer().serializeToString(svg);
                const name = this.escapeHtml(data.name || '');
                const price = this.escapeHtml(data.price || '');
                const sku = this.escapeHtml(data.sku || '');
                const hasSide = Boolean(price || sku);
                const printWindow = window.open('', '_blank', 'width=650,height=500');
                if (!printWindow) { alert('يرجى السماح بالنوافذ المنبثقة للطباعة.'); return; }
                const html = `<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">
<title>طباعة ملصق</title><style>
@page { size: ${w}mm ${h}mm; margin: 0; }
* { box-sizing: border-box; }
html, body { width: ${w}mm; height: ${h}mm; padding: 0; margin: 0; overflow: hidden; background: #fff; }
body { font-family: Tahoma, Arial, sans-serif; color: #000; }
.label { width: ${w}mm; height: ${h}mm; padding: 1mm 1.2mm 1.3mm; display: flex; flex-direction: column; gap: 0.4mm; overflow: hidden; }
.title { flex: 0 0 auto; max-height: 8mm; font-size: 8pt; font-weight: 800; line-height: 1.05; text-align: center; overflow: hidden; overflow-wrap: anywhere; }
.content { display: flex; flex: 1 1 0; min-height: 0; gap: 0.8mm; direction: rtl; align-items: stretch; }
.barcode-box { flex: 1 1 0; min-width: 0; min-height: 0; display: flex; align-items: stretch; justify-content: center; padding: 0 0.3mm; }
.barcode-box svg { display: block; width: 100%; height: 100%; max-width: 100%; max-height: 100%; }
.side { flex: 0 0 10mm; min-width: 10mm; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.2mm; text-align: center; }
.price { font: 900 14pt/1 Arial, sans-serif; }
.currency { font: 900 8pt/1 Arial, sans-serif; }
.sku { font: 700 6pt/1 Arial, sans-serif; overflow-wrap: anywhere; }
@media print { html,body { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }
</style></head><body><div class="label">
${name ? `<div class="title">${name}</div>` : ''}
<div class="content">
<div class="barcode-box">${barcodeSvg}</div>
${hasSide ? `<div class="side">${sku ? `<div class="sku">#${sku}</div>` : ''}${price ? `<div class="price">${price}</div><div class="currency">NIS</div>` : ''}</div>` : ''}
</div></div></body></html>`;
                printWindow.document.open();
                printWindow.document.write(html);
                printWindow.document.close();
                // Print only after layout and SVG are ready.
                let didPrint = false;
                const doPrint = () => {
                    if (didPrint || printWindow.closed) return;
                    didPrint = true;
                    printWindow.focus();
                    printWindow.print();
                };
                printWindow.addEventListener('load', () => {
                    printWindow.requestAnimationFrame(() => printWindow.requestAnimationFrame(doPrint));
                }, {once: true});
                if (printWindow.document.readyState === 'complete') {
                    setTimeout(doPrint, 350);
                }
            }
        };
    }
    if (!window.__barcodeLabelListenersRegistered) {
        window.__barcodeLabelListenersRegistered = true;
        Livewire.on('start-label-print', (params) => {
            const data = Array.isArray(params) ? params[0] : params;
            window.labelPrinter.print(data);
        });
        Livewire.hook('morph.updated', ({el}) => {
            if (!document.getElementById('preview-barcode-wrapper')) return;
            clearTimeout(window.__barcodePreviewTimer);
            window.__barcodePreviewTimer = setTimeout(() => window.labelPrinter.drawPreview(), 120);
        });
    }
    window.labelPrinter.refreshPreview();
})();
</script>
@endscript

</div>
