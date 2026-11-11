<?php

use Livewire\Component;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\Product;
use App\Services\PosProductSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new class extends Component {

    /*
    |--------------------------------------------------------------------------
    | الفاتورة
    |--------------------------------------------------------------------------
    */

    public ?int $currentOrderId = null;

    #[\Livewire\Attributes\Locked]
    public string $currentOrderType = '';

    #[\Livewire\Attributes\Locked]
    public float $posDeliveryFee = 0.0;

    #[\Livewire\Attributes\Locked]
    public float $voucherPaidAmount = 0.0;

    #[\Livewire\Attributes\Locked]
    public ?int $posShiftId = null;

    public bool $invoiceEditMode = true;

    #[\Livewire\Attributes\Locked]
    public float $savedPaidAmount = 0.0;

    public float $paidAmount = 0.0;

    public string $paymentMethod = 'cash';

    public string $settlementAmount = '';

    public string $settlementNotes = '';

    public string $settlementMethod = 'cash';

    public bool $showSettlementModal = false;

    public string $invoiceNumber = '';

    public string $invoiceDate = '';

    public ?int $selectedBranchId = null;

    public ?int $invoiceBranchFilter = null;

    public ?int $selectedCustomerId = null;

    public bool $showCustomerStatementModal = false;

    public array $customerStatementRows = [];

    public float $customerStatementOpeningBalance = 0.0;

    public float $customerStatementCurrentBalance = 0.0;

    public float $customerStatementCalculatedBalance = 0.0;

    public string $customerSearch = '';

    public string $rowSearch = '';

    public ?int $selectedProductId = null;

    #[\Livewire\Attributes\Locked]
    public ?string $editingCartKey = null;

    #[\Livewire\Attributes\Locked]
    public ?string $replacingCartKey = null;

    public string $replacementSearch = '';

    public string $selectedProductName = '';

    public float $selectedPrice = 0.0;

    public float $selectedCost = 0.0;

    public float $selectedStock = 0.0;

    public float $selectedQuantity = 1.0;

    public float $selectedDiscount = 0.0;

    public array $cart = [];

    public float $discount = 0.0;

    public float $taxPercent = 16.0;

    public bool $taxable = true;

    public string $notes = '';

    /*
    |--------------------------------------------------------------------------
    | البحث عن الفواتير
    |--------------------------------------------------------------------------
    */

    public string $searchInvoiceQuery = '';

    public bool $showInvoiceSearch = false;

    /*
    |--------------------------------------------------------------------------
    | رسائل
    |--------------------------------------------------------------------------
    */

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public ?int $lastSavedOrderId = null;

    public ?int $lastSavedCustomerId = null;

    public string $lastSavedInvoiceNumber = '';

    public float $lastSavedReceiptAmount = 0.0;

    /*
    |--------------------------------------------------------------------------
    | Mount
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $this->invoiceDate = now()->format('Y-m-d');
        $this->selectedBranchId = $this->resolveBranchId();
        $this->invoiceBranchFilter = (auth()->user()?->isTenantOwner() || auth()->user()?->isSaaSAdmin()) ? null : $this->selectedBranchId;
        $this->startNewInvoice(false);

        $invoiceId = request()->integer('invoice');
        if ($invoiceId > 0) {
            $order = Order::query()->where('tenant_id', $this->tenantId())->find($invoiceId);
            if ($order) {
                $this->loadOrder($order);
            }
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
        $this->selectedPrice = 0;
        $this->selectedCost = 0;
        $this->selectedStock = 0;
        $this->rowSearch = '';
    }

    public function updatedInvoiceBranchFilter($value): void
    {
        $user = auth()->user();
        if (! $user?->isTenantOwner() && ! $user?->isSaaSAdmin()) {
            $this->invoiceBranchFilter = $user?->activeBranchId();
            return;
        }

        $branchId = $value === '' || $value === null ? null : (int) $value;
        if ($branchId && ! Branch::query()->where('tenant_id', $this->tenantId())->whereKey($branchId)->exists()) {
            $this->invoiceBranchFilter = null;
            $this->errorMessage = 'الفرع المحدد غير صالح لهذا المتجر.';
            return;
        }

        $this->invoiceBranchFilter = $branchId;
    }

    private function invoiceBranchScopeId(): ?int
    {
        $user = auth()->user();
        return $user?->isTenantOwner() || $user?->isSaaSAdmin()
            ? $this->invoiceBranchFilter
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
        $this->savedPaidAmount = 0.0;
        $this->paidAmount = 0.0;
        $this->paymentMethod = 'cash';
        $this->currentOrderId = null;
        $this->currentOrderType = '';
        $this->posDeliveryFee = 0.0;
        $this->voucherPaidAmount = 0.0;
        $this->posShiftId = null;
        $this->replacingCartKey = null;
        $this->replacementSearch = '';
        $this->invoiceNumber = $this->makeInvoiceNumber();
        $this->invoiceDate = now()->format('Y-m-d');

        $this->selectedCustomerId = null;
        $this->customerSearch = '';

        $this->rowSearch = '';
        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPrice = 0.0;
        $this->selectedCost = 0.0;
        $this->selectedStock = 0.0;
        $this->selectedQuantity = 1.0;
        $this->selectedDiscount = 0.0;
        $this->editingCartKey = null;

        $this->cart = [];
        $this->discount = 0.0;
        $this->taxable = true;
        $this->taxPercent = 16.0;
        $this->notes = '';
        $this->searchInvoiceQuery = '';
        $this->showInvoiceSearch = false;

        $this->errorMessage = null;

        if ($showMessage) {
            $this->successMessage = 'تم فتح فاتورة مبيعات جديدة.';
        } else {
            $this->successMessage = null;
        }

        $this->dispatch('focus-customer-search');
    }

    private function makeInvoiceNumber(): string
    {
        return 'SAL-' . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    /*
    |--------------------------------------------------------------------------
    | العميل
    |--------------------------------------------------------------------------
    */

    public function selectCustomer(int $customerId): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            $this->errorMessage = 'لم يتم تحديد المتجر الحالي.';
            return;
        }

        $customer = Party::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['customer', 'supplier', 'both'])
            ->where('is_active', true)
            ->find($customerId);

        if (!$customer) {
            $this->errorMessage = 'العميل المحدد غير صالح.';
            return;
        }

        $this->selectedCustomerId = $customer->id;
        $this->customerSearch = $customer->name;
        $this->errorMessage = null;

        $this->dispatch('focus-product-search');
    }

    public function applyLastCustomerPrice(?string $cartKey = null): ?float
    {
        if (! $this->ensureInvoiceEditable()) {
            return null;
        }

        $tenantId = $this->tenantId();
        if (! $tenantId || ! $this->selectedCustomerId) {
            $this->errorMessage = 'اختر الزبون أولاً لاستخدام آخر سعر بيع له.';

            return null;
        }

        $productId = $cartKey !== null
            ? ($this->cart[$cartKey]['id'] ?? null)
            : $this->selectedProductId;

        if (! $productId || ! Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->exists()) {
            $this->errorMessage = 'اختر صنفاً صالحاً أولاً.';

            return null;
        }

        $lastPrice = DB::table('order_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->where('items.tenant_id', $tenantId)
            ->where('items.product_id', $productId)
            ->where('orders.customer_id', $this->selectedCustomerId)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', 'completed')
            ->where('orders.type', '!=', 'return')
            ->where('items.quantity', '>', 0)
            ->orderByDesc('orders.created_at')
            ->orderByDesc('items.id')
            ->value('items.unit_price');

        if ($lastPrice === null) {
            $this->errorMessage = 'لا يوجد سعر بيع سابق لهذا الصنف عند هذا الزبون.';

            return null;
        }

        $price = (float) $lastPrice;
        if ($cartKey !== null) {
            $this->cart[$cartKey]['price'] = $price;
            unset($this->cart[$cartKey]['total_override']);
        } else {
            $this->selectedPrice = $price;
        }

        $this->errorMessage = null;

        return $price;
    }

    public function clearCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->customerSearch = '';
        $this->dispatch('focus-customer-search');
    }

    public function openCustomerStatement(): void
    {
        abort_unless(auth()->user()?->can('reports.analytics') && auth()->user()?->can('customers.view'), 403);

        $tenantId = $this->tenantId();
        abort_unless($tenantId && $this->selectedCustomerId, 404);

        $party = Party::query()->where('tenant_id', $tenantId)
            ->whereIn('type', ['customer', 'both'])->findOrFail($this->selectedCustomerId);
        $this->customerStatementOpeningBalance = (float) $party->opening_balance;
        $this->customerStatementCurrentBalance = (float) $party->current_balance;
        $runningBalance = $this->customerStatementOpeningBalance;
        $transactions = collect();

        Order::query()->where('tenant_id', $tenantId)->where('customer_id', $party->id)
            ->get(['id', 'type', 'total', 'delivery_fee', 'invoice_number', 'invoice_date', 'created_at'])
            ->each(function (Order $order) use ($transactions): void {
                $isReturn = $order->type === 'return';
                $amount = max(0, abs((float) $order->total) - (float) $order->delivery_fee);
                $transactions->push([
                    'date' => $order->invoice_date ?? $order->created_at,
                    'description' => ($isReturn ? 'مردود مبيعات #' : 'فاتورة مبيعات #').($order->invoice_number ?: $order->id),
                    'debit' => $isReturn ? 0.0 : $amount,
                    'credit' => $isReturn ? $amount : 0.0,
                ]);
            });

        Payment::query()->where('tenant_id', $tenantId)->where('party_id', $party->id)
            ->get(['id', 'type', 'amount', 'order_id', 'payment_date', 'created_at', 'notes'])
            ->each(function (Payment $payment) use ($transactions): void {
                $isRefund = $payment->type === 'payment';
                $description = $isRefund ? 'مبلغ مردود للعميل' : 'سند قبض';
                if ($payment->order_id) {
                    $description .= ' للفاتورة #'.$payment->order_id;
                }
                if ($payment->notes) {
                    $description .= ' ('.$payment->notes.')';
                }
                $transactions->push([
                    'date' => $payment->payment_date ?: $payment->created_at,
                    'description' => $description,
                    'debit' => $isRefund ? abs((float) $payment->amount) : 0.0,
                    'credit' => $isRefund ? 0.0 : abs((float) $payment->amount),
                ]);
            });

        $this->customerStatementRows = $transactions->sortBy(fn (array $transaction): int => \Illuminate\Support\Carbon::parse($transaction['date'])->timestamp)
            ->values()->map(function (array $transaction) use (&$runningBalance): array {
                $runningBalance += $transaction['debit'] - $transaction['credit'];
                $transaction['balance'] = $runningBalance;

                return $transaction;
            })->all();
        $this->customerStatementCalculatedBalance = $runningBalance;
        $this->showCustomerStatementModal = true;
    }

    public function closeCustomerStatement(): void
    {
        $this->showCustomerStatementModal = false;
        $this->customerStatementRows = [];
    }

    /*
    |--------------------------------------------------------------------------
    | المنتج
    |--------------------------------------------------------------------------
    */

    private function ensureInvoiceEditable(): bool
    {
        if ($this->currentOrderId && ! $this->invoiceEditMode) {
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
            $this->errorMessage = 'المنتج المحدد غير متاح.';
            return;
        }

        $branchProduct = $product->branchProducts->first();

        $this->selectedProductId = $product->id;
        $this->selectedProductName = $product->name;
        $this->selectedCost = (float) $product->cost_price;
        $this->selectedStock = (float) ($branchProduct?->stock_quantity ?? 0);

        if ($branchProduct) {
            $this->selectedPrice = $this->currentOrderType === 'pos'
                ? (float) $branchProduct->retail_price
                : (float) ($branchProduct->wholesale_price > 0 ? $branchProduct->wholesale_price : $branchProduct->retail_price);
        } else {
            $this->selectedPrice = 0.0;
        }

        $this->selectedQuantity = $this->editingCartKey !== null
            ? (float) ($this->cart[$this->editingCartKey]['quantity'] ?? 1)
            : 1.0;
        $this->selectedDiscount = $this->editingCartKey !== null
            ? (float) ($this->cart[$this->editingCartKey]['discount'] ?? 0)
            : 0.0;
        $this->rowSearch = $product->name;
        $this->errorMessage = null;

        $this->dispatch('focus-quantity');
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

    public function updatedRowSearch(string $value): void
    {
        if (
            trim($value) !== ''
            && (! $this->currentOrderId || $this->invoiceEditMode)
            && $this->selectProductByBarcode($value)
        ) {
            return;
        }

        if ($this->selectedProductId && trim($value) !== $this->selectedProductName) {
            $this->selectedProductId = null;
            $this->selectedProductName = '';
            $this->selectedPrice = 0;
            $this->selectedCost = 0;
            $this->selectedStock = 0;
            $this->selectedQuantity = 1;
            $this->selectedDiscount = 0.0;
        }
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

        if ($this->selectedPrice < 0) {
            $this->errorMessage = 'سعر البيع غير صالح.';
            return;
        }

        if ($this->selectedQuantity <= 0) {
            $this->errorMessage = 'الكمية يجب أن تكون أكبر من صفر.';
            return;
        }

        if ($this->selectedDiscount < 0) {
            $this->errorMessage = 'الخصم يجب ألا يكون سالباً.';
            return;
        }

        if ($this->editingCartKey !== null && ! isset($this->cart[$this->editingCartKey])) {
            $this->errorMessage = 'تعذر العثور على سطر الفاتورة المراد تعديله.';
            $this->editingCartKey = null;
            return;
        }

        $cartKey = $this->editingCartKey ?? ($this->selectedProductId . '-' . Str::uuid()->toString());

        $this->cart[$cartKey] = [
            'id' => $this->selectedProductId,
            'name' => $this->selectedProductName,
            'price' => round((float) $this->selectedPrice, 2),
            'cost' => round((float) $this->selectedCost, 2),
            'quantity' => round((float) $this->selectedQuantity, 2),
            'discount' => round(min((float) $this->selectedDiscount, (float) $this->selectedPrice * (float) $this->selectedQuantity), 2),
            'stock' => round((float) $this->selectedStock, 2),
        ];

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPrice = 0.0;
        $this->selectedCost = 0.0;
        $this->selectedStock = 0.0;
        $this->selectedQuantity = 1.0;
        $this->selectedDiscount = 0.0;
        $this->rowSearch = '';
        $this->editingCartKey = null;
        $this->replacingCartKey = null;
        $this->replacementSearch = '';
        $this->errorMessage = null;

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

        if ($this->replacingCartKey === $cartKey) {
            $this->cancelInlineReplacement();
        }

        if ($this->editingCartKey === $cartKey) {
            $this->cancelCartItemEdit();
        }
    }

    public function openInlineReplacement(string $cartKey): void
    {
        if (! isset($this->cart[$cartKey])) {
            return;
        }

        if ($this->currentOrderId && ! $this->invoiceEditMode) {
            $this->editLoadedInvoice();
        }

        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        $this->replacingCartKey = $cartKey;
        $this->replacementSearch = '';
        $this->errorMessage = null;
    }

    public function replaceCartItem(int $productId): void
    {
        $cartKey = $this->replacingCartKey;
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if ($cartKey === null || ! isset($this->cart[$cartKey])) {
            $this->errorMessage = 'اختر سطر الفاتورة الذي تريد استبداله أولاً.';
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->selectedBranchId ?: $this->resolveBranchId();
        if (! $tenantId || ! $branchId) {
            $this->errorMessage = 'لم يتم تحديد المتجر أو الفرع الحالي.';
            return;
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->with(['branchProducts' => fn ($query) => $query->where('branch_id', $branchId)])
            ->find($productId);

        $branchProduct = $product?->branchProducts->first();
        if (! $product || ! $branchProduct) {
            $this->errorMessage = 'الصنف المحدد غير مرتبط بفرع الفاتورة.';
            return;
        }

        $price = $this->currentOrderType === 'pos'
            ? (float) $branchProduct->retail_price
            : (float) ($branchProduct->wholesale_price > 0 ? $branchProduct->wholesale_price : $branchProduct->retail_price);

        foreach (['total_override', 'is_gift', 'offer_id', 'offer_quantity', 'offer_price', 'normal_subtotal', 'promotion_savings'] as $field) {
            unset($this->cart[$cartKey][$field]);
        }

        $this->cart[$cartKey] = [
            ...$this->cart[$cartKey],
            'id' => (int) $product->id,
            'name' => $product->name,
            'price' => round($price, 2),
            'cost' => round((float) $product->cost_price, 2),
            'discount' => 0.0,
            'stock' => round((float) $branchProduct->stock_quantity, 2),
        ];

        $this->replacingCartKey = null;
        $this->replacementSearch = '';
        $this->successMessage = "تم استبدال الصنف بـ {$product->name}. راجع الفاتورة ثم احفظ التعديل.";
        $this->errorMessage = null;
        $this->dispatch('toast', type: 'success', message: $this->successMessage);
        $this->dispatch('focus-product-search');
    }

    public function cancelInlineReplacement(): void
    {
        $this->replacingCartKey = null;
        $this->replacementSearch = '';
    }

    public function cancelCartItemEdit(): void
    {
        $this->editingCartKey = null;
        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPrice = 0.0;
        $this->selectedCost = 0.0;
        $this->selectedStock = 0.0;
        $this->selectedQuantity = 1.0;
        $this->selectedDiscount = 0.0;
        $this->rowSearch = '';
        $this->dispatch('focus-product-search');
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

        $this->cart[$cartKey]['price'] = max(0, round((float) $price, 2));
        unset($this->cart[$cartKey]['total_override']);
    }

    public function updateItemTotal(string $cartKey, mixed $total): void
    {
        if (! $this->ensureInvoiceEditable()) {
            return;
        }

        if (!isset($this->cart[$cartKey])) {
            return;
        }

        $quantity = (float) $this->cart[$cartKey]['quantity'];
        if (! is_numeric($total) || ! is_finite((float) $total) || (float) $total < 0 || ! is_finite($quantity) || $quantity <= 0) {
            return;
        }

        $lineTotal = round((float) $total, 2);
        $discount = max(0, (float) ($this->cart[$cartKey]['discount'] ?? 0));
        $this->updateItemPrice($cartKey, ($lineTotal + $discount) / $quantity);
        $this->cart[$cartKey]['total_override'] = $lineTotal;
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
    | حسابات الفاتورة
    |--------------------------------------------------------------------------
    */

    private function calculateTotals(): array
    {
        $subtotal = 0.0;
        $totalCost = 0.0;

        foreach ($this->cart as $item) {
            $price = (float) ($item['price'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 0);
            $lineDiscount = (float) ($item['discount'] ?? 0);
            $cost = (float) ($item['cost'] ?? 0);

            $lineTotal = isset($item['total_override'])
                ? max(0, (float) $item['total_override'])
                : max(0, ($price * $quantity) - $lineDiscount);

            $subtotal += $lineTotal;
            $totalCost += $cost * $quantity;
        }

        $invoiceDiscount = max(0, (float) $this->discount);

        $taxableAmount = max(
            0,
            $subtotal - $invoiceDiscount
        );

        $taxRate = max(0, (float) $this->taxPercent);
        $taxAmount = $taxRate > 0
            ? $taxableAmount * ($taxRate / (100 + $taxRate))
            : 0.0;

        $deliveryFee = $this->currentOrderType === 'pos' ? max(0, $this->posDeliveryFee) : 0.0;
        $grandTotal = $taxableAmount + $deliveryFee;

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($invoiceDiscount, 2),
            'tax' => round($taxAmount, 2),
            'total' => round($grandTotal, 2),
            'total_cost' => round($totalCost, 2),
            'profit' => round($grandTotal - $deliveryFee - $taxAmount - $totalCost, 2),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | حفظ الفاتورة
    |--------------------------------------------------------------------------
    */

    public function saveInvoice(bool $shouldPrint = false, bool $openSettlementModal = false): void
    {
        if ($this->currentOrderId && ! $this->invoiceEditMode) {
            $this->errorMessage = 'هذه فاتورة محفوظة للعرض فقط. اضغط «تعديل» أولاً لتغييرها.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);

            return;
        }

        try {
            $this->authorize($this->currentOrderId ? 'sales_invoices.edit' : 'sales_invoices.create');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            $this->errorMessage = 'لا تملك صلاحية حفظ أو تعديل فاتورة المبيعات.';
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

        $customer = null;

        if ($this->selectedCustomerId) {
            $customer = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'supplier', 'both'])
                ->where('is_active', true)
                ->find($this->selectedCustomerId);

            if (!$customer) {
                $this->errorMessage = 'العميل المحدد غير صالح.';
                $this->dispatch('toast', type: 'error', message: $this->errorMessage);
                return;
            }
        }

        if ($openSettlementModal && !$customer) {
            $this->errorMessage = 'اختر زبوناً للفاتورة قبل تسجيل سند القبض.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);
            return;
        }

        $totals = $this->calculateTotals();
        $paidAmount = round(max(0, (float) $this->paidAmount), 2);
        if (! is_finite($paidAmount)) {
            $this->errorMessage = 'أدخل مبلغ دفع صالحاً.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);

            return;
        }

        $wasEditingInvoice = $this->currentOrderId !== null;

        try {
            $order = DB::transaction(function () use ($tenantId, $customer, $totals, $paidAmount, $wasEditingInvoice) {
                $oldCustomerId = null;
                $oldOutstandingBalance = 0.0;
                $isPosOrder = false;
                $deliveryFee = 0.0;
                $receiptAmount = 0.0;

                if ($this->currentOrderId) {
                    $order = Order::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($this->currentOrderId)
                        ->lockForUpdate()
                        ->first();

                    if (!$order) {
                        throw new \RuntimeException('الفاتورة المطلوب تعديلها غير موجودة.');
                    }

                    $isPosOrder = $order->type === 'pos';
                    if (! in_array($order->type, ['wholesale', 'pos'], true) || ($isPosOrder && ! $order->customer_id)) {
                        throw new \DomainException('لا يمكن تعديل هذا النوع من الفواتير من شاشة المبيعات.');
                    }

                    if ($isPosOrder) {
                        if (config('branch-sync.mode') === 'edge' || app(\App\Services\DesktopPosConnection::class)->enabled()) {
                            throw new \DomainException('عدّل فاتورة نقطة البيع من جهاز نقطة البيع المرتبط بها.');
                        }

                        if ((int) $this->selectedBranchId !== (int) $order->branch_id || $this->invoiceNumber !== $order->invoice_number) {
                            throw new \DomainException('لا يمكن تغيير فرع فاتورة نقطة البيع أو رقمها، حفاظاً على ارتباط الشيفت وسند القبض.');
                        }

                        $deliveryFee = max(0, (float) $order->delivery_fee);
                        $receipts = Payment::query()
                            ->where('tenant_id', $tenantId)
                            ->where('order_id', $order->id)
                            ->where('type', 'receipt')
                            ->lockForUpdate()
                            ->get();
                        $receiptAmount = (float) $receipts->sum(fn (Payment $payment): float => (float) $payment->amount);
                        $voucherShiftIds = $receipts->pluck('shift_id')->filter()->unique()->values();
                        $voucherShiftId = $voucherShiftIds->first();
                        if ($voucherShiftId !== null && (int) $order->shift_id !== (int) $voucherShiftId) {
                            $order->shift_id = (int) $voucherShiftId;
                        }

                        app(\App\Services\BranchStockAllocator::class)->restore($order);
                    }

                    $oldCustomerId = $order->customer_id ? (int) $order->customer_id : null;
                    $oldReceiptAmount = $isPosOrder ? $receiptAmount : (float) Payment::query()
                        ->where('tenant_id', $tenantId)->where('order_id', $order->id)->where('type', 'receipt')->sum('amount');
                    $oldOutstandingBalance = max(0, abs((float) $order->total) - (float) $order->delivery_fee) - $oldReceiptAmount;

                    $effectivePaidAmount = $isPosOrder
                        ? min((float) $totals['total'], $receiptAmount + $deliveryFee)
                        : ($oldReceiptAmount > 0
                            ? min((float) $totals['total'], $oldReceiptAmount)
                            : min((float) $order->total, (float) $order->paid_amount));

                    $order->update([
                        'branch_id' => $isPosOrder ? $order->branch_id : $this->selectedBranchId,
                        'customer_id' => $customer?->id,
                        'customer_name' => $customer?->name ?? 'زبون عام',
                        'customer_phone' => $customer?->phone,
                        'customer_address' => $customer?->address,
                        'invoice_number' => $isPosOrder ? $order->invoice_number : $this->invoiceNumber,
                        'invoice_date' => $this->invoiceDate,
                        'type' => $isPosOrder ? 'pos' : 'wholesale',
                        'source' => $isPosOrder ? 'pos' : $order->source,
                        'shift_id' => $isPosOrder ? $voucherShiftId : $order->shift_id,
                        'sale_kind' => $isPosOrder ? $order->sale_kind : 'retail',
                        'status' => 'completed',
                        'subtotal' => $totals['subtotal'],
                        'tax_amount' => $isPosOrder ? 0 : $totals['tax'],
                        'tax_percent' => $isPosOrder ? 0 : max(0, $this->taxPercent),
                        'discount_type' => $isPosOrder ? $order->discount_type : 'fixed',
                        'discount_rate' => $isPosOrder ? $order->discount_rate : 0,
                        'discount' => $totals['discount'],
                        'total' => $totals['total'],
                        'total_cost' => $totals['total_cost'],
                        'total_profit' => $totals['profit'],
                        'delivery_fee' => $isPosOrder ? $deliveryFee : 0,
                        'delivery_party_id' => $isPosOrder ? $order->delivery_party_id : null,
                        'paid_amount' => $effectivePaidAmount,
                        'payment_status' => $effectivePaidAmount >= $totals['total'] ? 'paid' : ($effectivePaidAmount > 0 ? 'partial' : 'unpaid'),
                        'notes' => $this->notes ?: null,
                    ]);

                    $order->items()->delete();
                } else {
                    $order = Order::query()->create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $this->selectedBranchId,
                        'shift_id' => null,
                        'created_by' => auth()->id(),
                        'customer_id' => $customer?->id,
                        'customer_name' => $customer?->name ?? 'زبون عام',
                        'customer_phone' => $customer?->phone,
                        'customer_address' => $customer?->address,
                        'invoice_number' => $this->invoiceNumber,
                        'invoice_date' => $this->invoiceDate,
                        'type' => 'wholesale',
                        'source' => 'web',
                        'sale_kind' => 'retail',
                        'status' => 'completed',
                        'subtotal' => $totals['subtotal'],
                        'tax_amount' => $totals['tax'],
                        'tax_percent' => max(0, $this->taxPercent),
                        'discount_type' => 'fixed',
                        'discount_rate' => 0,
                        'discount' => $totals['discount'],
                        'total' => $totals['total'],
                        'total_cost' => $totals['total_cost'],
                        'total_profit' => $totals['profit'],
                        'paid_amount' => min($paidAmount, (float) $totals['total']),
                        'payment_status' => $paidAmount >= $totals['total'] && $totals['total'] > 0 ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid'),
                        'notes' => $this->notes ?: null,
                    ]);
                }

                foreach ($this->cart as $item) {
                    $quantity = (float) ($item['quantity'] ?? 0);
                    $price = (float) ($item['price'] ?? 0);
                    $lineDiscount = (float) ($item['discount'] ?? 0);
                    $cost = (float) ($item['cost'] ?? 0);

                    $lineTotal = isset($item['total_override'])
                        ? max(0, (float) $item['total_override'])
                        : max(0, ($price * $quantity) - $lineDiscount);

                    OrderItem::query()->create([
                        'tenant_id' => $tenantId,
                        'order_id' => $order->id,
                        'product_id' => (int) $item['id'],
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'total_price' => $lineTotal,
                        'cost_price' => $cost,
                        'discount' => $lineDiscount,
                        'total_cost' => $cost * $quantity,
                        'is_gift' => $isPosOrder && ! empty($item['is_gift']),
                        'offer_id' => $isPosOrder ? ($item['offer_id'] ?? null) : null,
                        'offer_quantity' => $isPosOrder ? ($item['offer_quantity'] ?? null) : null,
                        'offer_price' => $isPosOrder ? ($item['offer_price'] ?? null) : null,
                        'normal_subtotal' => $isPosOrder ? (float) ($item['normal_subtotal'] ?? $lineTotal) : $lineTotal,
                        'promotion_savings' => $isPosOrder ? (float) ($item['promotion_savings'] ?? 0) : 0,
                    ]);
                }

                if ($isPosOrder) {
                    $saleQuantities = [];
                    foreach ($this->cart as $item) {
                        $productId = (int) ($item['id'] ?? 0);
                        $quantity = max(0, (float) ($item['quantity'] ?? 0));
                        if ($productId > 0 && $quantity > 0) {
                            $saleQuantities[$productId] = ($saleQuantities[$productId] ?? 0) + $quantity;
                        }
                    }

                    app(\App\Services\BranchStockAllocator::class)->deductMany(
                        $order,
                        $saleQuantities,
                        TenantSetting::getBool((int) $tenantId, 'unified_stock'),
                        TenantSetting::getBool((int) $tenantId, 'allow_negative_stock'),
                    );
                }

                if (! $this->currentOrderId && $paidAmount > 0) {
                    Payment::query()->create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $this->selectedBranchId,
                        'shift_id' => null,
                        'created_by' => auth()->id(),
                        'party_id' => $customer?->id,
                        'type' => 'receipt',
                        'voucher_number' => 'RCV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                        'amount' => $paidAmount,
                        'payment_method' => $this->paymentMethod,
                        'order_id' => $order->id,
                        'notes' => 'دفعة على الفاتورة رقم: '.$order->invoice_number,
                        'payment_date' => now(),
                    ]);
                }

                $newReceiptAmount = (float) Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('order_id', $order->id)
                    ->where('type', 'receipt')
                    ->sum('amount');
                $newOutstandingBalance = max(0, abs((float) $order->total) - (float) $order->delivery_fee) - $newReceiptAmount;

                if ($wasEditingInvoice && $oldCustomerId !== (int) ($customer?->id ?? 0)) {
                    if ($oldCustomerId) {
                        $oldCustomer = Party::query()
                            ->where('tenant_id', $tenantId)
                            ->lockForUpdate()
                            ->find($oldCustomerId);

                        if ($oldCustomer) {
                            $oldCustomer->current_balance = round((float) $oldCustomer->current_balance - $oldCustomer->saleBalanceDirection() * $oldOutstandingBalance, 2);
                            $oldCustomer->save();
                        }
                    }

                    if ($customer) {
                        $newCustomer = Party::query()
                            ->where('tenant_id', $tenantId)
                            ->lockForUpdate()
                            ->find($customer->id);

                        if ($newCustomer) {
                            $newCustomer->current_balance = round((float) $newCustomer->current_balance + $newCustomer->saleBalanceDirection() * $newOutstandingBalance, 2);
                            $newCustomer->save();
                        }
                    }
                } elseif ($customer) {
                    $lockedCustomer = Party::query()
                        ->where('tenant_id', $tenantId)
                        ->lockForUpdate()
                        ->find($customer->id);

                    if ($lockedCustomer) {
                        $balanceChange = $wasEditingInvoice
                            ? $newOutstandingBalance - $oldOutstandingBalance
                            : $newOutstandingBalance;
                        $lockedCustomer->current_balance = round((float) $lockedCustomer->current_balance + $lockedCustomer->saleBalanceDirection() * $balanceChange, 2);
                        $lockedCustomer->save();
                    }
                }

                return $order->fresh(['items.product']);
            });

            $this->currentOrderId = $order->id;
            $this->currentOrderType = $order->type;
            $this->posDeliveryFee = $order->type === 'pos' ? (float) $order->delivery_fee : 0.0;
            $this->savedPaidAmount = (float) $order->paid_amount;
            $this->voucherPaidAmount = $order->type === 'pos'
                ? (float) Payment::query()->where('tenant_id', $tenantId)->where('order_id', $order->id)->where('type', 'receipt')->sum('amount')
                : 0.0;
            $this->posShiftId = $order->type === 'pos'
                ? (int) (Payment::query()->where('tenant_id', $tenantId)->where('order_id', $order->id)->where('type', 'receipt')->whereNotNull('shift_id')->orderBy('id')->value('shift_id') ?? 0) ?: null
                : null;
            $this->invoiceNumber = $order->invoice_number;
            $this->lastSavedOrderId = $order->id;
            $this->lastSavedCustomerId = $order->customer_id;
            $this->lastSavedInvoiceNumber = $order->invoice_number;
            $this->lastSavedReceiptAmount = max(
                0,
                (float) $order->total - ($order->type === 'pos' ? (float) $order->delivery_fee : 0) - (float) Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('order_id', $order->id)
                    ->where('type', 'receipt')
                    ->sum('amount')
            );

            $this->successMessage = $this->currentOrderId
                ? 'تم حفظ الفاتورة بنجاح.'
                : 'تم حفظ الفاتورة بنجاح.';

            if ($shouldPrint) {
                $this->dispatchPrint($order, $totals);
            }

            if ($wasEditingInvoice) {
                $this->loadOrder($order);
                $this->successMessage = 'تم تعديل فاتورة المبيعات بنجاح.';
                if ($openSettlementModal) {
                    $this->openSettlementModal();
                }
                $this->dispatch('toast', type: 'success', message: $this->successMessage);

                return;
            }

            if ($openSettlementModal) {
                $this->loadOrder($order);
                $this->openSettlementModal();
                $this->successMessage = 'تم حفظ الفاتورة. أدخل مبلغ سند القبض.';
                return;
            }

            $this->startNewInvoice(false);
            $this->successMessage = 'تم حفظ الفاتورة بنجاح وفتح فاتورة جديدة.';
            $this->dispatch('toast', type: 'success', message: 'تم حفظ فاتورة المبيعات بنجاح.');

        } catch (\Throwable $e) {
            report($e);
            $this->errorMessage = 'تعذر حفظ الفاتورة. تأكد من البيانات وحاول مرة أخرى.';
            $this->dispatch('toast', type: 'error', message: $this->errorMessage);
        }
    }

    private function openSettlementModal(): void
    {
        if (!$this->currentOrderId || !$this->selectedCustomerId) {
            $this->errorMessage = 'تعذر فتح سند القبض بدون زبون مرتبط بالفاتورة.';
            return;
        }

        $order = Order::query()->where('tenant_id', $this->tenantId())->findOrFail($this->currentOrderId);
        $receiptsTotal = (float) Payment::query()
            ->where('tenant_id', $this->tenantId())
            ->where('order_id', $order->id)
            ->where('type', 'receipt')
            ->sum('amount');

        $this->settlementAmount = (string) max(0, (float) $order->total - (float) $order->delivery_fee - $receiptsTotal);
        $this->settlementMethod = 'cash';
        $this->settlementNotes = '';
        $this->resetValidation('settlementNotes');
        $this->showSettlementModal = true;
    }

    public function saveSettlementVoucher(): void
    {
        $this->authorize('accounting.receipt.create');

        $validated = $this->validate([
            'settlementAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999'],
            'settlementNotes' => ['nullable', 'string', 'max:5000'],
            'settlementMethod' => ['required', 'in:cash,card,bank_transfer,cheque'],
        ]);

        $tenantId = $this->tenantId();
        abort_unless($tenantId && $this->currentOrderId, 404);

        DB::transaction(function () use ($tenantId, $validated): void {
            $order = Order::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($this->currentOrderId);
            abort_unless($order->customer_id, 422);

            $customer = Party::query()->where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($order->customer_id);
            $amount = round((float) $validated['settlementAmount'], 2);

            Payment::query()->create([
                'tenant_id' => $tenantId,
                'branch_id' => $order->branch_id,
                'shift_id' => $order->shift_id,
                'created_by' => auth()->id(),
                'party_id' => $customer->id,
                'type' => 'receipt',
                'voucher_number' => 'RCV-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'amount' => $amount,
                'payment_method' => $validated['settlementMethod'],
                'order_id' => $order->id,
                'notes' => trim($validated['settlementNotes'] ?? '') !== ''
                    ? trim($validated['settlementNotes'])
                    : 'سند قبض مرتبط بفاتورة المبيعات '.$order->invoice_number,
                'payment_date' => now(),
            ]);

            $receiptsTotal = (float) Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $order->id)
                ->where('type', 'receipt')
                ->sum('amount');
            $paidAmount = min((float) $order->total, $receiptsTotal + (float) $order->delivery_fee);
            $order->update([
                'paid_amount' => $paidAmount,
                'payment_status' => $paidAmount >= (float) $order->total ? 'paid' : ($paidAmount > 0 ? 'partial' : 'unpaid'),
            ]);

            $customer->current_balance = round((float) $customer->current_balance - $customer->saleBalanceDirection() * $amount, 2);
            $customer->save();
        });

        $this->showSettlementModal = false;
        $this->loadOrder(Order::query()->where('tenant_id', $tenantId)->findOrFail($this->currentOrderId));
        $this->dispatch('toast', type: 'success', message: 'تم حفظ سند القبض وربطه بفاتورة المبيعات.');
    }

    public function saveAndPrint(): void
    {
        if ($this->currentOrderId && ! $this->invoiceEditMode) {
            $this->printCurrentInvoice();

            return;
        }

        $this->saveInvoice(true);
    }

    /*
    |--------------------------------------------------------------------------
    | البحث عن الفاتورة والتنقل
    |--------------------------------------------------------------------------
    */

    public function toggleInvoiceSearch(): void
    {
        $this->showInvoiceSearch = !$this->showInvoiceSearch;

        if ($this->showInvoiceSearch) {
            $this->dispatch('focus-invoice-search');
        }
    }

    public function searchInvoice(): void
    {
        $search = trim(strtr(trim($this->searchInvoiceQuery), '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', '01234567890123456789'));
        $tenantId = $this->tenantId();

        if ($search === '' || !$tenantId) {
            return;
        }

        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query): void {
                $query->where('type', 'wholesale')
                    ->orWhere(fn ($pos) => $pos->where('type', 'pos')->whereNotNull('customer_id')->where('status', 'completed'));
            })
            ->where('status', 'completed')
            ->when($this->invoiceBranchScopeId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->where(function ($query) use ($search) {
                $query->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");

                if (is_numeric($search)) {
                    $query->orWhere('id', (int) $search);
                }
            })
            ->orderByRaw('CASE WHEN invoice_number = ? THEN 0 ELSE 1 END', [$search])
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->first();

        if (!$order) {
            $this->errorMessage = 'لم يتم العثور على فاتورة مبيعات مطابقة.';
            return;
        }

        $this->loadOrder($order);
        $this->searchInvoiceQuery = '';
        $this->showInvoiceSearch = false;
    }

    public function openInvoiceFromSearch(int $orderId): void
    {
        $order = Order::query()
            ->where('tenant_id', $this->tenantId())
            ->when($this->invoiceBranchScopeId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->where(function ($query): void {
                $query->where('type', 'wholesale')
                    ->orWhere(fn ($pos) => $pos->where('type', 'pos')->whereNotNull('customer_id')->where('status', 'completed'));
            })
            ->where('status', 'completed')
            ->find($orderId);

        if (! $order) {
            $this->errorMessage = 'الفاتورة غير موجودة أو ليست ضمن نطاقك.';
            return;
        }

        $this->loadOrder($order);
        if ($this->currentOrderId === $order->id) {
            $this->showInvoiceSearch = false;
        }
    }

    public function loadOrder(Order $order): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId || (int) $order->tenant_id !== $tenantId) {
            $this->errorMessage = 'لا يمكنك تحميل هذه الفاتورة.';
            return;
        }

        if ($this->invoiceBranchScopeId() && (int) $order->branch_id !== $this->invoiceBranchScopeId()) {
            $this->errorMessage = 'لا يمكنك عرض فواتير فرع آخر ضمن نطاق الفرع المحدد.';
            return;
        }

        if (! in_array($order->type, ['wholesale', 'pos'], true) || ($order->type === 'pos' && ! $order->customer_id)) {
            $this->errorMessage = 'هذه الفاتورة ليست فاتورة مبيعات مرتبطة بزبون.';
            return;
        }

        if ($order->type === 'pos' && $order->status !== 'completed') {
            $this->errorMessage = 'لا يمكن تحميل فاتورة نقطة بيع غير مكتملة.';
            return;
        }

        $order->load('items.product');

        $this->currentOrderId = $order->id;
        $this->currentOrderType = $order->type;
        $this->invoiceEditMode = false;
        $this->invoiceNumber = $order->invoice_number;
        $this->invoiceDate = $order->invoice_date?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');
        $this->selectedBranchId = $order->branch_id;
        $this->selectedCustomerId = $order->customer_id;
        $this->posDeliveryFee = $order->type === 'pos' ? (float) $order->delivery_fee : 0.0;
        $linkedReceipts = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('order_id', $order->id)
            ->where('type', 'receipt')
            ->orderBy('id')
            ->get();
        $this->voucherPaidAmount = (float) $linkedReceipts->sum(fn (Payment $payment): float => (float) $payment->amount);
        $this->posShiftId = (int) ($linkedReceipts->first(fn (Payment $payment): bool => $payment->shift_id !== null)?->shift_id ?? 0) ?: null;
        $this->savedPaidAmount = $this->voucherPaidAmount > 0
            ? min((float) $order->total, $this->voucherPaidAmount + ($order->type === 'pos' ? $this->posDeliveryFee : 0))
            : (float) $order->paid_amount;
        $this->paidAmount = $this->savedPaidAmount;
        $this->customerSearch = $order->customer_name ?: '';
        $this->discount = (float) ($order->discount ?? 0);
        $this->taxPercent = $order->type === 'pos' ? 0.0 : max(0, (float) ($order->tax_percent ?? 16));
        $this->taxable = $order->type !== 'pos';
        $this->notes = $order->notes ?? '';

        $this->cart = [];
        $this->replacingCartKey = null;
        $this->replacementSearch = '';

        foreach ($order->items as $item) {
            $key = $item->product_id . '-' . Str::uuid()->toString();

            $this->cart[$key] = [
                'id' => (int) $item->product_id,
                'name' => $item->product?->name ?? 'مادة محذوفة',
                'price' => (float) $item->unit_price,
                'cost' => (float) $item->cost_price,
                'quantity' => (float) $item->quantity,
                'discount' => (float) $item->discount,
                'stock' => 0,
                'is_gift' => (bool) $item->is_gift,
                'offer_id' => $item->offer_id,
                'offer_quantity' => $item->offer_quantity,
                'offer_price' => $item->offer_price,
                'normal_subtotal' => (float) $item->normal_subtotal,
                'promotion_savings' => (float) $item->promotion_savings,
            ];

            $calculatedLineTotal = max(0, ((float) $item->unit_price * (float) $item->quantity) - (float) $item->discount);
            if (abs($calculatedLineTotal - (float) $item->total_price) >= 0.005) {
                $this->cart[$key]['total_override'] = (float) $item->total_price;
            }
        }

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPrice = 0;
        $this->selectedCost = 0;
        $this->selectedStock = 0;
        $this->selectedQuantity = 1;
        $this->selectedDiscount = 0.0;
        $this->rowSearch = '';
        $this->successMessage = $order->type === 'pos'
            ? "تم تحميل فاتورة نقطة البيع {$order->invoice_number}، والتحصيل والشيفت مرتبطان بسند القبض."
            : "تم تحميل الفاتورة {$order->invoice_number}.";
        $this->errorMessage = null;
    }

    public function previousInvoice(): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return;
        }

        $query = Order::query()
            ->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->where('type', 'wholesale')->orWhere(fn ($pos) => $pos->where('type', 'pos')->whereNotNull('customer_id')->where('status', 'completed')))
            ->when($this->invoiceBranchScopeId(), fn ($query, $branchId) => $query->where('branch_id', $branchId));

        if ($this->currentOrderId) {
            $query->where('id', '<', $this->currentOrderId);
        }

        $order = $query->orderByDesc('id')->first();

        if (!$order) {
            $this->errorMessage = 'لا توجد فاتورة مبيعات أقدم.';
            return;
        }

        $this->loadOrder($order);
    }

    public function nextInvoice(): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId || !$this->currentOrderId) {
            return;
        }

        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->where(fn ($query) => $query->where('type', 'wholesale')->orWhere(fn ($pos) => $pos->where('type', 'pos')->whereNotNull('customer_id')->where('status', 'completed')))
            ->when($this->invoiceBranchScopeId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
            ->where('id', '>', $this->currentOrderId)
            ->orderBy('id')
            ->first();

        if (!$order) {
            $this->errorMessage = 'لا توجد فاتورة مبيعات أحدث.';
            return;
        }

        $this->loadOrder($order);
    }

    /*
    |--------------------------------------------------------------------------
    | طباعة
    |--------------------------------------------------------------------------
    */

    private function dispatchPrint(Order $order, array $totals): void
    {
        $order->loadMissing('items.product');

        $customer = Party::query()
            ->where('tenant_id', $order->tenant_id)
            ->whereKey($order->customer_id)
            ->first();

        $items = [];

        foreach ($order->items as $item) {
            $items[] = [
                'name' => $item->product?->name ?? 'مادة غير محددة',
                'product_number' => (string) ($item->product?->product_number ?: $item->product_id),
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->unit_price,
                'discount' => (float) $item->discount,
                'total' => (float) $item->total_price,
            ];
        }

        $this->dispatch(
            'print-sales-invoice',
            invoice: [
                'pdf_url' => route('tenant.sale-invoices.pdf', ['orderId' => $order->id]),
                'store_name' => $order->branch?->name ?: Tenant::query()->whereKey($order->tenant_id)->value('name') ?: 'المتجر',
                'number' => $order->invoice_number,
                'date' => $order->invoice_date?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d H:i'),
                'customer' => $order->customer_name ?: 'زبون عام',
                'file_title' => \Illuminate\Support\Str::beforeLast(\App\Services\PdfFilename::forDocument($order->customer_name ?: $customer?->name, $order->invoice_number, 'زبون عام'), '.pdf'),
                'phone' => $order->customer_phone,
                'notes' => $order->notes,
                'items' => $items,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'show_tax' => TenantSetting::getBool((int) $order->tenant_id, 'show_tax', true),
                'total' => $totals['total'],
                'paid' => (float) $order->paid_amount,
                'remaining' => round(max(0, (float) $order->total - (float) $order->paid_amount), 2),
                'account_balance' => $customer ? (float) $customer->current_balance : null,
                'summary' => \App\Services\InvoiceBalanceSummary::forOrder($order),
            ]
        );
    }

    public function printCurrentInvoice(): void
    {
        if (!$this->currentOrderId) {
            $this->errorMessage = 'احفظ الفاتورة أولاً قبل الطباعة.';
            return;
        }

        $tenantId = $this->tenantId();

        $order = Order::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($this->currentOrderId)
            ->with('items.product')
            ->first();

        if (!$order) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة.';
            return;
        }

        $totals = [
            'subtotal' => (float) $order->subtotal,
            'discount' => (float) $order->discount,
            'tax' => (float) $order->tax_amount,
            'total' => (float) $order->total,
        ];

        $this->dispatchPrint($order, $totals);
    }

    public function editLoadedInvoice(): void
    {
        if (! $this->currentOrderId) {
            return;
        }

        $this->invoiceEditMode = true;
        $this->successMessage = 'تم تفعيل تعديل الفاتورة. عدّل البيانات ثم اضغط حفظ.';
        $this->errorMessage = null;
    }

    public function downloadInvoicePdf(): mixed
    {
        if (! $this->currentOrderId) {
            $this->errorMessage = 'احفظ الفاتورة أولاً قبل تنزيل PDF.';

            return null;
        }

        $order = Order::query()
            ->where('tenant_id', $this->tenantId())
            ->whereKey($this->currentOrderId)
            ->with(['items.product', 'user', 'branch'])
            ->withSum('payments as collected_amount', 'amount')
            ->first();

        if (! $order) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة.';

            return null;
        }

        return response()->streamDownload(function () use ($order): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pages.tenant.pos.partials.invoice-pdf', [
                'order' => $order,
                'accountBalance' => $order->customer_id ? Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->value('current_balance') : null,
                'storeName' => $order->branch?->name ?: Tenant::query()->whereKey($order->tenant_id)->value('name') ?: 'المتجر',
            ]);

            echo $pdf->output();
        }, \App\Services\PdfFilename::forDocument($order->customer_name ?: ($order->customer_id ? Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->value('name') : null), $order->invoice_number, 'زبون عام'));
    }

    public function shareInvoicePdf(): void
    {
        if (! $this->currentOrderId) {
            $this->errorMessage = 'احفظ الفاتورة أولاً قبل إرسال PDF عبر واتساب.';

            return;
        }

        $customerName = $this->selectedCustomerId
            ? (string) Party::query()->where('tenant_id', $this->tenantId())->whereKey($this->selectedCustomerId)->value('name')
            : '';

        $this->dispatch(
            'share-invoice-pdf',
            url: route('tenant.sale-invoices.pdf', ['orderId' => $this->currentOrderId]),
            filename: \App\Services\PdfFilename::forDocument($customerName, $this->invoiceNumber, 'زبون عام'),
            message: 'مرفق فاتورة مبيعات رقم '.$this->invoiceNumber
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    /** Fast product lookup with the POS catalog matching rules. */
    private function applyPosCatalogSearch($query, string $search, int $tenantId, bool $fallback = false): void
    {
        $searchService = app(PosProductSearch::class);
        $search = $searchService->normalize($search);
        if ($search === '' || mb_strlen($search) > 120) {
            $query->whereRaw('1 = 0');
            return;
        }

        $escape = static fn (string $value): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
        $numberPattern = '%' . $escape($search) . '%';
        $query->where(function ($matches) use ($search, $searchService, $tenantId, $numberPattern, $fallback): void {
            $matches->where(function ($standard) use ($search, $searchService, $tenantId): void {
                $searchService->apply($standard->getQuery(), $search, $tenantId);
            })->orWhereRaw("LOWER(products.product_number) LIKE ? ESCAPE '!'", [$numberPattern]);

            $searchService->orWhereBarcodeContains($matches->getQuery(), $search, $tenantId);

            if ($fallback) {
                $matches->orWhere(function ($names) use ($search, $searchService): void {
                    $searchService->applyContains($names->getQuery(), $search);
                });
            }
        });
    }
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
            // Items with the most stock in this branch come first.
            $baseQuery = fn () => Product::query()
                ->where('tenant_id', $tenantId)
                ->with(['branchProducts' => fn ($query) => $query->where('branch_id', $branchId)])
                ->orderByRaw('COALESCE((SELECT bp_sort.stock_quantity FROM branch_products AS bp_sort WHERE bp_sort.product_id = products.id AND bp_sort.branch_id = ? LIMIT 1), 0) DESC', [$branchId])
                ->orderBy('name')
                ->orderBy('id');

            $query = $baseQuery();
            $this->applyPosCatalogSearch($query, $search, (int) $tenantId);
            $productResults = $query->limit(24)->get();

            // Slower contains search only when the fast first pass has no matches.
            if ($productResults->isEmpty()) {
                $query = $baseQuery();
                $this->applyPosCatalogSearch($query, $search, (int) $tenantId, true);
                $productResults = $query->limit(24)->get();
            }
        }

        $replacementProductResults = collect();
        if ($tenantId && $this->replacingCartKey !== null && trim($this->replacementSearch) !== '') {
            $search = trim($this->replacementSearch);
            $baseQuery = fn () => Product::query()
                ->where('tenant_id', $tenantId)
                ->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $branchId))
                ->with(['branchProducts' => fn ($query) => $query->where('branch_id', $branchId)]);

            $query = $baseQuery();
            $this->applyPosCatalogSearch($query, $search, (int) $tenantId);
            $replacementProductResults = $query->limit(12)->get();

            if ($replacementProductResults->isEmpty()) {
                $query = $baseQuery();
                $this->applyPosCatalogSearch($query, $search, (int) $tenantId, true);
                $replacementProductResults = $query->limit(12)->get();
            }
        }

        $customerResults = collect();

        if ($tenantId && $this->selectedCustomerId === null && trim($this->customerSearch) !== '') {
            $search = trim($this->customerSearch);

            $customerResults = Party::query()
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

        $selectedCustomer = null;

        if ($tenantId && $this->selectedCustomerId) {
            $selectedCustomer = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'supplier', 'both'])
                ->find($this->selectedCustomerId);
        }

        $totals = $this->calculateTotals();
        $linkedReceipts = $tenantId && $this->currentOrderId
            ? Payment::query()->where('tenant_id', $tenantId)->where('order_id', $this->currentOrderId)->where('type', 'receipt')->orderBy('id')->get(['id', 'voucher_number', 'amount'])
            : collect();
        $linkedReceiptAmount = (float) $linkedReceipts->sum(fn (Payment $payment): float => (float) $payment->amount);

        $invoiceSearchResults = collect();
        if ($tenantId && $this->showInvoiceSearch) {
            $search = trim(strtr(trim($this->searchInvoiceQuery), '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', '01234567890123456789'));
            $invoiceSearchResults = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'completed')
                ->where(function ($query): void {
                    $query->where('type', 'wholesale')
                        ->orWhere(fn ($pos) => $pos->where('type', 'pos')->whereNotNull('customer_id'));
                })
                ->when($this->invoiceBranchScopeId(), fn ($query, $branchId) => $query->where('branch_id', $branchId))
                ->when($search !== '', function ($query) use ($search): void {
                    $query->where(function ($matches) use ($search): void {
                        $matches->where('invoice_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%")
                            ->orWhere('customer_phone', 'like', "%{$search}%");

                        if (is_numeric($search)) {
                            $matches->orWhere('id', (int) $search);
                        }
                    });
                })
                ->orderByDesc('invoice_date')
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'branch_id', 'type', 'invoice_number', 'invoice_date', 'customer_name', 'total', 'paid_amount', 'payment_status']);

            $invoiceSearchResults->load('branch:id,name');
        }

        $accountBalanceAfterInvoice = null;
        if ($selectedCustomer) {
            $savedOrder = $this->currentOrderId ? Order::query()->where('tenant_id', $tenantId)->find($this->currentOrderId) : null;
            $previousOutstanding = $savedOrder && $savedOrder->customer_id === $selectedCustomer->id
                ? max(0, (float) $savedOrder->total - (float) $savedOrder->paid_amount) : 0;
            $effectivePaid = $savedOrder ? (float) $savedOrder->paid_amount : max(0, (float) $this->paidAmount);
            $accountBalanceAfterInvoice = round((float) $selectedCustomer->current_balance
                + $selectedCustomer->saleBalanceDirection() * (max(0, $totals['total'] - $effectivePaid) - $previousOutstanding), 2);
        }

        return $this->view([
            'cartProductNumbers' => $tenantId && $this->cart !== []
                ? Product::query()->where('tenant_id', $tenantId)->whereIn('id', array_column($this->cart, 'id'))->pluck('product_number', 'id')->filter()->all()
                : [],
            'branches' => $branches,
            'invoiceBranches' => $branches,
            'invoiceSearchResults' => $invoiceSearchResults,
            'productResults' => $productResults,
            'replacementProductResults' => $replacementProductResults,
            'customerResults' => $customerResults,
            'selectedCustomer' => $selectedCustomer,
            'accountBalanceAfterInvoice' => $accountBalanceAfterInvoice,
            'subtotal' => $totals['subtotal'],
            'invoiceDiscount' => $totals['discount'],
            'taxAmount' => $totals['tax'],
            'showTax' => TenantSetting::getBool((int) $tenantId, 'show_tax', true) && $this->currentOrderType !== 'pos',
            'grandTotal' => $totals['total'],
            'linkedReceipts' => $linkedReceipts,
            'linkedReceiptAmount' => $linkedReceiptAmount,
            'totalCost' => $totals['total_cost'],
            'profit' => $totals['profit'],
            'userBranchLocked' => (bool) ($user?->branch_id && ! $user->isTenantOwner() && ! $user->isSaaSAdmin()),
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="bg-slate-100 p-2! sm:p-3! xl:h-[var(--invoice-fit-height,calc(100dvh-4rem))] xl:overflow-y-auto" dir="rtl">

        <div
        class="mx-auto max-w-[1700px] xl:flex xl:h-full xl:flex-col"
        x-data="salesInvoiceKeyboard()"
    >

        <livewire:product-history :key="'sales-product-history-singleton'" />
        {{-- ================================================================
             شريط الأدوات الرئيسي
        ================================================================= --}}

        <div class="mb-3 shrink-0 rounded-xl border border-slate-200 bg-white px-3 py-3 shadow-sm sm:px-4">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">

                <div class="flex min-w-0 items-center gap-3">
                    <flux:icon.shopping-cart class="size-10 shrink-0 text-blue-600" />

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="text-xl font-black text-slate-900">فاتورة مبيعات</h1>

                            @if ($currentOrderId)
                                @if ($invoiceEditMode)
                                    <span class="rounded-full bg-amber-500 px-3 py-0.5 text-xs font-black text-white">تعديل فاتورة</span>
                                @else
                                    <span class="rounded-full bg-slate-700 px-3 py-0.5 text-xs font-black text-white">محفوظة</span>
                                @endif
                                @if ($currentOrderType === 'pos')
                                    <span class="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-0.5 text-xs font-black text-emerald-700">فاتورة نقطة بيع مرتبطة بزبون</span>
                                @endif
                            @else
                                <span class="rounded-full bg-emerald-600 px-3 py-0.5 text-xs font-black text-white">فاتورة جديدة</span>
                            @endif
                        </div>

                        <div class="mt-0.5 font-mono text-xs font-bold text-slate-500">{{ $invoiceNumber }}</div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative" x-data="{ toolsOpen: false }" x-on:click.outside="toolsOpen = false" x-on:keydown.escape.stop="toolsOpen = false">
                        <button type="button" x-on:click="toolsOpen = ! toolsOpen" x-bind:aria-expanded="toolsOpen" aria-haspopup="menu" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                            أدوات
                            <flux:icon.chevron-down class="size-4" />
                        </button>

                        <div x-cloak x-show="toolsOpen" x-transition.origin.top x-on:click="toolsOpen = false" role="menu" class="absolute right-0 top-full z-50 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-1.5 text-sm font-bold text-slate-700 shadow-xl">
                            <button type="button" role="menuitem" x-on:click="document.getElementById('sales-invoice-notes')?.focus()" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right hover:bg-slate-50">
                                <flux:icon.chat-bubble-left-ellipsis class="size-4 text-slate-500" /> ملاحظات الفاتورة
                            </button>

                            @if ($currentOrderId)
                                <button type="button" role="menuitem" wire:click="printCurrentInvoice" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right hover:bg-slate-50">
                                    <flux:icon.printer class="size-4 text-slate-500" /> طباعة الفاتورة المحفوظة
                                </button>
                                <button type="button" role="menuitem" wire:click="downloadInvoicePdf" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right hover:bg-slate-50">
                                    <flux:icon.document-arrow-down class="size-4 text-slate-500" /> تحميل PDF
                                </button>
                                <button type="button" role="menuitem" wire:click="shareInvoicePdf" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right text-emerald-700 hover:bg-emerald-50">
                                    <flux:icon.share class="size-4" /> واتساب PDF
                                </button>
                            @endif

                            @if ($selectedCustomer && auth()->user()->can('reports.analytics') && auth()->user()->can('customers.view'))
                                <div class="my-1 border-t border-slate-100"></div>
                                <button type="button" role="menuitem" wire:click="openCustomerStatement" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right text-indigo-700 hover:bg-indigo-50">
                                    <flux:icon.document-text class="size-4" /> كشف الحساب
                                </button>
                                <a role="menuitem" href="{{ route('tenant.reports', ['report' => 'statement', 'party_id' => $selectedCustomerId, 'party_type' => 'customer']) }}" target="_blank" rel="noopener" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right text-indigo-700 hover:bg-indigo-50">
                                    <flux:icon.arrow-top-right-on-square class="size-4" /> كشف حساب الزبون
                                </a>
                            @endif

                            @if ($currentOrderId && $selectedCustomerId)
                                <div class="my-1 border-t border-slate-100"></div>
                                <a role="menuitem" href="{{ route('tenant.receipts', ['party_id' => $selectedCustomerId, 'order_id' => $currentOrderId, 'amount' => max(0, $grandTotal - ($currentOrderType === 'pos' ? $posDeliveryFee : 0) - $linkedReceiptAmount), 'notes' => 'سند قبض لفاتورة '.$invoiceNumber]) }}" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-right text-emerald-700 hover:bg-emerald-50">
                                    <flux:icon.banknotes class="size-4" /> إضافة سند قبض
                                </a>
                            @endif
                        </div>
                    </div>

                    <div class="inline-flex h-10 overflow-hidden rounded-lg border border-slate-300 bg-white shadow-sm">
                        <button type="button" wire:click="previousInvoice" title="الفاتورة السابقة" class="inline-flex items-center gap-1 px-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                            <flux:icon.chevron-right class="size-4" /> السابقة
                        </button>
                        <button type="button" wire:click="nextInvoice" title="الفاتورة التالية" class="inline-flex items-center gap-1 border-r border-slate-300 px-3 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                            التالية <flux:icon.chevron-left class="size-4" />
                        </button>
                    </div>

                    <button type="button" wire:click="toggleInvoiceSearch" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                        <flux:icon.magnifying-glass class="size-4" /> استعلام <span class="text-xs text-slate-400">(F7)</span>
                    </button>

                    @if ($currentOrderId && ! $invoiceEditMode)
                        <button type="button" wire:click="editLoadedInvoice" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 text-sm font-bold text-amber-800 shadow-sm transition hover:bg-amber-100">
                            <flux:icon.pencil-square class="size-4" /> تعديل
                        </button>
                    @endif

                    <button type="button" wire:click="startNewInvoice" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50">
                        <flux:icon.plus-circle class="size-5 text-blue-600" /> جديد <span class="text-xs text-slate-400">(Shift+F2)</span>
                    </button>

                    @can('accounting.receipt.create')
                        <button type="button" wire:click="saveInvoice(false, true)" wire:loading.attr="disabled" wire:target="saveInvoice" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 text-sm font-bold text-emerald-800 shadow-sm transition hover:bg-emerald-100 disabled:cursor-not-allowed disabled:opacity-50">
                            <flux:icon.banknotes class="size-4" /> {{ $currentOrderId ? 'حفظ التعديل وتسديد' : 'حفظ وتسديد' }}
                        </button>
                    @endcan

                    <button type="button" wire:click="saveAndPrint" wire:loading.attr="disabled" wire:target="saveAndPrint" class="inline-flex h-10 items-center gap-1.5 rounded-lg border border-slate-300 bg-slate-100 px-3 text-sm font-bold text-slate-800 shadow-sm transition hover:bg-slate-200 disabled:cursor-not-allowed disabled:opacity-50">
                        <flux:icon.printer class="size-4" /> {{ $currentOrderId && ! $invoiceEditMode ? 'طباعة' : 'حفظ وطباعة' }} <span class="text-xs text-slate-500">(F6)</span>
                    </button>

                    <button type="button" wire:click="saveInvoice" wire:loading.attr="disabled" wire:target="saveInvoice" class="inline-flex h-10 items-center gap-1.5 rounded-lg bg-emerald-600 px-5 text-sm font-black text-white shadow-sm transition hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50">
                        <flux:icon.check-circle class="size-5" /> {{ $currentOrderId ? 'حفظ التعديل' : 'حفظ الفاتورة' }} <span class="text-xs font-bold text-emerald-100">(F3)</span>
                    </button>
                </div>
            </div>
        </div>

        @if ($showSettlementModal)
            <div class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" x-data x-trap.inert.noscroll="true" x-on:keydown.escape.window="$wire.set('showSettlementModal', false)">
                <section role="dialog" aria-modal="true" aria-labelledby="sales-settlement-title" class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl" dir="rtl">
                    <h2 id="sales-settlement-title" class="text-lg font-black text-slate-900">سند قبض للفاتورة {{ $invoiceNumber }}</h2>
                    <p class="mt-1 text-sm leading-6 text-slate-500">سيُحفظ المبلغ كاملًا كسند قبض مرتبط بهذه الفاتورة، حتى لو تجاوز إجماليها أو رصيد العميل.</p>
                    <label class="mt-4 block text-sm font-bold text-slate-700">مبلغ السند
                        <input type="number" min="0.01" step="0.01" wire:model="settlementAmount" autofocus class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-lg font-black outline-none focus:border-emerald-400 focus:bg-white">
                    </label>
                    @error('settlementAmount')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                    <label class="mt-3 block text-sm font-bold text-slate-600">ملاحظات السند (اختياري)
                        <textarea wire:model="settlementNotes" rows="3" maxlength="5000" placeholder="اكتب ملاحظات السند..." class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 font-semibold outline-none focus:border-emerald-400 focus:bg-white"></textarea>
                    </label>
                    @error('settlementNotes')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                    <label class="mt-3 block text-sm font-bold text-slate-700">طريقة القبض
                        <select wire:model="settlementMethod" class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 font-bold outline-none focus:border-emerald-400">
                            <option value="cash">نقداً</option><option value="card">بطاقة</option><option value="bank_transfer">تحويل بنكي</option><option value="cheque">شيك</option>
                        </select>
                    </label>
                    <div class="mt-5 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="saveSettlementVoucher" wire:loading.attr="disabled" wire:target="saveSettlementVoucher" class="rounded-xl bg-emerald-600 px-4 py-3 font-black text-white disabled:opacity-50">حفظ سند القبض</button>
                        <button type="button" wire:click="$set('showSettlementModal', false)" class="rounded-xl border border-slate-200 px-4 py-3 font-black text-slate-700">إلغاء</button>
                    </div>
                </section>
            </div>
        @endif

        {{-- ================================================================
             الرسائل
        ================================================================= --}}

        @if ($errorMessage)
            <div class="mb-3 flex shrink-0 items-center justify-between gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100">!</span>
                    <span>{{ $errorMessage }}</span>
                </div>

                <button type="button" wire:click="$set('errorMessage', null)" class="rounded-lg px-2 py-1 hover:bg-rose-100">✕</button>
            </div>
        @endif

        <x-flash-notice :message="$successMessage" />

        @if ($lastSavedOrderId && $lastSavedCustomerId && $lastSavedOrderId !== $currentOrderId)
            <div class="mb-2 flex shrink-0 items-center justify-between gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-1.5 text-xs font-bold text-emerald-800">
                <span>تم حفظ الفاتورة {{ $lastSavedInvoiceNumber }}</span>
                <a href="{{ route('tenant.receipts', ['party_id' => $lastSavedCustomerId, 'order_id' => $lastSavedOrderId, 'amount' => $lastSavedReceiptAmount, 'notes' => 'سند قبض لفاتورة '.$lastSavedInvoiceNumber]) }}" class="rounded-md bg-emerald-600 px-2.5 py-1 font-black text-white hover:bg-emerald-700">إضافة سند قبض</a>
            </div>
        @endif

        {{-- ================================================================
             البحث عن فاتورة
        ================================================================= --}}

        @if ($showInvoiceSearch)
            <div class="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/50 p-2 backdrop-blur-sm sm:p-5" role="dialog" aria-modal="true" aria-labelledby="sales-invoice-search-title">
                <div class="flex max-h-[94vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                    <div class="flex items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                        <div>
                            <h2 id="sales-invoice-search-title" class="text-base font-black text-slate-900">استعلام عن فواتير المبيعات السابقة</h2>
                            <p class="mt-1 text-xs font-semibold text-slate-500">اختر فاتورة من النتائج لفتحها في شاشة المبيعات.</p>
                        </div>
                        <button type="button" wire:click="$set('showInvoiceSearch', false)" class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-black text-slate-600 hover:bg-slate-100" aria-label="إغلاق">✕</button>
                    </div>

                    <div class="grid gap-2 border-b border-slate-200 p-3 sm:grid-cols-[1fr_13rem_auto] sm:p-4">
                        @if (auth()->user()->isTenantOwner() || auth()->user()->isSaaSAdmin())
                            <select wire:model.live="invoiceBranchFilter" class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-bold">
                                <option value="">كل الفروع</option>
                                @foreach ($invoiceBranches as $branch)<option wire:key="sales-invoice-filter-branch-{{ $branch->id }}" value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
                            </select>
                        @endif
                        <input id="invoice-search-input" x-ref="invoiceSearch" type="search" wire:model.live.debounce.250ms="searchInvoiceQuery" wire:keydown.enter="searchInvoice" placeholder="ابحث برقم الفاتورة أو اسم الزبون أو هاتفه..." class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:border-indigo-400 focus:bg-white" />
                        <button type="button" wire:click="searchInvoice" class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-black text-white hover:bg-slate-800">فتح المطابقة الأولى</button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-3 sm:p-4">
                        <div class="mb-2 text-xs font-bold text-slate-500">أحدث {{ $invoiceSearchResults->count() }} فاتورة (حتى 100 نتيجة)</div>
                        <div class="space-y-2">
                            @forelse ($invoiceSearchResults as $invoice)
                                <button type="button" wire:key="sales-invoice-result-{{ $invoice->id }}" wire:click="openInvoiceFromSearch({{ $invoice->id }})" class="grid w-full grid-cols-2 items-center gap-x-3 gap-y-1 rounded-xl border border-slate-200 bg-white p-3 text-right transition hover:border-indigo-300 hover:bg-indigo-50 sm:grid-cols-[1.1fr_1.3fr_1fr_0.8fr_1fr_auto] sm:gap-4">
                                    <span class="font-mono text-sm font-black text-indigo-700">{{ $invoice->invoice_number }}</span>
                                    <span class="col-span-1 truncate text-sm font-bold text-slate-800 sm:col-span-1">{{ $invoice->customer_name ?: 'زبون عام' }}</span>
                                    <span class="text-xs font-semibold text-slate-500">{{ $invoice->invoice_date?->format('Y-m-d') ?? '—' }}</span>
                                    <span class="hidden truncate text-xs font-semibold text-slate-500 sm:block">{{ $invoice->branch?->name ?? '—' }}</span>
                                    <span class="text-xs font-bold text-slate-700">الإجمالي: {{ number_format((float) $invoice->total, 2) }}</span>
                                    <span class="hidden rounded-lg bg-indigo-100 px-3 py-2 text-xs font-black text-indigo-700 sm:inline-flex">فتح</span>
                                </button>
                            @empty
                                <div class="rounded-xl border border-dashed border-slate-300 p-8 text-center text-sm font-bold text-slate-500">لا توجد فواتير مطابقة للبحث.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ================================================================
             بيانات رأس الفاتورة
        ================================================================= --}}

        @php
            $showBranchField = ! auth()->user()->isTenantOwner() && ! auth()->user()->isSaaSAdmin();
        @endphp

        <div class="relative z-40 mb-3 shrink-0 rounded-xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-12">

                {{-- رقم الفاتورة --}}
                <div class="xl:col-span-2">
                    <label for="sales-invoice-number" class="mb-1 block text-xs font-bold text-slate-700">رقم الفاتورة</label>
                    <input id="sales-invoice-number" type="text" value="{{ $invoiceNumber }}" readonly tabindex="-1" class="h-10 w-full rounded-lg border border-slate-300 bg-slate-50 px-3 font-mono text-sm font-bold text-slate-800 outline-none" />
                </div>

                {{-- التاريخ --}}
                <div class="xl:col-span-2">
                    <label for="sales-invoice-date" class="mb-1 block text-xs font-bold text-slate-700">التاريخ</label>
                    <input
                        id="sales-invoice-date"
                        type="date"
                        wire:model="invoiceDate"
                        class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                    />
                </div>

                {{-- العميل --}}
                <div class="relative {{ $showBranchField ? 'xl:col-span-4' : 'xl:col-span-6' }}" data-invoice-search-group x-data="{ partySearchOpen: false }" x-on:focusout="if (!$el.contains($event.relatedTarget)) partySearchOpen = false" x-on:click.outside="partySearchOpen = false" x-on:keydown.escape.stop="partySearchOpen = false">
                    <label for="customer-search-input" class="mb-1 block text-xs font-bold text-slate-700">العميل / المورد</label>

                    @if ($selectedCustomer)
                        <div class="flex h-10 items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-3">
                            <flux:icon.user class="size-4 shrink-0 text-emerald-700" />
                            <div class="min-w-0 flex-1 truncate text-sm">
                                <span class="font-black text-emerald-900">{{ $selectedCustomer->name }}</span>
                                <span class="mr-1 text-xs font-semibold text-emerald-700">{{ $selectedCustomer->phone ?: 'بدون هاتف' }}</span>
                            </div>
                            <button type="button" wire:click="clearCustomer" title="تغيير العميل" class="shrink-0 rounded-md px-2 py-1 text-xs font-black text-emerald-800 hover:bg-emerald-100">تغيير</button>
                        </div>
                    @else
                        <div class="relative">
                            <flux:icon.user class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                            <input
                                id="customer-search-input" autofocus x-init="$nextTick(() => requestAnimationFrame(() => $el.focus({ preventScroll: true })))" x-on:focus="partySearchOpen = true" x-on:input="customerIndex = -1; refreshResultClasses(); partySearchOpen = true"
                                x-ref="customerSearch"
                                type="text"
                                wire:model.live.debounce.180ms="customerSearch"
                                placeholder="ابحث باسم العميل أو المورد أو الهاتف..."
                                autocomplete="off"
                                class="h-10 w-full rounded-lg border border-slate-300 bg-white pl-3 pr-9 text-sm font-bold text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                            />
                        </div>

                        @if (trim($customerSearch) !== '')
                            <div data-invoice-search-results x-cloak x-show="partySearchOpen" x-on:click="partySearchOpen = false" class="absolute right-0 top-full z-40 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                                <div class="max-h-64 overflow-y-auto p-1.5">
                                    @forelse ($customerResults as $customer)
                                        <button
                                            type="button"
                                            wire:key="sales-customer-result-{{ $customer->id }}"
                                            data-customer-result
                                            data-result-index="{{ $loop->index }}"
                                            wire:click="selectCustomer({{ $customer->id }})"
                                            class="group flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2.5 text-right transition hover:bg-slate-50"
                                        >
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-black text-slate-800">{{ $customer->name }}</div>
                                                <div class="mt-0.5 text-[11px] font-semibold text-slate-400">
                                                    {{ $customer->phone ?: 'بدون هاتف' }}
                                                </div>
                                            </div>

                                            <div class="text-left">
                                                <div class="font-mono text-xs font-black text-slate-700">
                                                    {{ number_format((float) $customer->current_balance, 2) }}
                                                </div>
                                                <div class="text-[10px] font-bold text-slate-400">الرصيد الحالي</div>
                                            </div>
                                        </button>
                                    @empty
                                        <div class="px-3 py-6 text-center text-xs font-bold text-slate-400">
                                            لا توجد نتائج مطابقة.
                                        </div>
                                    @endforelse
                                </div>
                            </div>
                        @endif
                    @endif
                </div>

                {{-- الفرع / المخزن --}}
                @if ($showBranchField)
                    <div class="xl:col-span-2">
                        <label for="sales-invoice-branch" class="mb-1 block text-xs font-bold text-slate-700">الفرع / المخزن</label>
                        <select
                            id="sales-invoice-branch"
                            wire:model.live="selectedBranchId"
                            @disabled($userBranchLocked || ($currentOrderId && $currentOrderType === 'pos'))
                            class="h-10 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm font-bold text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:opacity-70"
                        >
                            @foreach ($branches as $branch)
                                <option wire:key="sales-selected-branch-{{ $branch->id }}" value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- الرصيد الحالي --}}
                <div class="xl:col-span-2">
                    <div class="mb-1 block text-xs font-bold text-slate-700">الرصيد الحالي</div>
                    <div class="flex h-10 items-center justify-between rounded-lg border border-slate-300 bg-slate-50 px-3">
                        <span class="font-mono text-sm font-black {{ $selectedCustomer && (float) $selectedCustomer->current_balance < 0 ? 'text-rose-600' : 'text-slate-800' }}">
                            {{ $selectedCustomer ? number_format((float) $selectedCustomer->current_balance, 2) : '—' }}
                        </span>
                        <flux:icon.wallet class="size-4 text-slate-400" />
                    </div>
                </div>
            </div>
        </div>

        {{-- ================================================================
             جدول الفاتورة مع سطر الإدخال
        ================================================================= --}}

        <div class="relative z-30 mb-3 flex flex-col rounded-xl focus-within:z-50 border border-slate-200 bg-white shadow-sm xl:min-h-56 xl:flex-1" data-invoice-search-group x-data="{ productSearchOpen: true }" x-on:keydown.escape.stop="productSearchOpen = false">
            <div class="app-table-scroll max-h-[62vh] overflow-auto rounded-xl xl:max-h-none xl:min-h-0" role="region" aria-label="جدول البيانات — اسحب لعرض بقية الأعمدة" tabindex="0">
                <table class="w-full min-w-[900px] border-collapse text-right">

                    <thead class="sticky top-0 z-20">
                        <tr class="bg-slate-800 text-xs font-bold text-white">
                            <th class="w-12 border-l border-slate-700 px-3 py-3 text-center">#</th>
                            <th class="w-32 border-l border-slate-700 px-3 py-3 text-center">رقم الصنف</th>
                            <th class="border-l border-slate-700 px-3 py-3">اسم الصنف</th>
                            <th class="w-28 border-l border-slate-700 px-3 py-3 text-center">المخزون</th>
                            <th class="w-40 border-l border-slate-700 px-3 py-3 text-center">الكمية</th>
                            <th class="w-36 border-l border-slate-700 px-3 py-3 text-center">سعر الوحدة</th>
                            <th class="w-36 border-l border-slate-700 px-3 py-3 text-center">المجموع</th>
                            <th class="w-24 px-3 py-3 text-center">حذف</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y-2 divide-slate-500">
                        @foreach ($cart as $key => $item)
                            @php
                                $lineQuantity = (float) $item['quantity'];
                                $lineTotal = isset($item['total_override'])
                                    ? max(0, (float) $item['total_override'])
                                    : max(0, ((float) $item['price'] * $lineQuantity) - (float) ($item['discount'] ?? 0));
                            @endphp

                            <tr
                                data-invoice-row="{{ $loop->index }}"
                                wire:key="invoice-item-{{ $key }}"
                                class="group transition hover:bg-slate-50 focus-within:bg-blue-50 {{ $editingCartKey === $key || $replacingCartKey === $key ? 'bg-amber-50 ring-1 ring-inset ring-amber-200' : '' }}"
                            >
                                <td class="border-l border-slate-100 px-3 py-2 text-center text-sm font-black text-slate-800">
                                    {{ $loop->iteration }}
                                </td>

                                <td class="border-l border-slate-100 px-3 py-2 text-center font-mono text-sm font-semibold text-slate-700">
                                    {{ $cartProductNumbers[$item['id']] ?? $item['id'] }}
                                </td>

                                <td class="border-l border-slate-100 px-3 py-2">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="min-w-0 truncate text-sm font-black text-slate-900" title="تكلفة الوحدة: {{ number_format((float) $item['cost'], 2) }}">{{ $item['name'] }}</span>
                                        <div class="flex shrink-0 items-center gap-0.5 text-[11px] font-bold opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100 focus-within:opacity-100">
                                            <button type="button" wire:click="$dispatch('show-product-history', { productId: {{ $item['id'] }} })" class="rounded px-1.5 py-0.5 text-indigo-600 hover:bg-indigo-50">حركة الصنف</button>
                                            <button type="button" wire:click="openInlineReplacement('{{ $key }}')" class="rounded px-1.5 py-0.5 text-amber-700 hover:bg-amber-50">{{ $replacingCartKey === $key ? 'اختيار بديل' : 'استبدال الصنف' }}</button>
                                            <button type="button" data-price-history-button x-on:click.stop="$dispatch('open-product-price-history', { productId: {{ $item['id'] }}, customerId: {{ $selectedCustomerId ?? 'null' }} })" title="سجل الأسعار والتكلفة" aria-label="سجل الأسعار والتكلفة" class="rounded p-0.5 text-indigo-600 hover:bg-indigo-50">
                                                <flux:icon.currency-dollar class="size-4" />
                                            </button>
                                        </div>
                                    </div>
                                    @if ($replacingCartKey === $key)
                                        <div class="mt-2 space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-2.5">
                                            <div class="flex gap-2">
                                                <input type="search" wire:model.live.debounce.180ms="replacementSearch" x-init="replacementIndex = -1; $nextTick(() => $el.focus())"
                                                    x-on:input="replacementIndex = -1; refreshResultClasses()"
                                                    x-on:keydown.arrow-down.prevent.stop="moveResult('replacement', 1)"
                                                    x-on:keydown.arrow-up.prevent.stop="moveResult('replacement', -1)"
                                                    x-on:keydown.enter.prevent.stop="clickCurrentResult('replacement')"
                                                    x-on:keydown.escape.prevent.stop="$wire.cancelInlineReplacement()" placeholder="ابحث عن الصنف البديل بالاسم أو الرقم أو الباركود..." class="min-w-0 flex-1 rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs font-bold text-slate-800 outline-none focus:border-amber-400" />
                                                <button type="button" wire:click="cancelInlineReplacement" class="rounded-lg border border-amber-200 bg-white px-3 py-2 text-xs font-bold text-amber-800">إلغاء</button>
                                            </div>
                                            @if (trim($replacementSearch) !== '')
                                                <div class="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-amber-100 bg-white p-1.5">
                                                    @forelse ($replacementProductResults as $replacementProduct)
                                                        @php
                                                            $replacementBranchProduct = $replacementProduct->branchProducts->first();
                                                            $replacementPrice = $currentOrderType === 'pos'
                                                                ? (float) ($replacementBranchProduct?->retail_price ?? 0)
                                                                : (float) ($replacementBranchProduct?->wholesale_price > 0 ? $replacementBranchProduct?->wholesale_price : ($replacementBranchProduct?->retail_price ?? 0));
                                                        @endphp
                                                        <button type="button" data-replacement-result wire:key="replacement-product-{{ $key }}-{{ $replacementProduct->id }}" wire:click="replaceCartItem({{ $replacementProduct->id }})" wire:loading.attr="disabled" class="flex w-full items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-right text-xs hover:bg-indigo-50 disabled:opacity-50">
                                                            <span class="min-w-0 truncate font-black text-slate-800">{{ $replacementProduct->name }} <span class="font-mono font-semibold text-slate-400">({{ $replacementProduct->product_number ?? $replacementProduct->id }})</span></span>
                                                            <span class="shrink-0 font-mono font-bold text-indigo-700">{{ number_format($replacementPrice, 2) }}</span>
                                                        </button>
                                                    @empty
                                                        <div class="p-3 text-center text-xs font-bold text-slate-500">لا توجد أصناف مطابقة.</div>
                                                    @endforelse
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </td>

                                <td class="border-l border-slate-100 px-3 py-2 text-center font-mono text-sm font-semibold {{ (float) ($item['stock'] ?? 0) < $lineQuantity ? 'text-rose-600' : 'text-slate-600' }}">
                                    {{ number_format((float) ($item['stock'] ?? 0), 2) }}
                                </td>

                                <td class="border-l border-slate-100 px-3 py-2 text-center">
                                    <div class="mx-auto flex w-32 items-stretch overflow-hidden rounded-lg border border-slate-300 bg-white focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-100">
                                        <div class="flex flex-col border-l border-slate-200">
                                            <button type="button" tabindex="-1" wire:click="incrementItem('{{ $key }}')" aria-label="زيادة الكمية" class="flex flex-1 items-center px-1.5 text-slate-600 hover:bg-slate-100"><flux:icon.chevron-up class="size-3" /></button>
                                            <button type="button" tabindex="-1" wire:click="decrementItem('{{ $key }}')" aria-label="إنقاص الكمية" class="flex flex-1 items-center px-1.5 text-slate-600 hover:bg-slate-100"><flux:icon.chevron-down class="size-3" /></button>
                                        </div>
                                        <input
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            value="{{ number_format($lineQuantity, 2, '.', '') }}"
                                            data-grid-input
                                            data-row="{{ $loop->index }}"
                                            data-col="0"
                                            wire:input.debounce.300ms="updateItemQuantity('{{ $key }}', $event.target.value)"
                                            aria-label="الكمية"
                                            class="w-full min-w-0 border-0 bg-transparent px-2 py-1.5 text-center text-sm font-black text-slate-900 outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                        />
                                    </div>
                                </td>

                                <td class="border-l border-slate-100 px-3 py-2 text-center">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value="{{ number_format((float) $item['price'], 2, '.', '') }}"
                                        data-customer-price-target
                                        data-cart-key="{{ $key }}"
                                        data-grid-input
                                        data-row="{{ $loop->index }}"
                                        data-col="1"
                                        wire:input.debounce.300ms="updateItemPrice('{{ $key }}', $event.target.value)"
                                        aria-label="سعر الوحدة"
                                        class="mx-auto block h-9 w-28 rounded-lg border border-slate-300 bg-white px-2 text-center text-sm font-bold text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                    />
                                </td>

                                <td class="border-l border-slate-100 bg-slate-50 px-3 py-2 text-center">
                                    <input type="number" min="0" step="0.01" value="{{ number_format((float) $lineTotal, 2, '.', '') }}" data-grid-input data-row="{{ $loop->index }}" data-col="2" wire:input.debounce.300ms="updateItemTotal('{{ $key }}', $event.target.value)" class="mx-auto block h-9 w-28 rounded-lg border border-transparent bg-transparent px-2 text-center text-base font-black text-slate-900 outline-none hover:border-slate-300 hover:bg-white focus:border-blue-500 focus:bg-white focus:ring-2 focus:ring-blue-100 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none" aria-label="إجمالي السطر" title="يمكن تعديل المجموع وسيُعاد حساب سعر الوحدة" />
                                </td>

                                <td class="px-3 py-2 text-center">
                                    <button
                                        type="button"
                                        wire:click="removeItem('{{ $key }}')"
                                        class="rounded-lg p-1.5 text-rose-600 transition hover:bg-rose-50"
                                        title="حذف السطر"
                                        aria-label="حذف السطر"
                                    >
                                        <flux:icon.trash class="size-5" />
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>

                    {{-- سطر إدخال صنف جديد --}}
                    <tfoot class="sticky bottom-0 z-20">
                        <tr data-invoice-entry-row class="bg-blue-50 [&>td]:shadow-[inset_0_2px_0_0_#93c5fd]">
                            <td class="px-2 py-2 text-center">
                                <button type="button" x-on:click="$refs.productSearch?.focus(); $refs.productSearch?.select()" title="إضافة صنف (F9)" aria-label="إضافة صنف" class="inline-flex size-9 items-center justify-center rounded-lg bg-blue-600 text-white shadow-sm hover:bg-blue-500">
                                    <flux:icon.plus class="size-5" />
                                </button>
                            </td>

                            <td colspan="2" class="px-2 py-2">
                                <div class="relative">
                                    <flux:icon.magnifying-glass class="pointer-events-none absolute right-3 top-1/2 z-10 size-5 -translate-y-1/2 text-slate-500" />
                                    <button type="button" x-ref="priceHistoryTrigger" data-entry-price-history aria-label="أسعار الصنف والتكلفة (F2)" title="أسعار الصنف والتكلفة (F2)" @disabled(!$selectedProductId) x-on:click.stop="$dispatch('open-product-price-history', { productId: {{ $selectedProductId ?? 'null' }}, customerId: {{ $selectedCustomerId ?? 'null' }} })" class="absolute left-1.5 top-1/2 z-10 -translate-y-1/2 rounded-md border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-bold text-indigo-700 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-40">الأسعار F2</button>
                                    <input
                                        id="product-search-input"
                                        x-ref="productSearch"
                                        type="text"
                                        wire:model.live.debounce.120ms="rowSearch"
                                        maxlength="120" x-on:input="productIndex = -1; productSearchOpen = true; updateSearchDirection($event.target); $nextTick(() => refreshResultClasses())" x-on:focus="productSearchOpen = true; updateSearchDirection($event.target)"
                                        autocomplete="off"
                                        aria-label="رقم الصنف / الباركود / اسم الصنف"
                                        placeholder="رقم الصنف / الباركود / اسم الصنف ثم Enter..."
                                        class="h-9 w-full rounded-md border border-blue-300 bg-white pl-24 pr-10 text-sm font-bold text-slate-900 outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                    />
                                </div>
                            </td>

                            <td class="px-2 py-2 text-center font-mono text-sm font-semibold text-slate-600">
                                {{ $selectedProductId ? number_format((float) $selectedStock, 2) : '—' }}
                            </td>

                            <td class="px-2 py-2">
                                <input
                                    x-ref="quantityInput"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    wire:model="selectedQuantity"
                                    aria-label="الكمية"
                                    x-on:focus="$event.target.select()"
                                    x-on:keydown.enter.prevent.stop="$refs.priceInput?.focus(); $refs.priceInput?.select()"
                                    class="mx-auto block h-9 w-32 rounded-md border border-slate-300 bg-white px-2 text-center text-sm font-black text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                />
                            </td>

                            <td class="px-2 py-2">
                                <input
                                    x-ref="priceInput"
                                    data-customer-price-target
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    wire:model="selectedPrice"
                                    aria-label="سعر الوحدة"
                                    x-on:focus="$event.target.select()"
                                    x-on:keydown.enter.prevent.stop="$refs.entryTotalInput?.focus(); $refs.entryTotalInput?.select()"
                                    class="mx-auto block h-9 w-28 rounded-md border border-slate-300 bg-white px-2 text-center text-sm font-black text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                />
                            </td>

                            <td class="px-2 py-2">
                                <input
                                    x-ref="entryTotalInput"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    aria-label="إجمالي الصنف"
                                    x-effect="if (document.activeElement !== $el) $el.value = Math.max(0, Number($wire.selectedPrice) * Number($wire.selectedQuantity)).toFixed(2)"
                                    x-on:input="const total = Number($event.target.value); const quantity = Number($wire.selectedQuantity); if ($event.target.value !== '' && Number.isFinite(total) && total >= 0 && Number.isFinite(quantity) && quantity > 0) { $wire.selectedPrice = Math.round(total / quantity * 100) / 100; }"
                                    x-on:focus="$event.target.select()"
                                    wire:keydown.enter.prevent.stop="commitRowToCart"
                                    class="mx-auto block h-9 w-28 rounded-md border border-slate-300 bg-white px-2 text-center font-mono text-sm font-black text-slate-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                                />
                            </td>

                            <td class="px-2 py-2 text-center">
                                <button
                                    type="button"
                                    wire:click="commitRowToCart"
                                    title="إضافة السطر (Enter)"
                                    class="inline-flex h-9 items-center justify-center gap-1 rounded-lg bg-emerald-600 px-3 text-xs font-black text-white shadow-sm transition hover:bg-emerald-500 disabled:opacity-50"
                                >
                                    @if ($editingCartKey !== null)
                                        حفظ التعديل
                                    @else
                                        <flux:icon.plus-circle class="size-4" /> إضافة
                                    @endif
                                </button>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            @if ($editingCartKey !== null)
                <div class="order-first flex items-center justify-between gap-2 border-b border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-800">
                    <span>اختر الصنف البديل للسطر المحدد، ثم احفظ التعديل.</span>
                    <button type="button" wire:click="cancelCartItemEdit" class="shrink-0 rounded px-2 py-1 text-amber-900 hover:bg-amber-100">إلغاء</button>
                </div>
            @endif

            @if (trim($rowSearch) !== '' && !$selectedProductId)
                <div data-invoice-search-results x-on:click="if ($event.target.closest('[data-product-result]')) productSearchOpen = false" x-bind:style="searchDropUp ? { bottom: searchOffset + 'px' } : { top: searchOffset + 'px' }" class="absolute right-12 z-50 w-[min(46rem,calc(100%-3rem))] overflow-hidden rounded-xl border border-slate-200 bg-white shadow-2xl">
                    <div class="overflow-auto" x-ref="productResults" x-bind:style="{ maxHeight: searchMaxHeight + 'px' }">
                        @if ($productResults->isEmpty())
                            <div class="p-4 text-center text-sm font-bold text-slate-500">لا توجد أصناف مطابقة للبحث.</div>
                        @else
                        <table class="w-full text-right text-sm">
                            <thead class="sticky top-0 bg-slate-800 text-xs text-white"><tr><th class="p-2">رقم الصنف</th><th class="p-2">اسم الصنف</th><th class="p-2">السعر</th><th class="p-2">المخزون</th></tr></thead>
                            <tbody>
                                @foreach ($productResults as $product)
                                    @php
                                        $bp = $product->branchProducts->first();
                                        $resultPrice = $currentOrderType === 'pos'
                                            ? (float) ($bp?->retail_price ?? 0)
                                            : (float) ($bp?->wholesale_price > 0 ? $bp?->wholesale_price : ($bp?->retail_price ?? 0));
                                        $stock = (float) ($bp?->stock_quantity ?? 0);
                                    @endphp
                                    <tr wire:key="sales-product-result-{{ $product->id }}" data-product-result data-result-index="{{ $loop->index }}" x-on:mousedown.prevent wire:click="selectProduct({{ $product->id }})" wire:loading.attr="disabled" class="cursor-pointer border-t border-slate-100 hover:bg-blue-50">
                                        <td class="p-2 font-mono">{{ $product->product_number ?? $product->id }}</td>
                                        <td class="p-2 font-bold">{{ $product->name }}</td>
                                        <td class="p-2 font-mono">{{ number_format($resultPrice, 2) }}</td>
                                        <td class="p-2 tabular-nums">{{ number_format($stock, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- ================================================================
             أسفل الفاتورة
        ================================================================= --}}

        @php
            $invoiceGrossTotal = 0.0;
            $invoiceTotalQuantity = 0.0;

            foreach ($cart as $cartLine) {
                $invoiceGrossTotal += (float) ($cartLine['price'] ?? 0) * (float) ($cartLine['quantity'] ?? 0);
                $invoiceTotalQuantity += (float) ($cartLine['quantity'] ?? 0);
            }

            $invoiceLineDiscounts = max(0, $invoiceGrossTotal - $subtotal);
            $invoiceTotalDiscount = $invoiceLineDiscounts + $invoiceDiscount;
            $invoiceAfterDiscount = max(0, $subtotal - $invoiceDiscount);
        @endphp

        <div class="grid shrink-0 gap-3 xl:mt-auto xl:grid-cols-12">

            {{-- الملاحظات --}}
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm xl:col-span-4">
                <label for="sales-invoice-notes" class="mb-2 flex items-center gap-1.5 text-sm font-black text-slate-800">
                    <flux:icon.chat-bubble-left-ellipsis class="size-5 text-slate-500" /> ملاحظات
                </label>
                <textarea
                    id="sales-invoice-notes"
                    wire:model="notes"
                    rows="3"
                    placeholder="اكتب ملاحظة على الفاتورة أو شروط البيع..."
                    class="w-full resize-none rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                ></textarea>

                @if ($currentOrderId && $linkedReceipts->isNotEmpty())
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($linkedReceipts as $linkedReceipt)
                            <a href="{{ route('tenant.receipts', ['voucher' => $linkedReceipt->id]) }}" class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-black text-indigo-700 hover:bg-indigo-100">
                                تعديل سند القبض {{ $linkedReceipt->voucher_number }} — {{ number_format((float) $linkedReceipt->amount, 2) }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- الكميات --}}
            <div class="grid grid-cols-2 gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm xl:col-span-2 xl:grid-cols-1">
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <div class="text-xs font-bold text-slate-500">إجمالي الكمية</div>
                    <div class="mt-1 flex items-center justify-between">
                        <span class="font-mono text-lg font-black text-slate-900">{{ number_format($invoiceTotalQuantity, 2) }}</span>
                        <flux:icon.cube class="size-5 text-slate-400" />
                    </div>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                    <div class="text-xs font-bold text-slate-500">عدد الأصناف</div>
                    <div class="mt-1 flex items-center justify-between">
                        <span class="font-mono text-lg font-black text-slate-900">{{ count($cart) }}</span>
                        <flux:icon.square-3-stack-3d class="size-5 text-slate-400" />
                    </div>
                </div>
            </div>

            {{-- الإجماليات --}}
            <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm xl:col-span-4">
                <div class="grid grid-cols-3 divide-x divide-x-reverse divide-slate-200 text-center">
                    <div class="px-2">
                        <div class="text-xs font-bold text-slate-500">إجمالي قبل الخصم</div>
                        <div class="mt-1 font-mono text-lg font-black text-slate-900">{{ number_format($invoiceGrossTotal, 2) }}</div>
                    </div>
                    <div class="px-2">
                        <div class="text-xs font-bold text-slate-500">إجمالي بعد الخصم</div>
                        <div class="mt-1 font-mono text-lg font-black text-slate-900">{{ number_format($invoiceAfterDiscount, 2) }}</div>
                    </div>
                    <div class="px-2">
                        <div class="text-xs font-bold text-slate-500">الخصم الإجمالي</div>
                        <div class="mt-1 font-mono text-lg font-black text-rose-600">{{ number_format($invoiceTotalDiscount, 2) }}</div>
                    </div>
                </div>

                <div class="mt-3 grid gap-2 border-t border-slate-100 pt-3 {{ $showTax ? 'grid-cols-3' : 'grid-cols-1' }}">
                    <label class="text-xs font-bold text-slate-600">خصم إضافي
                        <input type="number" min="0" step="0.01" wire:model.live="discount" class="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-center text-sm font-black text-rose-600 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                    </label>
                    @if ($showTax)
                        <label class="text-xs font-bold text-slate-600">نسبة الضريبة %
                            <input type="number" min="0" step="0.01" wire:model.live.debounce.500ms="taxPercent" class="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-center text-sm font-black text-slate-800 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100" />
                        </label>
                        <div class="text-xs font-bold text-slate-600">قيمة الضريبة المضمنة
                            <div class="mt-1 flex h-9 items-center justify-center rounded-lg border border-slate-200 bg-slate-50 font-mono text-sm font-black text-slate-800">{{ number_format($taxAmount, 2) }}</div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- الإجمالي النهائي --}}
            <div class="flex flex-col justify-between rounded-xl bg-emerald-700 p-4 text-white shadow-sm xl:col-span-2">
                <div class="text-sm font-black text-emerald-50">الإجمالي النهائي</div>
                <div class="mt-2 font-mono text-3xl font-black tabular-nums">{{ number_format($grandTotal, 2) }}</div>

                @if ($currentOrderType === 'pos' && $posDeliveryFee > 0)
                    <div class="mt-1 text-xs font-bold text-emerald-100">يشمل التوصيل: {{ number_format($posDeliveryFee, 2) }}</div>
                @endif

                @if ($accountBalanceAfterInvoice !== null)
                    <div class="mt-2 border-t border-emerald-500/60 pt-2 text-xs font-bold text-emerald-100">
                        الرصيد بعد الفاتورة:
                        <span class="font-mono font-black text-white">{{ number_format($accountBalanceAfterInvoice, 2) }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- ================================================================
             اختصارات لوحة المفاتيح
        ================================================================= --}}

        <div class="mt-3 flex shrink-0 flex-wrap items-center gap-x-4 gap-y-2 rounded-xl bg-slate-900 px-3 py-2 text-xs font-bold text-slate-200 shadow-lg">
            <span class="inline-flex items-center gap-1.5 text-slate-300"><flux:icon.question-mark-circle class="size-4" /> اختصارات لوحة المفاتيح</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">Enter</kbd> إضافة سطر / التالي</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">↑ ↓ ← →</kbd> التنقل بين الخانات</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F3</kbd> حفظ</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F6</kbd> حفظ وطباعة / طباعة المحفوظة</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">Shift+F2</kbd> جديد</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F2</kbd> الأسعار</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F7</kbd> استعلام</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F8</kbd> سعر الزبون السابق / العميل</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">F9</kbd> الصنف</span>
            <span><kbd class="rounded border border-slate-600 bg-slate-800 px-1.5 py-0.5 font-mono text-white">Esc</kbd> إغلاق القوائم</span>
        </div>

        {{-- ================================================================
             Placeholder للطباعة
        ================================================================= --}}

        <div id="sales-print-template" class="hidden"></div>
    </div>
    @if ($showCustomerStatementModal)
        <div class="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/55 p-3 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="كشف حساب الزبون" wire:key="sales-customer-statement-{{ $selectedCustomerId }}">
            <div class="flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl" x-on:keydown.escape.window="$wire.closeCustomerStatement()">
                <div class="flex items-center justify-between gap-3 border-b border-slate-200 p-4">
                    <div>
                        <h2 class="text-lg font-black text-slate-900">كشف حساب {{ $selectedCustomer?->name }}</h2>
                        <p class="mt-1 text-xs font-semibold text-slate-500">الحركات المسجلة للزبون ورصيده الحالي.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        @if ($currentOrderId && $selectedCustomerId)
                            <a href="{{ route('tenant.receipts', ['party_id' => $selectedCustomerId, 'order_id' => $currentOrderId, 'amount' => max(0, $grandTotal - ($currentOrderType === 'pos' ? $posDeliveryFee : 0) - $linkedReceiptAmount), 'notes' => 'سند قبض لفاتورة '.$invoiceNumber]) }}" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-black text-white hover:bg-emerald-700">إضافة سند قبض</a>
                        @endif
                        <button type="button" wire:click="closeCustomerStatement" class="rounded-lg border border-slate-200 px-3 py-2 text-sm font-black text-slate-600 hover:bg-slate-50" aria-label="إغلاق">✕</button>
                    </div>
                </div>
                <div class="grid shrink-0 gap-2 border-b border-slate-100 p-4 sm:grid-cols-3">
                    <div class="rounded-xl bg-slate-50 p-3"><div class="text-xs font-bold text-slate-500">الرصيد الافتتاحي</div><div class="mt-1 font-mono font-black">{{ number_format($customerStatementOpeningBalance, 2) }}</div></div>
                    <div class="rounded-xl bg-indigo-50 p-3"><div class="text-xs font-bold text-indigo-600">الرصيد الحالي المسجل</div><div class="mt-1 font-mono font-black text-indigo-800">{{ number_format($customerStatementCurrentBalance, 2) }}</div></div>
                    <div class="rounded-xl bg-emerald-50 p-3"><div class="text-xs font-bold text-emerald-700">الرصيد المحسوب من الحركات</div><div class="mt-1 font-mono font-black text-emerald-800">{{ number_format($customerStatementCalculatedBalance, 2) }}</div></div>
                </div>
                <div class="min-h-0 flex-1 overflow-auto">
                    <table class="w-full min-w-[680px] text-right text-sm">
                        <thead class="sticky top-0 bg-slate-900 text-white"><tr><th class="p-3">التاريخ</th><th class="p-3">البيان</th><th class="p-3">مدين</th><th class="p-3">دائن</th><th class="p-3">الرصيد</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr class="bg-slate-50 font-bold"><td class="p-3">—</td><td class="p-3">رصيد افتتاحي</td><td class="p-3">{{ $customerStatementOpeningBalance > 0 ? number_format($customerStatementOpeningBalance, 2) : '—' }}</td><td class="p-3">{{ $customerStatementOpeningBalance < 0 ? number_format(abs($customerStatementOpeningBalance), 2) : '—' }}</td><td class="p-3">{{ number_format($customerStatementOpeningBalance, 2) }}</td></tr>
                            @forelse ($customerStatementRows as $transaction)
                                <tr wire:key="sales-statement-row-{{ $loop->index }}"><td class="whitespace-nowrap p-3">{{ \Illuminate\Support\Carbon::parse($transaction['date'])->format('Y-m-d H:i') }}</td><td class="p-3">{{ $transaction['description'] }}</td><td class="p-3 tabular-nums">{{ $transaction['debit'] > 0 ? number_format($transaction['debit'], 2) : '—' }}</td><td class="p-3 tabular-nums">{{ $transaction['credit'] > 0 ? number_format($transaction['credit'], 2) : '—' }}</td><td class="p-3 font-bold tabular-nums">{{ number_format($transaction['balance'], 2) }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="p-8 text-center font-semibold text-slate-400">لا توجد حركات بعد الرصيد الافتتاحي.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
    <livewire:product-price-history-modal :key="'sales-price-history-singleton'" />
</flux:main>

@script
<script>
    Alpine.data('salesInvoiceKeyboard', () => ({
        productIndex: -1,
        customerIndex: -1,
        replacementIndex: -1,
        resultRefreshTimer: null,
        keyboardController: null,
        searchDropUp: false,
        searchMaxHeight: 320,
        searchOffset: 0,
        knownItemRows: 0,

        init() {
            if (this.keyboardController && !this.keyboardController.signal.aborted) return;

            this.keyboardController = new AbortController();
            window.addEventListener('keydown', (event) => this.globalKeydown(event), {
                signal: this.keyboardController.signal,
            });

            this.fitToViewport();
            this.$nextTick(() => this.fitToViewport());
            window.addEventListener('resize', () => this.fitToViewport(), { signal: this.keyboardController.signal });
            window.addEventListener('load', () => this.fitToViewport(), { signal: this.keyboardController.signal });
            setTimeout(() => this.fitToViewport(), 300);

            this.$nextTick(() => setTimeout(() => {
                const input = this.$el.querySelector('#customer-search-input') ?? this.$el.querySelector('#product-search-input');
                if (this.$el.isConnected) input?.focus({ preventScroll: true });
            }, 120));

            this.refreshResultClasses();

            Livewire.on('focus-product-search', () => {
                this.$nextTick(() => requestAnimationFrame(() => {
                    const input = this.$el.querySelector('#product-search-input');
                    if (!input || !this.$el.contains(input)) return;

                    input.focus({ preventScroll: true });
                    input.select();
                    this.scrollItemsToNewest();
                }));
            });

            Livewire.on('focus-quantity', () => {
                setTimeout(() => {
                    const input = this.entryRef('quantityInput');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }, 60);
            });

            Livewire.on('focus-customer-search', () => {
                setTimeout(() => {
                    const input = this.$el.querySelector('#customer-search-input');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }, 60);
            });

            Livewire.on('focus-invoice-search', () => {
                setTimeout(() => {
                    const input = this.entryRef('invoiceSearch');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                }, 60);
            });

            Livewire.hook('morph.updated', ({ el }) => {
                if (!this.$el.isConnected || !this.$el.contains(el)) return;
                clearTimeout(this.resultRefreshTimer);
                this.resultRefreshTimer = setTimeout(() => this.refreshResultClasses(), 10);
            });
        },

        destroy() {
            clearTimeout(this.resultRefreshTimer);
            this.keyboardController?.abort();
        },

        /** Finds an x-ref anywhere in this page, including refs declared inside nested x-data scopes. */
        /** Sizes the page to the visible window on desktop so only the items table scrolls. */
        fitToViewport() {
            const main = this.$el?.closest?.('[data-flux-main]');
            if (!main) return;

            // Stored on <html> because Livewire re-renders the component root and would drop an inline height.
            const root = document.documentElement;
            const top = main.getBoundingClientRect().top + window.scrollY;
            let height = Math.max(520, window.innerHeight - top);
            root.style.setProperty('--invoice-fit-height', height + 'px');

            // Remove any page scroll left over from padding or margins outside the invoice.
            requestAnimationFrame(() => {
                const overflow = (document.scrollingElement ?? root).scrollHeight - window.innerHeight;
                if (overflow > 0 && height - overflow >= 520) {
                    height -= overflow;
                    root.style.setProperty('--invoice-fit-height', height + 'px');
                }
                window.scrollTo(0, 0);
            });
        },

        /** Opens the product suggestions upward when there is not enough room below the entry row. */
        updateSearchDirection(input) {
            const rect = input.getBoundingClientRect();
            const card = input.closest('[data-invoice-search-group]');
            const cardRect = card?.getBoundingClientRect() ?? { top: 0, bottom: window.innerHeight };
            const header = card?.querySelector('thead')?.getBoundingClientRect().height ?? 0;
            const fitsInCard = window.innerWidth >= 1280;
            const bottomLimit = fitsInCard ? cardRect.bottom : window.innerHeight;
            const spaceBelow = bottomLimit - rect.bottom - 8;
            const spaceAbove = rect.top - cardRect.top - header - 8;

            this.searchDropUp = spaceAbove > spaceBelow;
            this.searchMaxHeight = Math.max(80, Math.min(360, this.searchDropUp ? spaceAbove : spaceBelow));
            this.searchOffset = this.searchDropUp
                ? cardRect.bottom - rect.top + 4
                : rect.bottom - cardRect.top + 4;
        },

        /** Scrolls the items table down to the newest line whenever a line was added. */
        scrollItemsToNewest() {
            const area = this.$root?.querySelector?.('[data-invoice-search-group] .app-table-scroll');
            if (!area) return;

            const rows = area.querySelectorAll('tbody > tr').length;
            if (rows > (this.knownItemRows ?? 0)) {
                area.scrollTo({ top: area.scrollHeight, behavior: 'smooth' });
            }
            this.knownItemRows = rows;
        },

        entryRef(name) {
            return this.$root.querySelector(`[x-ref="${name}"]`);
        },

        isTypingElement(element) {
            if (!element) return false;

            return element.matches('input, textarea, select, button, [contenteditable="true"]');
        },

        productResults() {
            return Array.from(this.$root.querySelectorAll('[data-product-result]')).filter(element => element.getClientRects().length > 0);
        },

        customerResults() {
            return Array.from(this.$root.querySelectorAll('[data-customer-result]')).filter(element => element.getClientRects().length > 0);
        },

        replacementResults() {
            return Array.from(this.$root.querySelectorAll('[data-replacement-result]')).filter(element => element.getClientRects().length > 0);
        },

        refreshResultClasses() {
            const products = this.productResults();
            const customers = this.customerResults();
            const replacements = this.replacementResults();
            this.replacementIndex = Math.min(this.replacementIndex, replacements.length - 1);
            replacements.forEach((element, index) => {
                element.classList.toggle('search-result-active', index === this.replacementIndex);
            });

            products.forEach((element, index) => {
                element.classList.toggle('search-result-active', index === this.productIndex);
                element.classList.toggle('ring-1', index === this.productIndex);
                element.classList.toggle('ring-inset', index === this.productIndex);
                element.classList.toggle('ring-indigo-300', index === this.productIndex);
            });

            customers.forEach((element, index) => {
                element.classList.toggle('search-result-active', index === this.customerIndex);
                element.classList.toggle('ring-1', index === this.customerIndex);
                element.classList.toggle('ring-inset', index === this.customerIndex);
                element.classList.toggle('ring-slate-300', index === this.customerIndex);
            });
        },

        moveResult(type, direction) {
            const list = type === 'product'
                ? this.productResults()
                : type === 'replacement' ? this.replacementResults() : this.customerResults();

            if (!list.length) return;

            const key = type === 'product' ? 'productIndex' : type === 'replacement' ? 'replacementIndex' : 'customerIndex';
            this[key] = direction > 0
                ? Math.min(list.length - 1, this[key] + 1)
                : Math.max(0, this[key] < 0 ? list.length - 1 : this[key] - 1);

            this.refreshResultClasses();

            list[this[key]]?.scrollIntoView({
                block: 'nearest',
                behavior: 'smooth'
            });
        },

        clickCurrentResult(type) {
            const list = type === 'product'
                ? this.productResults()
                : type === 'replacement' ? this.replacementResults() : this.customerResults();

            if (!list.length) return false;

            const key = type === 'product' ? 'productIndex' : type === 'replacement' ? 'replacementIndex' : 'customerIndex';
            const selected = list[this[key]] ?? (this[key] < 0 ? list[0] : null);
            if (!selected || selected.disabled) return false;

            selected.click();
            return true;
        },

        rowInputs() {
            return Array.from(this.$el.querySelectorAll('[data-grid-input]'))
                .filter((element) => element.getClientRects().length > 0);
        },

        gridInput(row, column) {
            return this.rowInputs().find((element) =>
                Number(element.dataset.row) === row && Number(element.dataset.col) === column
            ) ?? null;
        },

        moveGridInput(element, direction, axis) {
            const row = Number(element.dataset.row);
            const col = Number(element.dataset.col);

            if (axis === 'row') {
                const targetRow = row + direction;
                const target = this.gridInput(targetRow, col);

                if (target) {
                    target.focus();
                    target.select();
                } else if (direction > 0) {
                    this.entryRef('productSearch')?.focus();
                    this.entryRef('productSearch')?.select();
                }

                return;
            }

            const targetCol = col + direction;
            const target = this.gridInput(row, targetCol);

            if (target) {
                target.focus();
                target.select();
            }
        },

        focusNextGridInput(element) {
            const row = Number(element.dataset.row);
            const col = Number(element.dataset.col);

            const nextSameRow = this.gridInput(row, col + 1);

            if (nextSameRow) {
                nextSameRow.focus();
                nextSameRow.select();
                return;
            }

            const nextRow = this.gridInput(row + 1, 0);

            if (nextRow) {
                nextRow.focus();
                nextRow.select();
            } else {
                this.entryRef('productSearch')?.focus();
                this.entryRef('productSearch')?.select();
            }
        },

        closeSuggestions() {
            this.$wire.set('rowSearch', '');
            this.$wire.set('customerSearch', '');
        },

        globalKeydown(event) {
            const key = event.key;
            const target = event.target;

            if (key === 'F2') {
                event.preventDefault();
                if (event.shiftKey) {
                    this.$wire.startNewInvoice();
                } else {
                    this.entryRef('priceHistoryTrigger')?.click();
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
                this.$wire.toggleInvoiceSearch();
                return;
            }

            if (key === 'F8') {
                event.preventDefault();
                if (target?.matches?.('[data-customer-price-target]')) {
                    const priceInput = target;
                    const cartKey = priceInput.dataset.cartKey || null;

                    this.$wire.applyLastCustomerPrice(cartKey).then((price) => {
                        if (price !== null && document.activeElement === priceInput) {
                            priceInput.value = Number(price).toFixed(2);
                        }
                    });

                    return;
                }

                const input = this.$el.querySelector('#customer-search-input');
                input?.focus({ preventScroll: true });
                input?.select();
                return;
            }

            if (key === 'F9') {
                event.preventDefault();
                const input = this.$el.querySelector('#product-search-input');
                input?.focus({ preventScroll: true });
                input?.select();
                return;
            }

            if ((event.ctrlKey || event.metaKey) && key.toLowerCase() === 's') {
                event.preventDefault();
                this.$wire.saveInvoice();
                return;
            }

            if (key === 'Escape') {
                const hasProductResults = this.productResults().length > 0;
                const hasCustomerResults = this.customerResults().length > 0;

                if (hasProductResults || hasCustomerResults) {
                    event.preventDefault();
                    this.closeSuggestions();
                }

                return;
            }

            const productInput = target?.matches?.('#product-search-input') ?? false;
            const customerInput = target?.matches?.('#customer-search-input') ?? false;

            if (productInput) {
                if (key === 'ArrowDown') {
                    event.preventDefault();
                    this.moveResult('product', 1);
                    return;
                }

                if (key === 'ArrowUp') {
                    event.preventDefault();
                    this.moveResult('product', -1);
                    return;
                }

                if (key === 'Enter') {
                    event.preventDefault();
                    this.$wire.selectProductByBarcode(target.value).then((barcodeMatched) => {
                        if (barcodeMatched) {
                            this.productIndex = -1;
                            return;
                        }

                        const products = this.productResults();
                        if (this.productIndex >= 0 && products[this.productIndex]) {
                            products[this.productIndex].click();
                        } else {
                            products[0]?.click();
                        }
                    });
                    return;
                }
            }

            if (customerInput) {
                if (key === 'ArrowDown') {
                    event.preventDefault();
                    this.moveResult('customer', 1);
                    return;
                }

                if (key === 'ArrowUp') {
                    event.preventDefault();
                    this.moveResult('customer', -1);
                    return;
                }

                if (key === 'Enter') {
                    const clicked = this.clickCurrentResult('customer');

                    if (clicked) {
                        event.preventDefault();
                    }

                    return;
                }
            }

            if (target?.matches('[data-grid-input]')) {
                if (key === 'ArrowDown') {
                    event.preventDefault();
                    this.moveGridInput(target, 1, 'row');
                    return;
                }

                if (key === 'ArrowUp') {
                    event.preventDefault();
                    this.moveGridInput(target, -1, 'row');
                    return;
                }

                if (key === 'ArrowRight') {
                    event.preventDefault();
                    this.moveGridInput(target, 1, 'col');
                    return;
                }

                if (key === 'ArrowLeft') {
                    event.preventDefault();
                    this.moveGridInput(target, -1, 'col');
                    return;
                }

                if (key === 'Enter') {
                    event.preventDefault();
                    this.focusNextGridInput(target);
                    return;
                }
            }

            if (!this.isTypingElement(target)) {
                if (key === 'ArrowDown') {
                    event.preventDefault();
                    this.entryRef('productSearch')?.focus();
                    this.entryRef('productSearch')?.select();
                }
            }
        }
    }));

    Livewire.on('print-sales-invoice', ({ invoice }) => {
        if (!invoice) return;
        if (invoice.pdf_url && window.printPdf) { window.printPdf(invoice.pdf_url, invoice.file_title); return; }

        const items = Array.isArray(invoice.items) ? invoice.items : [];

        const itemRows = items.map((item, index) => `
            <tr>
                <td>${index + 1}</td>
                <td class="num" data-print-product-number>${escapeHtml(item.product_number ?? '')}</td>
                <td>${escapeHtml(item.name ?? '')}</td>
                <td class="num">${Number(item.quantity ?? 0).toFixed(2)}</td>
                <td class="num">${Number(item.price ?? 0).toFixed(2)}</td>
                <td class="num">${Number(item.discount ?? 0).toFixed(2)}</td>
                <td class="num">${Number(item.total ?? 0).toFixed(2)}</td>
            </tr>
        `).join('');

        const html = `
            <!doctype html>
            <html lang="ar" dir="rtl">
            <head>
                <meta charset="utf-8">
                <title>${escapeHtml(invoice.file_title ?? ('فاتورة مبيعات - ' + (invoice.number ?? '')))}</title>
                <style>
                    * { box-sizing: border-box; }
                    body { margin: 0; padding: 18mm; font-family: Tahoma, Arial, sans-serif; color: #111827; background: #fff; }
                    .page { max-width: 920px; margin: 0 auto; }
                    .header { display: flex; justify-content: space-between; gap: 24px; padding-bottom: 18px; border-bottom: 2px solid #111827; }
                    .title { font-size: 26px; font-weight: 900; }
                    .meta { margin-top: 8px; font-size: 12px; line-height: 1.8; color: #4b5563; }
                    .box-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 18px 0; }
                    .box { border: 1px solid #d1d5db; border-radius: 10px; padding: 12px; }
                    .label { font-size: 10px; font-weight: 700; color: #6b7280; }
                    .value { margin-top: 5px; font-size: 14px; font-weight: 900; }
                    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
                    th, td { border: 1px solid #d1d5db; padding: 8px; font-size: 11px; }
                    th { background: #f3f4f6; font-weight: 900; }
                    .num { text-align: center; font-family: Arial, sans-serif; }
                    .summary { width: 360px; margin-top: 16px; margin-right: auto; }
                    .summary-row { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; border-bottom: 1px solid #e5e7eb; font-size: 12px; }
                    .total { font-size: 18px; font-weight: 900; padding-top: 10px; }
                    .notes { margin-top: 20px; padding-top: 12px; border-top: 1px solid #d1d5db; font-size: 11px; white-space: pre-wrap; }
                    @page { size: A4; margin: 10mm; }
                    @media print { body { padding: 0; } }
                </style>
            </head>
            <body>
                <div class="page">
                    <div class="header">
                        <div>
                            <div data-invoice-store-name style="font-size:20px;font-weight:700;margin-bottom:8px">${escapeHtml(invoice.store_name ?? '')}</div>
                            <div class="title">فاتورة مبيعات</div>
                            <div class="meta">
                                رقم الفاتورة: <strong>${escapeHtml(invoice.number ?? '')}</strong><br>
                                التاريخ: <strong>${escapeHtml(invoice.date ?? '')}</strong>
                            </div>
                        </div>
                        <div style="text-align:left">
                            <div class="label">الزبون</div>
                            <div class="value">${escapeHtml(invoice.customer ?? 'زبون عام')}</div>
                            ${invoice.phone ? `<div class="meta">${escapeHtml(invoice.phone)}</div>` : ''}
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th style="width:40px">#</th>
                                <th style="width:110px">رقم الصنف</th>
                                <th>الصنف</th>
                                <th style="width:85px">الكمية</th>
                                <th style="width:100px">السعر</th>
                                <th style="width:90px">الخصم</th>
                                <th style="width:110px">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>${itemRows}</tbody>
                    </table>

                    <div class="summary" data-invoice-balance-summary>
                        <div class="summary-row"><span>المجموع</span><strong>${Number(invoice.summary?.total ?? 0).toFixed(2)}</strong></div>
                        <div class="summary-row"><span>الخصم</span><strong>${Number(invoice.summary?.discount ?? 0).toFixed(2)}</strong></div>
                        <div class="summary-row"><span>دفعة</span><strong>${Number(invoice.summary?.payment ?? 0).toFixed(2)}</strong></div>
                        ${invoice.summary?.previous_balance != null ? `<div class="summary-row"><span>رصيد سابق</span><strong>${Number(invoice.summary.previous_balance).toFixed(2)}</strong></div>` : ''}
                        ${invoice.summary?.new_balance != null ? `<div class="total summary-row"><span>رصيد جديد</span><strong>${Number(invoice.summary.new_balance).toFixed(2)}</strong></div>` : ''}
                    </div>

                    ${invoice.notes ? `<div class="notes"><strong>ملاحظات:</strong><br>${escapeHtml(invoice.notes)}</div>` : ''}
                </div>
                <x-invoice-copyright />

                <script>
                    window.onload = function () {
                        setTimeout(function () { window.print(); }, 200);
                    };
                <\/script>
            </body>
            </html>
        `;

        const printWindow = window.open('', '_blank', 'width=1000,height=900');

        if (!printWindow) {
            return;
        }

        printWindow.document.open();
        printWindow.document.write(html);
        printWindow.document.close();
    });

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

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
</script>
@endscript
