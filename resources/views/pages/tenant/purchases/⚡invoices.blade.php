<?php

use Livewire\Component;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Payment;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new class extends Component {

    /*
    |--------------------------------------------------------------------------
    | الفاتورة
    |--------------------------------------------------------------------------
    */

    public ?int $currentPurchaseId = null;

    public bool $invoiceEditMode = true;

    public string $invoiceNumber = '';

    public string $invoiceDate = '';

    public ?int $selectedBranchId = null;

    public ?int $purchaseBranchFilter = null;

    public ?int $selectedSupplierId = null;

    public string $supplierSearch = '';

    public string $rowSearch = '';

    public ?int $selectedProductId = null;

    public string $selectedProductName = '';

    public float $selectedPurchasePrice = 0.0;

    public float $selectedRetailPrice = 0.0;

    public float $selectedStock = 0.0;

    public float $selectedQuantity = 1.0;

    public array $cart = [];

    public float $discount = 0.0;

    public string $discountType = 'fixed';

    public ?float $paidAmount = 0.0;

    public string $paymentMethod = 'cash';

    public string $notes = '';

    /*
    |--------------------------------------------------------------------------
    | البحث والتنقل بين الفواتير
    |--------------------------------------------------------------------------
    */

    public string $searchPurchaseQuery = '';

    public bool $showPurchaseSearch = false;

    public bool $showPriceUpdateModal = false;

    #[\Livewire\Attributes\Locked]
    public ?int $priceUpdateBranchId = null;

    /** @var array<int, array{name: string, purchase_cost: float, current_cost: float, retail_price: float}> */
    #[\Livewire\Attributes\Locked]
    public array $priceUpdateCandidates = [];

    /** @var array<int, array{cost: bool, retail: bool}> */
    public array $priceUpdateSelections = [];

    /** @var array<int, float|string> */
    public array $priceUpdateRetailPrices = [];

    /*
    |--------------------------------------------------------------------------
    | رسائل
    |--------------------------------------------------------------------------
    */

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    /*
    |--------------------------------------------------------------------------
    | Mount
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $this->selectedBranchId = $this->resolveBranchId();
        $this->purchaseBranchFilter = (auth()->user()?->isTenantOwner() || auth()->user()?->isSaaSAdmin()) ? null : $this->selectedBranchId;
        $this->startNewInvoice(false);

        $purchaseId = request()->integer('purchase');
        if ($purchaseId > 0) {
            $this->loadPurchase($purchaseId);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant / Branch
    |--------------------------------------------------------------------------
    */

    private function tenantId(): ?int
    {
        $user = auth()->user();

        $tenantId = session('active_tenant_id');

        if ($tenantId) {
            return (int) $tenantId;
        }

        if ($user?->tenant_id) {
            return (int) $user->tenant_id;
        }

        if ($user && method_exists($user, 'tenants')) {
            $tenant = $user->tenants()->first();

            if ($tenant) {
                return (int) $tenant->id;
            }
        }

        return null;
    }

    private function resolveBranchId(): ?int
    {
        $tenantId = $this->tenantId();
        $user = auth()->user();

        if (!$tenantId) {
            return null;
        }

        if ($user?->isTenantOwner() || $user?->isSaaSAdmin()) {
            $activeBranchId = $user->activeBranchId();

            if ($activeBranchId && Branch::query()->where('tenant_id', $tenantId)->whereKey($activeBranchId)->exists()) {
                return $activeBranchId;
            }
        } elseif ($user?->branch_id) {
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
        $user = auth()->user();

        if (!$tenantId) {
            $this->selectedBranchId = null;
            return;
        }

        if ($user?->branch_id && ! $user->isTenantOwner() && ! $user->isSaaSAdmin()) {
            $this->selectedBranchId = (int) $user->branch_id;
            return;
        }

        if ($user?->isTenantOwner() || $user?->isSaaSAdmin()) {
            $this->selectedBranchId = $user->activeBranchId();
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

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPurchasePrice = 0;
        $this->selectedRetailPrice = 0;
        $this->selectedStock = 0;
        $this->rowSearch = '';
    }

    public function updatedPurchaseBranchFilter($value): void
    {
        $user = auth()->user();
        if (! $user?->isTenantOwner() && ! $user?->isSaaSAdmin()) {
            $this->purchaseBranchFilter = $user?->activeBranchId();
            return;
        }

        $branchId = $value === '' || $value === null ? null : (int) $value;
        if ($branchId && ! Branch::query()->where('tenant_id', $this->tenantId())->whereKey($branchId)->exists()) {
            $this->purchaseBranchFilter = null;
            $this->errorMessage = 'الفرع المحدد غير صالح لهذا المتجر.';
            return;
        }

        $this->purchaseBranchFilter = $branchId;
    }

    private function purchaseBranchScopeId(): ?int
    {
        $user = auth()->user();
        return $user?->isTenantOwner() || $user?->isSaaSAdmin()
            ? $this->purchaseBranchFilter
            : ($user?->branch_id ? (int) $user->branch_id : $user?->activeBranchId());
    }

    /*
    |--------------------------------------------------------------------------
    | فاتورة جديدة
    |--------------------------------------------------------------------------
    */

    public function startNewInvoice(bool $showMessage = true): void
    {
        $this->invoiceEditMode = true;
        $this->currentPurchaseId = null;
        $this->invoiceNumber = $this->makeInvoiceNumber();
        $this->invoiceDate = now()->format('Y-m-d');

        $this->selectedSupplierId = null;
        $this->supplierSearch = '';

        $this->rowSearch = '';
        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPurchasePrice = 0.0;
        $this->selectedRetailPrice = 0.0;
        $this->selectedStock = 0.0;
        $this->selectedQuantity = 1.0;

        $this->cart = [];
        $this->discount = 0.0;
        $this->discountType = 'fixed';
        $this->paidAmount = 0.0;
        $this->paymentMethod = 'cash';
        $this->notes = '';
        $this->searchPurchaseQuery = '';
        $this->showPurchaseSearch = false;
        $this->errorMessage = null;

        if ($showMessage) {
            $this->successMessage = 'تم فتح فاتورة مشتريات جديدة.';
        } else {
            $this->successMessage = null;
        }

        $this->dispatch('focus-supplier-search');
    }

    private function makeInvoiceNumber(): string
    {
        return 'PUR-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    /*
    |--------------------------------------------------------------------------
    | المورد
    |--------------------------------------------------------------------------
    */

    public function selectSupplier(int $supplierId): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            $this->errorMessage = 'لم يتم تحديد المتجر الحالي.';
            return;
        }

        $supplier = Party::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['customer', 'supplier', 'both'])
            ->where('is_active', true)
            ->find($supplierId);

        if (!$supplier) {
            $this->errorMessage = 'المورد المحدد غير صالح.';
            return;
        }

        $this->selectedSupplierId = $supplier->id;
        $this->supplierSearch = $supplier->name;
        $this->errorMessage = null;

        $this->dispatch('focus-product-search');
    }

    public function clearSupplier(): void
    {
        $this->selectedSupplierId = null;
        $this->supplierSearch = '';
        $this->dispatch('focus-supplier-search');
    }

    /*
    |--------------------------------------------------------------------------
    | المنتج
    |--------------------------------------------------------------------------
    */

    private function ensureInvoiceEditable(): bool
    {
        if ($this->currentPurchaseId && ! $this->invoiceEditMode) {
            $this->errorMessage = 'الفاتورة محفوظة للعرض فقط. اضغط «تعديل» قبل تغيير الأصناف.';

            return false;
        }

        return true;
    }

    public function selectProduct(int $productId): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->selectedBranchId ?: $this->resolveBranchId();

        if (!$tenantId) {
            $this->errorMessage = 'لم يتم تحديد المتجر الحالي.';
            return;
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'branchProducts' => fn ($query) => $query->where('branch_id', $branchId),
            ])
            ->find($productId);

        if (!$product) {
            $this->errorMessage = 'المادة المحددة غير متاحة.';
            return;
        }

        $branchProduct = $product->branchProducts->first();

        $this->selectedProductId = $product->id;
        $this->selectedProductName = $product->name;
        $this->selectedPurchasePrice = (float) ($product->cost_price ?? 0);
        $this->selectedRetailPrice = (float) ($branchProduct?->retail_price ?? 0);
        $this->selectedStock = (float) ($branchProduct?->stock_quantity ?? 0);
        $this->selectedQuantity = 1.0;
        $this->rowSearch = $product->name;
        $this->errorMessage = null;

        $this->dispatch('purchase-product-selected');
        $this->dispatch('focus-quantity');
    }

    public function addProductFromSearch(int $productId): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        $this->selectProduct($productId);
    }

    public function updatedRowSearch(string $value): void
    {
        if (
            trim($value) !== ''
            && (! $this->currentPurchaseId || $this->invoiceEditMode)
            && $this->selectProductByBarcode($value)
        ) {
            return;
        }

        if ($this->selectedProductId && trim($value) !== $this->selectedProductName) {
            $this->selectedProductId = null;
            $this->selectedProductName = '';
            $this->selectedPurchasePrice = 0;
            $this->selectedRetailPrice = 0;
            $this->selectedStock = 0;
            $this->selectedQuantity = 1;
        }
    }

    public function selectProductByBarcode(string $barcode): bool
    {
        if (! $this->ensureInvoiceEditable()) {
            return false;
        }

        $barcode = trim($barcode);
        $tenantId = $this->tenantId();

        if ($barcode === '' || mb_strlen($barcode) > 100 || ! $tenantId) {
            return false;
        }

        $productId = Product::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($barcode, $tenantId): void {
                $query->where('product_number', $barcode)
                    ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery
                        ->where('tenant_id', $tenantId)
                        ->where('barcode', $barcode));
            })
            ->value('id');

        if (! $productId) {
            return false;
        }

        $this->selectProduct((int) $productId);

        return true;
    }

    public function scanBarcodeToCart(string $barcode): bool
    {
        if (! $this->ensureInvoiceEditable()) return false;
        $tenantId = $this->tenantId();
        $barcode = trim($barcode);
        if (! $tenantId || $barcode === '' || mb_strlen($barcode) > 100) return false;

        $product = Product::query()->where('tenant_id', $tenantId)
            ->where(function ($q) use ($barcode, $tenantId) {
                $q->where('product_number', $barcode)
                    ->orWhereHas('barcodes', fn ($b) => $b->where('tenant_id', $tenantId)->where('barcode', $barcode));
            })->first();
        if (! $product) return false;

        foreach ($this->cart as $key => $item) {
            if ((int) ($item['id'] ?? 0) === (int) $product->id) {
                $this->cart[$key]['quantity'] = round((float) $item['quantity'] + 1, 2);
                unset($this->cart[$key]['total_override']);
                $this->rowSearch = '';
                $this->selectedProductId = null;
                $this->dispatch('focus-product-search');
                return true;
            }
        }

        $branchId = $this->selectedBranchId ?: $this->resolveBranchId();
        $bp = BranchProduct::query()->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)->where('product_id', $product->id)->first();
        $key = $product->id . '-' . Str::uuid()->toString();
        $this->cart[$key] = [
            'id' => $product->id,
            'name' => $product->name,
            'price' => round((float) ($product->cost_price ?? 0), 2),
            'retail_price' => round((float) ($bp?->retail_price ?? 0), 2),
            'quantity' => 1.0,
            'discount' => 0.0,
            'stock' => round((float) ($bp?->stock_quantity ?? 0), 2),
        ];
        $this->rowSearch = '';
        $this->selectedProductId = null;
        $this->dispatch('focus-product-search');
        return true;
    }

    // Pass current DOM values in one request so blur/morphing cannot lose edits.
    public function commitRowWithValues($quantity, $price): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (! is_numeric($quantity) || ! is_numeric($price)
            || ! is_finite((float) $quantity) || ! is_finite((float) $price)
            || (float) $quantity <= 0 || (float) $price < 0) {
            $this->errorMessage = 'تحقق من الكمية وسعر التكلفة.';
            return;
        }

        $this->selectedQuantity = round((float) $quantity, 2);
        $this->selectedPurchasePrice = round((float) $price, 2);
        if ($this->selectedQuantity <= 0) {
            $this->errorMessage = 'الكمية يجب أن تكون أكبر من صفر.';
            return;
        }
        $this->commitRowToCart();
    }

    public function commitRowToCart(): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (!$this->selectedProductId) {
            $this->dispatch('focus-product-search');
            return;
        }

        if ($this->selectedPurchasePrice < 0) {
            $this->errorMessage = 'سعر التكلفة غير صالح.';
            return;
        }

        if ($this->selectedQuantity <= 0) {
            $this->errorMessage = 'الكمية يجب أن تكون أكبر من صفر.';
            return;
        }

        $cartKey = $this->selectedProductId . '-' . Str::uuid()->toString();

        $this->cart[$cartKey] = [
            'id' => $this->selectedProductId,
            'name' => $this->selectedProductName,
            'price' => round((float) $this->selectedPurchasePrice, 2),
            'retail_price' => round((float) $this->selectedRetailPrice, 2),
            'quantity' => round((float) $this->selectedQuantity, 2),
            'discount' => 0.0,
            'stock' => round((float) $this->selectedStock, 2),
        ];

        $productId = $this->selectedProductId;
        $purchaseCost = round((float) $this->selectedPurchasePrice, 2);
        $branchId = $this->selectedBranchId ?: $this->resolveBranchId();

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPurchasePrice = 0.0;
        $this->selectedRetailPrice = 0.0;
        $this->selectedStock = 0.0;
        $this->selectedQuantity = 1.0;
        $this->rowSearch = '';
        $this->errorMessage = null;

        $this->preparePriceUpdateCandidate($productId, $purchaseCost, $branchId);

        $this->dispatch('toast', type: 'success', message: 'تمت إضافة الصنف إلى الفاتورة.');
        $this->dispatch('focus-product-search');
    }

    public function removeItem(string $cartKey): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (!isset($this->cart[$cartKey])) {
            return;
        }

        unset($this->cart[$cartKey]);
    }

    public function updateItemQuantity(string $cartKey, $quantity): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $quantity = (float) $quantity;

        if ($quantity <= 0) {
            unset($this->cart[$cartKey]);
            return;
        }

        $this->cart[$cartKey]['quantity'] = round($quantity, 2);
        unset($this->cart[$cartKey]['total_override']);
    }

    public function incrementItem(string $cartKey): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $this->cart[$cartKey]['quantity'] = round(
            (float) $this->cart[$cartKey]['quantity'] + 1,
            2
        );
        unset($this->cart[$cartKey]['total_override']);
    }

    public function decrementItem(string $cartKey): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $quantity = round(
            (float) $this->cart[$cartKey]['quantity'] - 1,
            2
        );

        if ($quantity <= 0) {
            unset($this->cart[$cartKey]);
            return;
        }

        $this->cart[$cartKey]['quantity'] = $quantity;
        unset($this->cart[$cartKey]['total_override']);
    }

    public function updateItemPrice(string $cartKey, $price): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $updatedPrice = max(0, round((float) $price, 2));
        $priceChanged = abs((float) $this->cart[$cartKey]['price'] - $updatedPrice) >= 0.005;
        $this->cart[$cartKey]['price'] = $updatedPrice;
        unset($this->cart[$cartKey]['total_override']);

        if ($priceChanged) {
            $this->preparePriceUpdateCandidate((int) $this->cart[$cartKey]['id'], $updatedPrice, $this->selectedBranchId ?: $this->resolveBranchId());
        }
    }

    public function updateItemTotal(string $cartKey, $total): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $this->cart[$cartKey]['total_override'] = max(0, round((float) $total, 2));
    }

    public function updateItemDiscount(string $cartKey, $discount): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $this->cart[$cartKey]['discount'] = max(0, round((float) $discount, 2));
        unset($this->cart[$cartKey]['total_override']);
    }

    /*
    |--------------------------------------------------------------------------
    | الحسابات
    |--------------------------------------------------------------------------
    */

    private function calculateTotals(): array
    {
        $subtotal = 0.0;
        $totalQuantity = 0.0;

        foreach ($this->cart as $item) {
            $price = max(0, (float) ($item['price'] ?? 0));
            $quantity = max(0, (float) ($item['quantity'] ?? 0));
            $lineDiscount = max(0, (float) ($item['discount'] ?? 0));

            $lineTotal = isset($item['total_override'])
                ? max(0, (float) $item['total_override'])
                : max(0, ($price * $quantity) - $lineDiscount);

            $subtotal += $lineTotal;
            $totalQuantity += $quantity;
        }

        $base = max(0, $subtotal);

        $invoiceDiscount = $this->discountType === 'percentage'
            ? min($base, $base * (max(0, $this->discount) / 100))
            : min($base, max(0, $this->discount));

        $grandTotal = max(0, $base - $invoiceDiscount);

        $paid = min(max(0, (float) $this->paidAmount), $grandTotal);

        $remaining = max(0, $grandTotal - $paid);

        $paymentStatus = $remaining <= 0.009
            ? 'paid'
            : ($paid > 0 ? 'partial' : 'unpaid');

        return [
            'subtotal' => round($base, 2),
            'discount' => round($invoiceDiscount, 2),
            'tax' => 0.0,
            'total' => round($grandTotal, 2),
            'paid' => round($paid, 2),
            'remaining' => round($remaining, 2),
            'payment_status' => $paymentStatus,
            'total_quantity' => round($totalQuantity, 2),
        ];
    }

    private function synchronizePurchaseStock(int $tenantId, int $purchaseId, int $branchId): void
    {
        $desiredQuantities = [];

        foreach ($this->cart as $item) {
            $productId = (int) ($item['id'] ?? 0);
            $quantity = max(0, (float) ($item['quantity'] ?? 0));

            if ($productId > 0 && $quantity > 0) {
                $desiredQuantities[$productId] = ($desiredQuantities[$productId] ?? 0) + $quantity;
            }
        }

        $recordedMovements = DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('source_type', 'purchase')
            ->where('source_id', $purchaseId)
            ->get(['branch_id', 'product_id', 'quantity']);

        $recordedQuantities = [];

        foreach ($recordedMovements as $movement) {
            $key = $movement->branch_id.':'.$movement->product_id;
            $recordedQuantities[$key] = ($recordedQuantities[$key] ?? 0) + (float) $movement->quantity;
        }

        $desiredByLocation = [];

        foreach ($desiredQuantities as $productId => $quantity) {
            $desiredByLocation[$branchId.':'.$productId] = $quantity;
        }

        $locations = array_unique(array_merge(array_keys($recordedQuantities), array_keys($desiredByLocation)));

        foreach ($locations as $location) {
            [$movementBranchId, $productId] = array_map('intval', explode(':', $location, 2));
            $recordedQuantity = (float) ($recordedQuantities[$location] ?? 0);
            $desiredQuantity = (float) ($desiredByLocation[$location] ?? 0);
            $quantityDelta = $desiredQuantity - $recordedQuantity;

            if (abs($quantityDelta) < 0.000001) {
                continue;
            }

            $branchProduct = DB::table('branch_products')
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $movementBranchId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $branchProduct && $desiredQuantity > 0) {
                $product = Product::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($productId)
                    ->first();

                if (! $product) {
                    throw new \RuntimeException('أحد المنتجات غير موجود في المتجر.');
                }

                BranchProduct::query()->firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'branch_id' => $movementBranchId,
                        'product_id' => $productId,
                    ],
                    [
                        'stock_quantity' => 0,
                        'created_by' => auth()->id(),
                    ]
                );

                $branchProduct = DB::table('branch_products')
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $movementBranchId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();
            }

            if (! $branchProduct) {
                throw new \RuntimeException('تعذر العثور على مخزون صنف مرتبط بفاتورة المشتريات.');
            }

            $newStock = (float) $branchProduct->stock_quantity + $quantityDelta;

            if ($newStock < -0.000001) {
                throw new \DomainException(
                    'لا يمكن تقليل كمية الصنف في فاتورة المشتريات؛ الكمية المراد سحبها غير متوفرة حالياً في المخزون.'
                );
            }

            DB::table('branch_products')
                ->where('id', $branchProduct->id)
                ->update([
                    'stock_quantity' => max(0, $newStock),
                    'updated_at' => now(),
                ] + \App\Services\StockMovementContext::referenceAttributes('purchase', $purchaseId));
        }
    }

    /*
    |--------------------------------------------------------------------------
    | حفظ الفاتورة
    |--------------------------------------------------------------------------
    */

    public function saveInvoice(bool $shouldPrint = false): void
    {
        if ($this->currentPurchaseId && ! $this->invoiceEditMode) {
            $this->errorMessage = 'هذه فاتورة محفوظة للعرض فقط. اضغط «تعديل» أولاً لتغييرها.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);

            return;
        }

        try {
            $this->authorize($this->currentPurchaseId ? 'purchases.edit' : 'purchases.create');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->errorMessage = 'لا تملك صلاحية حفظ أو تعديل فاتورة المشتريات.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);

            return;
        }

        $this->errorMessage = null;
        $this->successMessage = null;

        try {
            $this->validate(['invoiceDate' => ['required', 'date']]);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->errorMessage = $exception->validator->errors()->first() ?: 'تحقق من تاريخ الفاتورة.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);

            return;
        }

        if (empty($this->cart)) {
            $this->errorMessage = 'أضف مادة واحدة على الأقل إلى الفاتورة.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);
            $this->dispatch('focus-product-search');
            return;
        }

        $tenantId = $this->tenantId();

        if (!$tenantId) {
            $this->errorMessage = 'لم يتم تحديد المتجر الحالي.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);
            return;
        }

        if (!$this->selectedBranchId) {
            $this->errorMessage = 'يرجى تحديد الفرع قبل حفظ الفاتورة.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);
            return;
        }

        $supplier = null;

        if ($this->selectedSupplierId) {
            $supplier = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'supplier', 'both'])
                ->where('is_active', true)
                ->find($this->selectedSupplierId);

            if (!$supplier) {
                $this->errorMessage = 'المورد المحدد غير صالح.';
                $this->dispatch('toast', type: 'error', message: $this->errorMessage);
                return;
            }
        }

        $totals = $this->calculateTotals();
        $enteredPaidAmount = (float) ($this->paidAmount ?? 0);
        if (! is_finite($enteredPaidAmount) || $enteredPaidAmount < 0 || $enteredPaidAmount > $totals['total']) {
            $this->errorMessage = 'المبلغ المدفوع يجب أن يكون بين صفر وإجمالي الفاتورة.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);

            return;
        }

        $wasEditingInvoice = $this->currentPurchaseId !== null;

        try {
            $purchaseId = DB::transaction(function () use ($tenantId, $supplier, $totals, $wasEditingInvoice) {

                $purchase = null;

                $oldSupplierId = null;
                $oldOutstanding = 0.0;

                if ($this->currentPurchaseId) {
                    $purchase = DB::table('purchases')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $this->currentPurchaseId)
                        ->lockForUpdate()
                        ->first();

                    if (!$purchase) {
                        throw new \RuntimeException('فاتورة المشتريات المطلوب تعديلها غير موجودة.');
                    }

                    if (DB::table('purchase_returns')->where('tenant_id', $tenantId)
                        ->where('purchase_id', $purchase->id)->exists()) {
                        throw new \DomainException('لا يمكن تعديل فاتورة عليها مرتجعات مسجلة؛ سجّل مرتجعاً إضافياً عند الحاجة.');
                    }

                    $oldSupplierId = $purchase->supplier_id;
                    $oldOutstanding = max(
                        0,
                        (float) $purchase->total - (float) $purchase->paid_amount
                    );

                    DB::table('purchase_items')
                        ->where('purchase_id', $purchase->id)
                        ->delete();

                    DB::table('purchases')
                        ->where('id', $purchase->id)
                        ->where('tenant_id', $tenantId)
                        ->update([
                            'branch_id' => $this->selectedBranchId,
                            'supplier_id' => $supplier?->id,
                            'reference_number' => $this->invoiceNumber,
                            'invoice_date' => $this->invoiceDate,
                            'total' => $totals['total'],
                            'tax_amount' => 0,
                            'tax_percent' => 0,
                            'paid_amount' => $totals['paid'],
                            'payment_status' => $totals['payment_status'],
                            'updated_at' => now(),
                        ]);
                } else {
                    $exists = DB::table('purchases')
                        ->where('tenant_id', $tenantId)
                        ->where('reference_number', $this->invoiceNumber)
                        ->exists();

                    if ($exists) {
                        $this->invoiceNumber = $this->makeInvoiceNumber();
                    }

                    $purchaseId = DB::table('purchases')->insertGetId([
                        'tenant_id' => $tenantId,
                        'branch_id' => $this->selectedBranchId,
                        'supplier_id' => $supplier?->id,
                        'created_by' => auth()->id(),
                        'reference_number' => $this->invoiceNumber,
                        'invoice_date' => $this->invoiceDate,
                        'total' => $totals['total'],
                        'tax_amount' => 0,
                        'tax_percent' => 0,
                        'paid_amount' => $totals['paid'],
                        'payment_status' => $totals['payment_status'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $purchase = DB::table('purchases')
                        ->where('id', $purchaseId)
                        ->where('tenant_id', $tenantId)
                        ->lockForUpdate()
                        ->first();
                }

                $purchaseId = $purchase->id;

                $this->synchronizePurchaseStock($tenantId, (int) $purchaseId, (int) $this->selectedBranchId);

                foreach ($this->cart as $item) {
                    $quantity = max(0, (float) ($item['quantity'] ?? 0));
                    $unitCost = max(0, (float) ($item['price'] ?? 0));
                    $lineDiscount = max(0, (float) ($item['discount'] ?? 0));
                    $lineTotal = isset($item['total_override'])
                        ? max(0, (float) $item['total_override'])
                        : max(0, ($unitCost * $quantity) - $lineDiscount);

                    if ($quantity <= 0) {
                        continue;
                    }

                    $productId = (int) ($item['id'] ?? 0);

                    $product = Product::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$product) {
                        throw new \RuntimeException('أحد المنتجات غير موجود في المتجر.');
                    }

                    DB::table('purchase_items')->insert([
                        'purchase_id' => $purchaseId,
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'subtotal' => $lineTotal,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                }

                if (! $wasEditingInvoice && $totals['paid'] > 0) {
                    Payment::query()->create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $this->selectedBranchId,
                        'shift_id' => null,
                        'created_by' => auth()->id(),
                        'party_id' => $supplier?->id,
                        'type' => 'payment',
                        'voucher_number' => 'PAY-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                        'amount' => $totals['paid'],
                        'payment_method' => $this->paymentMethod,
                        'purchase_id' => $purchaseId,
                        'notes' => 'دفعة على فاتورة المشتريات رقم: '.$this->invoiceNumber,
                        'payment_date' => now(),
                    ]);
                }

                /*
                | لا نعدل رصيد المورد إلا بالنسبة لصافي المديونية الناتجة
                | عن الفاتورة نفسها: total - paid_amount.
                */
                $newOutstanding = $totals['remaining'];

                if ($oldSupplierId && $oldOutstanding > 0) {
                    $oldParty = Party::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($oldSupplierId)
                        ->lockForUpdate()
                        ->first();

                    if ($oldParty) {
                        $oldParty->current_balance = (float) $oldParty->current_balance - $oldParty->purchaseBalanceDirection() * $oldOutstanding;
                        $oldParty->save();
                    }
                }

                if ($supplier && $newOutstanding > 0) {
                    $supplier = Party::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($supplier->id)
                        ->lockForUpdate()
                        ->first();

                    if ($supplier) {
                        $supplier->current_balance = (float) $supplier->current_balance + $supplier->purchaseBalanceDirection() * $newOutstanding;
                        $supplier->save();
                    }
                }

                return $purchaseId;
            });

            $this->currentPurchaseId = $purchaseId;

            $this->successMessage = 'تم حفظ فاتورة المشتريات بنجاح.';

            if ($shouldPrint) {
                $this->printCurrentInvoice();
            }

            if ($wasEditingInvoice) {
                $this->loadPurchase($purchaseId);
                $this->successMessage = 'تم تعديل فاتورة المشتريات بنجاح.';
                $this->dispatch('toast', type: 'success', message: $this->successMessage);

                return;
            }

            $this->startNewInvoice(false);
            $this->successMessage = 'تم حفظ فاتورة المشتريات بنجاح وفتح فاتورة جديدة.';
            $this->dispatch('toast', type: 'success', message: 'تم حفظ فاتورة المشتريات بنجاح.');

        } catch (\Throwable $e) {
            report($e);
            $this->errorMessage = $e instanceof \DomainException
                ? $e->getMessage()
                : 'تعذر حفظ فاتورة المشتريات. تأكد من البيانات والجداول المطلوبة ثم حاول مرة أخرى.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);
        }
    }

    private function preparePriceUpdateCandidate(int $productId, float $purchaseCost, ?int $branchId): void
    {
        $this->priceUpdateBranchId = null;
        $this->priceUpdateCandidates = [];
        $this->priceUpdateSelections = [];
        $this->priceUpdateRetailPrices = [];
        $this->showPriceUpdateModal = false;

        if (! auth()->user()?->can('products.price.edit')) {
            return;
        }

        $tenantId = $this->tenantId();
        if (! $tenantId || ! $branchId) {
            return;
        }

        $product = Product::query()->where('tenant_id', $tenantId)->find($productId);
        if (! $product || abs($purchaseCost - (float) $product->cost_price) < 0.005) {
            return;
        }

        $branchProduct = BranchProduct::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->first();
        $retailPrice = (float) ($branchProduct?->retail_price ?? 0);
        $this->priceUpdateCandidates[$productId] = [
            'name' => (string) $product->name,
            'purchase_cost' => $purchaseCost,
            'current_cost' => (float) $product->cost_price,
            'retail_price' => $retailPrice,
        ];
        $this->priceUpdateSelections[$productId] = ['cost' => true, 'retail' => false];
        $this->priceUpdateRetailPrices[$productId] = $retailPrice;
        $this->priceUpdateBranchId = $branchId;
        $this->showPriceUpdateModal = true;
    }

    public function applyPurchasePriceUpdates(): void
    {
        $this->authorize('products.price.edit');

        if (! $this->priceUpdateBranchId || $this->priceUpdateCandidates === []) {
            $this->dismissPriceUpdateModal();
            return;
        }

        $tenantId = $this->tenantId();
        $branchExists = Branch::query()->where('tenant_id', $tenantId)->whereKey($this->priceUpdateBranchId)->exists();
        if (! $tenantId || ! $branchExists) {
            $this->addError('priceUpdateCandidates', 'الفرع المحدد غير صالح لهذا المتجر.');
            return;
        }

        DB::transaction(function () use ($tenantId): void {
            foreach ($this->priceUpdateCandidates as $productId => $candidate) {
                $productId = (int) $productId;
                $product = Product::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($productId)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    continue;
                }

                $selection = $this->priceUpdateSelections[$productId] ?? [];
                if (! empty($selection['cost'])) {
                    $product->cost_price = max(0, (float) $candidate['purchase_cost']);
                    $product->save();
                }

                if (! empty($selection['retail'])) {
                    $this->validate([
                        'priceUpdateRetailPrices.'.$productId => ['required', 'numeric', 'min:0', 'max:999999999'],
                    ]);

                    $branchProduct = BranchProduct::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $this->priceUpdateBranchId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (! $branchProduct) {
                        $branchProduct = BranchProduct::query()->create([
                            'tenant_id' => $tenantId,
                            'branch_id' => $this->priceUpdateBranchId,
                            'product_id' => $productId,
                            'stock_quantity' => 0,
                            'retail_price' => 0,
                        ]);
                    }

                    $branchProduct->retail_price = (float) $this->priceUpdateRetailPrices[$productId];
                    $branchProduct->save();
                }
            }
        });

        $this->dismissPriceUpdateModal();
        $this->dispatch('toast', type: 'success', message: 'تم تحديث أسعار الأصناف المحددة.');
    }

    public function dismissPriceUpdateModal(): void
    {
        $this->showPriceUpdateModal = false;
        $this->priceUpdateBranchId = null;
        $this->priceUpdateCandidates = [];
        $this->priceUpdateSelections = [];
        $this->priceUpdateRetailPrices = [];
        $this->resetValidation('priceUpdateCandidates');
    }

    public function saveAndPrint(): void
    {
        $this->saveInvoice(true);
    }

    /*
    |--------------------------------------------------------------------------
    | البحث والتنقل
    |--------------------------------------------------------------------------
    */

    public function togglePurchaseSearch(): void
    {
        $this->showPurchaseSearch = !$this->showPurchaseSearch;

        if ($this->showPurchaseSearch) {
            $this->dispatch('focus-purchase-search');
        }
    }

    public function searchPurchase(): void
    {
        $search = trim($this->searchPurchaseQuery);
        $tenantId = $this->tenantId();

        if ($search === '' || !$tenantId) {
            return;
        }

        $query = DB::table('purchases')
            ->where('tenant_id', $tenantId)
            ->when($this->purchaseBranchScopeId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%");

                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });

        $purchaseId = $query
            ->orderByRaw('CASE WHEN reference_number = ? THEN 0 ELSE 1 END', [$search])
            ->orderByDesc('id')
            ->value('id');

        if (!$purchaseId) {
            $this->errorMessage = 'لم يتم العثور على فاتورة مشتريات مطابقة.';
            return;
        }

        $this->loadPurchase((int) $purchaseId);
        $this->searchPurchaseQuery = '';
        $this->showPurchaseSearch = false;
    }

    public function loadPurchase(int $purchaseId): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            $this->errorMessage = 'لم يتم تحديد المتجر الحالي.';
            return;
        }

        $purchase = DB::table('purchases')
            ->where('tenant_id', $tenantId)
            ->when($this->purchaseBranchScopeId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->where('id', $purchaseId)
            ->first();

        if (!$purchase) {
            $this->errorMessage = 'فاتورة المشتريات غير موجودة.';
            return;
        }

        $this->currentPurchaseId = $purchase->id;
        $this->invoiceEditMode = false;
        $this->invoiceNumber = $purchase->reference_number;
        $this->invoiceDate = $purchase->invoice_date
            ? \Carbon\Carbon::parse($purchase->invoice_date)->format('Y-m-d')
            : ($purchase->created_at
                ? \Carbon\Carbon::parse($purchase->created_at)->format('Y-m-d')
                : now()->format('Y-m-d'));
        $this->selectedBranchId = $purchase->branch_id;
        $this->selectedSupplierId = $purchase->supplier_id;
        $this->discount = 0.0;
        $this->discountType = 'fixed';
        $this->paidAmount = (float) $purchase->paid_amount;
        $this->notes = '';

        if ($this->selectedSupplierId) {
            $supplier = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($this->selectedSupplierId)
                ->first();

            $this->supplierSearch = $supplier?->name ?? '';
        } else {
            $this->supplierSearch = '';
        }

        $items = DB::table('purchase_items as pi')
            ->leftJoin('products as p', 'p.id', '=', 'pi.product_id')
            ->where('pi.purchase_id', $purchase->id)
            ->orderBy('pi.id')
            ->get([
                'pi.id as item_id',
                'pi.product_id',
                'pi.quantity',
                'pi.unit_cost',
                'pi.subtotal',
                'p.name as product_name',
            ]);

        $this->cart = [];

        foreach ($items as $item) {
            $key = $item->product_id . '-' . Str::uuid()->toString();

            $this->cart[$key] = [
                'id' => (int) $item->product_id,
                'name' => $item->product_name ?? 'مادة محذوفة',
                'price' => (float) $item->unit_cost,
                'retail_price' => 0,
                'quantity' => (float) $item->quantity,
                'discount' => max(0, ((float) $item->unit_cost * (float) $item->quantity) - (float) $item->subtotal),
                'stock' => 0,
            ];

            $calculatedLineTotal = max(
                0,
                ((float) $item->unit_cost * (float) $item->quantity)
                    - (float) $this->cart[$key]['discount']
            );
            if (abs($calculatedLineTotal - (float) $item->subtotal) >= 0.005) {
                $this->cart[$key]['total_override'] = (float) $item->subtotal;
            }
        }

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPurchasePrice = 0;
        $this->selectedRetailPrice = 0;
        $this->selectedStock = 0;
        $this->selectedQuantity = 1;
        $this->rowSearch = '';

        $this->successMessage = "تم تحميل فاتورة المشتريات {$purchase->reference_number}.";
        $this->errorMessage = null;
    }

    public function previousInvoice(): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return;
        }

        $query = DB::table('purchases')
            ->where('tenant_id', $tenantId)
            ->when($this->purchaseBranchScopeId(), fn ($query, $branchId) => $query->where('branch_id', $branchId));

        if ($this->currentPurchaseId) {
            $query->where('id', '<', $this->currentPurchaseId);
        }

        $purchaseId = $query->orderByDesc('id')->value('id');

        if (!$purchaseId) {
            $this->errorMessage = 'لا توجد فاتورة مشتريات أقدم.';
            return;
        }

        $this->loadPurchase((int) $purchaseId);
    }

    public function nextInvoice(): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId || !$this->currentPurchaseId) {
            return;
        }

        $purchaseId = DB::table('purchases')
            ->where('tenant_id', $tenantId)
            ->when($this->purchaseBranchScopeId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->where('id', '>', $this->currentPurchaseId)
            ->orderBy('id')
            ->value('id');

        if (!$purchaseId) {
            $this->successMessage = 'تم الانتقال إلى فاتورة مشتريات جديدة.';
            $this->startNewInvoice(false);
            return;
        }

        $this->loadPurchase((int) $purchaseId);
    }

    /*
    |--------------------------------------------------------------------------
    | طباعة
    |--------------------------------------------------------------------------
    */

    public function printCurrentInvoice(): void
    {
        if (!$this->currentPurchaseId) {
            $this->errorMessage = 'احفظ الفاتورة أولاً قبل الطباعة.';
            return;
        }

        $tenantId = $this->tenantId();

        $purchase = DB::table('purchases')
            ->where('tenant_id', $tenantId)
            ->where('id', $this->currentPurchaseId)
            ->first();

        if (!$purchase) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة.';
            return;
        }

        $items = DB::table('purchase_items as pi')
            ->leftJoin('products as p', 'p.id', '=', 'pi.product_id')
            ->where('pi.purchase_id', $purchase->id)
            ->orderBy('pi.id')
            ->get([
                'pi.quantity',
                'pi.unit_cost',
                'pi.subtotal',
                'p.name as product_name',
            ])
            ->map(function ($item) {
                $gross = (float) $item->unit_cost * (float) $item->quantity;
                return [
                    'name' => $item->product_name ?? 'مادة غير محددة',
                    'quantity' => (float) $item->quantity,
                    'price' => (float) $item->unit_cost,
                    'discount' => max(0, $gross - (float) $item->subtotal),
                    'total' => (float) $item->subtotal,
                ];
            })
            ->all();

        $supplierName = 'مورد عام';
        $supplier = null;

        if ($purchase->supplier_id) {
            $supplier = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($purchase->supplier_id)
                ->first();
            $supplierName = $supplier?->name ?: 'مورد عام';
        }

        $branchName = Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($purchase->branch_id)
            ->value('name');

        $this->dispatch(
            'print-purchase-invoice',
            invoice: [
                'store_name' => $branchName ?: Tenant::query()->whereKey($purchase->tenant_id)->value('name') ?: 'المتجر',
                'number' => $purchase->reference_number,
                'date' => $purchase->invoice_date ?: $purchase->created_at,
                'supplier' => $supplierName,
                'notes' => null,
                'items' => $items,
                'subtotal' => array_sum(array_map(fn ($item) => (float) $item['total'], $items)),
                'discount' => 0.0,
                'tax' => (float) $purchase->tax_amount,
                'show_tax' => TenantSetting::getBool((int) $tenantId, 'show_tax', true),
                'total' => (float) $purchase->total,
                'paid' => (float) $purchase->paid_amount,
                'remaining' => max(0, (float) $purchase->total - (float) $purchase->paid_amount),
                'account_balance' => $supplier ? (float) $supplier->current_balance : null,
            ]
        );
    }

    public function editLoadedInvoice(): void
    {
        if (! $this->currentPurchaseId) {
            return;
        }

        $this->invoiceEditMode = true;
        $this->successMessage = 'تم تفعيل تعديل الفاتورة. عدّل البيانات ثم اضغط حفظ.';
        $this->errorMessage = null;
    }

    public function downloadInvoicePdf(): mixed
    {
        if (! $this->currentPurchaseId) {
            $this->errorMessage = 'احفظ الفاتورة أولاً قبل تنزيل PDF.';

            return null;
        }

        $tenantId = $this->tenantId();
        $purchase = DB::table('purchases')
            ->where('tenant_id', $tenantId)
            ->whereKey($this->currentPurchaseId)
            ->first();

        if (! $purchase) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة.';

            return null;
        }

        $items = DB::table('purchase_items as pi')
            ->leftJoin('products as p', 'p.id', '=', 'pi.product_id')
            ->where('pi.purchase_id', $purchase->id)
            ->orderBy('pi.id')
            ->get(['pi.quantity', 'pi.unit_cost', 'pi.subtotal', 'p.name as product_name']);

        $supplierName = $purchase->supplier_id
            ? Party::query()->where('tenant_id', $tenantId)->whereKey($purchase->supplier_id)->value('name')
            : null;
        $branchName = Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($purchase->branch_id)
            ->value('name');

        return response()->streamDownload(function () use ($purchase, $items, $supplierName, $branchName): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pages.tenant.purchases.partials.invoice-pdf', [
                'purchase' => $purchase,
                'items' => $items,
                'supplierName' => $supplierName ?: 'مورد عام',
                'storeName' => $branchName ?: Tenant::query()->whereKey($purchase->tenant_id)->value('name') ?: 'المتجر',
            ]);

            echo $pdf->output();
        }, 'purchase-invoice-'.$purchase->reference_number.'.pdf');
    }

    public function shareInvoicePdf(): void
    {
        if (! $this->currentPurchaseId) {
            $this->errorMessage = 'احفظ الفاتورة أولاً قبل إرسال PDF عبر واتساب.';

            return;
        }

        $this->dispatch(
            'share-invoice-pdf',
            url: route('tenant.purchase-invoices.pdf', ['purchaseId' => $this->currentPurchaseId]),
            filename: 'purchase-invoice-'.$this->invoiceNumber.'.pdf',
            message: 'مرفق فاتورة مشتريات رقم '.$this->invoiceNumber
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        $tenantId = $this->tenantId();
        $user = auth()->user();
        $branchId = $this->selectedBranchId ?: $this->resolveBranchId();

        $branches = collect();
        $productSearchLocked = $this->currentPurchaseId !== null && ! $this->invoiceEditMode;

        if ($tenantId) {
            $branches = Branch::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $productResults = collect();

        if ($tenantId && ! $productSearchLocked && trim($this->rowSearch) !== '' && !$this->selectedProductId) {
            $search = trim($this->rowSearch);

            $searchService = app(\App\Services\PosProductSearch::class);
            // Support full and partial product numbers (e.g. I00000022, 00000022, 22).
            // Keep all matching conditions inside a group so tenant isolation remains enforced.
            $normalizedSearch = $searchService->normalize($search);
            $numberPattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalizedSearch).'%';
            $productResults = Product::query()
                ->where('tenant_id', $tenantId)
                ->with(['branchProducts' => fn ($query) => $query->where('branch_id', $branchId)])
                ->where(function ($query) use ($searchService, $search, $tenantId, $numberPattern) {
                    $query->where(function ($prefixQuery) use ($searchService, $search, $tenantId) {
                        $searchService->apply($prefixQuery->getQuery(), $search, (int) $tenantId);
                    })->orWhereRaw("LOWER(products.product_number) LIKE ? ESCAPE '!'", [$numberPattern]);
                })
                ->orderBy('name')->orderBy('id')
                ->limit(12)
                ->get();

            // Preserve searching words inside product names, but avoid the costly
            // contains scan when the indexed prefix search already found results.
            if ($productResults->isEmpty()) {
                $productResults = Product::query()
                    ->where('tenant_id', $tenantId)
                    ->with(['branchProducts' => fn ($query) => $query->where('branch_id', $branchId)])
                    ->tap(fn ($query) => $searchService->applyContains($query->getQuery(), $search))
                    ->limit(12)
                    ->get();
            }
        }

        $supplierResults = collect();

        if ($tenantId && $this->selectedSupplierId === null && trim($this->supplierSearch) !== '') {
            $search = trim($this->supplierSearch);

            $supplierResults = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'supplier', 'both'])
                ->where('is_active', true)
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($subQuery) use ($search) {
                        $subQuery->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                })
                ->orderBy('name')
                ->limit(20)
                ->get(['id', 'name', 'phone', 'current_balance']);
        }

        $selectedSupplier = null;

        if ($tenantId && $this->selectedSupplierId) {
            $selectedSupplier = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'supplier', 'both'])
                ->find($this->selectedSupplierId);
        }

        $totals = $this->calculateTotals();

        $accountBalanceAfterPayment = $totals['remaining'];

        if ($selectedSupplier) {
            $previousOutstanding = 0.0;

            if ($this->currentPurchaseId) {
                $savedPurchase = DB::table('purchases')
                    ->where('tenant_id', $tenantId)
                    ->where('supplier_id', $selectedSupplier->id)
                    ->where('id', $this->currentPurchaseId)
                    ->first(['total', 'paid_amount']);

                if ($savedPurchase) {
                    $previousOutstanding = max(0, (float) $savedPurchase->total - (float) $savedPurchase->paid_amount);
                }
            }

            $accountBalanceAfterPayment = round(
                (float) $selectedSupplier->current_balance
                    + $selectedSupplier->purchaseBalanceDirection() * ($totals['remaining'] - $previousOutstanding),
                2
            );
        }

        return $this->view([
            'branches' => $branches,
            'purchaseBranches' => $branches,
            'productSearchLocked' => $productSearchLocked,
            'productResults' => $productResults,
            'supplierResults' => $supplierResults,
            'selectedSupplier' => $selectedSupplier,
            'subtotal' => $totals['subtotal'],
            'invoiceDiscount' => $totals['discount'],
            'taxAmount' => $this->currentPurchaseId
                ? (float) DB::table('purchases')->where('tenant_id', $tenantId)->where('id', $this->currentPurchaseId)->value('tax_amount')
                : $totals['tax'],
            'showTax' => TenantSetting::getBool((int) $tenantId, 'show_tax', true),
            'grandTotal' => $totals['total'],
            'paidTotal' => $totals['paid'],
            'remainingTotal' => $totals['remaining'],
            'accountBalanceAfterPayment' => $accountBalanceAfterPayment,
            'paymentStatus' => $totals['payment_status'],
            'totalQuantity' => $totals['total_quantity'],
            'userBranchLocked' => (bool) ($user?->branch_id && ! $user->isTenantOwner() && ! $user->isSaaSAdmin()),
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="min-h-[calc(100vh-4rem)] bg-slate-100 p-2 sm:p-3 md:p-4" dir="rtl">

    <div
        class="mx-auto max-w-[1700px]"
        x-data="purchaseInvoiceKeyboard()"
    >

        {{-- شريط الأدوات --}}
        <livewire:product-history :key="'purchase-product-history-singleton'" />
        @if ($selectedSupplierId && auth()->user()->can('reports.analytics') && auth()->user()->can('customers.view'))
            <a class="mb-3 inline-flex rounded-xl bg-indigo-50 px-4 py-2 text-sm font-bold text-indigo-700" href="{{ route('tenant.reports', ['report' => 'statement', 'party_id' => $selectedSupplierId, 'party_type' => 'supplier']) }}" target="_blank" rel="noopener">كشف حساب المورد</a>
        @endif
        <div class="mb-3 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 bg-slate-950 p-3 text-white lg:flex-row lg:items-center lg:justify-between">
                <div class="flex min-w-0 items-center gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/10 text-xl">
                        🛒
                    </div>
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="truncate text-base font-black sm:text-lg">فاتورة مشتريات</h1>
                            @if ($currentPurchaseId)
                                <span class="rounded-full border border-amber-300/30 bg-amber-400/15 px-2.5 py-1 text-[10px] font-black text-amber-300">تعديل فاتورة</span>
                            @else
                                <span class="rounded-full border border-emerald-300/30 bg-emerald-400/15 px-2.5 py-1 text-[10px] font-black text-emerald-300">فاتورة جديدة</span>
                            @endif
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-300">
                            <span>رقم: <strong class="font-mono text-white">{{ $invoiceNumber }}</strong></span>
                            <span>التاريخ: <strong class="text-white">{{ $invoiceDate }}</strong></span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <button type="button" wire:click="startNewInvoice" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-black transition hover:bg-emerald-500">+ جديدة</button>
                    <button type="button" wire:click="togglePurchaseSearch" class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15">🔎 استعلام</button>
                    <button type="button" wire:click="previousInvoice" class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15">← السابقة</button>
                    <button type="button" wire:click="nextInvoice" class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15">التالية →</button>
                    @if ($currentPurchaseId)
                        <button type="button" wire:click="printCurrentInvoice" class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15">🖨 طباعة</button>
                        <button type="button" wire:click="downloadInvoicePdf" class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15">📄 PDF</button>
                        <button type="button" wire:click="shareInvoicePdf" class="rounded-xl border border-emerald-300/30 bg-emerald-500/20 px-3 py-2 text-xs font-black text-emerald-100 transition hover:bg-emerald-500/30">🟢 واتساب PDF</button>
                        @if (! $invoiceEditMode)
                            <button type="button" wire:click="editLoadedInvoice" class="rounded-xl bg-amber-500 px-3 py-2 text-xs font-black text-slate-950 transition hover:bg-amber-400">✎ تعديل</button>
                        @endif
                    @else
                        <button type="button" wire:click="saveAndPrint" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black transition hover:bg-indigo-500">💾 حفظ وطباعة</button>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-500">
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">↑ ↓</kbd> تنقل</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">← →</kbd> الخانات</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">F2</kbd> الأسعار</span><span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">Shift+F2</kbd> جديدة</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">F3</kbd> حفظ</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">F6</kbd> حفظ وطباعة</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">F7</kbd> استعلام</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">F8</kbd> المورد</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">F9</kbd> المادة</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">Enter</kbd> اختيار / إضافة</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">Esc</kbd> إغلاق</span>
            </div>
        </div>

        {{-- الرسائل --}}
        @if ($errorMessage)
            <div class="mb-3 flex items-center justify-between gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
                <div class="flex items-center gap-2"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100">!</span><span>{{ $errorMessage }}</span></div>
                <button type="button" wire:click="$set('errorMessage', null)" class="rounded-lg px-2 py-1 hover:bg-rose-100">✕</button>
            </div>
        @endif

        @if ($successMessage)
            <div class="mb-3 flex items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
                <div class="flex items-center gap-2"><span class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-100">✓</span><span>{{ $successMessage }}</span></div>
                <button type="button" wire:click="$set('successMessage', null)" class="rounded-lg px-2 py-1 hover:bg-emerald-100">✕</button>
            </div>
        @endif

        {{-- البحث عن فاتورة --}}
        @if ($showPurchaseSearch)
            <div class="mb-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex flex-col gap-2 sm:flex-row">
                    @if (auth()->user()->isTenantOwner() || auth()->user()->isSaaSAdmin())
                        <select wire:model.live="purchaseBranchFilter" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-bold">
                            <option value="">كل الفروع</option>
                            @foreach ($purchaseBranches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                        </select>
                    @endif
                    <input id="purchase-search-input" x-ref="purchaseSearch" type="text" wire:model.live.debounce.200ms="searchPurchaseQuery" wire:keydown.enter="searchPurchase" placeholder="رقم فاتورة المشتريات أو رقم فاتورة المورد..." class="w-full flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:border-slate-400 focus:bg-white" />
                    <button type="button" wire:click="searchPurchase" class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-black text-white hover:bg-slate-800">بحث</button>
                    <button type="button" wire:click="$set('showPurchaseSearch', false)" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-black text-slate-600 hover:bg-slate-50">إغلاق</button>
                </div>
            </div>
        @endif

        {{-- رأس الفاتورة --}}
        <div class="mb-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="mb-3 flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                <div>
                    <div class="text-sm font-black text-slate-900">بيانات الفاتورة</div>
                    <div class="mt-1 text-[11px] font-semibold text-slate-400">المورد، رقم فاتورة المورد، الفرع والتاريخ</div>
                </div>
                <div class="rounded-xl bg-slate-50 px-3 py-2 text-left">
                    <div class="text-[10px] font-bold text-slate-400">رقم الفاتورة</div>
                    <div class="font-mono text-sm font-black text-slate-900">{{ $invoiceNumber }}</div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-12">
                <div class="relative lg:col-span-4" data-invoice-search-group x-data="{ partySearchOpen: false }" x-on:focusout="if (!$el.contains($event.relatedTarget)) partySearchOpen = false" x-on:click.outside="partySearchOpen = false" x-on:keydown.escape.stop="partySearchOpen = false">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">العميل / المورد</label>
                    @if ($selectedSupplier)
                        <div class="flex items-center justify-between rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2.5">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-black text-indigo-900">{{ $selectedSupplier->name }}</div>
                                <div class="mt-0.5 text-[11px] font-semibold text-indigo-700">{{ $selectedSupplier->phone ?: 'بدون هاتف' }}</div>
                            </div>
                            <button type="button" wire:click="clearSupplier" class="rounded-lg px-2 py-1 text-xs font-black text-indigo-700 hover:bg-indigo-100">تغيير</button>
                        </div>
                    @else
                        <input id="supplier-search-input" x-on:focus="partySearchOpen = true" x-on:input="partySearchOpen = true" x-ref="supplierSearch" type="text" wire:model.live.debounce.180ms="supplierSearch" placeholder="ابحث باسم العميل أو المورد أو الهاتف..." autocomplete="off" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:border-slate-400 focus:bg-white" />
                        @if (trim($supplierSearch) !== '')
                            <div data-invoice-search-results x-cloak x-show="partySearchOpen" x-on:click="partySearchOpen = false" class="absolute right-0 top-full z-40 mt-1 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                                <div class="max-h-64 overflow-y-auto p-1.5" x-ref="supplierResults">
                                    @forelse ($supplierResults as $supplier)
                                        <button type="button" wire:key="purchase-search-supplier-{{ $supplier->id }}" data-supplier-result data-result-index="{{ $loop->index }}" wire:click="selectSupplier({{ $supplier->id }})" class="group flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-right transition hover:bg-slate-50">
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-black text-slate-800">{{ $supplier->name }}</div>
                                                <div class="mt-0.5 text-[11px] font-semibold text-slate-400">{{ $supplier->phone ?: 'بدون هاتف' }}</div>
                                            </div>
                                            <div class="text-left">
                                                <div class="font-mono text-xs font-black text-slate-700">{{ number_format((float) $supplier->current_balance, 2) }}</div>
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

                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">مرجع الفاتورة</label>
                    <input type="text" wire:model="invoiceNumber" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700 outline-none focus:border-slate-400 focus:bg-white" />
                </div>

                @if (!auth()->user()->isTenantOwner() && !auth()->user()->isSaaSAdmin())
                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">الفرع</label>
                    <select wire:model.live="selectedBranchId" @disabled($userBranchLocked) class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700 outline-none focus:border-slate-400 focus:bg-white disabled:cursor-not-allowed disabled:opacity-60">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                @endif

                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">التاريخ</label>
                    <input type="date" wire:model="invoiceDate" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700 outline-none focus:border-slate-400 focus:bg-white" />
                </div>

                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">نوع العملية</label>
                    <div class="flex h-[46px] items-center rounded-xl border border-indigo-200 bg-indigo-50 px-3">
                        <span class="h-2.5 w-2.5 rounded-full bg-indigo-500"></span>
                        <span class="mr-2 text-xs font-black text-indigo-800">مشتريات</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- منطقة الإدخال --}}
        <div class="grid gap-3 xl:grid-cols-12">

            {{-- المواد --}}
            <div class="min-w-0 xl:col-span-12">
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

            <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-3 lg:flex-row lg:items-end">

                <div class="min-w-0 flex-1">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">إضافة مادة / باركود</label>

                    <div class="relative" data-invoice-search-group x-data="{ productSearchOpen: false }" x-on:purchase-product-selected.window="productSearchOpen = false" x-on:focusout="if (!$el.contains($event.relatedTarget)) productSearchOpen = false" x-on:click.outside="productSearchOpen = false" x-on:keydown.escape.stop="productSearchOpen = false">
                        <button type="button" x-ref="priceHistoryTrigger" data-entry-price-history aria-label="أسعار الصنف والتكلفة (F2)" title="أسعار الصنف والتكلفة (F2)" @disabled(!$selectedProductId) x-on:click.stop="$dispatch('open-product-price-history', { productId: {{ $selectedProductId ?? 'null' }}, customerId: null })" class="absolute left-2 top-1/2 z-10 -translate-y-1/2 rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1.5 text-xs font-bold text-indigo-700 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-40">الأسعار F2</button>
                        <input
                            id="product-search-input"
                            x-ref="productSearch"
                            type="text"
                            wire:model.live.debounce.120ms="rowSearch"
                            maxlength="120" x-on:input="productSearchOpen = true" x-on:focus="productSearchOpen = true" @disabled($productSearchLocked)
                            autocomplete="off"
                            placeholder="{{ $productSearchLocked ? 'اضغط تعديل أولاً للبحث عن صنف' : 'ابحث باسم الصنف أو رقمه أو امسح الباركود ثم اضغط Enter...' }}"
                            class="w-full rounded-xl border-2 border-indigo-200 bg-white px-4 py-3 pl-28 text-sm font-black text-slate-900 outline-none transition focus:border-indigo-500 disabled:cursor-not-allowed disabled:bg-slate-100"
                        />

                        @if ($productSearchLocked)
                            <p class="mt-1 text-xs font-bold text-amber-700">الفاتورة للعرض فقط. اضغط «تعديل» بالأعلى لتفعيل البحث وإضافة الأصناف.</p>
                        @endif

                        @if ($selectedProductId)
                            <p role="status" class="mt-1 text-xs font-bold text-indigo-700">تم اختيار «{{ $selectedProductName }}». أدخل الكمية والتكلفة ثم اضغط «+ إضافة» لإدخاله في الفاتورة.</p>
                        @endif

                        @if (count($productResults) > 0)
                            <div data-invoice-search-results x-cloak x-show="productSearchOpen" class="absolute right-0 top-full z-50 mt-1 w-full overflow-hidden rounded-2xl border border-indigo-200 bg-white shadow-2xl">
                                <div class="max-h-80 overflow-auto" x-ref="productResults">
                                    <table class="w-full text-right text-sm">
                                        <thead class="sticky top-0 bg-slate-900 text-xs text-white"><tr><th class="p-2">رقم الصنف</th><th class="p-2">اسم الصنف</th><th class="p-2">السعر</th><th class="p-2">المخزون</th></tr></thead>
                                        <tbody>
                                            @foreach ($productResults as $product)
                                                @php
                                                    $bp = $product->branchProducts->first();
                                                    $resultPrice = (float) $product->cost_price;
                                                    $stock = (float) ($bp?->stock_quantity ?? 0);
                                                @endphp
                                                <tr wire:key="purchase-search-product-{{ $product->id }}" data-product-result data-result-index="{{ $loop->index }}" class="border-t border-slate-100">
                                                    <td colspan="4" class="p-0">
                                                        <button type="button" x-on:click.stop wire:click="selectProduct({{ $product->id }})" wire:loading.attr="disabled" wire:target="selectProduct({{ $product->id }})" class="grid w-full grid-cols-[1.15fr_2.4fr_1fr_0.8fr] items-center gap-1 px-2 py-2 text-right hover:bg-indigo-50 disabled:cursor-wait disabled:opacity-60">
                                                            <span class="font-mono">{{ $product->product_number ?? $product->id }}</span>
                                                            <span class="font-bold">{{ $product->name }}</span>
                                                            <span class="font-mono">{{ number_format($resultPrice, 2) }}</span>
                                                            <span class="tabular-nums">{{ number_format($stock, 2) }}</span>
                                                        </button>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="w-full lg:w-40">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">الكمية</label>
                    <input
                        x-ref="quantity"
                        type="text"
                        inputmode="decimal"
                        wire:model="selectedQuantity"
                        x-on:focus="$event.target.select()"
                        x-on:keydown.arrow-up.stop.prevent="$refs.purchasePriceInput?.focus()"
                        x-on:keydown.arrow-down.stop.prevent="$refs.purchasePriceInput?.focus()"
                        x-on:keydown.enter.stop.prevent="$refs.purchasePriceInput?.focus(); $refs.purchasePriceInput?.select()"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-sm font-black text-slate-900 outline-none focus:border-indigo-500"
                    />
                </div>

                <div class="w-full lg:w-40">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">سعر التكلفة</label>
                    <input
                        x-ref="purchasePriceInput"
                        type="text"
                        inputmode="decimal"
                        wire:model="selectedPurchasePrice"
                        x-on:focus="$event.target.select()"
                        x-on:keydown.arrow-up.stop.prevent="$refs.quantity?.focus()"
                        x-on:keydown.arrow-down.stop.prevent="$refs.quantity?.focus()"
                        x-on:keydown.enter.stop.prevent="addCurrentPurchaseRow()"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-sm font-black text-indigo-700 outline-none focus:border-indigo-500"
                    />
                </div>

                <div class="w-full lg:w-40">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">الإجمالي</label>
                    <div class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-center font-mono text-sm font-black text-slate-900">
                        {{ number_format(max(0, $selectedPurchasePrice * $selectedQuantity), 2) }}
                    </div>
                </div>

                <button
                    type="button"
                    x-ref="addProductButton"
                    x-on:click="addCurrentPurchaseRow()"
                    wire:loading.attr="disabled"
                    wire:target="commitRowWithValues"
                    @disabled(!$selectedProductId || $productSearchLocked)
                    class="flex h-[46px] items-center justify-center rounded-xl bg-indigo-600 px-5 text-sm font-black text-white transition hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="commitRowWithValues">+ إضافة</span>
                    <span wire:loading wire:target="commitRowWithValues">جارٍ الإضافة...</span>
                </button>
            </div>

                    {{-- جدول المواد --}}
                    <div class="hidden md:block">
                        <div class="max-h-[52vh] overflow-auto">
                            <div class="app-table-scroll" role="region" aria-label="جدول البيانات — اسحب لعرض بقية الأعمدة" tabindex="0">
                            <table class="w-full min-w-[850px] text-right">
                                <thead class="sticky top-0 z-20 border-b border-slate-200 bg-slate-900">
                                    <tr class="text-[11px] font-black text-white">
                                        <th class="w-12 px-3 py-3">#</th>
                                        <th class="px-3 py-3">المادة</th>
                                        <th class="w-28 px-3 py-3">الكمية</th>
                                        <th class="w-32 px-3 py-3">التكلفة</th>
                                        <th class="w-36 px-3 py-3">الإجمالي</th>
                                        <th class="w-20 px-3 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @forelse ($cart as $key => $item)
                                        @php($lineTotal = isset($item['total_override']) ? max(0, (float) $item['total_override']) : max(0, ((float) $item['price'] * (float) $item['quantity']) - (float) ($item['discount'] ?? 0)))
                                        <tr
                                            wire:key="purchase-item-{{ $key }}"
                                            data-cart-row="{{ $loop->index }}"
                                            data-cart-key="{{ $key }}"



                                            class="transition-colors duration-100"
                                        >
                                            <td class="px-3 py-3 text-xs font-black text-slate-400">{{ $loop->iteration }}</td>
                                            <td class="px-3 py-3">
                                                <div class="text-sm font-black text-slate-800"><button type="button" data-price-history-button x-on:click.stop="$dispatch('open-product-price-history', { productId: {{ $item['id'] }}, customerId: null })" title="سجل الأسعار والتكلفة" aria-label="سجل الأسعار والتكلفة" style="float: left; width: 20px; height: 20px; padding: 0; border: 0; background: transparent; color: #4f46e5; cursor: pointer;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width: 18px; height: 18px;" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8m3-6h-4a2 2 0 0 0 0 4h2a2 2 0 0 0 0-4M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></button>{{ $item['name'] }} <button type="button" wire:click="$dispatch('show-product-history', { productId: {{ $item['id'] }} })" class="rounded px-2 py-1 text-xs text-indigo-700">حركة الصنف</button></div>
                                                @if(isset($item['stock']))
                                                    <div class="mt-1 text-[10px] font-semibold text-slate-400">المخزون الحالي: {{ number_format((float) $item['stock'], 2) }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="flex items-center gap-1">
                                                    <input type="text" inputmode="decimal" min="0.01" step="0.01" value="{{ $item['quantity'] }}" wire:change="updateItemQuantity('{{ $key }}', $event.target.value)" data-purchase-grid-input data-row="{{ $loop->index }}" data-col="0" class="w-20 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-xs font-black outline-none focus:border-indigo-400" />
                                                </div>
                                            </td>
                                            <td class="px-3 py-3">
                                                <input type="text" inputmode="decimal" min="0" step="0.01" value="{{ $item['price'] }}" wire:change="updateItemPrice('{{ $key }}', $event.target.value)" data-purchase-grid-input data-row="{{ $loop->index }}" data-col="1" class="w-28 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-xs font-black outline-none focus:border-indigo-400" />
                                            </td>
                                            <td class="px-3 py-3"><input type="text" inputmode="decimal" min="0" step="0.01" value="{{ number_format((float) $lineTotal, 2, '.', '') }}" wire:change="updateItemTotal('{{ $key }}', $event.target.value)" data-purchase-grid-input data-row="{{ $loop->index }}" data-col="2" class="w-28 rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-2 text-center text-xs font-black text-indigo-700" aria-label="إجمالي السطر" /></td>
                                            <td class="px-3 py-3 text-center">
                                                <button type="button" wire:click="removeItem('{{ $key }}')" class="rounded-lg px-2 py-1.5 text-xs font-black text-rose-600 hover:bg-rose-50">حذف</button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="6" class="px-6 py-16 text-center">
                                                <div class="mx-auto max-w-sm">
                                                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 text-2xl">＋</div>
                                                    <div class="mt-4 text-base font-black text-slate-800">ابدأ بإضافة المواد</div>
                                                    <div class="mt-1 text-sm font-semibold text-slate-400">ابحث عن المادة أو امسح الباركود ثم اضغط Enter.</div>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                            </div>
                        </div>
                    </div>

                    {{-- Mobile --}}
                    <div class="divide-y divide-slate-100 md:hidden">
                        @forelse ($cart as $key => $item)
                            @php($lineTotal = isset($item['total_override']) ? max(0, (float) $item['total_override']) : max(0, ((float) $item['price'] * (float) $item['quantity']) - (float) ($item['discount'] ?? 0)))
                            <div wire:key="purchase-mobile-item-{{ $key }}" data-cart-row="{{ $loop->index }}" data-cart-key="{{ $key }}"    class="p-3 transition-colors">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-sm font-black text-slate-800">{{ $loop->iteration }}. {{ $item['name'] }} <button type="button" wire:click="$dispatch('show-product-history', { productId: {{ $item['id'] }} })" class="rounded px-2 py-1 text-xs text-indigo-700">حركة الصنف</button></div>
                                        <div class="mt-1 text-xs font-semibold text-slate-400">الإجمالي: {{ number_format($lineTotal, 2) }}</div>
                                    </div>
                                    <button type="button" wire:click="removeItem('{{ $key }}')" class="text-xs font-black text-rose-600">حذف</button>
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    <div>
                                        <label class="mb-1 block text-[10px] font-black text-slate-400">الكمية</label>
                                        <input type="text" inputmode="decimal" min="0.01" step="0.01" value="{{ $item['quantity'] }}" wire:change="updateItemQuantity('{{ $key }}', $event.target.value)" data-purchase-grid-input data-row="{{ $loop->index }}" data-col="0" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-xs font-black" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[10px] font-black text-slate-400">التكلفة</label>
                                        <input type="text" inputmode="decimal" min="0" step="0.01" value="{{ $item['price'] }}" wire:change="updateItemPrice('{{ $key }}', $event.target.value)" data-purchase-grid-input data-row="{{ $loop->index }}" data-col="1" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-xs font-black" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[10px] font-black text-slate-400">الإجمالي</label>
                                        <input type="text" inputmode="decimal" min="0" step="0.01" value="{{ number_format((float) $lineTotal, 2, '.', '') }}" wire:change="updateItemTotal('{{ $key }}', $event.target.value)" data-purchase-grid-input data-row="{{ $loop->index }}" data-col="2" class="w-full rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-2 text-xs font-black text-indigo-700" aria-label="إجمالي السطر" />
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="px-6 py-16 text-center text-sm font-bold text-slate-400">لا توجد مواد في الفاتورة.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- الملخص والدفع --}}
            <div class="xl:col-span-12" data-purchase-summary>
                <div class="grid grid-cols-1 items-start gap-3 lg:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <div class="text-sm font-black text-slate-900">ملخص الفاتورة</div>
                                <div class="mt-1 text-xs font-semibold text-slate-600">تفاصيل المبلغ المستحق للمورد</div>
                            </div>
                            <div class="rounded-xl bg-indigo-50 px-3 py-2 text-center">
                                <div class="text-xs font-bold text-indigo-500">إجمالي الكمية</div>
                                <div class="text-sm font-black text-indigo-800">{{ number_format($totalQuantity, 2) }}</div>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="font-bold text-slate-500">المجموع الفرعي</span>
                                <span class="font-black text-slate-900">{{ number_format($subtotal, 2) }}</span>
                            </div>

                            <div class="grid grid-cols-12 items-center gap-2">
                                <div class="col-span-7">
                                    <label class="mb-1 block text-xs font-black text-slate-600">خصم الفاتورة</label>
                                    <input type="number" min="0" step="0.01" wire:model.live="discount" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-black outline-none focus:border-indigo-400" />
                                </div>
                                <div class="col-span-5">
                                    <label class="mb-1 block text-xs font-black text-slate-600">نوع الخصم</label>
                                    <select wire:model.live="discountType" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs font-black outline-none focus:border-indigo-400">
                                        <option value="fixed">مبلغ</option>
                                        <option value="percentage">%</option>
                                    </select>
                                </div>
                            </div>

                            <div class="flex items-center justify-between text-sm">
                                <span class="font-bold text-slate-500">الخصم المحتسب</span>
                                <span class="font-black text-rose-600">- {{ number_format($invoiceDiscount, 2) }}</span>
                            </div>

                            @if ($showTax)
                                <div class="flex items-center justify-between text-sm" data-purchase-tax>
                                    <span class="font-bold text-slate-500">قيمة الضريبة</span>
                                    <span class="font-black text-amber-600">{{ number_format($taxAmount, 2) }}</span>
                                </div>
                            @endif

                            <div class="rounded-xl bg-indigo-50 p-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-base font-black text-slate-900">إجمالي الفاتورة</span>
                                    <span class="text-2xl font-black tabular-nums text-indigo-700">{{ number_format($grandTotal, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        @if (! $currentPurchaseId)
                            <div class="grid grid-cols-1 items-end gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="paid-amount-input" class="mb-2 block text-sm font-bold text-slate-700">الدفعة</label>
                                    <input id="paid-amount-input" x-ref="paidAmount" type="number" min="0" step="0.01" wire:model.live.debounce.800ms="paidAmount" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-lg font-black text-slate-900 outline-none focus:border-emerald-400 focus:bg-white" />
                                </div>
                                <div>
                                    <label for="payment-method-input" class="mb-2 block text-sm font-bold text-slate-700">طريقة الدفع</label>
                                    <select id="payment-method-input" wire:model="paymentMethod" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-bold text-slate-900 outline-none focus:border-emerald-400 focus:bg-white">
                                        <option value="cash">نقداً</option>
                                        <option value="card">بطاقة</option>
                                        <option value="bank_transfer">تحويل بنكي</option>
                                        <option value="cheque">شيك</option>
                                    </select>
                                </div>
                            </div>
                        @else
                            <p class="text-sm font-semibold text-slate-500">لتسجيل دفعة جديدة على هذه الفاتورة، أضف سند دفع مرتبطاً بها.</p>
                        @endif
                        <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3" role="status" aria-live="polite">
                            <div class="text-sm font-bold text-rose-700">الرصيد بعد الدفعة</div>
                            <div class="mt-1 text-xl font-black tabular-nums text-rose-800">{{ number_format($accountBalanceAfterPayment, 2) }}</div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <label for="purchase-notes" class="mb-1.5 block text-sm font-bold text-slate-600">ملاحظات (اختياري)</label>
                        <textarea id="purchase-notes" wire:model="notes" rows="1" placeholder="ملاحظة الفاتورة..." class="w-full resize-none rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold outline-none focus:border-indigo-400 focus:bg-white"></textarea>
                    </div>

                    <div class="grid grid-cols-2 items-end gap-2 self-end">
                        <button type="button" wire:click="saveInvoice" wire:loading.attr="disabled" wire:target="saveInvoice" class="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-indigo-500 disabled:opacity-60">
                            <span wire:loading.remove wire:target="saveInvoice">💾 حفظ</span>
                            <span wire:loading wire:target="saveInvoice">جاري الحفظ...</span>
                        </button>
                        <button type="button" wire:click="saveAndPrint" wire:loading.attr="disabled" wire:target="saveAndPrint" class="rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-60">
                            <span wire:loading.remove wire:target="saveAndPrint">🖨 حفظ وطباعة</span>
                            <span wire:loading wire:target="saveAndPrint">جاري الحفظ...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @if ($showPriceUpdateModal)
        <div class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/50 p-3" role="presentation">
            <section class="w-full max-w-2xl rounded-2xl bg-white p-5 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="purchase-price-update-title" dir="rtl">
                <div class="mb-4">
                    <h2 id="purchase-price-update-title" class="text-lg font-black text-slate-900">تحديث أسعار الأصناف؟</h2>
                    <p class="mt-1 text-sm text-slate-600">تم حفظ فاتورة المشتريات. اختر الأسعار التي تريد تحديثها للأصناف التي تغيرت تكلفتها.</p>
                </div>
                <div class="max-h-[55vh] space-y-3 overflow-y-auto">
                    @foreach ($priceUpdateCandidates as $productId => $candidate)
                        <div wire:key="purchase-price-update-{{ $productId }}" class="rounded-xl border border-slate-200 p-4">
                            <div class="mb-3 font-black text-slate-800">{{ $candidate['name'] }}</div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <label class="flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-sm">
                                    <input type="checkbox" wire:model="priceUpdateSelections.{{ $productId }}.cost" class="mt-1 rounded border-slate-300 text-indigo-600">
                                    <span>تحديث سعر التكلفة إلى <strong>{{ number_format($candidate['purchase_cost'], 2) }}</strong> (الحالي {{ number_format($candidate['current_cost'], 2) }})</span>
                                </label>
                                <div class="rounded-lg bg-slate-50 p-3">
                                    <label class="flex items-center gap-2 text-sm font-bold">
                                        <input type="checkbox" wire:model="priceUpdateSelections.{{ $productId }}.retail" class="rounded border-slate-300 text-indigo-600">
                                        <span>تحديث سعر البيع لهذا الفرع</span>
                                    </label>
                                    <input type="number" min="0" step="0.01" wire:model="priceUpdateRetailPrices.{{ $productId }}" @disabled(empty($priceUpdateSelections[$productId]['retail'])) class="mt-2 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm disabled:bg-slate-100" aria-label="سعر البيع الجديد للصنف {{ $candidate['name'] }}">
                                    @error('priceUpdateRetailPrices.'.$productId)<div class="mt-1 text-xs text-rose-600">{{ $message }}</div>@enderror
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                @error('priceUpdateCandidates')<p class="mt-3 text-sm text-rose-600">{{ $message }}</p>@enderror
                <div class="mt-5 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="dismissPriceUpdateModal" class="rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-600">تخطي التحديث</button>
                    <button type="button" wire:click="applyPurchasePriceUpdates" wire:loading.attr="disabled" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white disabled:opacity-60">
                        <span wire:loading.remove wire:target="applyPurchasePriceUpdates">تحديث الأسعار المحددة</span>
                        <span wire:loading wire:target="applyPurchasePriceUpdates">جارٍ التحديث...</span>
                    </button>
                </div>
            </section>
        </div>
    @endif

    <livewire:product-price-history-modal :key="'purchase-price-history-singleton'" />
</flux:main>

<style>
    /* Strong highlight for the currently selected purchase item. */
    .purchase-cart-selected, tr[data-cart-key]:focus-within, div[data-cart-row][data-cart-key]:focus-within { background: #bfdbfe !important; outline: 2px solid #2563eb; outline-offset: -2px; }
    .purchase-cart-selected td, tr[data-cart-key]:focus-within td { background: transparent !important; }
    .purchase-cart-selected input, [data-cart-key]:focus-within input { border-color: #2563eb !important; background: #eff6ff !important; }
    .purchase-cart-selected [data-price-history-button] { color: #1d4ed8 !important; }
    input[data-purchase-grid-input] { appearance: textfield; -moz-appearance: textfield; }
    input[data-purchase-grid-input]::-webkit-inner-spin-button,
    input[data-purchase-grid-input]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
</style>

@script
<script>
    Alpine.data('purchaseInvoiceKeyboard', () => ({
        supplierIndex: -1,
        productIndex: -1,
        rowIndex: 0,
        selectedCartKey: null,
        keyboardController: null,
        livewireCleanup: [],
        addingRow: false,

        async addCurrentPurchaseRow() {
            if (this.addingRow) return;
            const quantity = this.$refs.quantity?.value?.trim();
            const price = this.$refs.purchasePriceInput?.value?.trim();
            if (!quantity || !price || !Number.isFinite(Number(quantity)) ||
                !Number.isFinite(Number(price)) || Number(quantity) <= 0 || Number(price) < 0) {
                this.$refs.quantity?.focus();
                return;
            }
            this.addingRow = true;
            try {
                await this.$wire.commitRowWithValues(quantity, price);
            } finally {
                this.addingRow = false;
            }
        },

        init() {
            this.$nextTick(() => this.supplierInput()?.focus());

            this.registerKeyboard();

            this.livewireCleanup.push(Livewire.on('focus-product-search', () => {
                this.$nextTick(() => {
                    this.productInput()?.focus();
                    this.productInput()?.select();
                });
            }));

            this.livewireCleanup.push(Livewire.on('focus-supplier-search', () => {
                this.$nextTick(() => this.supplierInput()?.focus());
            }));

            this.livewireCleanup.push(Livewire.on('focus-quantity', () => {
                this.$nextTick(() => { this.$refs.quantity?.focus(); this.$refs.quantity?.select(); });
            }));

            this.livewireCleanup.push(Livewire.on('focus-paid-amount', () => {
                this.$nextTick(() => this.$refs.paidAmount?.focus());
            }));

            this.livewireCleanup.push(Livewire.on('focus-purchase-search', () => {
                this.$nextTick(() => this.$refs.purchaseSearch?.focus());
            }));
        },

        destroy() {
            this.keyboardController?.abort();
            this.livewireCleanup.forEach(cleanup => { if (typeof cleanup === 'function') cleanup(); });
            this.livewireCleanup = [];
        },

        visibleResults(selector) {
            return [...this.$el.querySelectorAll(selector)].filter(element => element.getClientRects().length > 0);
        },

        supplierInput() {
            return this.$el.querySelector('#supplier-search-input');
        },

        productInput() {
            return this.$el.querySelector('#product-search-input');
        },

        purchaseGridInput(row, column) {
            return this.visibleResults(`[data-purchase-grid-input][data-row="${row}"][data-col="${column}"]`)[0] ?? null;
        },

        registerKeyboard() {
            this.keyboardController?.abort();
            this.keyboardController = new AbortController();

            // Clicking anywhere on a product row activates its editable field.
            // Clicking the product name or a blank cell defaults to quantity;
            // clicking the cost/total cell focuses that column instead.
            this.$el.addEventListener('click', (event) => {
                const row = event.target.closest?.('[data-cart-key][data-cart-row]');
                if (!row || !this.$el.contains(row) || !row.getClientRects().length) return;
                if (event.target.closest('button, a, select, textarea, [contenteditable="true"]')) return;
                if (event.target.closest('[data-purchase-grid-input]')) return;

                let column = 0;
                const cell = event.target.closest('td');
                if (cell && row.tagName === 'TR') {
                    const index = [...row.children].indexOf(cell);
                    if (index === 3) column = 1; // Cost
                    if (index === 4) column = 2; // Total
                }
                const input = row.querySelector(`[data-purchase-grid-input][data-col="${column}"]`)
                    ?? row.querySelector('[data-purchase-grid-input][data-col="0"]');
                if (!input || input.disabled) return;
                input.focus({ preventScroll: true });
                input.select();
            }, { signal: this.keyboardController.signal });

            window.addEventListener('keydown', async (event) => {
                if (!this.$el.isConnected || this.$el.getClientRects().length === 0) return;
                const target = event.target;

                const isTextField =
                    target instanceof HTMLInputElement ||
                    target instanceof HTMLTextAreaElement ||
                    target instanceof HTMLSelectElement ||
                    target?.isContentEditable;

                const key = event.key;

                const purchaseGridInput = target?.closest?.('[data-purchase-grid-input]');
                if (purchaseGridInput && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'Enter'].includes(key)) {
                    event.preventDefault();
                    event.stopImmediatePropagation();

                    const currentRow = purchaseGridInput.closest('[data-cart-key]');
                    const column = Number(purchaseGridInput.dataset.col);
                    const allRows = this.visibleResults('tr[data-cart-key], div[data-cart-key][data-cart-row]');
                    const rows = allRows.filter(row => row.querySelector('[data-purchase-grid-input]'));
                    const rowIndex = rows.indexOf(currentRow);
                    if (rowIndex < 0) return;

                    let destinationRow = currentRow;
                    let destinationColumn = column;

                    if (key === 'ArrowUp' || key === 'ArrowDown') {
                        // Vertical arrows always change PRODUCT ROW, never the field.
                        const offset = key === 'ArrowDown' ? 1 : -1;
                        destinationRow = rows[Math.max(0, Math.min(rows.length - 1, rowIndex + offset))];
                    } else if (key === 'ArrowLeft' || key === 'ArrowRight') {
                        // In RTL, Right moves to the visually right field; Left to the visually left field.
                        const columns = [0, 1, 2];
                        const currentPosition = columns.indexOf(column);
                        const nextPosition = currentPosition + (key === 'ArrowRight' ? -1 : 1);
                        if (nextPosition >= 0 && nextPosition < columns.length) {
                            destinationColumn = columns[nextPosition];
                        }
                    } else if (key === 'Enter') {
                        const columns = [0, 1, 2];
                        const position = columns.indexOf(column);
                        if (position >= 0 && position < columns.length - 1) {
                            destinationColumn = columns[position + 1];
                        } else if (rowIndex < rows.length - 1) {
                            destinationRow = rows[rowIndex + 1];
                            destinationColumn = 0;
                        } else {
                            this.productInput()?.focus();
                            return;
                        }
                    }

                    const next = [...destinationRow.querySelectorAll('[data-purchase-grid-input]')]
                        .find(input => Number(input.dataset.col) === destinationColumn);
                    if (next && next !== purchaseGridInput) {
                        // Move actual DOM focus; CSS :focus-within highlights ONLY its row.
                        next.focus({ preventScroll: true });
                        next.select();
                        destinationRow.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                    }
                    return;
                }

                if (key === 'F2') {
                    event.preventDefault();
                    if (event.shiftKey) {
                        this.$wire.startNewInvoice();
                    } else {
                        this.$refs.priceHistoryTrigger?.click();
                    }
                    return;
                }

                if (key === 'F3') {
                    event.preventDefault();
                    this.$wire.saveInvoice();
                    return;
                }

                if (key === 'F6') {
                    event.preventDefault();
                    this.$wire.saveAndPrint();
                    return;
                }

                if (key === 'F7') {
                    event.preventDefault();
                    this.$wire.togglePurchaseSearch();
                    return;
                }

                if (key === 'F8') {
                    event.preventDefault();
                    this.$nextTick(() => this.supplierInput()?.focus());
                    return;
                }

                if (key === 'F9') {
                    event.preventDefault();
                    this.$nextTick(() => this.productInput()?.focus());
                    return;
                }

                if (key === 'Escape') {
                    const supplierBox = this.visibleResults('[data-supplier-result]').length > 0;
                    const productBox = this.visibleResults('[data-product-result]').length > 0;

                    if (supplierBox || productBox) {
                        event.preventDefault();
                        this.supplierIndex = -1;
                        this.productIndex = -1;
                    }

                    if (!isTextField) {
                        this.$wire.startNewInvoice(false);
                    }

                    return;
                }

                if (isTextField) {
                    if (key === 'ArrowDown' || key === 'ArrowUp') {
                        if (target?.matches?.('#supplier-search-input')) {
                            event.preventDefault();
                            this.moveSupplier(key === 'ArrowDown' ? 1 : -1);
                            return;
                        }

                        if (target?.matches?.('#product-search-input')) {
                            event.preventDefault();
                            this.moveProduct(key === 'ArrowDown' ? 1 : -1);
                            return;
                        }
                    }

                    if (key === 'Enter') {
                        if (target?.matches?.('#supplier-search-input')) {
                            event.preventDefault();
                            this.selectActiveSupplier();
                            return;
                        }

                        if (target?.matches?.('#product-search-input')) {
                            event.preventDefault();
                            // If user navigated with arrows, choose the highlighted result first.
                            const results = this.visibleResults('[data-product-result]');
                            if (this.productIndex >= 0 && results[this.productIndex]) {
                                results[this.productIndex].querySelector('button')?.click();
                                this.productIndex = -1;
                                return;
                            }

                            // Barcode scanners usually send Enter. Exact code match
                            // adds one unit, or increments an existing cart line.
                            const barcodeMatched = await this.$wire.scanBarcodeToCart(target.value);
                            if (barcodeMatched) {
                                this.productIndex = -1;
                                return;
                            }
                            this.visibleResults('[data-product-result]')[0]?.querySelector('button')?.click();
                            return;
                        }
                    }

                    return;
                }

                // Outside search and invoice grid, arrows never change invoices.

            }, { signal: this.keyboardController.signal, capture: true });
        },

        moveSupplier(direction) {
            const nodes = this.visibleResults('[data-supplier-result]');
            if (!nodes.length) return;

            this.supplierIndex += direction;

            if (this.supplierIndex < 0) this.supplierIndex = nodes.length - 1;
            if (this.supplierIndex >= nodes.length) this.supplierIndex = 0;

            nodes.forEach((node, index) => {
                node.classList.toggle('bg-indigo-50', index === this.supplierIndex);
                node.classList.toggle('ring-1', index === this.supplierIndex);
                node.classList.toggle('ring-indigo-200', index === this.supplierIndex);
            });

            nodes[this.supplierIndex]?.scrollIntoView({ block: 'nearest' });
        },

        moveProduct(direction) {
            const nodes = this.visibleResults('[data-product-result]');
            if (!nodes.length) return;

            this.productIndex += direction;

            if (this.productIndex < 0) this.productIndex = nodes.length - 1;
            if (this.productIndex >= nodes.length) this.productIndex = 0;

            nodes.forEach((node, index) => {
                node.classList.toggle('invoice-product-selected', index === this.productIndex);
                node.classList.toggle('ring-1', index === this.productIndex);
                node.classList.toggle('ring-indigo-200', index === this.productIndex);
            });

            nodes[this.productIndex]?.scrollIntoView({ block: 'nearest' });
        },

        selectActiveSupplier() {
            const active = this.visibleResults('[data-supplier-result]')[this.supplierIndex];

            if (active) {
                active.click();
                return;
            }

            const first = this.visibleResults('[data-supplier-result]')[0];
            first?.click();
        },
    }));

    Livewire.on('share-invoice-pdf', async ({ url, filename, message }) => {
        try {
            const response = await fetch(url, { credentials: 'same-origin' });

            if (!response.ok) {
                throw new Error('PDF download failed');
            }

            const file = new File([await response.blob()], filename, { type: 'application/pdf' });

            if (navigator.canShare?.({ files: [file] })) {
                await navigator.share({ files: [file], text: message, title: filename });

                return;
            }

            const downloadUrl = URL.createObjectURL(file);
            const link = document.createElement('a');
            link.href = downloadUrl;
            link.download = filename;
            link.click();
            URL.revokeObjectURL(downloadUrl);
            window.open('https://wa.me/?text=' + encodeURIComponent(message + '\n\nتم تنزيل ملف PDF؛ أرفقه في المحادثة.'), '_blank', 'noopener');
        } catch {
            window.open('https://wa.me/?text=' + encodeURIComponent(message), '_blank', 'noopener');
        }
    });

    Livewire.on('print-purchase-invoice', ({ invoice }) => {
        const esc = (value) => String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');

        const money = (value) => Number(value || 0).toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

        const rows = (invoice.items || []).map((item, index) => `
            <tr>
                <td>${index + 1}</td>
                <td>${esc(item.name)}</td>
                <td>${money(item.quantity)}</td>
                <td>${money(item.price)}</td>
                <td>${money(item.discount)}</td>
                <td>${money(item.total)}</td>
            </tr>
        `).join('');

        const html = `
            <!doctype html>
            <html lang="ar" dir="rtl">
            <head>
                <meta charset="utf-8">
                <title>فاتورة مشتريات ${esc(invoice.number)}</title>
                <style>
                    * { box-sizing: border-box; }
                    body {
                        margin: 0;
                        padding: 14mm;
                        color: #111827;
                        font-family: Arial, Tahoma, sans-serif;
                        background: #fff;
                    }
                    .head { display:flex; justify-content:space-between; gap:20px; border-bottom:2px solid #111827; padding-bottom:12px; margin-bottom:18px; }
                    h1 { margin:0 0 5px; font-size:24px; }
                    .muted { color:#6b7280; font-size:12px; }
                    .meta { display:grid; grid-template-columns: 1fr 1fr; gap:8px 20px; margin-bottom:18px; }
                    .meta div { padding:8px 10px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; }
                    table { width:100%; border-collapse:collapse; }
                    th, td { border-bottom:1px solid #e5e7eb; padding:8px 7px; font-size:12px; text-align:right; }
                    th { background:#f8fafc; font-weight:700; }
                    .totals { width:380px; max-width:100%; margin-top:18px; margin-right:0; margin-left:auto; }
                    .line { display:flex; justify-content:space-between; padding:7px 0; font-size:13px; }
                    .grand { border-top:2px solid #111827; margin-top:5px; padding-top:10px; font-size:17px; font-weight:800; }
                    .notes { margin-top:18px; border-top:1px solid #e5e7eb; padding-top:10px; font-size:12px; }
                    @page { size:A4; margin:10mm; }
                    @media print { body { padding:0; } }
                </style>
            </head>
            <body>
                <div class="head">
                    <div>
                        <div data-invoice-store-name style="font-size:20px;font-weight:700;margin-bottom:8px">${esc(invoice.store_name ?? '')}</div>
                            <h1>فاتورة مشتريات</h1>
                        <div class="muted">نظام إدارة المشتريات</div>
                    </div>
                    <div>
                        <div><strong>رقم الفاتورة:</strong> ${esc(invoice.number)}</div>
                        <div class="muted">${esc(invoice.date)}</div>
                    </div>
                </div>

                <div class="meta">
                    <div><strong>المورد:</strong> ${esc(invoice.supplier)}</div>
                </div>

                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>المادة</th>
                            <th>الكمية</th>
                            <th>التكلفة</th>
                            <th>الخصم</th>
                            <th>الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>

                <div class="totals">
                    <div class="line"><span>المجموع الفرعي</span><strong>${money(invoice.subtotal)}</strong></div>
                    <div class="line"><span>الخصم</span><strong>${money(invoice.discount)}</strong></div>
                    ${invoice.show_tax ? `<div class="line"><span>الضريبة</span><strong>${money(invoice.tax)}</strong></div>` : ''}
                    <div class="line grand"><span>الإجمالي</span><strong>${money(invoice.total)}</strong></div>
                    <div class="line"><span>المدفوع</span><strong>${money(invoice.paid)}</strong></div>
                    <div class="line"><span>المتبقي من الفاتورة</span><strong>${money(invoice.remaining)}</strong></div>
                    ${invoice.account_balance != null ? `<div class="line"><span>رصيد الحساب الحالي بعد الفاتورة</span><strong>${money(invoice.account_balance)}</strong></div>` : ''}
                </div>

                ${invoice.notes ? `<div class="notes"><strong>ملاحظات:</strong> ${esc(invoice.notes)}</div>` : ''}

                <x-invoice-copyright />

                <script>
                    window.onload = function() {
                        setTimeout(function() { window.print(); }, 200);
                    };
                <\/script>
            </body>
            </html>
        `;

        const printWindow = window.open('', '_blank', 'width=1100,height=900');

        if (!printWindow) return;

        printWindow.document.open();
        printWindow.document.write(html);
        printWindow.document.close();
    });
</script>
@endscript
