<?php

use Livewire\Component;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    public string $search = '';
    public ?int $selectedBranchId = null;
    public string $labelSize = '40x30';
    public string $labelLayout = 'vertical';
    public bool $showPrice = true;
    public bool $showProductName = true;
    public bool $showSku = false;
    public bool $showBarcodeText = true;

    /** @var array<int,int> */
    public array $printQuantities = [];

    /** @var array<int> */
    public array $selectedProducts = [];

    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    public function mount(): void
    {
        $this->selectedBranchId = $this->resolveBranchId();
    }

    private function tenantId(): ?int
    {
        $user = Auth::user();
        $tenantId = session('active_tenant_id');

        if ($tenantId) {
            return (int) $tenantId;
        }

        if ($user?->tenant_id) {
            return (int) $user->tenant_id;
        }

        if ($user && method_exists($user, 'tenants')) {
            $tenant = $user->tenants()->first();
            return $tenant ? (int) $tenant->id : null;
        }

        return null;
    }

    private function resolveBranchId(): ?int
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();

        if (!$tenantId) {
            return null;
        }

        if ($user?->branch_id) {
            return (int) $user->branch_id;
        }

        $sessionBranchId = session('active_branch_id');

        if ($sessionBranchId && Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($sessionBranchId)
            ->exists()) {
            return (int) $sessionBranchId;
        }

        return Branch::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->value('id');
    }

    public function updatedSelectedBranchId($value): void
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();

        if (!$tenantId) {
            $this->selectedBranchId = null;
            return;
        }

        if ($user?->branch_id) {
            $this->selectedBranchId = (int) $user->branch_id;
            return;
        }

        $branchId = (int) $value;

        $valid = Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($branchId)
            ->exists();

        if (!$valid) {
            $this->selectedBranchId = $this->resolveBranchId();
            $this->errorMessage = 'الفرع المحدد غير صالح لهذا المتجر.';
            return;
        }

        session(['active_branch_id' => $branchId]);
    }

    public function generateBarcode(int $productId): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            $this->errorMessage = 'لم يتم تحديد المتجر الحالي.';
            return;
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($productId)
            ->first();

        if (!$product) {
            $this->errorMessage = 'المنتج غير موجود.';
            return;
        }

        $existing = ProductBarcode::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $product->id)
            ->first();

        if ($existing) {
            $this->successMessage = 'المنتج لديه باركود بالفعل.';
            return;
        }

        do {
            $barcode = '20' . str_pad(
                (string) random_int(1, 99999999999),
                11,
                '0',
                STR_PAD_LEFT
            );
        } while (ProductBarcode::query()
            ->where('tenant_id', $tenantId)
            ->where('barcode', $barcode)
            ->exists());

        try {
            ProductBarcode::create([
                'tenant_id' => $tenantId,
                'product_id' => $product->id,
                'barcode' => $barcode,
            ]);
        } catch (\Throwable $e) {
            report($e);
            $this->errorMessage = 'تعذر حفظ الباركود في قاعدة البيانات: ' . $e->getMessage();
            return;
        }

        if (!in_array((int) $product->id, array_map('intval', $this->selectedProducts), true)) {
            $this->selectedProducts[] = (int) $product->id;
        }

        $this->printQuantities[$product->id] = max(
            1,
            (int) ($this->printQuantities[$product->id] ?? 1)
        );

        $this->errorMessage = null;
        $this->successMessage = "تم إنشاء الباركود {$barcode} وحفظه للمنتج.";

        // Force the browser to rebuild the SVG after Livewire updates the preview.
        $this->dispatch('barcode-preview-refresh');
    }

    public function addProduct(int $productId): void
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveBranchId();

        if (!$tenantId || !$branchId) {
            $this->errorMessage = 'لم يتم تحديد المتجر أو الفرع الحالي.';
            return;
        }

        $exists = Product::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($productId)
            ->whereHas('branchProducts', fn ($q) => $q->where('tenant_id', $tenantId)->where('branch_id', $branchId))
            ->exists();

        if (!$exists) {
            $this->errorMessage = 'المنتج غير موجود في الفرع المحدد.';
            return;
        }

        if (!in_array($productId, $this->selectedProducts, true)) {
            $this->selectedProducts[] = $productId;
        }

        $this->printQuantities[$productId] = max(1, (int) ($this->printQuantities[$productId] ?? 1));
        $this->errorMessage = null;
    }

    public function removeProduct(int $productId): void
    {
        $this->selectedProducts = array_values(array_filter(
            $this->selectedProducts,
            fn ($id) => (int) $id !== $productId
        ));

        unset($this->printQuantities[$productId]);
    }

    public function clearSelection(): void
    {
        $this->selectedProducts = [];
        $this->printQuantities = [];
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function selectAllVisible(): void
    {
        foreach ($this->products() as $product) {
            $this->addProduct((int) $product->id);
        }

        $this->successMessage = 'تم تحديد المنتجات الظاهرة.';
    }

    public function incrementQuantity(int $productId): void
    {
        $this->printQuantities[$productId] = min(999, ((int) ($this->printQuantities[$productId] ?? 1)) + 1);
    }

    public function decrementQuantity(int $productId): void
    {
        $this->printQuantities[$productId] = max(1, ((int) ($this->printQuantities[$productId] ?? 1)) - 1);
    }

    public function setQuantity(int $productId, $quantity): void
    {
        $quantity = (int) $quantity;
        $this->printQuantities[$productId] = min(999, max(1, $quantity));
    }

    public function preparePrint(): void
    {
        if (empty($this->selectedProducts)) {
            $this->errorMessage = 'حدد منتجاً واحداً على الأقل قبل الطباعة.';
            return;
        }

        foreach ($this->selectedProducts as $productId) {
            $this->printQuantities[$productId] = min(999, max(1, (int) ($this->printQuantities[$productId] ?? 1)));
        }

        $this->errorMessage = null;
        $this->successMessage = 'المعاينة جاهزة للطباعة.';
        $this->dispatch('barcode-print-ready');
    }

    private function products()
    {
        $tenantId = $this->tenantId();
        $branchId = $this->resolveBranchId();
        $search = trim($this->search);

        if (!$tenantId || !$branchId) {
            return collect();
        }

        return Product::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'barcodes' => fn ($q) => $q->where('tenant_id', $tenantId),
                'branchProducts' => fn ($q) => $q->where('tenant_id', $tenantId)->where('branch_id', $branchId),
            ])
            ->when($search !== '', function ($query) use ($search, $tenantId): void {
                $query->where(function ($q) use ($search, $tenantId): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('id', $search)
                        ->orWhereHas('barcodes', function ($barcodeQuery) use ($search, $tenantId): void {
                            $barcodeQuery
                                ->where('tenant_id', $tenantId)
                                ->where('barcode', 'like', "%{$search}%");
                        });
                });
            })
            ->orderBy('name')
            ->limit(40)
            ->get();
    }

    private function selectedProductData()
    {
        if (empty($this->selectedProducts)) {
            return collect();
        }

        $tenantId = $this->tenantId();
        $branchId = $this->resolveBranchId();

        if (!$tenantId || !$branchId) {
            return collect();
        }

        return Product::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $this->selectedProducts)
            ->with([
                'barcodes' => fn ($q) => $q->where('tenant_id', $tenantId),
                'branchProducts' => fn ($q) => $q->where('tenant_id', $tenantId)->where('branch_id', $branchId),
            ])
            ->get()
            ->sortBy(fn ($product) => array_search($product->id, $this->selectedProducts, true))
            ->values();
    }

    public function render()
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();
        $branches = collect();

        if ($tenantId && !$user?->branch_id) {
            $branches = Branch::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get();
        } elseif ($tenantId && $user?->branch_id) {
            $branches = Branch::query()->whereKey($user->branch_id)->get();
        }

        return $this->view([
            'branches' => $branches,
            'products' => $this->products(),
            'selectedProductData' => $this->selectedProductData(),
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="min-h-[calc(100vh-4rem)] bg-slate-100 font-sans">
    <div
        x-data="barcodePrinterPage()"
        x-init="init()"
        class="mx-auto flex min-h-[calc(100vh-4rem)] max-w-[1800px] flex-col gap-3 p-3 md:p-4"
    >
        {{-- Header --}}
        <header class="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:flex-row md:items-center md:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-900 text-white shadow-lg">
                    <flux:icon icon="qr-code" class="h-6 w-6" />
                </div>
                <div>
                    <h1 class="text-xl font-black tracking-tight text-slate-900">طباعة الباركود</h1>
                    <p class="mt-0.5 text-xs font-semibold text-slate-500">تجهيز ملصقات المنتجات ومعاينتها قبل إرسالها إلى الطابعة.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-600">
                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                    {{ count($selectedProducts) }} منتج محدد
                </div>
                <button type="button" wire:click="clearSelection" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-black text-slate-600 transition hover:bg-slate-50">
                    مسح التحديد
                </button>
                <button type="button" wire:click="preparePrint" class="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-5 py-2.5 text-xs font-black text-white shadow-sm transition hover:bg-slate-800">
                    <flux:icon icon="printer" class="h-4 w-4" />
                    طباعة الملصقات
                </button>
            </div>
        </header>

        @if ($errorMessage || $successMessage)
            <div class="grid gap-2 md:grid-cols-2">
                @if ($errorMessage)
                    <div class="flex items-center justify-between rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-bold text-rose-800">
                        <span>⚠ {{ $errorMessage }}</span>
                        <button type="button" wire:click="$set('errorMessage', null)" class="mr-2">✕</button>
                    </div>
                @endif
                @if ($successMessage)
                    <div class="flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs font-bold text-emerald-800">
                        <span>✓ {{ $successMessage }}</span>
                        <button type="button" wire:click="$set('successMessage', null)" class="mr-2">✕</button>
                    </div>
                @endif
            </div>
        @endif

        <div class="grid min-h-0 flex-1 gap-3 xl:grid-cols-12">
            {{-- Controls --}}
            <section class="xl:col-span-4 2xl:col-span-3">
                <div class="sticky top-3 space-y-3">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center gap-2">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
                                <flux:icon icon="adjustments-horizontal" class="h-4 w-4" />
                            </div>
                            <div>
                                <h2 class="text-sm font-black text-slate-900">إعدادات الطباعة</h2>
                                <p class="text-[10px] font-semibold text-slate-400">اختر شكل الملصق ومحتواه</p>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div>
                                <label class="mb-1.5 block text-[11px] font-black text-slate-500">الفرع</label>
                                <select wire:model.live="selectedBranchId" class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm font-bold text-slate-700 focus:border-indigo-500 focus:ring-indigo-500">
                                    @forelse ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @empty
                                        <option value="">لا يوجد فرع متاح</option>
                                    @endforelse
                                </select>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1.5 block text-[11px] font-black text-slate-500">مقاس الملصق</label>
                                    <select wire:model.live="labelSize" class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm font-bold text-slate-700">
                                        <option value="40x30">40 × 30 مم</option>
                                        <option value="50x30">50 × 30 مم</option>
                                        <option value="58x40">58 × 40 مم</option>
                                        <option value="60x40">60 × 40 مم</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-[11px] font-black text-slate-500">اتجاه المحتوى</label>
                                    <select wire:model.live="labelLayout" class="w-full rounded-xl border-slate-200 bg-slate-50 text-sm font-bold text-slate-700">
                                        <option value="vertical">عمودي</option>
                                        <option value="horizontal">أفقي</option>
                                    </select>
                                </div>
                            </div>

                            <div class="space-y-2 rounded-xl border border-slate-100 bg-slate-50 p-3">
                                <label class="flex cursor-pointer items-center justify-between gap-3 text-xs font-black text-slate-700">
                                    <span>اسم المنتج</span>
                                    <input type="checkbox" wire:model.live="showProductName" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                </label>
                                <label class="flex cursor-pointer items-center justify-between gap-3 text-xs font-black text-slate-700">
                                    <span>السعر</span>
                                    <input type="checkbox" wire:model.live="showPrice" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                </label>
                                <label class="flex cursor-pointer items-center justify-between gap-3 text-xs font-black text-slate-700">
                                    <span>SKU / رقم المنتج</span>
                                    <input type="checkbox" wire:model.live="showSku" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                </label>
                                <label class="flex cursor-pointer items-center justify-between gap-3 text-xs font-black text-slate-700">
                                    <span>أرقام الباركود</span>
                                    <input type="checkbox" wire:model.live="showBarcodeText" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <div>
                                <h2 class="text-sm font-black text-slate-900">إضافة المنتجات</h2>
                                <p class="text-[10px] font-semibold text-slate-400">ابحث بالاسم أو الباركود</p>
                            </div>
                            <button type="button" wire:click="selectAllVisible" class="text-[10px] font-black text-indigo-600 hover:text-indigo-800">تحديد الظاهر</button>
                        </div>

                        <div class="relative">
                            <flux:icon icon="magnifying-glass" class="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input wire:model.live.debounce.250ms="search" type="search" placeholder="اسم المنتج أو الباركود..." class="w-full rounded-xl border-slate-200 bg-slate-50 py-3 pr-9 pl-3 text-sm font-bold text-slate-700 placeholder:text-slate-400 focus:border-indigo-500 focus:ring-indigo-500">
                        </div>

                        <div class="mt-3 max-h-[360px] space-y-1.5 overflow-y-auto pl-1">
                            @forelse ($products as $product)
                                @php
                                    $bp = $product->branchProducts->first();
                                    $barcode = $product->barcodes->first()?->barcode;
                                    $price = (float) ($bp?->retail_price ?? 0);
                                    $isSelected = in_array((int) $product->id, $selectedProducts, true);
                                @endphp
                                <div class="flex items-center gap-2 rounded-xl border {{ $isSelected ? 'border-indigo-200 bg-indigo-50' : 'border-slate-100 bg-white' }} p-2.5 transition">
                                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $isSelected ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-500' }} text-[10px] font-black">
                                        {{ $product->id }}
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-xs font-black text-slate-800">{{ $product->name }}</div>
                                        <div class="mt-0.5 flex items-center gap-2 text-[9px] font-bold text-slate-400">
                                            <span>{{ $barcode ?: 'بدون باركود' }}</span>
                                            <span>•</span>
                                            <span>{{ number_format($price, 2) }}</span>
                                        </div>
                                    </div>
                                    @if (!$barcode)
                                        <button type="button" wire:click="generateBarcode({{ $product->id }})" class="shrink-0 rounded-lg bg-amber-500 px-2.5 py-2 text-[9px] font-black text-white shadow-sm hover:bg-amber-600" title="إنشاء باركود">
                                            + إنشاء باركود
                                        </button>
                                    @elseif ($isSelected)
                                        <button type="button" wire:click="removeProduct({{ $product->id }})" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-rose-500 shadow-sm hover:bg-rose-50" title="إزالة">
                                            <flux:icon icon="minus" class="h-4 w-4" />
                                        </button>
                                    @else
                                        <button type="button" wire:click="addProduct({{ $product->id }})" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-900 text-white shadow-sm hover:bg-slate-800" title="إضافة">
                                            <flux:icon icon="plus" class="h-4 w-4" />
                                        </button>
                                    @endif
                                </div>
                            @empty
                                <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-xs font-bold text-slate-400">
                                    لا توجد منتجات مطابقة للبحث.
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </section>

            {{-- Preview --}}
            <section class="min-h-0 xl:col-span-8 2xl:col-span-9">
                <div class="flex h-full min-h-[700px] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-slate-200 shadow-sm">
                    <div class="flex shrink-0 flex-col gap-2 border-b border-slate-200 bg-white px-4 py-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h2 class="text-sm font-black text-slate-900">معاينة الملصقات</h2>
                            <p class="text-[10px] font-semibold text-slate-400">المعاينة تمثل الشكل الذي سيرسل إلى الطابعة.</p>
                        </div>
                        <div class="flex items-center gap-2 text-[10px] font-black text-slate-500">
                            <span class="rounded-lg bg-slate-100 px-2 py-1">{{ $labelSize }} مم</span>
                            <span class="rounded-lg bg-slate-100 px-2 py-1">{{ $labelLayout === 'vertical' ? 'عمودي' : 'أفقي' }}</span>
                        </div>
                    </div>

                    <div id="barcode-print-area" class="min-h-0 flex-1 overflow-auto p-5 md:p-8">
                        @if ($selectedProductData->isEmpty())
                            <div class="flex h-full min-h-[500px] items-center justify-center">
                                <div class="max-w-md text-center">
                                    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-3xl bg-white text-slate-300 shadow-sm">
                                        <flux:icon icon="qr-code" class="h-10 w-10" />
                                    </div>
                                    <h3 class="mt-5 text-lg font-black text-slate-700">لم يتم اختيار منتجات</h3>
                                    <p class="mt-2 text-sm font-semibold leading-6 text-slate-400">ابحث عن المنتجات من القائمة الجانبية، ثم أضفها إلى قائمة الطباعة لتظهر هنا.</p>
                                </div>
                            </div>
                        @else
                            <div class="flex flex-wrap items-start justify-center gap-4" id="barcode-labels">
                                @foreach ($selectedProductData as $product)
                                    @php
                                        $bp = $product->branchProducts->first();
                                        $barcode = $product->barcodes->first()?->barcode;
                                        $price = (float) ($bp?->retail_price ?? 0);
                                        $qty = max(1, (int) ($printQuantities[$product->id] ?? 1));
                                        $sku = $product->sku ?? $product->code ?? $product->id;
                                    @endphp

                                    @for ($copy = 0; $copy < $qty; $copy++)
                                    <div class="barcode-label label-{{ $labelSize }} layout-{{ $labelLayout }}">
                                        <div class="label-content">
                                            @if ($showProductName)
                                                <div class="label-name">{{ $product->name }}</div>
                                            @endif

                                            @if ($barcode)
                                                <svg wire:ignore class="barcode-svg" data-barcode-value="{{ $barcode }}" data-barcode-text="{{ $showBarcodeText ? 'true' : 'false' }}"></svg>
                                            @else
                                                <div class="no-barcode">
                                                    <div>لا يوجد باركود</div>
                                                    <button type="button" wire:click="generateBarcode({{ $product->id }})" class="mt-1 rounded-lg bg-slate-900 px-2.5 py-1.5 text-[9px] font-black text-white">
                                                        + إنشاء باركود
                                                    </button>
                                                </div>
                                            @endif

                                            <div class="label-bottom">
                                                @if ($showPrice)
                                                    <span class="label-price">{{ number_format($price, 2) }}</span>
                                                @endif
                                                @if ($showSku)
                                                    <span class="label-sku">SKU: {{ $sku }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                    @endfor
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if ($selectedProductData->isNotEmpty())
                        <div class="shrink-0 border-t border-slate-200 bg-white p-3">
                            <div class="grid gap-2 md:grid-cols-2 lg:grid-cols-3">
                                @foreach ($selectedProductData as $product)
                                    <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="truncate text-[11px] font-black text-slate-700">{{ $product->name }}</div>
                                            <div class="text-[9px] font-bold text-slate-400">{{ $product->barcodes->first()?->barcode ?: 'بدون باركود' }}</div>
                                        </div>
                                        <div class="flex items-center gap-1 rounded-lg bg-white p-1 shadow-sm">
                                            <button type="button" wire:click="decrementQuantity({{ $product->id }})" class="flex h-7 w-7 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100">−</button>
                                            <input type="number" min="1" max="999" wire:model.live="printQuantities.{{ $product->id }}" wire:change="setQuantity({{ $product->id }}, $event.target.value)" class="h-7 w-12 border-0 bg-transparent p-0 text-center text-xs font-black text-slate-800 focus:ring-0">
                                            <button type="button" wire:click="incrementQuantity({{ $product->id }})" class="flex h-7 w-7 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100">+</button>
                                        </div>
                                        <button type="button" wire:click="removeProduct({{ $product->id }})" class="flex h-7 w-7 items-center justify-center rounded-lg text-rose-500 hover:bg-rose-50">
                                            <flux:icon icon="trash" class="h-3.5 w-3.5" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            </section>
        </div>
    </div>
</flux:main>

<style>
    [x-cloak] { display: none !important; }

    .barcode-label {
        width: 40mm;
        height: 30mm;
        flex: 0 0 auto;
        overflow: hidden;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 5px;
        padding: 1.4mm;
        box-sizing: border-box;
        color: #000;
    }

    .barcode-label .label-content {
        width: 100%;
        height: 100%;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        text-align: center;
    }

    .label-name {
        width: 100%;
        margin-bottom: 0.6mm;
        font-size: 8pt;
        line-height: 1.05;
        font-weight: 900;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .barcode-svg {
        display: block;
        width: 100%;
        max-width: 36mm;
        height: 12mm;
    }

    .label-bottom {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 1mm;
        margin-top: 0.5mm;
        font-size: 7pt;
        line-height: 1;
    }

    .label-price {
        font-weight: 900;
        font-size: 9pt;
        direction: ltr;
    }

    .label-sku {
        font-size: 6.5pt;
        font-weight: 700;
    }

    .no-barcode {
        width: 100%;
        padding: 5mm 0;
        font-size: 7pt;
        font-weight: 800;
        color: #64748b;
        border: 1px dashed #cbd5e1;
    }

    .label-40x30 { width: 40mm; height: 30mm; }
    .label-50x30 { width: 50mm; height: 30mm; }
    .label-58x40 { width: 58mm; height: 40mm; }
    .label-60x40 { width: 60mm; height: 40mm; }

    .layout-horizontal .label-content {
        display: grid;
        grid-template-columns: 30% 70%;
        grid-template-rows: auto 1fr auto;
        column-gap: 2mm;
    }

    .layout-horizontal .label-name {
        grid-column: 1 / -1;
        margin-bottom: 0;
        text-align: center;
    }

    .layout-horizontal .barcode-svg {
        grid-column: 2;
        grid-row: 2;
        align-self: center;
        max-width: 100%;
    }

    .layout-horizontal .label-bottom {
        grid-column: 1 / -1;
        grid-row: 3;
    }

    @media print {
        html, body {
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
        }

        body * {
            visibility: hidden !important;
        }

        #barcode-print-area,
        #barcode-print-area * {
            visibility: visible !important;
        }

        #barcode-print-area {
            position: absolute !important;
            inset: 0 !important;
            width: auto !important;
            min-height: 0 !important;
            overflow: visible !important;
            padding: 0 !important;
            margin: 0 !important;
            background: #fff !important;
        }

        #barcode-labels {
            display: flex !important;
            flex-wrap: wrap !important;
            align-items: flex-start !important;
            justify-content: flex-start !important;
            gap: 0 !important;
            width: 100% !important;
        }

        .barcode-label {
            border: 0 !important;
            border-radius: 0 !important;
            margin: 0 !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            box-shadow: none !important;
        }

        .label-40x30 { width: 40mm !important; height: 30mm !important; }
        .label-50x30 { width: 50mm !important; height: 30mm !important; }
        .label-58x40 { width: 58mm !important; height: 40mm !important; }
        .label-60x40 { width: 60mm !important; height: 40mm !important; }

        @page {
            size: 40mm 30mm;
            margin: 0;
        }

        #barcode-labels {
            display: block !important;
            width: 40mm !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .barcode-label {
            display: flex !important;
            width: 40mm !important;
            height: 30mm !important;
            padding: 1.4mm !important;
            margin: 0 !important;
            border: 0 !important;
            border-radius: 0 !important;
            page-break-after: always !important;
            break-after: page !important;
        }

        .barcode-label:last-child {
            page-break-after: auto !important;
            break-after: auto !important;
        }

        .barcode-svg {
            width: 36mm !important;
            max-width: 36mm !important;
            height: 12mm !important;
        }

        .label-name {
            font-size: 8pt !important;
        }

        .label-price {
            font-size: 9pt !important;
        }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
(function () {
    // Alpine component used by the page root.
    window.barcodePrinterPage = function () {
        return {
            init() {
                window.renderPosBarcodes?.();

                document.addEventListener('livewire:init', () => {
                    Livewire.hook('morph.updated', () => {
                        setTimeout(() => window.renderPosBarcodes?.(), 80);
                    });

                    Livewire.on('barcode-preview-refresh', () => {
                        setTimeout(() => window.renderPosBarcodes?.(), 80);
                    });

                    Livewire.on('barcode-print-ready', () => {
                        setTimeout(() => {
                            window.renderPosBarcodes?.();
                            setTimeout(() => window.print(), 250);
                        }, 150);
                    });
                });
            }
        };
    };

    function renderPosBarcodes() {
        if (typeof window.JsBarcode !== 'function') {
            // The CDN script can finish loading after this page script.
            return;
        }

        document.querySelectorAll('#barcode-print-area .barcode-svg').forEach(function (svg) {
            const value = svg.getAttribute('data-barcode-value');
            if (!value) return;

            try {
                // Livewire ignores the SVG contents after it is created, so
                // JsBarcode can safely draw into it without being removed by morphing.
                while (svg.firstChild) {
                    svg.removeChild(svg.firstChild);
                }

                window.JsBarcode(svg, String(value), {
                    format: 'CODE128',
                    displayValue: svg.dataset.barcodeText === 'true',
                    font: 'Tahoma',
                    fontSize: 8,
                    fontOptions: 'bold',
                    textMargin: 0,
                    margin: 0,
                    height: 30,
                    width: 1.2,
                });
            } catch (error) {
                console.error('Barcode rendering failed:', error, value);
            }
        });
    }

    window.renderPosBarcodes = renderPosBarcodes;

    function scheduleBarcodeRender() {
        // Render after Livewire has finished inserting the labels.
        [0, 100, 300, 600].forEach(function (delay) {
            setTimeout(renderPosBarcodes, delay);
        });
    }

    // Initial page load.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleBarcodeRender, { once: true });
    } else {
        scheduleBarcodeRender();
    }

    window.addEventListener('load', scheduleBarcodeRender);

    // Livewire may navigate to this page without a full browser reload.
    document.addEventListener('livewire:navigated', scheduleBarcodeRender);

    document.addEventListener('livewire:init', function () {
        Livewire.hook('morph.updated', function () {
            scheduleBarcodeRender();
        });

        Livewire.on('barcode-preview-refresh', function () {
            scheduleBarcodeRender();
        });

        Livewire.on('barcode-print-ready', function () {
            scheduleBarcodeRender();
            setTimeout(function () {
                renderPosBarcodes();
                window.print();
            }, 900);
        });
    });
})();
</script>
