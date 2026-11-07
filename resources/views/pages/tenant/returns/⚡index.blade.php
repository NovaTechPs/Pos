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

    public function updatedSelectedBranchId(): void
    {
        $user = auth()->user();
        if ($user?->isTenantOwner() || $user?->isSaaSAdmin()) {
            $this->selectedBranchId = $user->activeBranchId();
        }

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
            })->orderBy('name')->limit(10)->get(['id', 'name', 'phone'])->toArray();
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
        $this->dispatch('share-return-pdf', url: route('tenant.returns.pdf', ['kind' => $this->kind, 'returnId' => $this->currentReturnId]), filename: 'return-'.$this->kind.'-'.$this->currentReturnId.'.pdf');
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
        $direction = $this->kind === 'sale' ? $party->saleBalanceDirection() : $party->purchaseBalanceDirection();
        $party->increment('current_balance', $direction * (abs((float) $old->total) - abs((float) $old->paid_amount)));
    }

    public function saveReturn(bool $print = false): void
    {
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
                $direction = $this->kind === 'sale' ? $party->saleBalanceDirection() : $party->purchaseBalanceDirection();
                $party->refresh()->decrement('current_balance', $direction * ($totals['total'] - $totals['paid']));
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
    }

    public function render(): View
    {
        $column = $this->kind === 'sale' ? 'invoice_number' : 'reference_number';
        $recentReturns = $this->returnQuery()->when(trim($this->returnSearch) !== '', fn ($query) => $query->where($column, 'like', '%'.trim($this->returnSearch).'%'))->orderByDesc('id')->limit(20)->get();

        return $this->view(['recentReturns' => $recentReturns, 'totals' => $this->totals(), 'showTax' => TenantSetting::getBool($this->tenantId(), 'show_tax', true)])->layout('layouts::tenant');
    }
}; ?>

