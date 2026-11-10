<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockVoucher;
use App\Models\StockVoucherItem;
use App\Services\PosProductSearch;
use App\Services\StockMovementContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public string $type = 'in';
    public ?int $branchId = null;
    public string $notes = '';
    public string $productSearch = '';
    public ?int $editingVoucherId = null;
    public ?int $selectedProductId = null;
    public string $selectedProductName = '';
    public float $selectedQuantity = 1.0;
    public float $selectedUnitCost = 0.0;
    public float $selectedStock = 0.0;
    /** @var list<array{product_id: string, quantity: float|int|string, unit_cost: float|int|string}> */
    public array $lines = [];
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->authorize('products.stock.adjust');
        $this->branchId = auth()->user()?->activeBranchId();
    }

    public function addLine(): void
    {
        $this->authorize('products.stock.adjust');
        $this->lines[] = ['product_id' => '', 'quantity' => 1, 'unit_cost' => 0];
    }

    public function selectLineProduct(int $index, int $productId): void
    {
        $this->authorize('products.stock.adjust');
        $tenantId = auth()->user()?->tenant_id;
        $product = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')->find($productId);

        if (! isset($this->lines[$index]) || ! $product) {
            $this->errorMessage = 'الصنف المحدد غير متاح.';

            return;
        }

        $this->lines[$index]['product_id'] = (string) $product->id;
        $this->lines[$index]['unit_cost'] = (float) $product->cost_price;
        $this->errorMessage = null;
    }

    public function selectProduct(int $productId): void
    {
        $this->authorize('products.stock.adjust');
        $tenantId = auth()->user()?->tenant_id;
        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->with(['branchProducts' => fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->when($this->branchId, fn ($branchQuery) => $branchQuery->where('branch_id', $this->branchId))])
            ->find($productId);

        if (! $product) {
            $this->errorMessage = 'الصنف المحدد غير متاح.';

            return;
        }

        $this->selectedProductId = $product->id;
        $this->selectedProductName = $product->name;
        $this->selectedQuantity = 1.0;
        $this->selectedUnitCost = (float) $product->cost_price;
        $this->selectedStock = (float) ($product->branchProducts->first()?->stock_quantity ?? 0);
        $this->productSearch = $product->name;
        $this->errorMessage = null;
        $this->dispatch('stock-voucher-product-selected');
        $this->dispatch('stock-voucher-focus-quantity');
    }

    public function updatedProductSearch(string $value): void
    {
        if ($this->selectedProductId && trim($value) !== $this->selectedProductName) {
            $this->selectedProductId = null;
            $this->selectedProductName = '';
            $this->selectedQuantity = 1.0;
            $this->selectedUnitCost = 0.0;
            $this->selectedStock = 0.0;
        }
    }

    public function selectProductByBarcode(string $barcode): bool
    {
        $barcode = trim($barcode);
        $tenantId = auth()->user()?->tenant_id;

        if ($barcode === '' || mb_strlen($barcode) > 100 || ! $tenantId) {
            return false;
        }

        $productId = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($barcode, $tenantId): void {
                $query->where('product_number', $barcode)
                    ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery
                        ->where('tenant_id', $tenantId)->where('barcode', $barcode));
            })->value('id');

        if (! $productId) {
            return false;
        }

        $this->selectProduct((int) $productId);

        return true;
    }

    public function addSelectedProduct(): void
    {
        $this->authorize('products.stock.adjust');
        $this->validate([
            'selectedProductId' => ['required', 'integer'],
            'selectedQuantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
            'selectedUnitCost' => ['required', 'numeric', 'gte:0', 'max:999999999.99'],
        ]);

        $tenantId = auth()->user()?->tenant_id;
        $product = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')->find($this->selectedProductId);

        if (! $product) {
            $this->errorMessage = 'الصنف المحدد غير متاح.';

            return;
        }

        $emptyIndex = collect($this->lines)->search(fn (array $line): bool => blank($line['product_id']));
        $newLine = [
            'product_id' => (string) $product->id,
            'quantity' => round((float) $this->selectedQuantity, 3),
            'unit_cost' => round((float) $this->selectedUnitCost, 2),
        ];

        if ($emptyIndex === false) {
            $this->lines[] = $newLine;
        } else {
            $this->lines[$emptyIndex] = $newLine;
        }

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedQuantity = 1.0;
        $this->selectedUnitCost = 0.0;
        $this->selectedStock = 0.0;
        $this->productSearch = '';
        $this->errorMessage = null;
        $this->dispatch('stock-voucher-focus-product-search');
    }

    public function addProduct(int $productId): void
    {
        $this->selectProduct($productId);

        if (! $this->selectedProductId) {
            return;
        }

        $this->addSelectedProduct();
    }

    public function removeLine(int $index): void
    {
        $this->authorize('products.stock.adjust');
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function editVoucher(int $voucherId): void
    {
        $this->authorize('products.stock.adjust');
        $voucher = StockVoucher::query()->where('tenant_id', auth()->user()?->tenant_id)->with('items')->findOrFail($voucherId);
        $this->editingVoucherId = $voucher->id;
        $this->type = $voucher->type;
        $this->branchId = $voucher->branch_id;
        $this->notes = (string) ($voucher->notes ?? '');
        $this->lines = $voucher->items->map(fn (StockVoucherItem $item): array => [
            'product_id' => (string) $item->product_id,
            'quantity' => (float) $item->quantity,
            'unit_cost' => (float) $item->unit_cost,
        ])->all();
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    public function deleteVoucher(int $voucherId): void
    {
        $this->authorize('products.stock.adjust');
        $tenantId = auth()->user()?->tenant_id;

        DB::transaction(function () use ($tenantId, $voucherId): void {
            $voucher = StockVoucher::query()->where('tenant_id', $tenantId)->lockForUpdate()->with('items')->findOrFail($voucherId);
            $direction = $voucher->type === 'in' ? -1 : 1;
            $stocks = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $voucher->branch_id)
                ->whereIn('product_id', $voucher->items->pluck('product_id'))->lockForUpdate()->get()->keyBy('product_id');

            foreach ($voucher->items as $item) {
                $stock = $stocks->get($item->product_id);
                abort_unless($stock, 422, 'تعذر العثور على مخزون أحد أصناف السند.');
                $delta = $direction * (float) $item->quantity;
                abort_if((float) $stock->stock_quantity + $delta < -0.0005, 422, 'لا يمكن حذف السند لأن الرصيد الحالي لا يسمح بعكس كمية الإخراج.');
                StockMovementContext::voucher('stock_voucher_reversal', $voucher->voucher_number, fn () => $stock->increment('stock_quantity', $delta));
            }

            $voucher->delete();
        });

        if ($this->editingVoucherId === $voucherId) {
            $this->editingVoucherId = null;
            $this->lines = [];
            $this->notes = '';
        }
        $this->successMessage = 'تم حذف السند وعكس أثره من رصيد المخزون.';
    }

    public function save(): void
    {
        $this->authorize('products.stock.adjust');
        $this->successMessage = null;
        $this->errorMessage = null;
        $this->validate([
            'type' => ['required', 'in:in,out'],
            'branchId' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
            'lines.*.unit_cost' => ['required', 'numeric', 'gte:0', 'max:999999999.99'],
        ]);

        $user = Auth::user();
        $tenantId = $user?->tenant_id;
        abort_unless($tenantId && $user, 403);
        abort_unless(Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($this->branchId)->exists(), 404);
        $productIds = array_map(fn (array $line): int => (int) $line['product_id'], $this->lines);
        abort_unless(Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('id', $productIds)->count() === count($productIds), 404);

        $voucherNumber = strtoupper($this->type === 'in' ? 'SIN' : 'SOUT').'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
        $sourceType = $this->type === 'in' ? 'stock_in' : 'stock_out';

        DB::transaction(function () use ($tenantId, $user, $voucherNumber, $sourceType): void {
            $existingVoucher = $this->editingVoucherId
                ? StockVoucher::query()->where('tenant_id', $tenantId)->lockForUpdate()->with('items')->findOrFail($this->editingVoucherId)
                : null;
            abort_if($existingVoucher && (int) $existingVoucher->branch_id !== (int) $this->branchId, 422, 'لا يمكن نقل السند إلى فرع آخر أثناء تعديله.');
            $productIds = array_values(array_unique(array_merge(
                array_map(fn (array $line): int => (int) $line['product_id'], $this->lines),
                $existingVoucher?->items->pluck('product_id')->map(fn ($id): int => (int) $id)->all() ?? [],
            )));
            $stocks = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $this->branchId)
                ->whereIn('product_id', $productIds)->lockForUpdate()->get()->keyBy('product_id');

            if ($existingVoucher) {
                $reversalDirection = $existingVoucher->type === 'in' ? -1 : 1;
                foreach ($existingVoucher->items as $oldItem) {
                    $stock = $stocks->get($oldItem->product_id);
                    abort_unless($stock, 422, 'تعذر العثور على مخزون أحد أصناف السند.');
                    $delta = $reversalDirection * (float) $oldItem->quantity;
                    abort_if((float) $stock->stock_quantity + $delta < -0.0005, 422, 'لا يمكن تعديل السند لأن الرصيد الحالي لا يسمح بعكس كمية الإخراج.');
                    StockMovementContext::voucher('stock_voucher_reversal', $existingVoucher->voucher_number, fn () => $stock->increment('stock_quantity', $delta));
                }
                $existingVoucher->update(['type' => $this->type, 'notes' => trim($this->notes) ?: null]);
                $existingVoucher->items()->delete();
            }

            foreach ($this->lines as $line) {
                $productId = (int) $line['product_id'];
                $quantity = (float) $line['quantity'];
                $stock = $stocks->get($productId);

                if (! $stock && $this->type === 'out') {
                    throw \Illuminate\Validation\ValidationException::withMessages(['lines' => ['لا يمكن إخراج صنف غير موجود في مخزون الفرع.']]);
                }
                if (! $stock) {
                    $stock = BranchProduct::create([
                        'tenant_id' => $tenantId, 'branch_id' => $this->branchId, 'product_id' => $productId,
                        'stock_quantity' => 0, 'created_by' => $user->id,
                    ]);
                    $stocks->put($productId, $stock);
                }
                if ($this->type === 'out' && (float) $stock->stock_quantity + .0005 < $quantity) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['lines' => ['كمية الإخراج أكبر من المتبقي للصنف المحدد.']]);
                }

                $stockVoucher ??= $existingVoucher ?? StockVoucher::create([
                    'tenant_id' => $tenantId, 'branch_id' => $this->branchId, 'voucher_number' => $voucherNumber,
                    'type' => $this->type, 'notes' => trim($this->notes) ?: null, 'created_by' => $user->id,
                ]);
                StockVoucherItem::create([
                    'tenant_id' => $tenantId, 'stock_voucher_id' => $stockVoucher->id,
                    'product_id' => $productId, 'quantity' => $quantity,
                    'unit_cost' => round((float) $line['unit_cost'], 2),
                ]);
                StockMovementContext::reference($sourceType, $stockVoucher->id, function () use ($stock, $quantity): void {
                    $this->type === 'in'
                        ? $stock->increment('stock_quantity', $quantity)
                        : $stock->decrement('stock_quantity', $quantity);
                }, $existingVoucher?->voucher_number ?? $voucherNumber);
            }
        });

        $this->successMessage = $this->editingVoucherId
            ? 'تم تعديل السند '.$voucherNumber.' بنجاح.'
            : 'تم حفظ سند '.($this->type === 'in' ? 'الإدخال' : 'الإخراج').' بنجاح: '.$voucherNumber;
        $this->editingVoucherId = null;
        $this->notes = '';
        $this->lines = [];
        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedQuantity = 1.0;
        $this->selectedUnitCost = 0.0;
        $this->selectedStock = 0.0;
        $this->productSearch = '';
        $this->resetValidation();
    }

    public function render(): mixed
    {
        $this->authorize('products.stock.adjust');
        $tenantId = auth()->user()?->tenant_id;
        abort_unless($tenantId, 403);
        $user = auth()->user();
        $branches = Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->when($user?->branch_id && ! $user->isTenantOwner() && ! $user->isSaaSAdmin(), fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')->get(['id', 'name']);
        $search = trim($this->productSearch);
        $normalizedSearch = app(PosProductSearch::class)->normalize($search);
        $searchWords = array_slice(array_filter(explode(' ', $normalizedSearch)), 0, 8);
        $productQuery = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at');
        if ($normalizedSearch !== '') {
            $productQuery->where(function ($query) use ($searchWords, $normalizedSearch, $tenantId): void {
                $query->where(function ($nameQuery) use ($searchWords): void {
                    foreach ($searchWords as $word) {
                        $nameQuery->where(function ($wordQuery) use ($word): void {
                            $wordQuery->where('search_name', 'like', '%'.$word.'%')
                                ->orWhere('name', 'like', '%'.$word.'%');
                        });
                    }
                })
                    ->orWhere('product_number', 'like', '%'.$normalizedSearch.'%')
                    ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery
                        ->where('tenant_id', $tenantId)->where('barcode', 'like', '%'.$normalizedSearch.'%'));
            });
        }
        $products = $productQuery->with(['branchProducts' => fn ($query) => $query
            ->where('tenant_id', $tenantId)
            ->when($this->branchId, fn ($branchQuery) => $branchQuery->where('branch_id', $this->branchId))])
            ->orderBy('name')->limit($search === '' ? 30 : 50)->get(['id', 'name', 'product_number', 'cost_price']);
        $selectedProductIds = collect($this->lines)->pluck('product_id')->filter()->map(fn (string|int $id): int => (int) $id)->unique();
        $lineProducts = $selectedProductIds->isEmpty() ? collect() : Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('id', $selectedProductIds)
            ->with(['branchProducts' => fn ($query) => $query->where('tenant_id', $tenantId)->when($this->branchId, fn ($branchQuery) => $branchQuery->where('branch_id', $this->branchId))])
            ->get(['id', 'name', 'product_number', 'cost_price']);
        $lineOptions = $products->concat($lineProducts)->unique('id')->values();
        $recent = StockVoucher::query()->where('tenant_id', $tenantId)->when($this->branchId, fn ($query) => $query->where('branch_id', $this->branchId))
            ->with(['items.product:id,name,product_number', 'branch:id,name'])->latest('id')->limit(30)->get();

        return $this->view(compact('branches', 'products', 'lineOptions', 'recent'))->layout('layouts::tenant');
    }
};
?>

