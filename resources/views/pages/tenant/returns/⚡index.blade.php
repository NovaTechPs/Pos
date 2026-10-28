<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component {
    public string $kind = 'sale';
    public string $invoiceNumber = '';
    public string $invoiceDate = '';
    public ?int $selectedBranchId = null;
    public array $branches = [];
    public string $partySearch = '';
    public array $partyResults = [];
    public ?int $selectedPartyId = null;
    public string $selectedPartyName = '';
    public string $productSearch = '';
    public array $productResults = [];
    public array $lines = [];
    public string $notes = '';
    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    public function mount(): void
    {
        if (request()->routeIs('tenant.sales-returns')) {
            $this->kind = 'sale';
        } elseif (request()->routeIs('tenant.purchase-returns')) {
            $this->kind = 'purchase';
        }

        $this->branches = Branch::query()
            ->where('tenant_id', $this->tenantId())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->toArray();
        $this->selectedBranchId = $this->branchId();
        $this->startNewReturn(false);
    }

    public function startNewReturn(bool $showMessage = true): void
    {
        $this->invoiceDate = now()->toDateString();
        $this->invoiceNumber = $this->generateInvoiceNumber();
        $this->selectedPartyId = null;
        $this->selectedPartyName = '';
        $this->partySearch = '';
        $this->partyResults = [];
        $this->productSearch = '';
        $this->productResults = [];
        $this->lines = [];
        $this->notes = '';
        $this->errorMessage = null;
        $this->successMessage = $showMessage ? 'تم بدء فاتورة مردود جديدة.' : null;
    }

    private function generateInvoiceNumber(): string
    {
        return ($this->kind === 'sale' ? 'RET-' : 'PR-')
            . now()->format('YmdHis') . '-' . Str::upper(Str::random(4));
    }

    private function tenantId(): int
    {
        return (int) (session('active_tenant_id') ?? auth()->user()?->tenant_id ?? 0);
    }

    private function branchId(): ?int
    {
        $tenantId = $this->tenantId();
        $branchId = auth()->user()?->branch_id ?: $this->selectedBranchId ?: session('active_branch_id');

        $branch = Branch::query()->where('tenant_id', $tenantId)->when($branchId, fn ($query) => $query->whereKey($branchId))->first();

        return $branch?->id ? (int) $branch->id : null;
    }

    public function updatedKind(): void
    {
        $this->selectedPartyId = null;
        $this->selectedPartyName = '';
        $this->partySearch = '';
        $this->partyResults = [];
        $this->productSearch = '';
        $this->productResults = [];
        $this->lines = [];
        $this->errorMessage = null;
        $this->successMessage = null;
        $this->startNewReturn(false);
    }

    public function updatedSelectedBranchId(): void
    {
        $this->productSearch = '';
        $this->productResults = [];
        $this->lines = [];
        $this->errorMessage = null;
    }

    public function updatedPartySearch(): void
    {
        $this->selectedPartyId = null;
        $this->selectedPartyName = '';
        $this->lines = [];
        $this->productResults = [];

        $search = trim($this->partySearch);
        if ($search === '') {
            $this->partyResults = [];
            return;
        }

        $types = $this->kind === 'sale' ? ['customer', 'both'] : ['supplier', 'both'];
        $this->partyResults = Party::query()->where('tenant_id', $this->tenantId())->whereIn('type', $types)
            ->where('is_active', true)->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%");
            })->orderBy('name')->limit(10)->get(['id', 'name', 'phone'])->toArray();
    }

    public function selectParty(int $partyId): void
    {
        $types = $this->kind === 'sale' ? ['customer', 'both'] : ['supplier', 'both'];
        $party = Party::query()->where('tenant_id', $this->tenantId())->whereIn('type', $types)
            ->where('is_active', true)->find($partyId);

        if (!$party) {
            $this->errorMessage = 'العميل أو المورد المحدد غير صالح.';
            return;
        }

        $this->selectedPartyId = (int) $party->id;
        $this->selectedPartyName = $party->name;
        $this->partySearch = $party->name;
        $this->partyResults = [];
        $this->productSearch = '';
        $this->productResults = [];
        $this->lines = [];
        $this->errorMessage = null;
    }

    public function selectFirstParty(): void
    {
        $this->updatedPartySearch();
        $party = $this->partyResults[0] ?? null;

        if ($party) {
            $this->selectParty((int) $party['id']);
        }
    }

    public function clearParty(): void
    {
        $this->selectedPartyId = null;
        $this->selectedPartyName = '';
        $this->partySearch = '';
        $this->partyResults = [];
        $this->productSearch = '';
        $this->productResults = [];
        $this->lines = [];
    }

    public function updatedProductSearch(): void
    {
        $this->searchProducts();
    }

    public function searchProducts(): void
    {
        $this->productResults = [];
        if (!$this->selectedPartyId || trim($this->productSearch) === '') {
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->branchId();
        $search = trim($this->productSearch);
        $this->productResults = Product::query()->where('tenant_id', $tenantId)
            ->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $branchId))
            ->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery->where('barcode', 'like', "{$search}%"));
            })->with(['branchProducts' => fn ($query) => $query->where('branch_id', $branchId)])
            ->orderBy('name')->limit(40)->get()->map(function (Product $product): array {
                $stock = $product->branchProducts->first();
                $available = $this->remainingQuantity((int) $product->id, $this->selectedPartyId);
                if ($this->kind === 'purchase') {
                    $available = min($available, (float) ($stock?->stock_quantity ?? 0));
                }

                return [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'stock' => (float) ($stock?->stock_quantity ?? 0),
                    'available' => $available,
                ];
            })->filter(fn (array $product): bool => $product['available'] > 0)
            ->take(12)
            ->values()
            ->all();
    }

    public function addFirstProduct(): void
    {
        $barcode = trim($this->productSearch);

        if ($barcode !== '') {
            $exactBarcodeProduct = Product::query()
                ->where('tenant_id', $this->tenantId())
                ->whereHas('barcodes', fn ($query) => $query->where('tenant_id', $this->tenantId())->where('barcode', $barcode))
                ->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $this->branchId()))
                ->value('id');

            if ($exactBarcodeProduct) {
                $this->addProduct((int) $exactBarcodeProduct);

                return;
            }
        }

        $this->searchProducts();
        $product = $this->productResults[0] ?? null;

        if ($product && count($this->productResults) === 1) {
            $this->addProduct((int) $product['id']);
        }
    }

    private function remainingQuantity(int $productId, int $partyId): float
    {
        if ($this->kind === 'sale') {
            $sold = (float) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.tenant_id', $this->tenantId())->where('orders.customer_id', $partyId)
                ->whereIn('orders.type', ['pos', 'wholesale', 'online'])->where('orders.status', 'completed')
                ->whereNull('orders.deleted_at')->where('order_items.product_id', $productId)->sum('order_items.quantity');
            $returned = (float) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.tenant_id', $this->tenantId())->where('orders.customer_id', $partyId)
                ->where('orders.type', 'return')->whereNull('orders.deleted_at')
                ->where('order_items.product_id', $productId)->sum(DB::raw('ABS(order_items.quantity)'));

            return max(0, $sold - $returned);
        }

        $purchased = (float) DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.tenant_id', $this->tenantId())->where('purchases.supplier_id', $partyId)
            ->whereNull('purchases.deleted_at')->where('purchase_items.product_id', $productId)->sum('purchase_items.quantity');
        $returned = (float) DB::table('purchase_return_items')->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_returns.tenant_id', $this->tenantId())->where('purchase_returns.supplier_id', $partyId)
            ->where('purchase_return_items.product_id', $productId)->sum('purchase_return_items.quantity');

        return max(0, $purchased - $returned);
    }

    private function latestPrice(int $productId, int $partyId, Product $product): float
    {
        if ($this->kind === 'sale') {
            $last = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('o.tenant_id', $this->tenantId())->where('o.customer_id', $partyId)
                ->whereIn('o.type', ['pos', 'wholesale', 'online'])->where('o.status', 'completed')
                ->whereNull('o.deleted_at')->where('oi.product_id', $productId)
                ->orderByDesc('o.created_at')->orderByDesc('oi.id')
                ->first(['oi.quantity', 'oi.total_price', 'o.subtotal', 'o.discount']);

            if ($last && abs((float) $last->quantity) > 0.000001) {
                $discountFactor = (float) $last->subtotal > 0
                    ? max(0, (float) $last->subtotal - (float) $last->discount) / (float) $last->subtotal
                    : 1;
                return round(abs((float) $last->total_price / (float) $last->quantity) * $discountFactor, 2);
            }

            $branchProduct = BranchProduct::query()->where('tenant_id', $this->tenantId())
                ->where('branch_id', $this->branchId())->where('product_id', $productId)->first();

            return (float) ($branchProduct?->retail_price ?? 0);
        }

        $lastCost = DB::table('purchase_items as pi')->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->where('p.tenant_id', $this->tenantId())->where('p.supplier_id', $partyId)
            ->whereNull('p.deleted_at')->where('pi.product_id', $productId)
            ->orderByDesc('p.created_at')->orderByDesc('pi.id')->value(DB::raw('pi.subtotal / NULLIF(pi.quantity, 0)'));

        return $lastCost !== null ? round((float) $lastCost, 2) : (float) $product->cost_price;
    }

    public function addProduct(int $productId): void
    {
        $this->errorMessage = null;
        $tenantId = $this->tenantId();
        $branchId = $this->branchId();

        if (!$this->selectedPartyId || !$branchId) {
            $this->errorMessage = 'اختر العميل أو المورد وحدد الفرع أولاً.';
            return;
        }

        $product = Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->first();
        $branchProduct = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
            ->where('product_id', $productId)->first();
        if (!$product || !$branchProduct) {
            $this->errorMessage = 'الصنف غير مرتبط بالفرع الحالي.';
            return;
        }

        $available = $this->remainingQuantity($productId, $this->selectedPartyId);
        if ($this->kind === 'purchase') {
            $available = min($available, (float) $branchProduct->stock_quantity);
        }
        if ($available <= 0) {
            $this->errorMessage = 'لا توجد كمية متاحة للإرجاع لهذا الصنف مع هذا الطرف.';
            return;
        }

        $lineIndex = collect($this->lines)->search(fn (array $line): bool => (int) $line['id'] === $productId);
        if ($lineIndex !== false) {
            if ((float) $this->lines[$lineIndex]['quantity'] + 1 > $available) {
                $this->errorMessage = 'الكمية المطلوب إرجاعها أكبر من الكمية المتاحة.';
                return;
            }
            $this->lines[$lineIndex]['quantity'] = (float) $this->lines[$lineIndex]['quantity'] + 1;
        } else {
            $this->lines[] = [
                'id' => $productId,
                'name' => $product->name,
                'available' => $available,
                'quantity' => 1,
                'price' => $this->latestPrice($productId, $this->selectedPartyId, $product),
            ];
        }

        $this->productSearch = '';
        $this->productResults = [];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function saveReturn(): void
    {
        Gate::authorize('returns.create');
        $this->errorMessage = null;
        $this->successMessage = null;
        $tenantId = $this->tenantId();
        $branchId = $this->branchId();

        if (!$this->selectedPartyId || !$branchId || !$this->lines || $tenantId < 1) {
            $this->errorMessage = 'اختر العميل أو المورد وأضف صنفاً واحداً على الأقل.';
            return;
        }

        $partyTypes = $this->kind === 'sale' ? ['customer', 'both'] : ['supplier', 'both'];
        $party = Party::query()->where('tenant_id', $tenantId)->whereIn('type', $partyTypes)
            ->where('is_active', true)->find($this->selectedPartyId);
        if (!$party) {
            $this->errorMessage = 'العميل أو المورد المحدد غير صالح.';
            return;
        }

        $items = [];
        foreach ($this->lines as $line) {
            $productId = (int) ($line['id'] ?? 0);
            $quantity = round((float) ($line['quantity'] ?? 0), 2);
            $price = round((float) ($line['price'] ?? 0), 2);
            if (!$productId || $quantity <= 0 || $price < 0) {
                $this->errorMessage = 'تأكد من الصنف والكمية والسعر في كل سطر.';
                return;
            }
            $items[$productId] = ['quantity' => $quantity, 'price' => $price];
        }

        try {
            $invoiceDate = Carbon::parse($this->invoiceDate)->startOfDay();
            $savedInvoiceNumber = $this->invoiceNumber;

            DB::transaction(function () use ($tenantId, $branchId, $party, $items, $invoiceDate): void {
                $lockedParty = Party::query()->where('tenant_id', $tenantId)->whereKey($party->id)->lockForUpdate()->firstOrFail();
                $validated = [];
                $total = 0.0;

                foreach ($items as $productId => $line) {
                    $product = Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->lockForUpdate()->firstOrFail();
                    $stock = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                        ->where('product_id', $productId)->lockForUpdate()->firstOrFail();
                    $available = $this->remainingQuantity($productId, (int) $lockedParty->id);
                    if ($this->kind === 'purchase') {
                        $available = min($available, (float) $stock->stock_quantity);
                    }
                    if ($line['quantity'] > $available + 0.00001) {
                        throw new \DomainException('كمية المرتجع تتجاوز الكمية التي تعامل بها هذا العميل أو المورد سابقاً.');
                    }

                    $lineTotal = round($line['quantity'] * $line['price'], 2);
                    $total += $lineTotal;
                    $validated[] = ['product' => $product, 'stock' => $stock, ...$line, 'total' => $lineTotal];
                }

                $total = round($total, 2);
                if ($this->kind === 'sale') {
                    $return = Order::create([
                        'tenant_id' => $tenantId, 'branch_id' => $branchId, 'created_by' => auth()->id(),
                        'customer_id' => $lockedParty->id, 'customer_name' => $lockedParty->name,
                        'customer_phone' => $lockedParty->phone, 'customer_address' => $lockedParty->address,
                        'invoice_number' => $this->invoiceNumber,
                        'type' => 'return', 'status' => 'completed', 'subtotal' => -$total,
                        'tax_amount' => 0, 'discount_type' => 'fixed', 'discount_rate' => 0,
                        'discount' => 0, 'delivery_fee' => 0, 'total' => -$total,
                        'total_cost' => 0, 'total_profit' => -$total, 'paid_amount' => 0,
                        'payment_status' => 'unpaid', 'notes' => $this->notes ?: null,
                        'created_at' => $invoiceDate, 'updated_at' => now(),
                    ]);

                    foreach ($validated as $line) {
                        DB::table('order_items')->insert([
                            'tenant_id' => $tenantId, 'order_id' => $return->id, 'product_id' => $line['product']->id,
                            'quantity' => -$line['quantity'], 'unit_price' => $line['price'], 'total_price' => -$line['total'],
                            'cost_price' => $line['product']->cost_price, 'discount' => 0,
                            'total_cost' => -(float) $line['product']->cost_price * $line['quantity'],
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        \App\Services\StockMovementContext::forDocument($return, fn () => $line['stock']->increment('stock_quantity', $line['quantity']));
                    }
                } else {
                    $returnId = DB::table('purchase_returns')->insertGetId([
                        'tenant_id' => $tenantId, 'branch_id' => $branchId, 'purchase_id' => null,
                        'supplier_id' => $lockedParty->id, 'created_by' => auth()->id(),
                        'reference_number' => $this->invoiceNumber, 'total' => $total,
                        'notes' => $this->notes ?: null, 'created_at' => $invoiceDate, 'updated_at' => now(),
                    ]);

                    foreach ($validated as $line) {
                        DB::table('purchase_return_items')->insert([
                            'tenant_id' => $tenantId, 'purchase_return_id' => $returnId, 'purchase_item_id' => null,
                            'product_id' => $line['product']->id, 'quantity' => $line['quantity'],
                            'unit_cost' => $line['price'], 'subtotal' => $line['total'],
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        \App\Services\StockMovementContext::reference('purchase_return', $returnId, fn () => $line['stock']->decrement('stock_quantity', $line['quantity']));
                    }

                    $lockedParty->current_balance = (float) $lockedParty->current_balance - $total;
                    $lockedParty->save();
                }
            });

            $this->successMessage = $this->kind === 'sale'
                ? "تم تسجيل مردود المبيعات على حساب العميل وإعادة الأصناف للمخزون. رقم الفاتورة: {$savedInvoiceNumber}"
                : "تم تسجيل مردود المشتريات على حساب المورد وخصم الأصناف من المخزون. رقم الفاتورة: {$savedInvoiceNumber}";
            $this->lines = [];
            $this->notes = '';
            $this->productSearch = '';
            $this->productResults = [];
            $this->invoiceNumber = $this->generateInvoiceNumber();
        } catch (\Throwable $exception) {
            report($exception);
            $this->errorMessage = $exception instanceof \DomainException
                ? $exception->getMessage()
                : 'تعذر حفظ المرتجع. تحقق من الرصيد والمخزون ثم حاول مجدداً.';
        }
    }

    public function render(): View
    {
        return $this->view()->layout('layouts::tenant');
    }
}; ?>

<flux:main class="min-h-[calc(100vh-4rem)] bg-slate-100 p-2 sm:p-3 md:p-4" dir="rtl">
    <div class="mx-auto max-w-[1700px] space-y-3">
        <section class="overflow-visible rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 rounded-t-2xl bg-slate-950 p-3 text-white lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-3">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 text-xl">🧾</span>
                    <div>
                        <h1 class="text-lg font-black">{{ $kind === 'sale' ? 'فاتورة مردود مبيعات' : 'فاتورة مردود مشتريات' }}</h1>
                        <div class="mt-1 flex flex-wrap gap-x-4 text-[11px] text-slate-300">
                            <span>رقم الفاتورة: <strong class="font-mono text-white">{{ $invoiceNumber }}</strong></span>
                            <span>التاريخ: <strong class="text-white">{{ $invoiceDate }}</strong></span>
                        </div>
                    </div>
                </div>
                <button type="button" wire:click="startNewReturn" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-black hover:bg-emerald-500">+ فاتورة جديدة</button>
            </div>
        </section>

        @if ($errorMessage)
            <div class="flex items-center justify-between gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
                <span>{{ $errorMessage }}</span><button type="button" wire:click="$set('errorMessage', null)">✕</button>
            </div>
        @endif
        @if ($successMessage)
            <div class="flex items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
                <span>{{ $successMessage }}</span><button type="button" wire:click="$set('successMessage', null)">✕</button>
            </div>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-3 flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
                <div>
                    <h2 class="text-sm font-black text-slate-900">بيانات الفاتورة</h2>
                    <p class="mt-1 text-[11px] font-semibold text-slate-400">اختر {{ $kind === 'sale' ? 'العميل' : 'المورد' }} والفرع والتاريخ.</p>
                </div>
                <span class="rounded-xl bg-slate-50 px-3 py-2 text-left">
                    <span class="block text-[10px] font-bold text-slate-400">رقم الفاتورة</span>
                    <strong class="font-mono text-sm text-slate-900">{{ $invoiceNumber }}</strong>
                </span>
            </div>

            <div class="grid gap-3 lg:grid-cols-12">
                <div class="relative lg:col-span-5">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">{{ $kind === 'sale' ? 'العميل' : 'المورد' }}</label>
                    @if ($selectedPartyId)
                        <div class="flex h-[46px] items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 px-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-black text-emerald-900">{{ $selectedPartyName }}</div>
                                <div class="text-[10px] font-semibold text-emerald-700">{{ $kind === 'sale' ? 'فاتورة مرتبطة بحساب العميل' : 'فاتورة مرتبطة بحساب المورد' }}</div>
                            </div>
                            <button type="button" wire:click="clearParty" class="rounded-lg px-2 py-1 text-xs font-black text-emerald-700 hover:bg-emerald-100">تغيير</button>
                        </div>
                    @else
                        <input type="text" wire:model.live.debounce.180ms="partySearch" wire:keydown.enter.prevent="selectFirstParty" autocomplete="off" placeholder="ابحث بالاسم أو الهاتف..." class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold outline-none focus:border-slate-400 focus:bg-white">
                        @if ($partySearch !== '' || count($partyResults))
                            <div class="absolute right-0 top-full z-40 mt-1 max-h-64 w-full overflow-y-auto rounded-2xl border border-slate-200 bg-white p-1.5 shadow-2xl">
                                @forelse ($partyResults as $party)
                                    <button type="button" wire:click="selectParty({{ $party['id'] }})" class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-right hover:bg-slate-50">
                                        <span class="text-sm font-black text-slate-800">{{ $party['name'] }}</span><span class="text-xs text-slate-400">{{ $party['phone'] ?: 'بدون هاتف' }}</span>
                                    </button>
                                @empty
                                    <div class="p-4 text-center text-xs font-bold text-slate-400">لا توجد نتائج مطابقة.</div>
                                @endforelse
                            </div>
                        @endif
                    @endif
                </div>
                <div class="lg:col-span-3">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">الفرع</label>
                    <select wire:model.live="selectedBranchId" @disabled(auth()->user()?->branch_id) class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700 disabled:opacity-60">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch['id'] }}">{{ $branch['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">التاريخ</label>
                    <input type="date" wire:model="invoiceDate" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700">
                </div>
                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">نوع العملية</label>
                    <div class="flex h-[46px] items-center rounded-xl border border-indigo-200 bg-indigo-50 px-3 text-xs font-black text-indigo-800">{{ $kind === 'sale' ? 'مردود مبيعات' : 'مردود مشتريات' }}</div>
                </div>
            </div>
        </section>

        <section class="overflow-visible rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 bg-slate-50 p-3">
                    <div class="mb-1.5 flex flex-wrap items-center justify-between gap-2">
                        <label for="return-product-search" class="block text-xs font-black text-slate-600">إضافة مادة / باركود</label>
                        @unless ($selectedPartyId)
                            <span class="text-[11px] font-bold text-amber-700">اختر {{ $kind === 'sale' ? 'العميل' : 'المورد' }} أولاً لتفعيل إضافة الأصناف.</span>
                        @endunless
                    </div>
                    <div class="relative">
                        <input id="return-product-search" type="text" wire:model.live.debounce.120ms="productSearch" wire:keydown.enter.prevent="addFirstProduct" @disabled(!$selectedPartyId) autocomplete="off" placeholder="ابحث باسم المادة أو امسح الباركود ثم اضغط Enter..." class="w-full rounded-xl border-2 border-indigo-200 bg-white px-4 py-3 text-sm font-black outline-none focus:border-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                        @if ($productSearch !== '' || count($productResults))
                            <div class="absolute right-0 top-full z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-2xl border border-indigo-200 bg-white p-1.5 shadow-2xl">
                                @forelse ($productResults as $product)
                                    <button type="button" wire:click="addProduct({{ $product['id'] }})" class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-right hover:bg-indigo-50">
                                        <span class="font-black text-slate-800">{{ $product['name'] }}</span>
                                        <span class="text-xs font-bold text-slate-500">المتاح للإرجاع {{ number_format($product['available'] ?? $product['stock'], 2) }}</span>
                                    </button>
                                @empty
                                    <div class="p-4 text-center text-xs font-bold text-slate-400">لا توجد أصناف مطابقة أو لا توجد كمية متاحة لهذا الطرف.</div>
                                @endforelse
                            </div>
                        @endif
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <div class="app-table-scroll" role="region" aria-label="جدول البيانات — اسحب لعرض بقية الأعمدة" tabindex="0">
                    <table class="w-full min-w-[900px] text-right">
                        <thead class="bg-slate-900 text-[11px] font-black text-white">
                            <tr><th class="w-12 px-3 py-3 text-center">#</th><th class="px-3 py-3">المادة</th><th class="w-32 px-3 py-3 text-center">المتاح للإرجاع</th><th class="w-32 px-3 py-3 text-center">الكمية</th><th class="w-36 px-3 py-3 text-center">{{ $kind === 'sale' ? 'سعر البيع' : 'تكلفة الشراء' }}</th><th class="w-36 px-3 py-3 text-center">الإجمالي</th><th class="w-16 px-3 py-3"></th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse ($lines as $index => $line)
                                <tr wire:key="return-line-{{ $line['id'] }}" class="transition hover:bg-slate-50">
                                    <td class="px-3 py-3 text-center text-xs font-black text-slate-400">{{ $loop->iteration }}</td>
                                    <td class="px-3 py-3"><div class="text-sm font-black text-slate-800">{{ $line['name'] }}</div><div class="mt-1 text-[10px] text-slate-400">رقم الصنف: {{ $line['id'] }}</div></td>
                                    <td class="px-3 py-3 text-center"><span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-black text-slate-600">{{ number_format($line['available'], 2) }}</span></td>
                                    <td class="px-3 py-3"><input type="number" min="0.01" max="{{ $line['available'] }}" step="0.01" wire:model.live="lines.{{ $index }}.quantity" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-sm font-black outline-none focus:border-indigo-400"></td>
                                    <td class="px-3 py-3"><input type="number" min="0" step="0.01" wire:model.live="lines.{{ $index }}.price" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-sm font-black text-indigo-700 outline-none focus:border-indigo-400"></td>
                                    <td class="px-3 py-3 text-center font-mono text-sm font-black text-slate-900">{{ number_format((float) $line['quantity'] * (float) $line['price'], 2) }}</td>
                                    <td class="px-3 py-3 text-center"><button type="button" wire:click="removeLine({{ $index }})" class="rounded-lg px-2 py-1.5 text-xs font-black text-rose-600 hover:bg-rose-50">حذف</button></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-6 py-14 text-center"><div class="text-3xl text-slate-300">＋</div><div class="mt-3 text-base font-black text-slate-700">{{ $selectedPartyId ? 'أضف أصناف الفاتورة' : 'الفاتورة جاهزة لإضافة الأصناف' }}</div><div class="mt-1 text-sm font-semibold text-slate-400">{{ $selectedPartyId ? 'اكتب اسم الصنف أو امسح الباركود ثم اضغط Enter.' : 'اختر العميل أو المورد بالأعلى، ثم ابحث عن الأصناف أو امسح باركودها.' }}</div></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    </div>
                </div>
        </section>

            <section class="grid gap-3 xl:grid-cols-12">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm xl:col-span-7">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">ملاحظات الفاتورة</label>
                    <textarea wire:model="notes" rows="3" placeholder="أضف ملاحظات الفاتورة..." class="w-full resize-none rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold outline-none focus:border-indigo-400 focus:bg-white"></textarea>
                    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 text-xs font-bold text-amber-800">سيُسجل المبلغ على حساب {{ $kind === 'sale' ? 'العميل ويعاد الصنف إلى المخزون' : 'المورد ويخصم الصنف من المخزون' }}.</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm xl:col-span-5">
                    @php
                        $returnTotal = collect($lines)->sum(fn ($line) => (float) $line['quantity'] * (float) $line['price']);
                        $returnQuantity = collect($lines)->sum(fn ($line) => (float) $line['quantity']);
                    @endphp
                    <div class="mb-3 flex items-center justify-between"><div><div class="text-sm font-black text-slate-900">ملخص الفاتورة</div><div class="mt-1 text-[11px] text-slate-400">{{ count($lines) }} أصناف</div></div><span class="rounded-xl bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-800">الكمية {{ number_format($returnQuantity, 2) }}</span></div>
                    <div class="flex items-center justify-between border-t border-slate-100 py-3 text-sm"><span class="font-bold text-slate-500">المجموع الفرعي</span><strong>{{ number_format($returnTotal, 2) }}</strong></div>
                    <div class="flex items-center justify-between border-t border-slate-200 py-3"><span class="text-base font-black text-slate-900">إجمالي المردود</span><strong class="text-3xl font-black text-indigo-700">{{ number_format($returnTotal, 2) }}</strong></div>
                    <button type="button" wire:click="saveReturn" wire:loading.attr="disabled" @disabled(empty($lines) || !$selectedPartyId) class="mt-2 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="saveReturn">💾 حفظ الفاتورة</span><span wire:loading wire:target="saveReturn">جاري الحفظ...</span></button>
                </div>
            </section>
    </div>
</flux:main>
