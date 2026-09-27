<?php

use Livewire\Component;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Party;
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

    public string $invoiceNumber = '';

    public string $invoiceDate = '';

    public ?int $selectedBranchId = null;

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

    public float $taxPercent = 16.0;

    public bool $taxable = true;

    public float $paidAmount = 0.0;

    public string $paymentMethod = 'cash';

    public string $notes = '';

    /*
    |--------------------------------------------------------------------------
    | البحث والتنقل بين الفواتير
    |--------------------------------------------------------------------------
    */

    public string $searchPurchaseQuery = '';

    public bool $showPurchaseSearch = false;

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
        $this->startNewInvoice(false);
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
        $user = auth()->user();

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

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPurchasePrice = 0;
        $this->selectedRetailPrice = 0;
        $this->selectedStock = 0;
        $this->rowSearch = '';
    }

    /*
    |--------------------------------------------------------------------------
    | فاتورة جديدة
    |--------------------------------------------------------------------------
    */

    public function startNewInvoice(bool $showMessage = true): void
    {
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
        $this->taxable = true;
        $this->taxPercent = 16.0;
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

        $this->dispatch('focus-product-search');
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
            ->whereIn('type', ['supplier', 'both'])
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

    public function selectProduct(int $productId): void
    {
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

        $this->dispatch('focus-quantity');
    }

    public function commitRowToCart(): void
    {
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

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPurchasePrice = 0.0;
        $this->selectedRetailPrice = 0.0;
        $this->selectedStock = 0.0;
        $this->selectedQuantity = 1.0;
        $this->rowSearch = '';
        $this->errorMessage = null;

        $this->dispatch('focus-product-search');
    }

    public function removeItem(string $cartKey): void
    {
        if (!isset($this->cart[$cartKey])) {
            return;
        }

        unset($this->cart[$cartKey]);
    }

    public function updateItemQuantity(string $cartKey, $quantity): void
    {
        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $quantity = (float) $quantity;

        if ($quantity <= 0) {
            unset($this->cart[$cartKey]);
            return;
        }

        $this->cart[$cartKey]['quantity'] = round($quantity, 2);
    }

    public function incrementItem(string $cartKey): void
    {
        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $this->cart[$cartKey]['quantity'] = round(
            (float) $this->cart[$cartKey]['quantity'] + 1,
            2
        );
    }

    public function decrementItem(string $cartKey): void
    {
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
    }

    public function updateItemPrice(string $cartKey, $price): void
    {
        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $this->cart[$cartKey]['price'] = max(0, round((float) $price, 2));
    }

    public function updateItemDiscount(string $cartKey, $discount): void
    {
        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $this->cart[$cartKey]['discount'] = max(0, round((float) $discount, 2));
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

            $lineTotal = max(
                0,
                ($price * $quantity) - $lineDiscount
            );

            $subtotal += $lineTotal;
            $totalQuantity += $quantity;
        }

        $base = max(0, $subtotal);

        $invoiceDiscount = $this->discountType === 'percentage'
            ? min($base, $base * (max(0, $this->discount) / 100))
            : min($base, max(0, $this->discount));

        $taxableAmount = max(0, $base - $invoiceDiscount);

        $taxAmount = $this->taxable
            ? $taxableAmount * (max(0, $this->taxPercent) / 100)
            : 0.0;

        $grandTotal = $taxableAmount + $taxAmount;

        $paid = min(max(0, (float) $this->paidAmount), $grandTotal);

        $remaining = max(0, $grandTotal - $paid);

        $paymentStatus = $remaining <= 0.009
            ? 'paid'
            : ($paid > 0 ? 'partial' : 'unpaid');

        return [
            'subtotal' => round($base, 2),
            'discount' => round($invoiceDiscount, 2),
            'tax' => round($taxAmount, 2),
            'total' => round($grandTotal, 2),
            'paid' => round($paid, 2),
            'remaining' => round($remaining, 2),
            'payment_status' => $paymentStatus,
            'total_quantity' => round($totalQuantity, 2),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | حفظ الفاتورة
    |--------------------------------------------------------------------------
    */

    public function saveInvoice(bool $shouldPrint = false): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if (empty($this->cart)) {
            $this->errorMessage = 'أضف مادة واحدة على الأقل إلى الفاتورة.';
            $this->dispatch('focus-product-search');
            return;
        }

        $tenantId = $this->tenantId();

        if (!$tenantId) {
            $this->errorMessage = 'لم يتم تحديد المتجر الحالي.';
            return;
        }

        if (!$this->selectedBranchId) {
            $this->errorMessage = 'يرجى تحديد الفرع قبل حفظ الفاتورة.';
            return;
        }

        $supplier = null;

        if ($this->selectedSupplierId) {
            $supplier = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['supplier', 'both'])
                ->where('is_active', true)
                ->find($this->selectedSupplierId);

            if (!$supplier) {
                $this->errorMessage = 'المورد المحدد غير صالح.';
                return;
            }
        }

        $totals = $this->calculateTotals();

        try {
            $purchaseId = DB::transaction(function () use ($tenantId, $supplier, $totals) {

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

                    $oldSupplierId = $purchase->supplier_id;
                    $oldOutstanding = max(
                        0,
                        (float) $purchase->total - (float) $purchase->paid_amount
                    );

                    $oldItems = DB::table('purchase_items')
                        ->where('purchase_id', $purchase->id)
                        ->lockForUpdate()
                        ->get(['id', 'product_id', 'quantity']);

                    foreach ($oldItems as $oldItem) {
                        $branchProduct = DB::table('branch_products')
                            ->where('tenant_id', $tenantId)
                            ->where('branch_id', $purchase->branch_id)
                            ->where('product_id', $oldItem->product_id)
                            ->lockForUpdate()
                            ->first();

                        if (!$branchProduct) {
                            throw new \RuntimeException('تعذر العثور على مخزون أحد أصناف الفاتورة السابقة.');
                        }

                        $newStock = max(
                            0,
                            (float) $branchProduct->stock_quantity - (float) $oldItem->quantity
                        );

                        DB::table('branch_products')
                            ->where('id', $branchProduct->id)
                            ->update([
                                'stock_quantity' => $newStock,
                                'updated_at' => now(),
                            ]);
                    }

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
                            'total' => $totals['total'],
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
                        'total' => $totals['total'],
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

                foreach ($this->cart as $item) {
                    $quantity = max(0, (float) ($item['quantity'] ?? 0));
                    $unitCost = max(0, (float) ($item['price'] ?? 0));
                    $lineDiscount = max(0, (float) ($item['discount'] ?? 0));
                    $lineTotal = max(
                        0,
                        ($unitCost * $quantity) - $lineDiscount
                    );

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

                    $branchProduct = DB::table('branch_products')
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $this->selectedBranchId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$branchProduct) {
                        throw new \RuntimeException("المادة {$product->name} غير مرتبطة بالفرع الحالي.");
                    }

                    DB::table('branch_products')
                        ->where('id', $branchProduct->id)
                        ->update([
                            'stock_quantity' => (float) $branchProduct->stock_quantity + $quantity,
                            'updated_at' => now(),
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
                        $oldParty->current_balance = (float) $oldParty->current_balance - $oldOutstanding;
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
                        $supplier->current_balance = (float) $supplier->current_balance + $newOutstanding;
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

        } catch (\Throwable $e) {
            report($e);
            $this->errorMessage = 'تعذر حفظ فاتورة المشتريات. تأكد من البيانات والجداول المطلوبة ثم حاول مرة أخرى.';
        }
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
            ->where(function ($q) use ($search) {
                $q->where('reference_number', 'like', "%{$search}%");

                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });

        $purchaseId = $query
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
            ->where('id', $purchaseId)
            ->first();

        if (!$purchase) {
            $this->errorMessage = 'فاتورة المشتريات غير موجودة.';
            return;
        }

        $this->currentPurchaseId = $purchase->id;
        $this->invoiceNumber = $purchase->reference_number;
        $this->invoiceDate = $purchase->created_at
            ? \Carbon\Carbon::parse($purchase->created_at)->format('Y-m-d')
            : now()->format('Y-m-d');
        $this->selectedBranchId = $purchase->branch_id;
        $this->selectedSupplierId = $purchase->supplier_id;
        // purchases الحالية لا تخزن تفصيل الخصم/الضريبة؛ نحملها كقيم محلية فقط.
        $this->discount = 0.0;
        $this->discountType = 'fixed';
        $this->taxPercent = 0.0;
        $this->taxable = false;
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
                'discount' => max(
                    0,
                    ((float) $item->unit_cost * (float) $item->quantity) - (float) $item->subtotal
                ),
                'stock' => 0,
            ];
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
            ->where('tenant_id', $tenantId);

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

        if ($purchase->supplier_id) {
            $supplierName = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($purchase->supplier_id)
                ->value('name') ?: 'مورد عام';
        }

        $this->dispatch(
            'print-purchase-invoice',
            invoice: [
                'number' => $purchase->reference_number,
                'date' => $purchase->created_at,
                'supplier' => $supplierName,
                'notes' => null,
                'items' => $items,
                'subtotal' => array_sum(array_map(fn ($item) => (float) $item['total'], $items)),
                'discount' => 0.0,
                'tax' => 0.0,
                'total' => (float) $purchase->total,
                'paid' => (float) $purchase->paid_amount,
                'remaining' => max(0, (float) $purchase->total - (float) $purchase->paid_amount),
            ]
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

        if ($tenantId) {
            $branches = Branch::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $productResults = collect();

        if ($tenantId && trim($this->rowSearch) !== '' && !$this->selectedProductId) {
            $search = trim($this->rowSearch);

            $productResults = Product::query()
                ->where('tenant_id', $tenantId)
                ->with([
                    'branchProducts' => fn ($query) => $query->where('branch_id', $branchId),
                ])
                ->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhereHas('barcodes', function ($barcodeQuery) use ($search) {
                            $barcodeQuery->where('barcode', 'like', "%{$search}%");
                        });

                    if (is_numeric($search)) {
                        $query->orWhere('id', (int) $search);
                    }
                })
                ->orderBy('name')
                ->limit(12)
                ->get();
        }

        $supplierResults = collect();

        if ($tenantId && $this->selectedSupplierId === null) {
            $search = trim($this->supplierSearch);

            $supplierResults = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['supplier', 'both'])
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
                ->whereIn('type', ['supplier', 'both'])
                ->find($this->selectedSupplierId);
        }

        $totals = $this->calculateTotals();

        return $this->view([
            'branches' => $branches,
            'productResults' => $productResults,
            'supplierResults' => $supplierResults,
            'selectedSupplier' => $selectedSupplier,
            'subtotal' => $totals['subtotal'],
            'invoiceDiscount' => $totals['discount'],
            'taxAmount' => $totals['tax'],
            'grandTotal' => $totals['total'],
            'paidTotal' => $totals['paid'],
            'remainingTotal' => $totals['remaining'],
            'paymentStatus' => $totals['payment_status'],
            'totalQuantity' => $totals['total_quantity'],
            'userBranchLocked' => (bool) $user?->branch_id,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="min-h-[calc(100vh-4rem)] bg-slate-100 p-2 sm:p-3 md:p-4" dir="rtl">

    <div
        class="mx-auto max-w-[1700px]"
        x-data="purchaseInvoiceKeyboard()"
        x-init="init()"
    >

        {{-- شريط الأدوات --}}
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
                    <button type="button" wire:click="saveAndPrint" class="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-black transition hover:bg-indigo-500">💾 حفظ وطباعة</button>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-200 bg-slate-50 px-3 py-2 text-[10px] font-black text-slate-500">
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">↑ ↓</kbd> تنقل</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">← →</kbd> الخانات</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">F2</kbd> جديدة</span>
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
                <div class="relative lg:col-span-4">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">المورد</label>
                    @if ($selectedSupplier)
                        <div class="flex items-center justify-between rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2.5">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-black text-indigo-900">{{ $selectedSupplier->name }}</div>
                                <div class="mt-0.5 text-[11px] font-semibold text-indigo-700">{{ $selectedSupplier->phone ?: 'بدون هاتف' }}</div>
                            </div>
                            <button type="button" wire:click="clearSupplier" class="rounded-lg px-2 py-1 text-xs font-black text-indigo-700 hover:bg-indigo-100">تغيير</button>
                        </div>
                    @else
                        <input id="supplier-search-input" x-ref="supplierSearch" type="text" wire:model.live.debounce.180ms="supplierSearch" placeholder="ابحث باسم المورد أو الهاتف..." autocomplete="off" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:border-slate-400 focus:bg-white" />
                        @if ($supplierSearch !== '' || count($supplierResults) > 0)
                            <div class="absolute right-0 top-full z-40 mt-1 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                                <div class="max-h-64 overflow-y-auto p-1.5" x-ref="supplierResults">
                                    @forelse ($supplierResults as $supplier)
                                        <button type="button" data-supplier-result data-result-index="{{ $loop->index }}" wire:click="selectSupplier({{ $supplier->id }})" class="group flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-right transition hover:bg-slate-50">
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

                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">الفرع</label>
                    <select wire:model.live="selectedBranchId" @disabled($userBranchLocked) class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700 outline-none focus:border-slate-400 focus:bg-white disabled:cursor-not-allowed disabled:opacity-60">
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>

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
            <div class="min-w-0 xl:col-span-8">
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

                    <div class="border-b border-slate-200 bg-slate-50 p-3">
                        <div class="relative">
                            <div class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-slate-400">🔎</div>
                            <input
                                id="product-search-input"
                                x-ref="productSearch"
                                type="text"
                                wire:model.live.debounce.160ms="rowSearch"
                                wire:keydown.enter="commitRowToCart"
                                placeholder="ابحث عن مادة أو امسح الباركود ثم Enter..."
                                autocomplete="off"
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 pr-10 text-sm font-black text-slate-900 outline-none focus:border-indigo-400"
                            />

                            @if ($rowSearch !== '' && !$selectedProductId)
                                <div class="absolute right-0 top-full z-50 mt-1 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                                    <div class="max-h-80 overflow-y-auto p-1.5" x-ref="productResults">
                                        @forelse ($productResults as $product)
                                            @php($branchProduct = $product->branchProducts->first())
                                            <button type="button" data-product-result data-result-index="{{ $loop->index }}" wire:click="selectProduct({{ $product->id }})" class="group flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-right hover:bg-slate-50">
                                                <div class="min-w-0">
                                                    <div class="truncate text-sm font-black text-slate-800">{{ $product->name }}</div>
                                                    <div class="mt-1 flex flex-wrap gap-2 text-[10px] font-bold text-slate-400">
                                                        <span>تكلفة: {{ number_format((float) $product->cost_price, 2) }}</span>
                                                        <span>بيع: {{ number_format((float) ($branchProduct?->retail_price ?? 0), 2) }}</span>
                                                    </div>
                                                </div>
                                                <div class="text-left">
                                                    <div class="font-mono text-xs font-black text-indigo-700">{{ number_format((float) ($branchProduct?->stock_quantity ?? 0), 2) }}</div>
                                                    <div class="text-[10px] font-bold text-slate-400">الرصيد الحالي</div>
                                                </div>
                                            </button>
                                        @empty
                                            <div class="px-3 py-6 text-center text-xs font-bold text-slate-400">لا توجد مادة مطابقة.</div>
                                        @endforelse
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    @if ($selectedProductId)
                        <div class="border-b border-indigo-100 bg-indigo-50/50 p-3">
                            <div class="grid items-end gap-2 sm:grid-cols-2 lg:grid-cols-12">
                                <div class="lg:col-span-4">
                                    <label class="mb-1 block text-[10px] font-black text-indigo-700">المادة المحددة</label>
                                    <div class="rounded-xl border border-indigo-200 bg-white px-3 py-2.5 text-sm font-black text-slate-900">{{ $selectedProductName }}</div>
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="mb-1 block text-[10px] font-black text-indigo-700">الكمية</label>
                                    <input id="quantity-input" x-ref="quantity" type="number" min="0.01" step="0.01" wire:model="selectedQuantity" wire:keydown.enter="commitRowToCart" class="w-full rounded-xl border border-indigo-200 bg-white px-3 py-2.5 text-sm font-black outline-none focus:border-indigo-400" />
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="mb-1 block text-[10px] font-black text-indigo-700">سعر التكلفة</label>
                                    <input type="number" min="0" step="0.01" wire:model.live="selectedPurchasePrice" wire:keydown.enter="commitRowToCart" class="w-full rounded-xl border border-indigo-200 bg-white px-3 py-2.5 text-sm font-black outline-none focus:border-indigo-400" />
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="mb-1 block text-[10px] font-black text-indigo-700">المخزون قبل</label>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-black text-slate-700">{{ number_format($selectedStock, 2) }}</div>
                                </div>
                                <div class="lg:col-span-2">
                                    <button type="button" wire:click="commitRowToCart" class="w-full rounded-xl bg-indigo-600 px-3 py-2.5 text-sm font-black text-white hover:bg-indigo-500">+ إضافة</button>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- جدول المواد --}}
                    <div class="hidden md:block">
                        <div class="max-h-[52vh] overflow-auto">
                            <table class="w-full min-w-[850px] text-right">
                                <thead class="sticky top-0 z-20 border-b border-slate-200 bg-slate-50">
                                    <tr class="text-[11px] font-black text-slate-500">
                                        <th class="w-12 px-3 py-3">#</th>
                                        <th class="px-3 py-3">المادة</th>
                                        <th class="w-28 px-3 py-3">الكمية</th>
                                        <th class="w-32 px-3 py-3">التكلفة</th>
                                        <th class="w-28 px-3 py-3">الخصم</th>
                                        <th class="w-36 px-3 py-3">الإجمالي</th>
                                        <th class="w-20 px-3 py-3"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    @forelse ($cart as $key => $item)
                                        @php($lineTotal = max(0, ((float) $item['price'] * (float) $item['quantity']) - (float) ($item['discount'] ?? 0)))
                                        <tr
                                            wire:key="purchase-item-{{ $key }}"
                                            data-cart-row="{{ $loop->index }}"
                                            class="transition {{ $loop->first ? 'bg-indigo-50/20' : '' }}"
                                        >
                                            <td class="px-3 py-3 text-xs font-black text-slate-400">{{ $loop->iteration }}</td>
                                            <td class="px-3 py-3">
                                                <div class="text-sm font-black text-slate-800">{{ $item['name'] }}</div>
                                                @if(isset($item['stock']))
                                                    <div class="mt-1 text-[10px] font-semibold text-slate-400">المخزون الحالي: {{ number_format((float) $item['stock'], 2) }}</div>
                                                @endif
                                            </td>
                                            <td class="px-3 py-3">
                                                <div class="flex items-center gap-1">
                                                    <button type="button" wire:click="decrementItem('{{ $key }}')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white font-black text-slate-600 hover:bg-slate-50">−</button>
                                                    <input type="number" min="0.01" step="0.01" wire:model.live="cart.{{ $key }}.quantity" class="w-20 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-xs font-black outline-none focus:border-indigo-400" />
                                                    <button type="button" wire:click="incrementItem('{{ $key }}')" class="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200 bg-white font-black text-slate-600 hover:bg-slate-50">+</button>
                                                </div>
                                            </td>
                                            <td class="px-3 py-3">
                                                <input type="number" min="0" step="0.01" wire:model.live="cart.{{ $key }}.price" class="w-28 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-xs font-black outline-none focus:border-indigo-400" />
                                            </td>
                                            <td class="px-3 py-3">
                                                <input type="number" min="0" step="0.01" wire:model.live="cart.{{ $key }}.discount" class="w-24 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-xs font-black outline-none focus:border-indigo-400" />
                                            </td>
                                            <td class="px-3 py-3 text-sm font-black text-slate-900">{{ number_format($lineTotal, 2) }}</td>
                                            <td class="px-3 py-3 text-center">
                                                <button type="button" wire:click="removeItem('{{ $key }}')" class="rounded-lg px-2 py-1.5 text-xs font-black text-rose-600 hover:bg-rose-50">حذف</button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="px-6 py-16 text-center">
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

                    {{-- Mobile --}}
                    <div class="divide-y divide-slate-100 md:hidden">
                        @forelse ($cart as $key => $item)
                            @php($lineTotal = max(0, ((float) $item['price'] * (float) $item['quantity']) - (float) ($item['discount'] ?? 0)))
                            <div wire:key="purchase-mobile-item-{{ $key }}" class="p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-sm font-black text-slate-800">{{ $loop->iteration }}. {{ $item['name'] }}</div>
                                        <div class="mt-1 text-xs font-semibold text-slate-400">الإجمالي: {{ number_format($lineTotal, 2) }}</div>
                                    </div>
                                    <button type="button" wire:click="removeItem('{{ $key }}')" class="text-xs font-black text-rose-600">حذف</button>
                                </div>
                                <div class="mt-3 grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="mb-1 block text-[10px] font-black text-slate-400">الكمية</label>
                                        <input type="number" min="0.01" step="0.01" wire:model.live="cart.{{ $key }}.quantity" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-xs font-black" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[10px] font-black text-slate-400">التكلفة</label>
                                        <input type="number" min="0" step="0.01" wire:model.live="cart.{{ $key }}.price" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-xs font-black" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-[10px] font-black text-slate-400">الخصم</label>
                                        <input type="number" min="0" step="0.01" wire:model.live="cart.{{ $key }}.discount" class="w-full rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-xs font-black" />
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
            <div class="xl:col-span-4">
                <div class="sticky top-3 space-y-3">
                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <div class="text-sm font-black text-slate-900">ملخص الفاتورة</div>
                                <div class="mt-1 text-[11px] font-semibold text-slate-400">راجع الكمية والتكلفة قبل الحفظ</div>
                            </div>
                            <div class="rounded-xl bg-indigo-50 px-3 py-2 text-center">
                                <div class="text-[10px] font-bold text-indigo-500">الكمية</div>
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
                                    <label class="mb-1 block text-[10px] font-black text-slate-400">خصم الفاتورة</label>
                                    <input type="number" min="0" step="0.01" wire:model.live="discount" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-black outline-none focus:border-indigo-400" />
                                </div>
                                <div class="col-span-5">
                                    <label class="mb-1 block text-[10px] font-black text-slate-400">النوع</label>
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

                            <div class="flex items-center justify-between text-sm">
                                <span class="font-bold text-slate-500">الضريبة</span>
                                <div class="flex items-center gap-2">
                                    <label class="inline-flex items-center gap-1.5 text-[10px] font-black text-slate-500">
                                        <input type="checkbox" wire:model.live="taxable" class="rounded border-slate-300 text-indigo-600" />
                                        مفعلة
                                    </label>
                                    @if ($taxable)
                                        <input type="number" min="0" step="0.01" wire:model.live="taxPercent" class="w-20 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1.5 text-xs font-black" />
                                        <span class="text-[10px] font-black text-slate-400">%</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center justify-between text-sm">
                                <span class="font-bold text-slate-500">قيمة الضريبة</span>
                                <span class="font-black text-slate-900">{{ number_format($taxAmount, 2) }}</span>
                            </div>

                            <div class="border-t border-slate-200 pt-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-base font-black text-slate-900">الإجمالي</span>
                                    <span class="text-3xl font-black text-indigo-700">{{ number_format($grandTotal, 2) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <div class="mb-3 text-sm font-black text-slate-900">الدفع</div>

                        <div class="grid grid-cols-2 gap-2">
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                                <div class="text-[10px] font-black text-emerald-700">المدفوع</div>
                                <div class="mt-1 text-lg font-black text-emerald-800">{{ number_format($paidTotal, 2) }}</div>
                            </div>
                            <div class="rounded-xl border border-rose-200 bg-rose-50 p-3">
                                <div class="text-[10px] font-black text-rose-700">المتبقي</div>
                                <div class="mt-1 text-lg font-black text-rose-800">{{ number_format($remainingTotal, 2) }}</div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="mb-1 block text-xs font-black text-slate-500">المبلغ المدفوع</label>
                            <input id="paid-amount-input" x-ref="paidAmount" type="number" min="0" step="0.01" wire:model.live="paidAmount" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-lg font-black text-slate-900 outline-none focus:border-emerald-400 focus:bg-white" />
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <button type="button" wire:click="$set('paidAmount', {{ $grandTotal }})" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-xs font-black text-emerald-700 hover:bg-emerald-100">دفع كامل</button>
                            <button type="button" wire:click="$set('paidAmount', 0)" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-xs font-black text-slate-700 hover:bg-slate-100">آجل</button>
                        </div>

                        <div class="mt-3">
                            <label class="mb-1 block text-xs font-black text-slate-500">طريقة الدفع</label>
                            <select wire:model="paymentMethod" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black outline-none focus:border-indigo-400">
                                <option value="cash">نقدي</option>
                                <option value="card">بطاقة</option>
                                <option value="bank">تحويل بنكي</option>
                                <option value="other">أخرى</option>
                            </select>
                        </div>

                        <div class="mt-3 flex items-center justify-between rounded-xl border px-3 py-2.5 {{ $paymentStatus === 'paid' ? 'border-emerald-200 bg-emerald-50' : ($paymentStatus === 'partial' ? 'border-amber-200 bg-amber-50' : 'border-rose-200 bg-rose-50') }}">
                            <span class="text-xs font-black text-slate-600">الحالة</span>
                            <span class="text-xs font-black {{ $paymentStatus === 'paid' ? 'text-emerald-700' : ($paymentStatus === 'partial' ? 'text-amber-700' : 'text-rose-700') }}">
                                {{ $paymentStatus === 'paid' ? 'مدفوعة' : ($paymentStatus === 'partial' ? 'جزئية' : 'آجلة') }}
                            </span>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                        <label class="mb-1.5 block text-xs font-black text-slate-600">ملاحظات</label>
                        <textarea wire:model="notes" rows="3" placeholder="ملاحظة الفاتورة..." class="w-full resize-none rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold outline-none focus:border-indigo-400 focus:bg-white"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="saveInvoice" wire:loading.attr="disabled" class="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-indigo-500 disabled:opacity-60">
                            <span wire:loading.remove wire:target="saveInvoice">💾 حفظ</span>
                            <span wire:loading wire:target="saveInvoice">جاري الحفظ...</span>
                        </button>
                        <button type="button" wire:click="saveAndPrint" wire:loading.attr="disabled" class="rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white shadow-sm transition hover:bg-slate-800 disabled:opacity-60">
                            <span wire:loading.remove wire:target="saveAndPrint">🖨 حفظ وطباعة</span>
                            <span wire:loading wire:target="saveAndPrint">جاري الحفظ...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</flux:main>

@script
<script>
    Alpine.data('purchaseInvoiceKeyboard', () => ({
        supplierIndex: -1,
        productIndex: -1,
        rowIndex: 0,

        init() {
            this.registerKeyboard();

            Livewire.on('focus-product-search', () => {
                this.$nextTick(() => this.$refs.productSearch?.focus());
            });

            Livewire.on('focus-supplier-search', () => {
                this.$nextTick(() => this.$refs.supplierSearch?.focus());
            });

            Livewire.on('focus-quantity', () => {
                this.$nextTick(() => this.$refs.quantity?.focus());
            });

            Livewire.on('focus-paid-amount', () => {
                this.$nextTick(() => this.$refs.paidAmount?.focus());
            });

            Livewire.on('focus-purchase-search', () => {
                this.$nextTick(() => this.$refs.purchaseSearch?.focus());
            });
        },

        registerKeyboard() {
            window.addEventListener('keydown', (event) => {
                const target = event.target;

                const isTextField =
                    target instanceof HTMLInputElement ||
                    target instanceof HTMLTextAreaElement ||
                    target instanceof HTMLSelectElement ||
                    target?.isContentEditable;

                const key = event.key;

                if (key === 'F2') {
                    event.preventDefault();
                    this.$wire.startNewInvoice();
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
                    this.$nextTick(() => this.$refs.supplierSearch?.focus());
                    return;
                }

                if (key === 'F9') {
                    event.preventDefault();
                    this.$nextTick(() => this.$refs.productSearch?.focus());
                    return;
                }

                if (key === 'Escape') {
                    const supplierBox = this.$refs.supplierResults;
                    const productBox = this.$refs.productResults;

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
                        if (target === this.$refs.supplierSearch) {
                            event.preventDefault();
                            this.moveSupplier(key === 'ArrowDown' ? 1 : -1);
                            return;
                        }

                        if (target === this.$refs.productSearch) {
                            event.preventDefault();
                            this.moveProduct(key === 'ArrowDown' ? 1 : -1);
                            return;
                        }
                    }

                    if (key === 'Enter') {
                        if (target === this.$refs.supplierSearch) {
                            event.preventDefault();
                            this.selectActiveSupplier();
                            return;
                        }

                        if (target === this.$refs.productSearch) {
                            event.preventDefault();
                            const active = document.querySelector('[data-product-result][data-result-index="' + this.productIndex + '"]');

                            if (active) {
                                active.click();
                            } else {
                                this.$wire.commitRowToCart();
                            }

                            return;
                        }
                    }

                    return;
                }

                if (key === 'ArrowDown') {
                    event.preventDefault();
                    this.$wire.nextInvoice();
                    return;
                }

                if (key === 'ArrowUp') {
                    event.preventDefault();
                    this.$wire.previousInvoice();
                    return;
                }

                if (key === 'ArrowRight') {
                    event.preventDefault();
                    this.$wire.nextInvoice();
                    return;
                }

                if (key === 'ArrowLeft') {
                    event.preventDefault();
                    this.$wire.previousInvoice();
                    return;
                }
            });
        },

        moveSupplier(direction) {
            const nodes = [...document.querySelectorAll('[data-supplier-result]')];
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
            const nodes = [...document.querySelectorAll('[data-product-result]')];
            if (!nodes.length) return;

            this.productIndex += direction;

            if (this.productIndex < 0) this.productIndex = nodes.length - 1;
            if (this.productIndex >= nodes.length) this.productIndex = 0;

            nodes.forEach((node, index) => {
                node.classList.toggle('bg-indigo-50', index === this.productIndex);
                node.classList.toggle('ring-1', index === this.productIndex);
                node.classList.toggle('ring-indigo-200', index === this.productIndex);
            });

            nodes[this.productIndex]?.scrollIntoView({ block: 'nearest' });
        },

        selectActiveSupplier() {
            const active = document.querySelector('[data-supplier-result][data-result-index="' + this.supplierIndex + '"]');

            if (active) {
                active.click();
                return;
            }

            const first = document.querySelector('[data-supplier-result]');
            first?.click();
        },
    }));

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
                    <div class="line"><span>الضريبة</span><strong>${money(invoice.tax)}</strong></div>
                    <div class="line grand"><span>الإجمالي</span><strong>${money(invoice.total)}</strong></div>
                    <div class="line"><span>المدفوع</span><strong>${money(invoice.paid)}</strong></div>
                    <div class="line"><span>المتبقي</span><strong>${money(invoice.remaining)}</strong></div>
                </div>

                ${invoice.notes ? `<div class="notes"><strong>ملاحظات:</strong> ${esc(invoice.notes)}</div>` : ''}

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