@php
    $selectedLineCount = collect($lines)->filter(fn ($line): bool => filled($line['product_id'] ?? null))->count();
    $totalLineQuantity = collect($lines)->filter(fn ($line): bool => filled($line['product_id'] ?? null))->sum(fn ($line): float => (float) ($line['quantity'] ?? 0));
    $estimatedTotalCost = collect($lines)->filter(fn ($line): bool => filled($line['product_id'] ?? null))->sum(fn ($line): float => (float) ($line['quantity'] ?? 0) * (float) ($line['unit_cost'] ?? 0));
@endphp

<flux:main dir="rtl" class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-gradient-to-l from-slate-950 via-slate-900 to-indigo-950 p-5 text-white shadow-lg">
        <div class="flex items-center gap-3">
            <div class="flex size-12 items-center justify-center rounded-xl bg-white/10 text-2xl">📦</div>
            <div>
                <flux:heading size="xl" class="text-white">سند إدخال وإخراج بضاعة</flux:heading>
                <flux:text class="mt-1 text-slate-300">أدخل الكمية والتكلفة لكل صنف، وراجع ملخص الحركة قبل الحفظ.</flux:text>
            </div>
        </div>
        <flux:button :href="route('tenant.stock-movements')" wire:navigate variant="filled" class="border border-white/15 bg-white/10 text-white hover:bg-white/15">سجل حركات المخزون</flux:button>
    </div>

    @if ($successMessage)<div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800">{{ $successMessage }}</div>@endif
    @if ($errorMessage)<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-800">{{ $errorMessage }}</div>@endif

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-4 dark:border-indigo-900 dark:bg-indigo-950/40"><div class="text-xs font-bold text-indigo-600 dark:text-indigo-300">عدد الأصناف</div><div class="mt-1 text-2xl font-black tabular-nums text-indigo-950 dark:text-white">{{ $selectedLineCount }}</div></div>
        <div class="rounded-2xl border border-sky-100 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/40"><div class="text-xs font-bold text-sky-700 dark:text-sky-300">مجموع الكميات</div><div class="mt-1 text-2xl font-black tabular-nums text-sky-950 dark:text-white">{{ number_format($totalLineQuantity, 3) }}</div></div>
        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-950/40"><div class="text-xs font-bold text-emerald-700 dark:text-emerald-300">إجمالي التكلفة التقديري</div><div class="mt-1 text-2xl font-black tabular-nums text-emerald-950 dark:text-white">{{ number_format($estimatedTotalCost, 2) }}</div></div>
    </div>

    <div class="space-y-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-6 dark:border-slate-700 dark:bg-slate-900">
        <section class="grid gap-4 border-b border-slate-100 pb-5 md:grid-cols-2 dark:border-slate-800">
            <flux:select label="نوع السند" wire:model.live="type"><option value="in">إدخال بضاعة</option><option value="out">إخراج بضاعة</option></flux:select>
            @if (!auth()->user()->isTenantOwner() && !auth()->user()->isSaaSAdmin())
                <flux:select label="الفرع / المخزن" wire:model="branchId" :disabled="$editingVoucherId !== null"><option value="">اختر الفرع</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>
            @endif
        </section>

        <section class="space-y-3" x-data="{ productSearchOpen: false }" x-on:stock-voucher-product-selected.window="productSearchOpen = false" x-on:stock-voucher-focus-product-search.window="$nextTick(() => $refs.productSearch?.focus())" x-on:stock-voucher-focus-quantity.window="$nextTick(() => $refs.quantity?.focus())" x-on:focusout="if (!$el.contains($event.relatedTarget)) productSearchOpen = false" x-on:click.outside="productSearchOpen = false" x-on:keydown.escape.stop="productSearchOpen = false">
            <div><h2 class="text-base font-black text-slate-900 dark:text-white">إضافة صنف للسند</h2><p class="mt-1 text-xs text-slate-500">ابحث عن الصنف واختره، ثم أدخل الكمية والتكلفة واضغط إضافة.</p></div>
            <div class="relative">
                <label for="stock-voucher-product-search" class="mb-1.5 block text-xs font-black text-slate-600 dark:text-slate-300">الصنف / الباركود</label>
                <input id="stock-voucher-product-search" x-ref="productSearch" type="text" wire:model.live.debounce.120ms="productSearch" maxlength="120" autocomplete="off" x-on:input="productSearchOpen = true" x-on:focus="productSearchOpen = true" x-on:keydown.arrow-down.prevent="$nextTick(() => $el.parentElement.querySelector('[data-stock-voucher-product-result]')?.focus())" x-on:keydown.enter.prevent="$wire.selectProductByBarcode($el.value).then((selected) => { if (!selected) $nextTick(() => $el.closest('section')?.querySelector('[data-stock-voucher-product-result]')?.click()) })" placeholder="ابحث باسم الصنف أو رقمه أو امسح الباركود ثم اضغط Enter..." class="w-full rounded-xl border-2 border-indigo-200 bg-white px-4 py-3 text-sm font-black text-slate-900 outline-none transition focus:border-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white" />
                @if ($selectedProductId)
                    <p role="status" class="mt-1 text-xs font-bold text-indigo-700 dark:text-indigo-300">تم اختيار «{{ $selectedProductName }}». أدخل الكمية والتكلفة ثم اضغط «+ إضافة».</p>
                @endif
                @if (trim($productSearch) !== '' && ! $selectedProductId)
                    <div x-cloak x-show="productSearchOpen" class="absolute right-0 top-full z-50 mt-1 w-full overflow-hidden rounded-2xl border border-indigo-200 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-900">
                        @if ($products->isNotEmpty())
                            <div class="max-h-80 overflow-auto">
                                <table class="w-full text-right text-sm">
                                    <thead class="sticky top-0 bg-slate-900 text-xs text-white"><tr><th class="p-2">رقم الصنف</th><th class="p-2">اسم الصنف</th><th class="p-2 text-center">التكلفة</th><th class="p-2 text-center">المخزون</th></tr></thead>
                                    <tbody>
                                        @foreach ($products as $product)
                                            @php $stock = (float) ($product->branchProducts->first()?->stock_quantity ?? 0); @endphp
                                            <tr wire:key="stock-voucher-search-{{ $product->id }}" class="border-t border-slate-100 dark:border-slate-800">
                                                <td colspan="4" class="p-0"><button type="button" data-stock-voucher-product-result wire:click="selectProduct({{ $product->id }})" wire:loading.attr="disabled" wire:target="selectProduct({{ $product->id }})" class="grid w-full grid-cols-[1fr_2fr_1fr_1fr] items-center gap-2 px-3 py-2.5 text-right transition hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none dark:hover:bg-indigo-950 dark:focus:bg-indigo-950">
                                                    <span class="font-mono text-xs">{{ $product->product_number ?: '#'.$product->id }}</span><span class="font-bold">{{ $product->name }}</span><span class="text-center font-mono">{{ number_format((float) $product->cost_price, 2) }}</span><span class="text-center font-mono tabular-nums">{{ number_format($stock, 3) }}</span>
                                                </button></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @else
                            <p role="status" class="p-5 text-center text-sm font-semibold text-slate-500">لا توجد أصناف مطابقة للبحث.</p>
                        @endif
                    </div>
                @endif
            </div>

            <div class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(12rem,1.5fr)_minmax(8rem,0.7fr)_minmax(9rem,0.8fr)_minmax(8rem,0.7fr)_auto]">
                <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 dark:border-slate-700 dark:bg-slate-800"><div class="text-[10px] font-bold text-slate-500">الصنف المختار</div><div class="mt-1 truncate text-sm font-black text-slate-900 dark:text-white">{{ $selectedProductName ?: 'لم يتم اختيار صنف' }}</div>@if ($selectedProductId)<div class="mt-1 text-[10px] text-slate-500">المتوفر بالفرع: {{ number_format($selectedStock, 3) }}</div>@endif</div>
                <div><label for="voucher-selected-quantity" class="mb-1.5 block text-xs font-black text-slate-600 dark:text-slate-300">الكمية</label><input id="voucher-selected-quantity" x-ref="quantity" type="number" min="0.001" step="0.001" inputmode="decimal" wire:model.live.debounce.200ms="selectedQuantity" x-on:focus="$event.target.select()" x-on:keydown.enter.prevent="$refs.unitCost?.focus(); $refs.unitCost?.select()" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-center font-mono text-sm font-black tabular-nums outline-none focus:border-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white" />@error('selectedQuantity')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <div><label for="voucher-selected-cost" class="mb-1.5 block text-xs font-black text-slate-600 dark:text-slate-300">تكلفة الوحدة</label><input id="voucher-selected-cost" x-ref="unitCost" type="number" min="0" step="0.01" inputmode="decimal" wire:model.live.debounce.200ms="selectedUnitCost" x-on:focus="$event.target.select()" x-on:keydown.enter.prevent="$wire.addSelectedProduct()" class="w-full rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-center font-mono text-sm font-black tabular-nums text-amber-900 outline-none focus:border-amber-500 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100" />@error('selectedUnitCost')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <div class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-center dark:border-slate-700 dark:bg-slate-800"><div class="text-[10px] font-bold text-slate-500">إجمالي السطر</div><div class="mt-1 font-mono text-sm font-black tabular-nums text-slate-900 dark:text-white">{{ number_format(max(0, $selectedQuantity * $selectedUnitCost), 2) }}</div></div>
                <button type="button" wire:click="addSelectedProduct" wire:loading.attr="disabled" wire:target="addSelectedProduct" @disabled(!$selectedProductId) class="h-12 rounded-xl bg-indigo-600 px-5 text-sm font-black text-white shadow-sm transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-40">+ إضافة</button>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-base font-black text-slate-900 dark:text-white">أصناف السند</h2><p class="mt-1 text-xs text-slate-500">عدّل الكمية وتكلفة الوحدة مباشرة، والإجمالي يُحسب تلقائياً.</p></div><button type="button" wire:click="addLine" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-black text-indigo-700 transition hover:bg-indigo-100 dark:border-indigo-900 dark:bg-indigo-950 dark:text-indigo-200">+ إضافة سطر</button></div>
            <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
                <table class="w-full min-w-[850px] text-right text-sm">
                    <thead class="bg-slate-900 text-white"><tr><th class="w-12 p-3 text-center">#</th><th class="min-w-64 p-3">الصنف</th><th class="w-36 p-3 text-center">الكمية</th><th class="w-40 p-3 text-center">تكلفة الوحدة</th><th class="w-40 p-3 text-center">إجمالي التكلفة</th><th class="w-20 p-3 text-center">حذف</th></tr></thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($lines as $index => $line)
                            @php
                                $lineProduct = $lineOptions->firstWhere('id', (int) ($line['product_id'] ?? 0));
                                $lineTotal = (float) ($line['quantity'] ?? 0) * (float) ($line['unit_cost'] ?? 0);
                            @endphp
                            <tr wire:key="voucher-line-{{ $index }}" class="bg-white transition hover:bg-indigo-50/50 dark:bg-slate-900 dark:hover:bg-slate-800/70">
                                <td class="p-3 text-center font-mono text-xs font-black text-slate-400">{{ $index + 1 }}</td>
                                <td class="min-w-64 p-3">
                                    <select wire:change="selectLineProduct({{ $index }}, $event.target.value)" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm font-bold text-slate-800 outline-none focus:border-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white"><option value="">اختر الصنف</option>@foreach ($lineOptions as $product)<option value="{{ $product->id }}" @selected((int) ($line['product_id'] ?? 0) === $product->id)>{{ $product->product_number ? $product->product_number.' — ' : '' }}{{ $product->name }}</option>@endforeach</select>
                                    @if ($lineProduct)<div class="mt-1.5 flex flex-wrap gap-x-3 text-[10px] font-semibold text-slate-500"><span>رقم: {{ $lineProduct->product_number ?: '#'.$lineProduct->id }}</span><span>المتوفر: {{ number_format((float) ($lineProduct->branchProducts->first()?->stock_quantity ?? 0), 3) }}</span></div>@endif
                                    @error('lines.'.$index.'.product_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                                </td>
                                <td class="p-3 text-center"><input wire:model.live.debounce.250ms="lines.{{ $index }}.quantity" type="number" min="0.001" step="0.001" inputmode="decimal" class="w-full rounded-lg border border-slate-200 bg-white px-2 py-2.5 text-center font-mono font-black tabular-nums outline-none focus:border-indigo-500 dark:border-slate-700 dark:bg-slate-800 dark:text-white">@error('lines.'.$index.'.quantity')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</td>
                                <td class="p-3 text-center"><input wire:model.live.debounce.250ms="lines.{{ $index }}.unit_cost" type="number" min="0" step="0.01" inputmode="decimal" aria-label="تكلفة الوحدة للصنف {{ $index + 1 }}" class="w-full rounded-lg border border-amber-200 bg-amber-50 px-2 py-2.5 text-center font-mono font-black tabular-nums text-amber-900 outline-none focus:border-amber-500 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100">@error('lines.'.$index.'.unit_cost')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</td>
                                <td class="p-3 text-center font-mono font-black tabular-nums text-slate-900 dark:text-white">{{ number_format($lineTotal, 2) }}</td>
                                <td class="p-3 text-center"><button type="button" wire:click="removeLine({{ $index }})" aria-label="حذف السطر {{ $index + 1 }}" class="rounded-lg bg-rose-50 px-3 py-2 text-sm font-black text-rose-700 transition hover:bg-rose-100 dark:bg-rose-950/50 dark:text-rose-300">×</button></td>
                            </tr>
                        @endforeach
                        @if ($selectedLineCount === 0)<tr><td colspan="6" class="p-8 text-center text-sm font-semibold text-slate-400">لم تتم إضافة أصناف بعد. اختر صنفاً من نتائج البحث أعلاه.</td></tr>@endif
                    </tbody>
                    <tfoot class="border-t-2 border-slate-200 bg-slate-50 font-black dark:border-slate-700 dark:bg-slate-800"><tr><td colspan="2" class="p-3">الإجمالي</td><td class="p-3 text-center font-mono tabular-nums">{{ number_format($totalLineQuantity, 3) }}</td><td></td><td class="p-3 text-center font-mono tabular-nums">{{ number_format($estimatedTotalCost, 2) }}</td><td></td></tr></tfoot>
                </table>
            </div>
            @error('lines')<p class="text-sm font-bold text-rose-600">{{ $message }}</p>@enderror
        </section>

        <section class="grid gap-4 border-t border-slate-100 pt-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end dark:border-slate-800">
            <flux:textarea label="سبب السند أو ملاحظات" wire:model="notes" placeholder="مثال: بضاعة وصلت دون فاتورة / تلف / استعمال داخلي" />
            <div class="flex gap-2">
                @if ($editingVoucherId)
                    <flux:button variant="ghost" wire:click="$set('editingVoucherId', null); $set('lines', []); $set('notes', '')">إلغاء التعديل</flux:button>
                @endif
                <flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">{{ $editingVoucherId ? 'حفظ تعديل السند' : 'حفظ سند '.($type === 'in' ? 'الإدخال' : 'الإخراج') }}</flux:button>
            </div>
        </section>
    </div>

    <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <div class="border-b border-slate-100 p-4 dark:border-slate-800"><h2 class="font-black text-slate-900 dark:text-white">آخر حركات الإدخال والإخراج</h2><p class="mt-1 text-xs text-slate-500">عرض آخر 30 حركة مسجلة.</p></div>
        <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-right text-sm"><thead class="bg-slate-50 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr><th class="p-3">التاريخ</th><th class="p-3">رقم السند</th><th class="p-3">النوع</th><th class="p-3">الفرع</th><th class="p-3">الأصناف</th><th class="p-3 text-center">الكمية</th><th class="p-3">الإجراءات</th></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-slate-800">@forelse($recent as $voucher)<tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50"><td class="p-3 tabular-nums">{{ $voucher->created_at->format('Y-m-d H:i') }}</td><td class="p-3 font-mono text-xs">{{ $voucher->voucher_number }}</td><td class="p-3">{{ $voucher->type === 'in' ? 'إدخال' : 'إخراج' }}</td><td class="p-3">{{ $voucher->branch?->name }}</td><td class="p-3 font-semibold">{{ $voucher->items->map(fn ($item) => $item->product?->name)->filter()->implode('، ') }}</td><td class="p-3 text-center font-mono font-black tabular-nums">{{ number_format((float) $voucher->items->sum('quantity'), 3) }}</td><td class="p-3"><div class="flex gap-2"><button type="button" wire:click="editVoucher({{ $voucher->id }})" class="rounded-lg bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700">تعديل</button><button type="button" wire:click="deleteVoucher({{ $voucher->id }})" wire:confirm="هل تريد حذف السند وعكس أثره من المخزون؟" class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700">حذف</button></div></td></tr>@empty<tr><td colspan="7" class="p-8 text-center text-slate-500">لا توجد سندات إدخال أو إخراج بعد.</td></tr>@endforelse</tbody></table></div>
    </section>
</flux:main>
