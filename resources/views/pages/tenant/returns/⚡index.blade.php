<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\Payment;
use App\Models\TenantSetting;
use App\Services\StockMovementContext;
use Livewire\Attributes\Locked;
use Illuminate\Support\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ?int $currentReturnId = null;
    #[Locked]
    public bool $editing = false;
    public string $returnSearch = '';
    public bool $showReturnSearch = false;
    public string $discountType = 'fixed';
    public $discountRate = 0;
    public $taxPercent = 16;
    public $paidAmount = 0;
    public string $paymentMethod = 'cash';
    public bool $showPrint = false;
    #[Locked]
    public array $printData = [];
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
    public ?int $selectedProductId = null;
    public string $selectedProductName = '';
    public float $selectedProductAvailable = 0;
    public float $selectedQuantity = 1;
    public float $selectedPrice = 0;
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
        $this->currentReturnId = null;
        $this->editing = false;
        $this->discountType = 'fixed';
        $this->discountRate = 0;
        $this->taxPercent = $this->kind === 'sale' ? 16 : 0;
        $this->paidAmount = 0;
        $this->paymentMethod = 'cash';
        $this->showPrint = false;
        $this->resetValidation();
        $this->invoiceDate = now()->toDateString();
        $this->invoiceNumber = $this->generateInvoiceNumber();
        $this->selectedPartyId = null;
        $this->selectedPartyName = '';
        $this->partySearch = '';
        $this->partyResults = [];
        $this->productSearch = '';
        $this->productResults = [];
        $this->resetProductSelection();
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
        $branchId = auth()->user()?->activeBranchId() ?: $this->selectedBranchId;

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
        $this->resetProductSelection();
        $this->lines = [];
        $this->errorMessage = null;
        $this->successMessage = null;
        $this->startNewReturn(false);
    }

    public function toggleReturnSearch(): void
    {
        $this->showReturnSearch = ! $this->showReturnSearch;
    }

    public function updatedSelectedBranchId(): void
    {
        $this->selectedBranchId = $this->branchId();

        $this->productSearch = '';
        $this->productResults = [];
        $this->resetProductSelection();
        $this->lines = [];
        $this->errorMessage = null;
    }

    public function updatedPartySearch(): void
    {
        if ($this->selectedPartyId && trim($this->partySearch) === $this->selectedPartyName) {
            return;
        }

        $this->selectedPartyId = null;
        $this->selectedPartyName = '';
        $this->resetProductSelection();
        $this->lines = [];
        $this->productResults = [];

        $search = trim($this->partySearch);
        if ($search === '') {
            $this->partyResults = [];
            return;
        }

        $types = ['customer', 'supplier', 'both'];
        $this->partyResults = Party::query()->where('tenant_id', $this->tenantId())->whereIn('type', $types)
            ->where('is_active', true)->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%");
            })->orderBy('name')->limit(10)->get(['id', 'name', 'phone', 'current_balance'])->toArray();
    }

    public function selectParty(int $partyId): void
    {
        $types = ['customer', 'supplier', 'both'];
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
        $this->resetProductSelection();
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
        $this->resetProductSelection();
        $this->lines = [];
    }

    public function updatedProductSearch(): void
    {
        if ($this->selectedProductId && trim($this->productSearch) !== $this->selectedProductName) {
            $this->resetProductSelection();
        }

        $this->searchProducts();
    }

    public function searchProducts(): void
    {
        $this->productResults = [];
        if (!$this->selectedPartyId || trim($this->productSearch) === '' || $this->selectedProductId) {
            $this->productResults = [];
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->branchId();
        $search = trim($this->productSearch);
        $this->productResults = Product::query()->where('tenant_id', $tenantId)
            ->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $branchId))
            ->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('product_number', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery->where('barcode', 'like', "{$search}%"));
            })->with(['branchProducts' => fn ($query) => $query->where('branch_id', $branchId)])
            ->orderBy('name')->limit(40)->get()->map(function (Product $product): array {
                $stock = $product->branchProducts->first();
                $available = $this->remainingQuantity((int) $product->id, $this->selectedPartyId);
                if ($this->kind === 'purchase') {
                    $available = min($available, (float) ($stock?->stock_quantity ?? 0) + $this->savedPurchaseQuantity((int) $product->id));
                }

                return [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'product_number' => $product->product_number,
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

    public function selectProduct(int $productId): void
    {
        $this->errorMessage = null;
        if (!$this->selectedPartyId) {
            $this->errorMessage = 'اختر العميل أو المورد أولاً.';
            return;
        }

        $product = Product::query()->where('tenant_id', $this->tenantId())->whereKey($productId)->first();
        $branchProduct = BranchProduct::query()->where('tenant_id', $this->tenantId())
            ->where('branch_id', $this->branchId())->where('product_id', $productId)->first();
        if (!$product || !$branchProduct) {
            $this->errorMessage = 'الصنف غير مرتبط بالفرع الحالي.';
            return;
        }

        $available = $this->remainingQuantity($productId, $this->selectedPartyId);
        if ($this->kind === 'purchase') {
            $available = min($available, (float) $branchProduct->stock_quantity + $this->savedPurchaseQuantity($productId));
        }
        if ($available <= 0) {
            $this->errorMessage = 'لا توجد كمية متاحة للإرجاع لهذا الصنف مع هذا الطرف.';
            return;
        }

        $this->selectedProductId = $productId;
        $this->selectedProductName = $product->name;
        $this->selectedProductAvailable = $available;
        $this->selectedQuantity = 1;
        $this->selectedPrice = $this->latestPrice($productId, $this->selectedPartyId, $product);
        $this->productSearch = $product->name;
        $this->productResults = [];
        $this->dispatch('return-product-selected');
    }

    public function addSelectedProduct(): void
    {
        if (!$this->selectedProductId) {
            $this->errorMessage = 'اختر صنفاً من نتائج البحث أولاً.';
            return;
        }

        $this->addProduct($this->selectedProductId, $this->selectedQuantity, $this->selectedPrice);
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
                ->when($this->currentReturnId, fn ($query) => $query->where('orders.id', '!=', $this->currentReturnId))
                ->where('order_items.product_id', $productId)->sum(DB::raw('ABS(order_items.quantity)'));

            return max(0, $sold - $returned);
        }

        $purchased = (float) DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.tenant_id', $this->tenantId())->where('purchases.supplier_id', $partyId)
            ->whereNull('purchases.deleted_at')->where('purchase_items.product_id', $productId)->sum('purchase_items.quantity');
        $returned = (float) DB::table('purchase_return_items')->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_returns.tenant_id', $this->tenantId())->where('purchase_returns.supplier_id', $partyId)
            ->when($this->currentReturnId, fn ($query) => $query->where('purchase_returns.id', '!=', $this->currentReturnId))
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

    public function addProduct(int $productId, ?float $quantity = null, ?float $price = null): void
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
            $available = min($available, (float) $branchProduct->stock_quantity + $this->savedPurchaseQuantity($productId));
        }
        if ($available <= 0) {
            $this->errorMessage = 'لا توجد كمية متاحة للإرجاع لهذا الصنف مع هذا الطرف.';
            return;
        }

        $quantity ??= 1;
        $price ??= $this->latestPrice($productId, $this->selectedPartyId, $product);
        if (!is_finite($quantity) || $quantity <= 0 || !is_finite($price) || $price < 0) {
            $this->errorMessage = 'أدخل كمية وسعراً صالحين للصنف.';
            return;
        }

        $lineIndex = collect($this->lines)->search(fn (array $line): bool => (int) $line['id'] === $productId);
        if ($lineIndex !== false) {
            if ((float) $this->lines[$lineIndex]['quantity'] + $quantity > $available) {
                $this->errorMessage = 'الكمية المطلوب إرجاعها أكبر من الكمية المتاحة.';
                return;
            }
            $this->lines[$lineIndex]['quantity'] = (float) $this->lines[$lineIndex]['quantity'] + $quantity;
        } else {
            $this->lines[] = [
                'id' => $productId,
                'returned_to_stock' => true,
                'name' => $product->name,
                'product_number' => $product->product_number,
                'available' => $available,
                'quantity' => $quantity,
                'price' => $price,
            ];
        }

        $this->productSearch = '';
        $this->productResults = [];
        $this->resetProductSelection();
        $this->dispatch('return-product-added');
    }

    private function resetProductSelection(): void
    {
        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedProductAvailable = 0;
        $this->selectedQuantity = 1;
        $this->selectedPrice = 0;
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function shareInvoicePdf(): void
    {
        Gate::authorize('returns.view');
        abort_unless($this->currentReturnId && $this->returnQuery()->where('id', $this->currentReturnId)->exists(), 404);
        $this->dispatch('share-return-pdf', url: route('tenant.returns.pdf', ['kind' => $this->kind, 'returnId' => $this->currentReturnId]), filename: \App\Services\PdfFilename::forDocument($this->selectedPartyName, $this->invoiceNumber, $this->kind === 'sale' ? 'زبون' : 'مورد'));
    }

    public function copyReturn(): void
    {
        Gate::authorize('returns.create');
        $this->currentReturnId = null;
        $this->editing = false;
        $this->invoiceNumber = $this->generateInvoiceNumber();
        $this->invoiceDate = now()->toDateString();
        $this->paidAmount = 0;
        $this->showPrint = false;
        foreach ($this->lines as &$line) {
            $line['available'] = $this->remainingQuantity((int) $line['id'], $this->selectedPartyId);
        }
        unset($line);
        $this->successMessage = 'تم نسخ الأصناف إلى مردود جديد. راجع الكميات المتاحة ثم احفظ.';
    }

    private function savedPurchaseQuantity(int $productId): float
    {
        if (!$this->currentReturnId || $this->kind !== 'purchase') {
            return 0;
        }
        $old = $this->returnQuery()->where('id', $this->currentReturnId)->first();
        if (!$old || (int) $old->branch_id !== $this->branchId()) {
            return 0;
        }

        return (float) $this->savedLines($this->currentReturnId)->where('product_id', $productId)->sum('quantity');
    }

    private function returnQuery(): \Illuminate\Database\Query\Builder
    {
        $query = DB::table($this->kind === 'sale' ? 'orders' : 'purchase_returns')
            ->where('tenant_id', $this->tenantId())
            ->when(
                auth()->user()?->branch_id && ! auth()->user()->isTenantOwner() && ! auth()->user()->isSaaSAdmin(),
                fn ($query, $branch) => $query->where('branch_id', $branch)
            )
            ->when(
                auth()->user()?->isTenantOwner() || auth()->user()?->isSaaSAdmin(),
                fn ($query) => $query->where('branch_id', $this->branchId())
            );
        if ($this->kind === 'sale') {
            $query->where('type', 'return')->whereNull('deleted_at')->where('source', 'web')
                ->whereNull('original_order_id')->whereNull('shift_id');
        } else {
            $query->whereNull('purchase_id');
        }

        return $query;
    }

    private function savedLines(int $id): \Illuminate\Support\Collection
    {
        return DB::table($this->kind === 'sale' ? 'order_items' : 'purchase_return_items')
            ->where('tenant_id', $this->tenantId())
            ->where($this->kind === 'sale' ? 'order_id' : 'purchase_return_id', $id)->get();
    }

    public function loadReturn(int $id): void
    {
        Gate::authorize('returns.view');
        $return = $this->returnQuery()->where('id', $id)->first();
        abort_unless($return, 404);
        $this->startNewReturn(false);
        $this->showReturnSearch = false;
        $this->currentReturnId = $id;
        $this->selectedBranchId = (int) $return->branch_id;
        $this->selectedPartyId = (int) ($this->kind === 'sale' ? $return->customer_id : $return->supplier_id);
        $this->selectedPartyName = (string) Party::query()->where('tenant_id', $this->tenantId())->whereKey($this->selectedPartyId)->value('name');
        $this->partySearch = $this->selectedPartyName;
        $this->invoiceNumber = $this->kind === 'sale' ? $return->invoice_number : $return->reference_number;
        $this->invoiceDate = Carbon::parse($return->created_at)->toDateString();
        $this->notes = $return->notes ?? '';
        $this->discountType = $return->discount_type ?? 'fixed';
        $this->discountRate = (float) ($return->discount_rate ?? 0);
        $savedTax = abs((float) ($return->tax_amount ?? 0));
        $net = abs((float) $return->total) - $savedTax;
        $this->taxPercent = $net > 0 ? round($savedTax / $net * 100, 2) : 0;
        $this->paidAmount = abs((float) ($return->paid_amount ?? 0));
        $this->paymentMethod = $return->payment_method ?? Payment::query()->where('tenant_id', $this->tenantId())->where('order_id', $id)->value('payment_method') ?? 'cash';
        foreach ($this->savedLines($id) as $line) {
            $product = Product::query()->where('tenant_id', $this->tenantId())->findOrFail($line->product_id);
            $this->lines[] = [
                'id' => $product->id, 'name' => $product->name, 'product_number' => $product->product_number,
                'quantity' => abs((float) $line->quantity),
                'price' => (float) ($this->kind === 'sale' ? $line->unit_price : $line->unit_cost),
                'available' => $this->remainingQuantity($product->id, $this->selectedPartyId),
                'returned_to_stock' => (bool) ($line->returned_to_stock ?? true),
            ];
        }
    }

    public function editLoadedInvoice(): void
    {
        Gate::authorize('returns.create');
        abort_unless($this->currentReturnId && $this->returnQuery()->where('id', $this->currentReturnId)->exists(), 404);
        $this->editing = true;
        $this->successMessage = 'عدّل الفاتورة ثم اضغط حفظ التعديلات.';
    }

    public function searchReturn(): void
    {
        Gate::authorize('returns.view');
        $column = $this->kind === 'sale' ? 'invoice_number' : 'reference_number';
        $id = $this->returnQuery()->where($column, trim($this->returnSearch))->value('id');
        if ($id) {
            $this->loadReturn((int) $id);
        } else {
            $this->errorMessage = 'لم يتم العثور على المردود بهذا الرقم.';
        }
    }

    public function previousInvoice(): void
    {
        $id = $this->returnQuery()->when($this->currentReturnId, fn ($query) => $query->where('id', '<', $this->currentReturnId))->orderByDesc('id')->value('id');
        if ($id) {
            $this->loadReturn((int) $id);
        }
    }

    public function nextInvoice(): void
    {
        $id = $this->returnQuery()->where('id', '>', $this->currentReturnId ?? 0)->orderBy('id')->value('id');
        if ($id) {
            $this->loadReturn((int) $id);
        }
    }

    /** @return array{subtotal: float, discount: float, tax: float, total: float, paid: float, balance: float} */
    public function totals(): array
    {
        $subtotal = round(collect($this->lines)->sum(fn ($line): float => round(max(0, round((float) ($line['quantity'] ?? 0), 2)) * max(0, round((float) ($line['price'] ?? 0), 2)), 2)), 2);
        $discount = round(min($subtotal, max(0, (float) $this->discountRate) * ($this->discountType === 'percentage' ? $subtotal / 100 : 1)), 2);
        $total = round($subtotal - $discount, 2);
        $rate = TenantSetting::getBool($this->tenantId(), 'show_tax', true) ? max(0, (float) $this->taxPercent) : 0;
        $tax = round($total * $rate / (100 + $rate), 2);
        $paid = max(0, (float) $this->paidAmount);
        $party = Party::query()->where('tenant_id', $this->tenantId())->find($this->selectedPartyId);
        $direction = $party ? ($this->kind === 'sale' ? $party->saleBalanceDirection() : $party->purchaseBalanceDirection()) : 1;
        $balance = (float) ($party?->current_balance ?? 0);
        if ($this->currentReturnId) {
            $old = $this->returnQuery()->where('id', $this->currentReturnId)->first();
            $oldParty = $old ? ($this->kind === 'sale' ? $old->customer_id : $old->supplier_id) : null;
            if ((int) $oldParty === $this->selectedPartyId) {
                $balance += $direction * (abs((float) $old->total) - abs((float) $old->paid_amount));
            }
        }

        return ['subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total, 'paid' => $paid, 'balance' => round($balance - $direction * ($total - $paid), 2)];
    }

    /** @return array<string, array{stock: BranchProduct, delta: float}> */
    private function stockChanges(?object $old, array $items, int $branchId): array
    {
        $changes = [];
        $add = function (int $branch, int $product, float $delta) use (&$changes): void {
            $key = $branch.':'.$product;
            if (!isset($changes[$key])) {
                $stock = BranchProduct::query()->where('tenant_id', $this->tenantId())->where('branch_id', $branch)->where('product_id', $product)->lockForUpdate()->firstOrFail();
                $changes[$key] = ['stock' => $stock, 'delta' => 0.0];
            }
            $changes[$key]['delta'] += $delta;
        };
        $direction = $this->kind === 'sale' ? 1 : -1;
        if ($old) {
            foreach ($this->savedLines((int) $old->id) as $line) {
                if ($this->kind !== 'sale' || ($line->returned_to_stock ?? true)) {
                    $add((int) $old->branch_id, (int) $line->product_id, -$direction * abs((float) $line->quantity));
                }
            }
        }
        foreach ($items as $line) {
            if ($this->kind !== 'sale' || ($line['returned_to_stock'] ?? true)) {
                $add($branchId, (int) $line['id'], $direction * (float) $line['quantity']);
            }
        }
        foreach ($changes as $change) {
            if ((float) $change['stock']->stock_quantity + $change['delta'] < -0.00001) {
                throw new \DomainException('المخزون غير كافٍ لتنفيذ تعديل أو حذف هذا المردود.');
            }
        }

        return $changes;
    }

    private function reverseBalance(object $old): void
    {
        $partyId = $this->kind === 'sale' ? $old->customer_id : $old->supplier_id;
        $party = Party::query()->where('tenant_id', $this->tenantId())->lockForUpdate()->findOrFail($partyId);
        $unsettled = abs((float) $old->total) - abs((float) $old->paid_amount);
        $this->kind === 'sale'
            ? app(\App\Services\PartyBalance::class)->applySale($party, $unsettled)
            : app(\App\Services\PartyBalance::class)->applyPurchase($party, $unsettled);
    }

    public function saveReturn(bool $print = false): void
    {
        if ($this->currentReturnId && !$this->editing && $print) {
            $this->printCurrentInvoice();

            return;
        }
        Gate::authorize('returns.create');
        if ($this->currentReturnId && !$this->editing) {
            $this->errorMessage = 'اضغط تعديل أولاً لتعديل الفاتورة المحفوظة.';
            return;
        }
        $this->validate([
            'kind' => ['required', 'in:sale,purchase'],
            'selectedPartyId' => ['required', 'integer'], 'invoiceDate' => ['required', 'date'],
            'invoiceNumber' => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::unique($this->kind === 'sale' ? 'orders' : 'purchase_returns', $this->kind === 'sale' ? 'invoice_number' : 'reference_number')->where('tenant_id', $this->tenantId())->ignore($this->currentReturnId)], 'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => ['required', 'array', 'min:1'], 'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.01'], 'lines.*.price' => ['required', 'numeric', 'min:0'],
            'lines.*.returned_to_stock' => ['sometimes', 'boolean'],
            'discountType' => ['required', 'in:fixed,percentage'], 'discountRate' => ['required', 'numeric', 'min:0'],
            'taxPercent' => ['required', 'numeric', 'min:0'], 'paidAmount' => ['required', 'numeric', 'min:0'],
            'paymentMethod' => ['required', 'in:cash,card,bank_transfer,cheque'],
        ]);
        foreach ($this->lines as &$line) {
            $line['quantity'] = round((float) $line['quantity'], 2);
            $line['price'] = round((float) $line['price'], 2);
        }
        unset($line);
        $totals = $this->totals();
        if ((float) $this->paidAmount > $totals['total'] || ($this->discountType === 'percentage' && (float) $this->discountRate > 100) || ($this->discountType === 'fixed' && (float) $this->discountRate > $totals['subtotal'])) {
            $this->errorMessage = 'الدفعة أو الخصم أكبر من قيمة الفاتورة.';
            return;
        }
        $this->errorMessage = null;
        try {
            $id = DB::transaction(function () use ($totals): int {
                $tenantId = $this->tenantId();
                $branchId = $this->branchId();
                abort_unless($branchId, 422);
                $old = $this->currentReturnId ? $this->returnQuery()->where('id', $this->currentReturnId)->lockForUpdate()->first() : null;
                abort_if($this->currentReturnId && !$old, 404);
                $party = Party::query()->where('tenant_id', $tenantId)->where('is_active', true)->lockForUpdate()->findOrFail($this->selectedPartyId);
                $cost = 0;
                foreach ($this->lines as $line) {
                    $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($line['id']);
                    if ((float) $line['quantity'] > $this->remainingQuantity($product->id, $party->id) + 0.00001) {
                        throw new \DomainException('كمية المرتجع تتجاوز الكمية التي تعامل بها هذا العميل أو المورد سابقاً.');
                    }
                    $cost += (float) $product->cost_price * (float) $line['quantity'];
                }
                $changes = $this->stockChanges($old, $this->lines, $branchId);
                if ($old) {
                    $this->reverseBalance($old);
                }
                $common = ['tenant_id' => $tenantId, 'branch_id' => $branchId,
                    'discount_type' => $this->discountType, 'discount_rate' => (float) $this->discountRate,
                    'discount' => $totals['discount'], 'paid_amount' => $this->kind === 'sale' ? -$totals['paid'] : $totals['paid'],
                    'notes' => $this->notes ?: null, 'created_at' => Carbon::parse($this->invoiceDate)->startOfDay(), 'updated_at' => now()];
                if ($this->kind === 'sale') {
                    $data = $common + ['customer_id' => $party->id, 'customer_name' => $party->name,
                        'customer_phone' => $party->phone, 'customer_address' => $party->address,
                        'invoice_number' => $this->invoiceNumber, 'type' => 'return', 'status' => 'completed',
                        'subtotal' => -$totals['subtotal'], 'total' => -$totals['total'], 'tax_amount' => -$totals['tax'],
                        'total_cost' => -$cost, 'total_profit' => -$totals['total'] + $totals['tax'] + $cost, 'delivery_fee' => 0,
                        'payment_status' => $totals['paid'] >= $totals['total'] ? 'paid' : ($totals['paid'] > 0 ? 'partial' : 'unpaid')];
                    if ($old) {
                        Order::query()->where('tenant_id', $tenantId)->whereKey($old->id)->update($data);
                        $id = (int) $old->id;
                    } else {
                        $id = Order::create($data + ['created_by' => auth()->id(), 'source' => 'web'])->id;
                    }
                } else {
                    $data = $common + ['payment_method' => $this->paymentMethod, 'supplier_id' => $party->id, 'reference_number' => $this->invoiceNumber,
                        'subtotal' => $totals['subtotal'], 'total' => $totals['total'], 'tax_amount' => $totals['tax']];
                    if ($old) {
                        DB::table('purchase_returns')->where('tenant_id', $tenantId)->where('id', $old->id)->update($data);
                        $id = (int) $old->id;
                    } else {
                        $id = DB::table('purchase_returns')->insertGetId($data + ['created_by' => auth()->id()]);
                    }
                }
                $table = $this->kind === 'sale' ? 'order_items' : 'purchase_return_items';
                $foreign = $this->kind === 'sale' ? 'order_id' : 'purchase_return_id';
                DB::table($table)->where('tenant_id', $tenantId)->where($foreign, $id)->delete();
                foreach ($this->lines as $line) {
                    $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($line['id']);
                    $item = ['tenant_id' => $tenantId, $foreign => $id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()];
                    $quantity = round((float) $line['quantity'], 2);
                    $price = round((float) $line['price'], 2);
                    $item += $this->kind === 'sale'
                        ? ['quantity' => -$quantity, 'unit_price' => $price, 'total_price' => -round($quantity * $price, 2), 'cost_price' => $product->cost_price, 'total_cost' => -$quantity * (float) $product->cost_price, 'discount' => 0, 'returned_to_stock' => (bool) ($line['returned_to_stock'] ?? true)]
                        : ['quantity' => $quantity, 'unit_cost' => $price, 'subtotal' => round($quantity * $price, 2)];
                    DB::table($table)->insert($item);
                }
                foreach ($changes as $change) {
                    if (abs($change['delta']) > 0.00001) {
                        StockMovementContext::reference($this->kind === 'sale' ? 'sales_return' : 'purchase_return', $id,
                            fn () => $change['stock']->increment('stock_quantity', $change['delta']), $this->invoiceNumber);
                    }
                }
                $unsettled = $totals['total'] - $totals['paid'];
                $this->kind === 'sale'
                    ? app(\App\Services\PartyBalance::class)->applySale($party->refresh(), -$unsettled)
                    : app(\App\Services\PartyBalance::class)->applyPurchase($party->refresh(), -$unsettled);
                $payments = Payment::query()->where('tenant_id', $tenantId)->where($foreign, $id);
                $payment = $payments->first();
                if ($totals['paid'] > 0) {
                    $paymentData = ['tenant_id' => $tenantId, 'branch_id' => $branchId, 'party_id' => $party->id, $foreign => $id,
                        'type' => $this->kind === 'sale' ? 'payment' : 'receipt', 'amount' => $totals['paid'],
                        'payment_method' => $this->paymentMethod, 'payment_date' => $this->invoiceDate,
                        'notes' => 'دفعة مردود '.$this->invoiceNumber];
                    if ($payment) {
                        $payment->update($paymentData);
                    } else {
                        Payment::create($paymentData + ['created_by' => auth()->id(), 'voucher_number' => 'RET-PAY-'.Str::upper(Str::random(12))]);
                    }
                } else {
                    $payments->delete();
                }

                return $id;
            });
            $this->loadReturn($id);
            $this->successMessage = $this->kind === 'sale' ? 'تم تسجيل مردود المبيعات وحفظ المخزون والرصيد.' : 'تم تسجيل مردود المشتريات وحفظ المخزون والرصيد.';
            if ($print) {
                $this->printCurrentInvoice();
            }
        } catch (\DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function deleteReturn(): void
    {
        Gate::authorize('returns.create');
        abort_unless($this->currentReturnId, 404);
        try {
            DB::transaction(function (): void {
                $old = $this->returnQuery()->where('id', $this->currentReturnId)->lockForUpdate()->first();
                abort_unless($old, 404);
                $changes = $this->stockChanges($old, [], (int) $old->branch_id);
                $this->reverseBalance($old);
                foreach ($changes as $change) {
                    StockMovementContext::reference($this->kind === 'sale' ? 'sales_return' : 'purchase_return', $old->id,
                        fn () => $change['stock']->increment('stock_quantity', $change['delta']), $this->invoiceNumber);
                }
                $foreign = $this->kind === 'sale' ? 'order_id' : 'purchase_return_id';
                Payment::query()->where('tenant_id', $this->tenantId())->where($foreign, $old->id)->delete();
                if ($this->kind === 'sale') {
                    Order::query()->where('tenant_id', $this->tenantId())->whereKey($old->id)->delete();
                } else {
                    Payment::withTrashed()->where('tenant_id', $this->tenantId())->where($foreign, $old->id)->update([$foreign => null]);
                    DB::table('purchase_return_items')->where('tenant_id', $this->tenantId())->where($foreign, $old->id)->delete();
                    $this->returnQuery()->where('id', $old->id)->delete();
                }
            });
            $this->startNewReturn(false);
            $this->successMessage = 'تم حذف المردود وعكس أثره على المخزون والرصيد.';
        } catch (\DomainException $exception) {
            $this->errorMessage = $exception->getMessage();
        }
    }

    public function printCurrentInvoice(): void
    {
        Gate::authorize('returns.view');
        if (!$this->currentReturnId) {
            $this->errorMessage = 'احفظ المردود أولاً قبل الطباعة.';
            return;
        }
        $return = $this->returnQuery()->where('id', $this->currentReturnId)->first();
        abort_unless($return, 404);
        $party = Party::query()->where('tenant_id', $this->tenantId())->find($this->kind === 'sale' ? $return->customer_id : $return->supplier_id);
        $this->printData = [
            'title' => $this->kind === 'sale' ? 'مردود مبيعات' : 'مردود مشتريات',
            'number' => $this->kind === 'sale' ? $return->invoice_number : $return->reference_number,
            'date' => Carbon::parse($return->created_at)->toDateString(), 'party' => $party?->name ?? '-',
            'branch' => Branch::query()->where('tenant_id', $this->tenantId())->whereKey($return->branch_id)->value('name'),
            'subtotal' => abs((float) $return->subtotal), 'discount' => (float) $return->discount,
            'tax' => abs((float) $return->tax_amount), 'total' => abs((float) $return->total),
            'paid' => abs((float) $return->paid_amount), 'balance' => (float) ($party?->current_balance ?? 0),
            'notes' => $return->notes, 'lines' => $this->savedLines($return->id)->map(function ($line): array {
                return ['name' => Product::query()->where('tenant_id', $this->tenantId())->whereKey($line->product_id)->value('name'),
                    'quantity' => abs((float) $line->quantity), 'price' => (float) ($this->kind === 'sale' ? $line->unit_price : $line->unit_cost),
                    'total' => abs((float) ($this->kind === 'sale' ? $line->total_price : $line->subtotal))];
            })->all(),
        ];
        $this->showPrint = true;
        $this->dispatch('print-pdf', url: route('tenant.returns.pdf', ['kind' => $this->kind, 'returnId' => $return->id]),
            title: \Illuminate\Support\Str::beforeLast(\App\Services\PdfFilename::forDocument($party?->name, $this->printData['number'], $this->kind === 'sale' ? 'زبون' : 'مورد'), '.pdf'));
    }

    public function render(): View
    {
        $column = $this->kind === 'sale' ? 'invoice_number' : 'reference_number';
        $recentReturns = $this->returnQuery()->when(trim($this->returnSearch) !== '', fn ($query) => $query->where($column, 'like', '%'.trim($this->returnSearch).'%'))->orderByDesc('id')->limit(20)->get();
        $partyColumn = $this->kind === 'sale' ? 'customer_id' : 'supplier_id';
        $returnPartyNames = Party::query()->where('tenant_id', $this->tenantId())
            ->whereIn('id', $recentReturns->pluck($partyColumn)->filter()->unique())->pluck('name', 'id');
        $selectedParty = $this->selectedPartyId
            ? Party::query()->where('tenant_id', $this->tenantId())->find($this->selectedPartyId, ['id', 'name', 'phone', 'current_balance'])
            : null;

        return $this->view([
            'recentReturns' => $recentReturns,
            'returnPartyNames' => $returnPartyNames,
            'partyColumn' => $partyColumn,
            'selectedParty' => $selectedParty,
            'totals' => $this->totals(),
            'showTax' => TenantSetting::getBool($this->tenantId(), 'show_tax', true),
        ])->layout('layouts::tenant');
    }
}; ?>

@php
    $isSale = $kind === 'sale';
    $partyLabel = $isSale ? 'العميل' : 'المورد';
    $viewOnly = $currentReturnId && ! $editing;
    $showBranchField = ! auth()->user()->isTenantOwner() && ! auth()->user()->isSaaSAdmin();
    $returnQuantity = collect($lines)->sum(fn ($line) => (float) $line['quantity']);
    $accent = $isSale ? 'rose' : 'amber';
@endphp

<flux:main class="bg-slate-100 p-2! sm:p-3! xl:h-[var(--invoice-fit-height,calc(100dvh-4rem))] xl:overflow-y-auto" dir="rtl">
    <div class="mx-auto max-w-[1700px] xl:flex xl:h-full xl:flex-col"
        x-data="{
            {{-- Sizes the page to the visible window on desktop so only the items table grows, like the invoice screens. --}}
            fitToViewport() {
                const main = this.$el?.closest?.('[data-flux-main]');
                if (!main) return;
                const top = main.getBoundingClientRect().top + window.scrollY;
                document.documentElement.style.setProperty('--invoice-fit-height', Math.max(520, window.innerHeight - top) + 'px');
            },
        }"
        x-init="$nextTick(() => fitToViewport())"
        x-on:resize.window.debounce.150ms="fitToViewport()"
        x-on:keydown.f3.window.prevent="$wire.saveReturn()"
        x-on:keydown.f6.window.prevent="$wire.saveReturn(true)"
        x-on:keydown.f7.window.prevent="$wire.toggleReturnSearch()"
        x-on:keydown.f9.window.prevent="document.getElementById('return-product-search')?.focus()"
        x-on:keydown.shift.f2.window.prevent="$wire.startNewReturn()">

        {{-- ================================================================
             شريط الأدوات الرئيسي
        ================================================================= --}}
        <div class="mb-3 shrink-0 rounded-xl border border-slate-200 bg-white px-3 py-3 shadow-sm sm:px-4">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <flux:icon.arrow-uturn-left class="size-10 shrink-0 {{ $isSale ? 'text-rose-600' : 'text-amber-600' }}" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-xl font-black text-slate-900">{{ $isSale ? 'فاتورة مردود مبيعات' : 'فاتورة مردود مشتريات' }}</h1>
                            @if ($currentReturnId)
                                @if ($editing)
                                    <span class="rounded-full bg-amber-500 px-3 py-0.5 text-xs font-black text-white">تعديل مردود</span>
                                @else
                                    <span class="rounded-full bg-slate-700 px-3 py-0.5 text-xs font-black text-white">محفوظ</span>
                                @endif
                            @else
                                <span class="rounded-full bg-emerald-600 px-3 py-0.5 text-xs font-black text-white">مردود جديد</span>
                            @endif
                        </div>
                        <div class="mt-0.5 font-mono text-xs font-bold text-slate-500">{{ $invoiceNumber }}</div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative" x-data="{ toolsOpen: false }" x-on:click.outside="toolsOpen = false" x-on:keydown.escape.stop="toolsOpen = false">
                        <button type="button" x-on:click="toolsOpen = ! toolsOpen" x-bind:aria-expanded="toolsOpen" aria-haspopup="menu" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                            أدوات <flux:icon.chevron-down class="size-4" />
                        </button>
                        <div x-cloak x-show="toolsOpen" x-transition.origin.top x-on:click="toolsOpen = false" role="menu" class="absolute right-0 top-full z-50 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-1.5 text-sm font-bold text-slate-700 shadow-xl">
                            <button type="button" role="menuitem" x-on:click="document.getElementById('return-notes')?.focus()" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right hover:bg-slate-50">
                                <flux:icon.chat-bubble-left-ellipsis class="size-4 text-slate-500" /> ملاحظات المردود
                            </button>
                            @if ($currentReturnId)
                                <button type="button" role="menuitem" wire:click="printCurrentInvoice" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right hover:bg-slate-50">
                                    <flux:icon.printer class="size-4 text-slate-500" /> طباعة المردود المحفوظ
                                </button>
                                <a role="menuitem" href="{{ route('tenant.returns.pdf', ['kind' => $kind, 'returnId' => $currentReturnId]) }}" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right hover:bg-slate-50">
                                    <flux:icon.document-arrow-down class="size-4 text-slate-500" /> تنزيل PDF
                                </a>
                                <button type="button" role="menuitem" wire:click="shareInvoicePdf" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right text-emerald-700 hover:bg-emerald-50">
                                    <flux:icon.share class="size-4" /> مشاركة / واتساب
                                </button>
                                <button type="button" role="menuitem" wire:click="copyReturn" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right hover:bg-slate-50">
                                    <flux:icon.document-duplicate class="size-4 text-slate-500" /> نسخ لفاتورة جديدة
                                </button>
                            @endif
                            @if ($selectedPartyId && auth()->user()->can('reports.analytics') && auth()->user()->can('customers.view'))
                                <div class="my-1 border-t border-slate-100"></div>
                                <a role="menuitem" href="{{ route('tenant.reports', ['report' => 'statement', 'party_id' => $selectedPartyId, 'party_type' => $isSale ? 'customer' : 'supplier']) }}" target="_blank" rel="noopener" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right text-indigo-700 hover:bg-indigo-50">
                                    <flux:icon.arrow-top-right-on-square class="size-4" /> كشف حساب {{ $partyLabel }}
                                </a>
                            @endif
                            @if ($currentReturnId)
                                <div class="my-1 border-t border-slate-100"></div>
                                <button type="button" role="menuitem" wire:click="deleteReturn" wire:confirm="حذف المردود وعكس أثره على المخزون والرصيد؟" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right text-rose-700 hover:bg-rose-50">
                                    <flux:icon.trash class="size-4" /> حذف المردود
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="inline-flex h-10 overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm">
                        <button type="button" wire:click="previousInvoice" title="المردود السابق" class="inline-flex items-center gap-1 px-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                            <flux:icon.chevron-right class="size-4" /> السابقة
                        </button>
                        <button type="button" wire:click="nextInvoice" title="المردود التالي" class="inline-flex items-center gap-1 border-r border-slate-300 px-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                            التالية <flux:icon.chevron-left class="size-4" />
                        </button>
                    </div>

                    <button type="button" wire:click="toggleReturnSearch" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                        <flux:icon.magnifying-glass class="size-4" /> استعلام <span class="text-xs text-slate-400">(F7)</span>
                    </button>

                    @if ($viewOnly)
                        <button type="button" wire:click="editLoadedInvoice" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 text-sm font-bold text-amber-800 shadow-sm transition hover:bg-amber-100">
                            <flux:icon.pencil-square class="size-4" /> تعديل
                        </button>
                    @endif

                    <button type="button" wire:click="startNewReturn" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                        <flux:icon.plus-circle class="size-5 text-blue-600" /> جديد <span class="text-xs text-slate-400">(Shift+F2)</span>
                    </button>

                    <button type="button" wire:click="saveReturn(true)" wire:loading.attr="disabled" wire:target="saveReturn" @disabled(! $viewOnly && (empty($lines) || ! $selectedPartyId)) class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-slate-300 bg-slate-100 px-3 text-sm font-bold text-slate-800 shadow-sm transition hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50">
                        <flux:icon.printer class="size-4" /> {{ $viewOnly ? 'طباعة' : 'حفظ وطباعة' }} <span class="text-xs text-slate-500">(F6)</span>
                    </button>

                    <button type="button" wire:click="saveReturn" wire:loading.attr="disabled" wire:target="saveReturn" @disabled($viewOnly || empty($lines) || ! $selectedPartyId) class="inline-flex h-10 items-center gap-1.5 rounded-lg bg-emerald-600 px-5 text-sm font-black text-white shadow-sm transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50">
                        <flux:icon.check-circle class="size-5" />
                        <span wire:loading.remove wire:target="saveReturn">{{ $currentReturnId ? 'حفظ التعديلات' : 'حفظ الفاتورة' }}</span>
                        <span wire:loading wire:target="saveReturn">جاري الحفظ...</span>
                        <span class="text-xs font-bold text-emerald-100">(F3)</span>
                    </button>
                </div>
            </div>
        </div>

        {{-- ================================================================
             الرسائل
        ================================================================= --}}
        @if ($errors->any())
            <div class="mb-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">{{ $errors->first() }}</div>
        @endif
        @if ($errorMessage)
            <div class="mb-3 flex items-center justify-between gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
                <div class="flex items-center gap-2"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100">!</span><span>{{ $errorMessage }}</span></div>
                <button type="button" wire:click="$set('errorMessage', null)" class="rounded-lg px-2 py-1 hover:bg-rose-100">✕</button>
            </div>
        @endif
        <x-flash-notice :message="$successMessage" />

        {{-- ================================================================
             الاستعلام عن المردودات السابقة
        ================================================================= --}}
        @if ($showReturnSearch)
            <div class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-2 backdrop-blur-sm sm:p-5" role="dialog" aria-modal="true" aria-labelledby="return-search-title" x-on:keydown.escape.window="$wire.set('showReturnSearch', false)">
                <div class="flex max-h-[94vh] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                        <div>
                            <h2 id="return-search-title" class="text-base font-black text-slate-900">{{ $isSale ? 'استعلام عن مردودات المبيعات السابقة' : 'استعلام عن مردودات المشتريات السابقة' }}</h2>
                            <p class="mt-1 text-xs font-semibold text-slate-500">اختر مردوداً من النتائج لفتحه.</p>
                        </div>
                        <button type="button" wire:click="$set('showReturnSearch', false)" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-black text-slate-600 hover:bg-slate-100" aria-label="إغلاق">✕</button>
                    </div>
                    <div class="grid gap-2 border-b border-slate-200 p-3 sm:grid-cols-[1fr_auto] sm:p-4">
                        <input type="search" x-init="$nextTick(() => $el.focus())" wire:model.live.debounce.300ms="returnSearch" wire:keydown.enter="searchReturn" placeholder="رقم المردود..." class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:border-indigo-400 focus:bg-white" />
                        <button type="button" wire:click="searchReturn" class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-black text-white hover:bg-slate-800">فتح المطابق</button>
                    </div>
                    <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-3 sm:p-4">
                        @forelse ($recentReturns as $savedReturn)
                            <button type="button" wire:key="return-result-{{ $savedReturn->id }}" wire:click="loadReturn({{ $savedReturn->id }})" class="grid w-full grid-cols-2 items-center gap-x-3 gap-y-1 rounded-xl border border-slate-200 bg-white p-3 text-right transition hover:border-indigo-300 hover:bg-indigo-50 sm:grid-cols-[1.2fr_1.4fr_1fr_1fr_auto] sm:gap-4">
                                <span class="font-mono text-sm font-black text-indigo-700">{{ $isSale ? $savedReturn->invoice_number : $savedReturn->reference_number }}</span>
                                <span class="truncate text-sm font-bold text-slate-800">{{ $returnPartyNames[$savedReturn->{$partyColumn}] ?? '—' }}</span>
                                <span class="text-xs font-semibold text-slate-500">{{ \Illuminate\Support\Carbon::parse($savedReturn->created_at)->format('Y-m-d') }}</span>
                                <span class="text-xs font-bold text-slate-700">الإجمالي: {{ number_format(abs((float) $savedReturn->total), 2) }}</span>
                                <span class="hidden rounded-lg bg-indigo-100 px-3 py-2 text-xs font-black text-indigo-700 sm:inline-flex">فتح</span>
                            </button>
                        @empty
                            <div class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm font-bold text-slate-500">لا توجد مردودات مطابقة.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <fieldset @disabled($viewOnly) class="min-w-0 xl:flex xl:min-h-0 xl:flex-1 xl:flex-col">
            {{-- ================================================================
                 بيانات رأس الفاتورة
            ================================================================= --}}
            <div class="relative z-40 mb-3 shrink-0 rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-12">
                    <div class="xl:col-span-2">
                        <label for="return-number" class="mb-1 block text-xs font-bold text-slate-700">رقم الفاتورة</label>
                        <input id="return-number" type="text" value="{{ $invoiceNumber }}" readonly tabindex="-1" class="h-10 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 font-mono text-sm font-bold text-slate-800 outline-none" />
                    </div>

                    <div class="xl:col-span-2">
                        <label for="return-date" class="mb-1 block text-xs font-bold text-slate-700">التاريخ</label>
                        <input id="return-date" type="date" wire:model="invoiceDate" class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                    </div>

                    <div class="relative {{ $showBranchField ? 'xl:col-span-4' : 'xl:col-span-6' }}" x-data="{ partyOpen: true }" x-on:click.outside="partyOpen = false" x-on:keydown.escape.stop="partyOpen = false">
                        <label for="return-party-search" class="mb-1 block text-xs font-bold text-slate-700">{{ $partyLabel }}</label>
                        @if ($selectedPartyId)
                            <div class="flex h-10 items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-3">
                                <flux:icon.user class="size-4 shrink-0 text-emerald-700" />
                                <div class="min-w-0 flex-1 truncate text-sm">
                                    <span class="font-black text-emerald-900">{{ $selectedPartyName }}</span>
                                    <span class="mr-1 text-xs font-semibold text-emerald-700">{{ $selectedParty?->phone ?: ($isSale ? 'فاتورة مرتبطة بحساب العميل' : 'فاتورة مرتبطة بحساب المورد') }}</span>
                                </div>
                                <button type="button" wire:click="clearParty" title="تغيير {{ $partyLabel }}" class="shrink-0 rounded-md px-2 py-1 text-xs font-black text-emerald-800 hover:bg-emerald-100">تغيير</button>
                            </div>
                        @else
                            <div class="relative">
                                <flux:icon.user class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                <input id="return-party-search" type="text" autofocus wire:model.live.debounce.180ms="partySearch" wire:keydown.enter.prevent="selectFirstParty" x-on:focus="partyOpen = true" x-on:input="partyOpen = true" autocomplete="off" placeholder="ابحث باسم {{ $partyLabel }} أو الهاتف..." class="h-10 w-full rounded-lg border border-slate-300 bg-white pl-3 pr-9 text-sm font-bold text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                            </div>
                            @if ($partySearch !== '' || count($partyResults))
                                <div x-show="partyOpen" class="absolute right-0 top-full z-40 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                                    <div class="max-h-64 overflow-y-auto p-1.5">
                                        @forelse ($partyResults as $party)
                                            <button type="button" wire:key="return-party-{{ $party['id'] }}" wire:click="selectParty({{ $party['id'] }})" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-right transition hover:bg-slate-50">
                                                <div class="min-w-0">
                                                    <div class="truncate text-sm font-black text-slate-800">{{ $party['name'] }}</div>
                                                    <div class="mt-0.5 text-[11px] font-semibold text-slate-400">{{ $party['phone'] ?: 'بدون هاتف' }}</div>
                                                </div>
                                                <div class="text-left">
                                                    <div class="font-mono text-xs font-black text-slate-700">{{ number_format((float) ($party['current_balance'] ?? 0), 2) }}</div>
                                                    <div class="text-[10px] font-bold text-slate-400">الرصيد الحالي</div>
                                                </div>
                                            </button>
                                        @empty
                                            <div class="px-3 py-6 text-center text-xs font-bold text-slate-400">لا توجد نتائج مطابقة.</div>
                                        @endforelse
                                    </div>
                                </div>
                            @endif
                        @endif
                    </div>

                    @if ($showBranchField)
                        <div class="xl:col-span-2">
                            <label for="return-branch" class="mb-1 block text-xs font-bold text-slate-700">الفرع / المخزن</label>
                            <select id="return-branch" wire:model.live="selectedBranchId" @disabled(auth()->user()?->branch_id) class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-800 outline-none focus:border-blue-500 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-70">
                                @foreach ($branches as $branch)
                                    <option wire:key="return-branch-{{ $branch['id'] }}" value="{{ $branch['id'] }}">{{ $branch['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="xl:col-span-2">
                        <div class="mb-1 block text-xs font-bold text-slate-700">الرصيد الحالي</div>
                        <div class="flex h-10 items-center justify-between rounded-lg border border-slate-300 bg-slate-50 px-3">
                            <span class="font-mono text-sm font-black {{ $selectedParty && (float) $selectedParty->current_balance < 0 ? 'text-rose-600' : 'text-slate-800' }}">{{ $selectedParty ? number_format((float) $selectedParty->current_balance, 2) : '—' }}</span>
                            <flux:icon.wallet class="size-4 text-slate-400" />
                        </div>
                    </div>
                </div>
            </div>

            {{-- ================================================================
                 جدول المردود مع سطر الإدخال
            ================================================================= --}}
            <div class="relative z-30 mb-3 flex flex-col rounded-xl border border-slate-200 bg-white shadow-sm focus-within:z-50 xl:min-h-56 xl:flex-1"
                x-data="{ active: 0, rows() { return [...(this.$refs.returnResults?.querySelectorAll('[data-return-product]') ?? [])] }, move(direction) { const rows = this.rows(); if (!rows.length) return; this.active = Math.max(0, Math.min(rows.length - 1, this.active + direction)); rows[this.active]?.scrollIntoView({ block: 'nearest' }); }, choose() { this.rows()[this.active]?.click() } }"
                x-on:return-product-selected.window="$nextTick(() => { $refs.returnQuantity?.focus(); $refs.returnQuantity?.select() })"
                x-on:return-product-added.window="$nextTick(() => $refs.returnProductSearch?.focus())">
                <div class="app-table-scroll max-h-[62vh] overflow-auto rounded-xl xl:max-h-none xl:min-h-0" role="region" aria-label="جدول البيانات — اسحب لعرض بقية الأعمدة" tabindex="0">
                    <table class="w-full min-w-[900px] border-collapse text-right">
                        <thead class="sticky top-0 z-20">
                            <tr class="bg-slate-800 text-xs font-bold text-white">
                                <th class="w-12 border-l border-slate-700 px-3 py-3 text-center">#</th>
                                <th class="w-32 border-l border-slate-700 px-3 py-3 text-center">رقم الصنف</th>
                                <th class="border-l border-slate-700 px-3 py-3">اسم الصنف</th>
                                <th class="w-32 border-l border-slate-700 px-3 py-3 text-center">المتاح للإرجاع</th>
                                <th class="w-36 border-l border-slate-700 px-3 py-3 text-center">الكمية</th>
                                <th class="w-36 border-l border-slate-700 px-3 py-3 text-center">{{ $isSale ? 'سعر البيع' : 'تكلفة الشراء' }}</th>
                                <th class="w-36 border-l border-slate-700 px-3 py-3 text-center">المجموع</th>
                                <th class="w-24 px-3 py-3 text-center">حذف</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @foreach ($lines as $index => $line)
                                <tr wire:key="return-line-{{ $line['id'] }}" class="group transition hover:bg-slate-50 focus-within:bg-blue-50">
                                    <td class="border-l border-slate-100 px-3 py-2 text-center text-sm font-black text-slate-800">{{ $loop->iteration }}</td>
                                    <td class="border-l border-slate-100 px-3 py-2 text-center font-mono text-sm font-semibold text-slate-700">{{ $line['product_number'] ?? $line['id'] }}</td>
                                    <td class="border-l border-slate-100 px-3 py-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="min-w-0 truncate text-sm font-black text-slate-900">{{ $line['name'] }}</span>
                                            <div class="flex shrink-0 items-center gap-0.5 text-[11px] font-bold opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">
                                                <button type="button" wire:click="$dispatch('show-product-history', { productId: {{ $line['id'] }} })" class="rounded px-1.5 py-0.5 text-indigo-600 hover:bg-indigo-50">حركة الصنف</button>
                                                <button type="button" x-on:click.stop="$dispatch('open-product-price-history', { productId: {{ $line['id'] }}, customerId: {{ $selectedPartyId ?? 'null' }} })" title="سجل الأسعار والتكلفة" aria-label="سجل الأسعار والتكلفة" class="rounded p-0.5 text-indigo-600 hover:bg-indigo-50"><flux:icon.currency-dollar class="size-4" /></button>
                                            </div>
                                        </div>
                                        @if ($isSale)
                                            <select wire:model.live="lines.{{ $index }}.returned_to_stock" aria-label="حالة الصنف المرتجع" class="mt-1.5 h-8 rounded-md border border-slate-200 bg-white px-2 text-xs font-bold {{ ($line['returned_to_stock'] ?? true) ? 'text-emerald-700' : 'text-rose-700' }}">
                                                <option value="1">سليم — يعود للمخزون</option>
                                                <option value="0">مرتجع تالف — لا يعود للمخزون</option>
                                            </select>
                                        @endif
                                    </td>
                                    <td class="border-l border-slate-100 px-3 py-2 text-center"><span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 font-mono text-xs font-black text-slate-600">{{ number_format($line['available'], 2) }}</span></td>
                                    <td class="border-l border-slate-100 px-3 py-2"><input type="number" min="0.01" max="{{ $line['available'] }}" step="0.01" aria-label="الكمية" wire:model.live.debounce.400ms="lines.{{ $index }}.quantity" class="mx-auto block h-9 w-28 rounded-md border border-slate-300 bg-white px-2 text-center text-sm font-black text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"></td>
                                    <td class="border-l border-slate-100 px-3 py-2"><input type="number" min="0" step="0.01" aria-label="السعر" wire:model.live.debounce.400ms="lines.{{ $index }}.price" class="mx-auto block h-9 w-28 rounded-md border border-slate-300 bg-white px-2 text-center text-sm font-black text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"></td>
                                    <td class="border-l border-slate-100 px-3 py-2 text-center font-mono text-sm font-black text-slate-900">{{ number_format((float) $line['quantity'] * (float) $line['price'], 2) }}</td>
                                    <td class="px-3 py-2 text-center"><button type="button" wire:click="removeLine({{ $index }})" aria-label="حذف السطر" class="inline-flex size-8 items-center justify-center rounded-lg text-rose-600 hover:bg-rose-50"><flux:icon.trash class="size-4" /></button></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="sticky bottom-0 z-20">
                            <tr class="bg-blue-50 [&>td]:shadow-[inset_0_2px_0_0_#93c5fd]">
                                <td class="px-2 py-2 text-center">
                                    <button type="button" x-on:click="$refs.returnProductSearch?.focus(); $refs.returnProductSearch?.select()" title="إضافة صنف (F9)" aria-label="إضافة صنف" class="inline-flex size-9 items-center justify-center rounded-lg bg-blue-600 text-white shadow-sm hover:bg-blue-500"><flux:icon.plus class="size-5" /></button>
                                </td>
                                <td colspan="2" class="px-2 py-2">
                                    <div class="relative">
                                        <flux:icon.magnifying-glass class="pointer-events-none absolute right-3 top-1/2 z-10 size-5 -translate-y-1/2 text-slate-500" />
                                        <button type="button" aria-label="أسعار الصنف والتكلفة (F2)" title="أسعار الصنف والتكلفة (F2)" @disabled(! $selectedProductId) x-on:click.stop="$dispatch('open-product-price-history', { productId: {{ $selectedProductId ?? 'null' }}, customerId: {{ $selectedPartyId ?? 'null' }} })" class="absolute left-1.5 top-1/2 z-10 -translate-y-1/2 rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-bold text-indigo-700 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-40">الأسعار F2</button>
                                        <input id="return-product-search" x-ref="returnProductSearch" type="text" wire:model.live.debounce.120ms="productSearch"
                                            x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)"
                                            x-on:keydown.enter.prevent="rows().length ? choose() : $wire.addFirstProduct()" x-on:input="active = 0"
                                            @disabled(! $selectedPartyId) autocomplete="off" aria-label="رقم الصنف / الباركود / اسم الصنف"
                                            placeholder="{{ $selectedPartyId ? 'رقم الصنف / الباركود / اسم الصنف ثم Enter...' : 'اختر '.$partyLabel.' أولاً' }}"
                                            class="h-9 w-full rounded-md border border-blue-300 bg-white pl-24 pr-10 text-sm font-bold text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100" />
                                    </div>
                                </td>
                                <td class="px-2 py-2 text-center font-mono text-sm font-semibold text-slate-600">{{ $selectedProductId ? number_format($selectedProductAvailable, 2) : '—' }}</td>
                                <td class="px-2 py-2">
                                    <input x-ref="returnQuantity" type="number" min="0.01" max="{{ $selectedProductAvailable ?: '' }}" step="0.01" wire:model="selectedQuantity" aria-label="كمية الصنف المختار" x-on:focus="$event.target.select()" x-on:keydown.enter.prevent="$refs.returnPrice?.focus(); $refs.returnPrice?.select()" class="mx-auto block h-9 w-32 rounded-md border border-slate-300 bg-white px-2 text-center text-sm font-black text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                                </td>
                                <td class="px-2 py-2">
                                    <input x-ref="returnPrice" type="number" min="0" step="0.01" wire:model="selectedPrice" aria-label="سعر الصنف المختار" x-on:focus="$event.target.select()" x-on:keydown.enter.prevent="$refs.returnTotal?.focus(); $refs.returnTotal?.select()" class="mx-auto block h-9 w-28 rounded-md border border-slate-300 bg-white px-2 text-center text-sm font-black text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                                </td>
                                <td class="px-2 py-2">
                                    <input x-ref="returnTotal" type="number" min="0" step="0.01" aria-label="إجمالي الصنف"
                                        x-effect="if (document.activeElement !== $el) $el.value = Math.max(0, Number($wire.selectedPrice) * Number($wire.selectedQuantity)).toFixed(2)"
                                        x-on:input="const total = Number($event.target.value); const quantity = Number($wire.selectedQuantity); if ($event.target.value !== '' && Number.isFinite(total) && total >= 0 && Number.isFinite(quantity) && quantity > 0) { $wire.selectedPrice = Math.round(total / quantity * 100) / 100; }"
                                        x-on:focus="$event.target.select()" x-on:keydown.enter.prevent="$wire.addSelectedProduct()"
                                        class="mx-auto block h-9 w-28 rounded-md border border-slate-300 bg-white px-2 text-center font-mono text-sm font-black text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <button type="button" wire:click="addSelectedProduct" wire:loading.attr="disabled" wire:target="addSelectedProduct" title="إضافة السطر (Enter)" class="inline-flex h-9 items-center justify-center gap-1 rounded-lg bg-emerald-600 px-3 text-xs font-black text-white shadow-sm transition hover:bg-emerald-500 disabled:opacity-50">
                                        <flux:icon.plus-circle class="size-4" /> إضافة
                                    </button>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if ($productSearch !== '' && ! $selectedProductId)
                    <div x-ref="returnResults" class="max-h-72 overflow-auto border-t-2 border-blue-200 bg-white">
                        <table class="w-full text-right text-sm">
                            <thead class="sticky top-0 bg-slate-100 text-xs font-black text-slate-600"><tr><th class="p-2">رقم الصنف</th><th class="p-2">اسم الصنف</th><th class="p-2 text-center">المتاح للإرجاع</th></tr></thead>
                            <tbody>
                                @forelse ($productResults as $product)
                                    <tr data-product-result data-return-product wire:key="return-search-{{ $product['id'] }}" x-on:mouseenter="active = {{ $loop->index }}" x-bind:class="active === {{ $loop->index }} ? 'search-result-active' : ''" x-on:mousedown.prevent wire:click="selectProduct({{ $product['id'] }})" class="cursor-pointer border-t hover:bg-indigo-50">
                                        <td class="p-2 font-mono">{{ $product['product_number'] ?: $product['id'] }}</td>
                                        <td class="p-2 font-bold">{{ $product['name'] }}</td>
                                        <td class="p-2 text-center tabular-nums">{{ number_format($product['available'] ?? $product['stock'], 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="3" class="p-4 text-center text-xs font-bold text-slate-400">لا توجد أصناف مطابقة أو لا توجد كمية متاحة لهذا الطرف.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ================================================================
                 الملاحظات والإجماليات
            ================================================================= --}}
            <div class="grid shrink-0 gap-3 xl:mt-auto xl:grid-cols-12">
                <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm xl:col-span-4">
                    <label for="return-notes" class="mb-2 flex items-center gap-1.5 text-sm font-black text-slate-800">
                        <flux:icon.chat-bubble-left-ellipsis class="size-5 text-slate-500" /> ملاحظات
                    </label>
                    <textarea id="return-notes" wire:model="notes" rows="3" placeholder="سبب المردود أو أي ملاحظة على الفاتورة..." class="w-full resize-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"></textarea>
                    <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">سيُسجل المبلغ على حساب {{ $isSale ? 'العميل ويعاد الصنف السليم إلى المخزون' : 'المورد ويخصم الصنف من المخزون' }}.</div>
                </div>

                <div class="grid grid-cols-2 gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm xl:col-span-2 xl:grid-cols-1">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="text-xs font-bold text-slate-500">إجمالي الكمية</div>
                        <div class="mt-1 flex items-center justify-between"><span class="font-mono text-lg font-black text-slate-900">{{ number_format($returnQuantity, 2) }}</span><flux:icon.cube class="size-5 text-slate-400" /></div>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="text-xs font-bold text-slate-500">عدد الأصناف</div>
                        <div class="mt-1 flex items-center justify-between"><span class="font-mono text-lg font-black text-slate-900">{{ count($lines) }}</span><flux:icon.square-3-stack-3d class="size-5 text-slate-400" /></div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm xl:col-span-4">
                    <div class="grid grid-cols-3 divide-x divide-x-reverse divide-slate-200 text-center">
                        <div class="px-2"><div class="text-xs font-bold text-slate-500">المجموع الفرعي</div><div class="mt-1 font-mono text-lg font-black text-slate-900">{{ number_format($totals['subtotal'], 2) }}</div></div>
                        <div class="px-2"><div class="text-xs font-bold text-slate-500">الخصم المحتسب</div><div class="mt-1 font-mono text-lg font-black text-rose-600">{{ number_format($totals['discount'], 2) }}</div></div>
                        <div class="px-2"><div class="text-xs font-bold text-slate-500">{{ $showTax ? 'الضريبة المضمنة' : 'بعد الخصم' }}</div><div class="mt-1 font-mono text-lg font-black text-slate-900">{{ number_format($showTax ? $totals['tax'] : $totals['total'], 2) }}</div></div>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2 border-t border-slate-100 pt-3 sm:grid-cols-3">
                        <label class="text-xs font-bold text-slate-600">الخصم
                            <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="discountRate" class="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-center text-sm font-black text-rose-600 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                        </label>
                        <label class="text-xs font-bold text-slate-600">نوع الخصم
                            <select wire:model.live="discountType" class="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm font-bold outline-none focus:border-blue-500"><option value="fixed">مبلغ</option><option value="percentage">نسبة %</option></select>
                        </label>
                        @if ($showTax)
                            <label class="text-xs font-bold text-slate-600">نسبة الضريبة المضمنة %
                                <input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="taxPercent" class="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-center text-sm font-black text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                            </label>
                        @endif
                        <label class="text-xs font-bold text-slate-600">{{ $isSale ? 'الدفعة المعادة للعميل' : 'الدفعة المستردة من المورد' }}
                            <input type="number" min="0" step="0.01" wire:model.live.debounce.800ms="paidAmount" class="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-center text-sm font-black text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" />
                        </label>
                        <label class="text-xs font-bold text-slate-600">طريقة الدفع
                            <select wire:model="paymentMethod" class="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm font-bold outline-none focus:border-blue-500"><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="bank_transfer">تحويل بنكي</option><option value="cheque">شيك</option></select>
                        </label>
                    </div>
                </div>

                <div class="flex flex-col justify-between rounded-xl {{ $isSale ? 'bg-rose-700' : 'bg-amber-700' }} p-4 text-white shadow-sm xl:col-span-2">
                    <div class="text-sm font-black text-white/90">إجمالي المردود</div>
                    <div class="mt-2 font-mono text-3xl font-black tabular-nums">{{ number_format($totals['total'], 2) }}</div>
                    @if ($selectedPartyId)
                        <div class="return-balance-summary mt-2 flex w-full justify-between border-t border-white/30 pt-2 text-xs font-bold text-white/90" style="position: static !important; inset: auto !important; width: 100% !important;">
                            <span>الرصيد بعد الدفعة</span><strong class="font-mono text-white">{{ number_format($totals['balance'], 2) }}</strong>
                        </div>
                    @endif
                </div>
            </div>
        </fieldset>

        {{-- ================================================================
             اختصارات لوحة المفاتيح
        ================================================================= --}}
        <div class="mt-3 flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 rounded-xl bg-slate-900 px-3 py-2 text-xs font-bold text-slate-200 shadow-lg">
            <span class="inline-flex items-center gap-1.5 text-slate-300"><flux:icon.question-mark-circle class="size-4" /> اختصارات لوحة المفاتيح</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">Enter</kbd> اختيار / إضافة السطر</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">↑ ↓</kbd> التنقل في النتائج</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F3</kbd> حفظ</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F6</kbd> حفظ وطباعة</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">Shift+F2</kbd> جديد</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F7</kbd> استعلام</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F9</kbd> الصنف</span>
        </div>
    </div>
    <livewire:product-history />
    <livewire:product-price-history-modal />
</flux:main>

@script
<script>
    $wire.on('share-return-pdf', async ({ url, filename }) => {
        try {
            const response = await fetch(url, { credentials: 'same-origin' });
            if (!response.ok) throw new Error('PDF download failed');
            const file = new File([await response.blob()], filename, { type: 'application/pdf' });
            if (navigator.canShare?.({ files: [file] })) {
                await navigator.share({ files: [file], title: 'فاتورة مردود' });
            } else {
                const download = URL.createObjectURL(file);
                const link = document.createElement('a');
                link.href = download;
                link.download = filename;
                link.click();
                setTimeout(() => URL.revokeObjectURL(download), 10000);
                $wire.set('successMessage', 'تم تنزيل PDF. يمكنك إرفاق الملف في واتساب.');
            }
        } catch (error) {
            if (error.name !== 'AbortError') $wire.set('errorMessage', 'تعذرت المشاركة. استخدم زر تنزيل PDF.');
        }
    });
</script>
@endscript