<flux:main class="min-h-[calc(100vh-4rem)] bg-slate-100 p-2 sm:p-3 md:p-4" dir="rtl">
    <div class="mx-auto max-w-[1700px] space-y-3" x-data x-on:keydown.f3.window.prevent="$wire.saveReturn()" x-on:keydown.f6.window.prevent="$wire.saveReturn(true)">
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
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="previousInvoice" class="rounded-xl bg-white/10 px-4 py-2 text-xs font-bold">السابقة</button>
                    <button type="button" wire:click="nextInvoice" class="rounded-xl bg-white/10 px-4 py-2 text-xs font-bold">التالية</button>
                    @if($currentReturnId)
                        <button type="button" wire:click="copyReturn" class="rounded-xl bg-white/10 px-4 py-2 text-xs font-bold">نسخ لفاتورة جديدة</button>
                        <button type="button" wire:click="editLoadedInvoice" class="rounded-xl bg-blue-600 px-4 py-2 text-xs font-bold">تعديل</button>
                        <a href="{{ route('tenant.returns.pdf', ['kind' => $kind, 'returnId' => $currentReturnId]) }}" class="rounded-xl bg-white/10 px-4 py-2 text-xs font-bold">تنزيل PDF</a>
                        <button type="button" wire:click="shareInvoicePdf" class="rounded-xl bg-emerald-700 px-4 py-2 text-xs font-bold">مشاركة / واتساب</button>
                        <button type="button" wire:click="printCurrentInvoice" class="rounded-xl bg-white/10 px-4 py-2 text-xs font-bold">طباعة / PDF</button>
                        <button type="button" wire:click="deleteReturn" wire:confirm="حذف المردود وعكس أثره على المخزون والرصيد؟" class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold">حذف المردود</button>
                    @endif
                    <button type="button" wire:click="startNewReturn" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-black hover:bg-emerald-500">+ فاتورة جديدة</button>
                </div>
            </div>
        </section>

        <details class="rounded-2xl border border-slate-200 bg-white p-3" @if($returnSearch !== '') open @endif>
            <summary class="cursor-pointer text-sm font-black">بحث وفتح المردودات السابقة</summary>
            <div class="mt-3 flex gap-2"><input type="text" wire:model.live.debounce.300ms="returnSearch" wire:keydown.enter="searchReturn" placeholder="رقم المردود..." class="w-full rounded-xl border border-slate-200 px-3 py-2"><button type="button" wire:click="searchReturn" class="rounded-xl bg-slate-900 px-4 text-white">بحث</button></div>
            <div class="mt-3 max-h-64 overflow-auto">
                @forelse($recentReturns as $savedReturn)
                    <button type="button" wire:click="loadReturn({{ $savedReturn->id }})" class="flex w-full items-center justify-between border-b border-slate-100 px-3 py-3 text-sm hover:bg-blue-50">
                        <span>{{ $kind === 'sale' ? $savedReturn->invoice_number : $savedReturn->reference_number }}</span><span>{{ \Illuminate\Support\Carbon::parse($savedReturn->created_at)->format('Y-m-d') }}</span><strong>{{ number_format(abs((float) $savedReturn->total), 2) }}</strong>
                    </button>
                @empty <p class="p-3 text-sm text-slate-500">لا توجد مردودات مطابقة.</p> @endforelse
            </div>
        </details>
        @if($currentReturnId)<div class="rounded-xl bg-blue-50 p-3 text-sm font-bold text-blue-800">{{ $editing ? 'تعديل المردود المحفوظ' : 'عرض المردود — اضغط تعديل لتغيير البيانات' }}</div>@endif
        @if($errors->any())<div class="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>@endif

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

        <fieldset @disabled($currentReturnId && !$editing) class="space-y-3">
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
                @if (!auth()->user()->isTenantOwner() && !auth()->user()->isSaaSAdmin())
                <div class="lg:col-span-3">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">الفرع</label>
                    <select wire:model.live="selectedBranchId" @disabled(auth()->user()?->branch_id) class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700 disabled:opacity-60">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch['id'] }}">{{ $branch['name'] }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
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
                <div class="relative z-30 flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-3 lg:flex-row lg:items-end" x-data="{}" x-on:return-product-selected.window="$nextTick(() => $refs.returnQuantity?.focus())" x-on:return-product-added.window="$nextTick(() => $refs.returnProductSearch?.focus())">
                    <div class="relative min-w-0 flex-1" x-data="{ active: 0, rows() { return [...(this.$refs.returnResults?.querySelectorAll('[data-return-product]') ?? [])] }, move(direction) { const rows = this.rows(); if (!rows.length) return; this.active = Math.max(0, Math.min(rows.length - 1, this.active + direction)); rows[this.active]?.scrollIntoView({ block: 'nearest' }); }, choose() { this.rows()[this.active]?.click() } }" x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)">
                        <label for="return-product-search" class="mb-1.5 block text-xs font-black text-slate-600">إضافة مادة / باركود</label>
                        @unless ($selectedPartyId)
                            <span class="mb-1 block text-[11px] font-bold text-amber-700">اختر {{ $kind === 'sale' ? 'العميل' : 'المورد' }} أولاً لتفعيل إضافة الأصناف.</span>
                        @endunless
                        <input id="return-product-search" x-ref="returnProductSearch" type="text" wire:model.live.debounce.120ms="productSearch" x-on:keydown.enter.prevent="rows().length ? choose() : $wire.addFirstProduct()" x-on:input="active = 0" @disabled(!$selectedPartyId) autocomplete="off" placeholder="ابحث باسم الصنف أو رقمه أو امسح الباركود ثم اضغط Enter..." class="w-full rounded-xl border-2 border-indigo-200 bg-white px-4 py-3 text-sm font-black outline-none focus:border-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                        @if ($productSearch !== '' && !$selectedProductId)
                            <div x-ref="returnResults" class="absolute right-0 top-full z-50 mt-1 max-h-80 w-full overflow-auto rounded-2xl border border-indigo-200 bg-white shadow-2xl">
                                <table class="w-full text-right text-sm"><thead class="sticky top-0 bg-slate-900 text-xs text-white"><tr><th class="p-2">رقم الصنف</th><th class="p-2">اسم الصنف</th><th class="p-2">المتاح للإرجاع</th></tr></thead><tbody>
                                    @forelse ($productResults as $product)
                                        <tr data-product-result data-return-product wire:key="return-search-{{ $product['id'] }}" x-on:mouseenter="active = {{ $loop->index }}" x-bind:class="active === {{ $loop->index }} ? 'search-result-active' : ''" x-on:mousedown.prevent wire:click="selectProduct({{ $product['id'] }})" class="cursor-pointer border-t hover:bg-indigo-50"><td class="p-2 font-mono">{{ $product['product_number'] ?: $product['id'] }}</td><td class="p-2 font-bold">{{ $product['name'] }}</td><td class="p-2 text-center tabular-nums">{{ number_format($product['available'] ?? $product['stock'], 2) }}</td></tr>
                                    @empty<tr><td colspan="3" class="p-4 text-center text-xs font-bold text-slate-400">لا توجد أصناف مطابقة أو لا توجد كمية متاحة لهذا الطرف.</td></tr>@endforelse
                                </tbody></table>
                            </div>
                        @endif
                        @if ($selectedProductId)
                            <p role="status" class="mt-1 text-xs font-bold text-indigo-700">تم اختيار «{{ $selectedProductName }}» — المتاح للإرجاع {{ number_format($selectedProductAvailable, 2) }}.</p>
                        @endif
                    </div>

                    <div class="w-full lg:w-32">
                        <label class="mb-1.5 block text-xs font-black text-slate-600">الكمية</label>
                        <input x-ref="returnQuantity" type="number" min="0.01" max="{{ $selectedProductAvailable ?: '' }}" step="0.01" wire:model="selectedQuantity" x-on:focus="$event.target.select()" x-on:keydown.enter.prevent="$refs.returnPrice?.focus(); $refs.returnPrice?.select()" @disabled(!$selectedProductId) class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-sm font-black outline-none focus:border-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                    </div>

                    <div class="w-full lg:w-36">
                        <label class="mb-1.5 block text-xs font-black text-slate-600">{{ $kind === 'sale' ? 'سعر البيع' : 'تكلفة الشراء' }}</label>
                        <input x-ref="returnPrice" type="number" min="0" step="0.01" wire:model="selectedPrice" x-on:focus="$event.target.select()" x-on:keydown.enter.prevent="$wire.addSelectedProduct()" @disabled(!$selectedProductId) class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-sm font-black text-indigo-700 outline-none focus:border-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100">
                    </div>

                    <div class="w-full lg:w-36">
                        <label class="mb-1.5 block text-xs font-black text-slate-600">الإجمالي</label>
                        <div class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-center font-mono text-sm font-black text-slate-900">{{ number_format(max(0, $selectedPrice * $selectedQuantity), 2) }}</div>
                    </div>

                    <button type="button" wire:click="addSelectedProduct" wire:loading.attr="disabled" wire:target="addSelectedProduct" @disabled(!$selectedProductId) class="flex h-[46px] items-center justify-center rounded-xl bg-indigo-600 px-5 text-sm font-black text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                        <span wire:loading.remove wire:target="addSelectedProduct">+ إضافة</span>
                        <span wire:loading wire:target="addSelectedProduct">جارٍ الإضافة...</span>
                    </button>
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
                                    <td class="px-3 py-3"><div class="text-sm font-black text-slate-800">{{ $line['name'] }} <button type="button" wire:click="$dispatch('show-product-history', { productId: {{ $line['id'] }} })" class="px-2 text-xs text-blue-700">حركة الصنف</button><button type="button" x-on:click="$dispatch('open-product-price-history', { productId: {{ $line['id'] }}, customerId: {{ $selectedPartyId ?? 'null' }} })" class="px-2 text-xs text-blue-700">الأسعار</button></div><div class="mt-1 text-[10px] text-slate-400">رقم الصنف: {{ $line['product_number'] ?? $line['id'] }}</div>@if($kind === 'sale')<select wire:model.live="lines.{{ $index }}.returned_to_stock" class="mt-2 rounded-lg border border-slate-200 p-1 text-xs"><option value="1">سليم — يعود للمخزون</option><option value="0">مرتجع تالف — لا يعود للمخزون</option></select>@endif</td>
                                    <td class="px-3 py-3 text-center"><span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-black text-slate-600">{{ number_format($line['available'], 2) }}</span></td>
                                    <td class="px-3 py-3"><input type="number" min="0.01" max="{{ $line['available'] }}" step="0.01" wire:model.live.debounce.400ms="lines.{{ $index }}.quantity" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-sm font-black outline-none focus:border-indigo-400"></td>
                                    <td class="px-3 py-3"><input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="lines.{{ $index }}.price" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-sm font-black text-indigo-700 outline-none focus:border-indigo-400"></td>
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
                        $returnTotal = $totals['subtotal'];
                        $returnQuantity = collect($lines)->sum(fn ($line) => (float) $line['quantity']);
                    @endphp
                    <div class="mb-3 flex items-center justify-between"><div><div class="text-sm font-black text-slate-900">ملخص الفاتورة</div><div class="mt-1 text-[11px] text-slate-400">{{ count($lines) }} أصناف</div></div><span class="rounded-xl bg-indigo-50 px-3 py-2 text-xs font-black text-indigo-800">الكمية {{ number_format($returnQuantity, 2) }}</span></div>
                    <div class="flex items-center justify-between border-t border-slate-100 py-3 text-sm"><span class="font-bold text-slate-500">المجموع الفرعي</span><strong>{{ number_format($returnTotal, 2) }}</strong></div>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="text-sm font-bold">الخصم<input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="discountRate" class="mt-1 w-full rounded-xl border border-slate-200 p-2"></label>
                        <label class="text-sm font-bold">نوع الخصم<select wire:model.live="discountType" class="mt-1 w-full rounded-xl border border-slate-200 p-2"><option value="fixed">مبلغ</option><option value="percentage">نسبة %</option></select></label>
                        @if($showTax)<label class="text-sm font-bold">نسبة الضريبة المضمنة %<input type="number" min="0" step="0.01" wire:model.live.debounce.400ms="taxPercent" class="mt-1 w-full rounded-xl border border-slate-200 p-2"></label>@endif
                        <label class="text-sm font-bold">{{ $kind === 'sale' ? 'الدفعة المعادة للعميل' : 'الدفعة المستردة من المورد' }}<input type="number" min="0" step="0.01" wire:model.live.debounce.800ms="paidAmount" class="mt-1 w-full rounded-xl border border-slate-200 p-2 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"></label>
                        <label class="text-sm font-bold">طريقة الدفع<select wire:model="paymentMethod" class="mt-1 w-full rounded-xl border border-slate-200 p-2"><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="bank_transfer">تحويل بنكي</option><option value="cheque">شيك</option></select></label>
                    </div>
                    @if($showTax)<div class="mt-3 flex justify-between text-sm"><span>قيمة الضريبة المضمنة (الأسعار شاملة الضريبة)</span><strong>{{ number_format($totals['tax'], 2) }}</strong></div>@endif
                    <div class="mt-3 flex justify-between text-sm"><span>الخصم المحتسب</span><strong>{{ number_format($totals['discount'], 2) }}</strong></div>
                    @if ($selectedPartyId)
                        <div class="return-balance-summary mt-3 flex w-full justify-between rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-700" style="position: static !important; inset: auto !important; width: 100% !important;"><span>الرصيد بعد الدفعة</span><strong>{{ number_format($totals['balance'], 2) }}</strong></div>
                    @endif
                    <div class="flex items-center justify-between border-t border-slate-200 py-3"><span class="text-base font-black text-slate-900">إجمالي المردود</span><strong class="text-3xl font-black text-indigo-700">{{ number_format($totals['total'], 2) }}</strong></div>
                    <button type="button" wire:click="saveReturn" wire:loading.attr="disabled" @disabled(empty($lines) || !$selectedPartyId) class="mt-2 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"><span wire:loading.remove wire:target="saveReturn">{{ $currentReturnId ? 'حفظ التعديلات' : 'حفظ الفاتورة' }}</span><span wire:loading wire:target="saveReturn">جاري الحفظ...</span></button>
                    <button type="button" wire:click="saveReturn(true)" wire:loading.attr="disabled" @disabled(empty($lines) || !$selectedPartyId) class="mt-2 w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white disabled:opacity-50">حفظ وطباعة</button>
                </div>
            </section>
        </fieldset>
    </div>
    <livewire:product-history />
    <livewire:product-price-history-modal />
    @if($showPrint && $printData)
        @teleport('body')
        <div class="return-print-modal fixed inset-0 z-[110] overflow-auto bg-slate-950/60 p-4" dir="rtl">
            <div class="mx-auto max-w-3xl rounded-2xl bg-white p-6">
                <div class="return-print-controls mb-4 flex gap-3"><button type="button" onclick="window.print()" class="rounded-xl bg-blue-600 px-4 py-2 text-white">طباعة / حفظ PDF</button><button type="button" wire:click="$set('showPrint', false)" class="rounded-xl border px-4 py-2">إغلاق</button></div>
                <h1 class="text-center text-2xl font-black">{{ $printData['title'] }}</h1>
                <p class="mt-2 text-center">{{ $printData['branch'] }} — {{ $printData['number'] }}</p>
                <div class="my-4 flex justify-between"><strong>{{ $printData['party'] }}</strong><span>{{ $printData['date'] }}</span></div>
                <table class="w-full text-right text-sm"><thead><tr class="border-b"><th class="p-2">الصنف</th><th>الكمية</th><th>السعر</th><th>الإجمالي</th></tr></thead><tbody>@foreach($printData['lines'] as $item)<tr class="border-b"><td class="p-2">{{ $item['name'] }}</td><td>{{ number_format($item['quantity'], 2) }}</td><td>{{ number_format($item['price'], 2) }}</td><td>{{ number_format($item['total'], 2) }}</td></tr>@endforeach</tbody></table>
                <div class="mt-4 space-y-2">
                    @foreach(['subtotal' => 'المجموع الفرعي', 'discount' => 'الخصم', 'tax' => 'الضريبة', 'total' => 'إجمالي المردود', 'paid' => 'الدفعة', 'balance' => 'رصيد الحساب الحالي'] as $key => $label)
                        @if($key !== 'tax' || $showTax)<div class="flex justify-between"><span>{{ $label }}</span><strong>{{ number_format($printData[$key], 2) }}</strong></div>@endif
                    @endforeach
                </div>
                <p class="mt-4 whitespace-pre-line">{{ $printData['notes'] }}</p>
                <x-invoice-copyright />
            </div>
        </div>
        @endteleport
        <style>@media print { html,body {display:block!important;height:auto!important;overflow:visible!important} body > :not(.return-print-modal) {display:none!important} .return-print-modal {position:static!important;padding:0!important;background:white!important;overflow:visible!important} .return-print-modal>div {max-width:none!important;border-radius:0!important} .return-print-controls {display:none!important} }</style>
    @endif
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
