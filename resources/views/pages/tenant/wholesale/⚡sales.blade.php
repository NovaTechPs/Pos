<?php

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination;

    /*
    |--------------------------------------------------------------------------
    | Search / Products
    |--------------------------------------------------------------------------
    */

    public string $search = '';

    /*
    |--------------------------------------------------------------------------
    | Customer Search
    |--------------------------------------------------------------------------
    */

    public string $customerSearch = '';
    public bool $showCustomerDropdown = false;

    /*
    |--------------------------------------------------------------------------
    | Cart
    |--------------------------------------------------------------------------
    */

    public array $cart = [];

    /*
    |--------------------------------------------------------------------------
    | Customer
    |--------------------------------------------------------------------------
    */

    public ?int $selectedCustomerId = null;

    /*
    |--------------------------------------------------------------------------
    | Invoice
    |--------------------------------------------------------------------------
    */

    public string $notes = '';

    public float $paidAmount = 0;

    public string $paymentMethod = 'cash';

    /*
    |--------------------------------------------------------------------------
    | Discount
    |--------------------------------------------------------------------------
    */

    public string $discountType = 'fixed';

    public float $discountAmount = 0;

    public float $discountRate = 0;

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    */

    public bool $sendWhatsapp = false;

    /*
    |--------------------------------------------------------------------------
    | Price History
    |--------------------------------------------------------------------------
    */

    public bool $showPriceHistoryModal = false;

    public ?array $selectedHistoryItem = null;

    /*
    |--------------------------------------------------------------------------
    | New Invoice Confirmation
    |--------------------------------------------------------------------------
    */

    public bool $showNewInvoiceConfirm = false;

    /*
    |--------------------------------------------------------------------------
    | Below Cost Confirmation
    |--------------------------------------------------------------------------
    */

    public bool $showBelowCostConfirm = false;

    public ?int $pendingBelowCostProductId = null;

    public float $pendingBelowCostPrice = 0;

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */

    public bool $isSaving = false;

    /*
    |--------------------------------------------------------------------------
    | Receipt Voucher
    |--------------------------------------------------------------------------
    */

    public bool $showReceiptForm = false;

    public string $receiptPartySearch = '';

    public bool $receiptPartyDropdownOpen = false;

    public ?int $receiptPartyId = null;

    public string $receiptPaymentDate = '';

    public string $receiptPaymentMethod = 'cash';

    public string $receiptAmount = '';

    public string $receiptNotes = '';

    public string $receiptVoucherNumber = '';

    /*
    |--------------------------------------------------------------------------
    | Tenant / Branch
    |--------------------------------------------------------------------------
    */

    private function tenantId(): ?int
    {
        $user = auth()->user();

        return session('active_tenant_id') ?: $user?->tenant_id ?: $user?->tenants?->first()?->id;
    }

    private function branchId(): ?int
    {
        $user = auth()->user();

        return session('active_branch_id') ?: $user?->branch_id;
    }

    private function currentUser(): ?object
    {
        return auth()->user();
    }

    /*
    |--------------------------------------------------------------------------
    | Stock Settings
    |--------------------------------------------------------------------------
    | These settings only control stock availability for this wholesale page.
    | The existing UI, invoice flow, payments, discounts and other tasks remain
    | unchanged.
    */

    private function allowNegativeStock(): bool
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return false;
        }

        return TenantSetting::getBool((int) $tenantId, 'allow_negative_stock', false);
    }

    private function unifiedStock(): bool
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return false;
        }

        return TenantSetting::getBool((int) $tenantId, 'unified_stock', false);
    }

    private function availableStock(int $tenantId, int $productId, int $branchId): float
    {
        $query = BranchProduct::query()->where('tenant_id', $tenantId)->where('product_id', $productId);

        if (!$this->unifiedStock()) {
            $query->where('branch_id', $branchId);
        }

        return (float) $query->sum('stock_quantity');
    }

    private function saleBranchProduct(int $tenantId, int $productId, int $branchId): ?BranchProduct
    {
        $current = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('product_id', $productId)->with('product')->first();

        if ($current || !$this->unifiedStock()) {
            return $current;
        }

        return BranchProduct::query()->where('tenant_id', $tenantId)->where('product_id', $productId)->with('product')->orderBy('id')->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /*
    |--------------------------------------------------------------------------
    | Customer Search
    |--------------------------------------------------------------------------
    */

    public function updatedCustomerSearch(): void
    {
        $this->showCustomerDropdown = true;
    }

    public function openCustomerDropdown(): void
    {
        $this->showCustomerDropdown = true;
    }

    public function closeCustomerDropdown(): void
    {
        $this->showCustomerDropdown = false;
    }

    public function selectCustomer(int $customerId): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            session()->flash('error', 'تعذر تحديد المتجر الحالي.');
            return;
        }

        $customer = Party::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_active', true)
            ->find($customerId);

        if (!$customer) {
            session()->flash('error', 'العميل المحدد غير صالح.');
            return;
        }

        $this->selectedCustomerId = $customer->id;
        $this->customerSearch = $customer->name;
        $this->showCustomerDropdown = false;
    }

    public function clearCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->customerSearch = '';
        $this->showCustomerDropdown = false;
    }

    /*
    |--------------------------------------------------------------------------
    | Discount
    |--------------------------------------------------------------------------
    */

    public function updatedDiscountType(): void
    {
        if ($this->discountType === 'percentage') {
            $this->discountAmount = 0;
        } else {
            $this->discountRate = 0;
        }
    }

    public function updatedDiscountAmount($value): void
    {
        $this->discountAmount = max(0, (float) $value);
    }

    public function updatedDiscountRate($value): void
    {
        $this->discountRate = min(100, max(0, (float) $value));
    }

    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    */

    public function updatedPaidAmount($value): void
    {
        // السماح بأن يكون المدفوع أكبر من إجمالي الفاتورة.
        $this->paidAmount = max(0, round((float) $value, 2));
    }

    public function setFullPayment(): void
    {
        $this->paidAmount = $this->total;
    }

    public function setPaymentAmount(float $amount): void
    {
        $this->paidAmount = max(0, round($amount, 2));
    }

    /*
    |--------------------------------------------------------------------------
    | Barcode
    |--------------------------------------------------------------------------
    */

    public function searchBarcode(): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->branchId();

        if (!$tenantId || !$branchId) {
            session()->flash('error', 'لا يمكن إصدار فاتورة قبل ربط المستخدم بمتجر وفرع.');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Exact barcode first
        |--------------------------------------------------------------------------
        */

        $product = Product::query()
            ->where('products.tenant_id', $tenantId)
            ->whereHas('barcodes', function ($query) use ($term) {
                $query->where('barcode', $term);
            })
            ->whereHas('branchProducts', function ($query) use ($branchId) {
                if (!$this->unifiedStock()) {
                    $query->where('branch_id', $branchId);
                }
            })
            ->first();

        if (!$product) {
            session()->flash('error', 'لم يتم العثور على منتج بهذا الباركود.');

            return;
        }

        $this->addToCart($product->id);

        $this->search = '';

        $this->resetPage();
    }

    /*
    |--------------------------------------------------------------------------
    | Cart
    |--------------------------------------------------------------------------
    */

    public function addToCart(int $productId): void
    {
        $tenantId = $this->tenantId();
        $branchId = $this->branchId();

        if (!$tenantId || !$branchId) {
            session()->flash('error', 'المستخدم غير مرتبط بفرع.');

            return;
        }

        $branchProduct = $this->saleBranchProduct($tenantId, $productId, $branchId);

        if (!$branchProduct?->product) {
            session()->flash('error', 'المنتج غير مرتبط بأي فرع في المتجر.');

            return;
        }

        $stock = $this->availableStock($tenantId, $productId, $branchId);

        $currentQty = (float) ($this->cart[$productId]['quantity'] ?? 0);

        if (!$this->allowNegativeStock() && $stock <= $currentQty) {
            session()->flash('error', "الكمية المتوفرة من {$branchProduct->product->name} هي {$stock}.");

            return;
        }

        $price = (float) ($branchProduct->wholesale_price > 0 ? $branchProduct->wholesale_price : $branchProduct->retail_price);

        /*
        |--------------------------------------------------------------------------
        | Existing item
        |--------------------------------------------------------------------------
        */

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity'] = $currentQty + 1;

            // بعد إضافة الصنف/زيادة كميته انزل بالسلة إلى آخر صنف.
            $this->dispatch('wholesale-cart-added');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | New item
        |--------------------------------------------------------------------------
        */

        $this->cart[$productId] = [
            'id' => $branchProduct->product->id,
            'name' => $branchProduct->product->name,
            'image' => $branchProduct->product->image,
            'price' => $price,
            'cost' => (float) $branchProduct->product->cost_price,
            'quantity' => 1,
            'stock' => $stock,
        ];

        // بعد إضافة الصنف انزل بالسلة إلى آخر صنف مباشرة.
        $this->dispatch('wholesale-cart-added');
    }

    public function updateQuantity(int $productId, $qty): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $qty = (float) $qty;

        if ($qty <= 0) {
            unset($this->cart[$productId]);

            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->branchId();

        $stock = $tenantId && $branchId ? $this->availableStock($tenantId, $productId, $branchId) : 0;

        if (!$this->allowNegativeStock() && $qty > $stock) {
            $this->cart[$productId]['quantity'] = $stock;

            session()->flash('error', "الكمية المطلوبة تتجاوز المخزون المتاح من {$this->cart[$productId]['name']}.");

            return;
        }

        $this->cart[$productId]['quantity'] = $qty;
        $this->cart[$productId]['stock'] = $stock;
    }

    /*
    |--------------------------------------------------------------------------
    | Price
    |--------------------------------------------------------------------------
    */

    public function updatePrice(int $productId, $newPrice): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $price = max(0, (float) $newPrice);

        $cost = (float) ($this->cart[$productId]['cost'] ?? 0);

        /*
        |--------------------------------------------------------------------------
        | Below cost
        |--------------------------------------------------------------------------
        */

        if ($price < $cost && $cost > 0) {
            if (!$this->canSellBelowCost()) {
                $this->pendingBelowCostProductId = $productId;
                $this->pendingBelowCostPrice = $price;
                $this->showBelowCostConfirm = true;

                return;
            }
        }

        $this->cart[$productId]['price'] = $price;
    }

    private function canSellBelowCost(): bool
    {
        $user = $this->currentUser();

        if (!$user) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | If your project already has permissions/spatie permissions,
        | this will work directly.
        |--------------------------------------------------------------------------
        */

        if (method_exists($user, 'can')) {
            return (bool) $user->can('sell-below-cost');
        }

        return false;
    }

    public function confirmBelowCostPrice(): void
    {
        if (!$this->pendingBelowCostProductId || !isset($this->cart[$this->pendingBelowCostProductId])) {
            $this->cancelBelowCostPrice();

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Explicit confirmation does not bypass authorization.
        |--------------------------------------------------------------------------
        */

        if (!$this->canSellBelowCost()) {
            session()->flash('error', 'لا تملك صلاحية البيع بأقل من التكلفة.');

            $this->cancelBelowCostPrice();

            return;
        }

        $this->cart[$this->pendingBelowCostProductId]['price'] = max(0, $this->pendingBelowCostPrice);

        $this->cancelBelowCostPrice();
    }

    public function cancelBelowCostPrice(): void
    {
        $this->pendingBelowCostProductId = null;
        $this->pendingBelowCostPrice = 0;
        $this->showBelowCostConfirm = false;
    }

    /*
    |--------------------------------------------------------------------------
    | Remove / Clear
    |--------------------------------------------------------------------------
    */

    public function removeFromCart(int $productId): void
    {
        unset($this->cart[$productId]);
    }

    public function clearCart(): void
    {
        $this->cart = [];

        $this->paidAmount = 0;

        $this->discountAmount = 0;

        $this->discountRate = 0;

        $this->notes = '';

        $this->selectedCustomerId = null;

        $this->customerSearch = '';

        $this->showCustomerDropdown = false;

        $this->paymentMethod = 'cash';

        $this->sendWhatsapp = false;

        $this->cancelBelowCostPrice();
    }

    public function requestNewInvoice(): void
    {
        if (empty($this->cart)) {
            $this->clearCart();

            return;
        }

        $this->showNewInvoiceConfirm = true;
    }

    public function confirmNewInvoice(): void
    {
        $this->showNewInvoiceConfirm = false;

        $this->clearCart();
    }

    public function cancelNewInvoice(): void
    {
        $this->showNewInvoiceConfirm = false;
    }

    /*
    |--------------------------------------------------------------------------
    | Price History
    |--------------------------------------------------------------------------
    */

    public function showLastPrice(int $productId): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return;
        }

        $product = Product::query()->where('tenant_id', $tenantId)->find($productId);

        $customer = $this->selectedCustomerId
            ? Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'both'])
                ->find($this->selectedCustomerId)
            : null;

        $history = [];

        if ($customer) {
            $items = OrderItem::query()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->whereHas('order', function ($query) use ($customer) {
                    $query->where('tenant_id', $this->tenantId())->where('customer_id', $customer->id)->where('type', 'wholesale')->where('status', 'completed');
                })
                ->latest('created_at')
                ->take(10)
                ->get();

            foreach ($items as $item) {
                $history[] = [
                    'price' => (float) $item->unit_price,
                    'quantity' => (float) $item->quantity,
                    'date' => $item->created_at?->format('Y-m-d h:i A') ?? '-',
                ];
            }
        }

        $this->selectedHistoryItem = [
            'product_name' => $product?->name ?? 'منتج غير موجود',
            'customer_name' => $customer?->name ?? 'لم يتم اختيار عميل',
            'history' => $history,
            'has_history' => !empty($history),
        ];

        $this->showPriceHistoryModal = true;
    }

    public function closePriceHistoryModal(): void
    {
        $this->showPriceHistoryModal = false;

        $this->selectedHistoryItem = null;
    }

    /*
    |--------------------------------------------------------------------------
    | Customer Balance
    |--------------------------------------------------------------------------
    */

    private function customerBalance(?Party $customer): float
    {
        if (!$customer) {
            return 0.0;
        }

        /*
        |--------------------------------------------------------------------------
        | current_balance is the authoritative operational balance.
        |
        | opening_balance is historical/fixed and must not be recalculated
        | from invoices and payments here.
        |--------------------------------------------------------------------------
        */

        return round((float) $customer->current_balance, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Complete Sale
    |--------------------------------------------------------------------------
    */

    public function completeSale(bool $shouldPrint = false): void
    {
        if ($this->isSaving) {
            return;
        }

        if (empty($this->cart)) {
            session()->flash('error', 'أضف صنفاً واحداً على الأقل إلى الفاتورة.');

            return;
        }

        $tenantId = $this->tenantId();

        $branchId = $this->branchId();

        $user = $this->currentUser();

        if (!$tenantId || !$branchId || !$user) {
            session()->flash('error', 'لا يمكن إصدار الفاتورة: بيانات المستخدم أو الفرع غير مكتملة.');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate customer
        |--------------------------------------------------------------------------
        */

        $customer = null;

        if ($this->selectedCustomerId) {
            $customer = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'both'])
                ->where('is_active', true)
                ->find($this->selectedCustomerId);

            if (!$customer) {
                session()->flash('error', 'العميل المحدد غير صالح.');

                return;
            }
        }

        $this->isSaving = true;

        $subtotal = 0.0;

        $totalCost = 0.0;

        $items = [];

        $previousBalance = 0.0;

        $currentBalance = 0.0;

        $order = null;

        $payment = null;

        try {
            DB::transaction(function () use ($tenantId, $branchId, $user, $customer, &$subtotal, &$totalCost, &$items, &$order, &$payment, &$previousBalance, &$currentBalance) {
                /*
                |--------------------------------------------------------------------------
                | Re-check every item inside transaction
                |--------------------------------------------------------------------------
                */

                foreach ($this->cart as $rawItem) {
                    $productId = (int) ($rawItem['id'] ?? 0);

                    $quantity = (float) ($rawItem['quantity'] ?? 0);

                    $price = max(0, (float) ($rawItem['price'] ?? 0));

                    if ($productId <= 0 || $quantity <= 0) {
                        throw new \RuntimeException('يوجد صنف أو كمية غير صالحة في الفاتورة.');
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Product
                    |--------------------------------------------------------------------------
                    */

                    $product = Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->first();

                    if (!$product) {
                        throw new \RuntimeException('أحد المنتجات لم يعد متاحاً في المتجر.');
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Branch Product + lock
                    |--------------------------------------------------------------------------
                    */

                    $branchProducts = BranchProduct::query()
                        ->where('tenant_id', $tenantId)
                        ->where('product_id', $productId)
                        ->when(!$this->unifiedStock(), fn($query) => $query->where('branch_id', $branchId))
                        ->lockForUpdate()
                        ->get();

                    if ($branchProducts->isEmpty()) {
                        throw new \RuntimeException("المنتج {$product->name} غير مرتبط بأي فرع في المتجر.");
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Stock
                    |--------------------------------------------------------------------------
                    | Normal mode: current branch only.
                    | Unified mode: total stock from all branches.
                    | Negative stock setting: allows the sale to exceed that total.
                    */

                    $available = (float) $branchProducts->sum(fn($row) => (float) $row->stock_quantity);

                    if (!$this->allowNegativeStock() && $available < $quantity) {
                        throw new \RuntimeException("الكمية المتوفرة من المنتج {$product->name} غير كافية. المتوفر: {$available}.");
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Minimum wholesale quantity
                    |--------------------------------------------------------------------------
                    */

                    $minimumWholesaleQuantity = (float) ($branchProduct->min_wholesale_quantity ?? 1);

                    if ($quantity < $minimumWholesaleQuantity) {
                        throw new \RuntimeException("الحد الأدنى للبيع بالجملة من المنتج {$product->name} هو {$minimumWholesaleQuantity}.");
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Cost
                    |--------------------------------------------------------------------------
                    */

                    $cost = round((float) $product->cost_price, 2);

                    /*
                    |--------------------------------------------------------------------------
                    | Below cost security check
                    |--------------------------------------------------------------------------
                    */

                    if ($price < $cost && $cost > 0 && !$this->canSellBelowCost()) {
                        throw new \RuntimeException("سعر المنتج {$product->name} أقل من التكلفة، ولا تملك صلاحية البيع بأقل من التكلفة.");
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Totals
                    |--------------------------------------------------------------------------
                    */

                    $lineTotal = round($price * $quantity, 2);

                    $lineCost = round($cost * $quantity, 2);

                    $subtotal += $lineTotal;

                    $totalCost += $lineCost;

                    $items[] = [
                        'product_id' => $productId,
                        'name' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'cost_price' => $cost,
                        'total_price' => $lineTotal,
                        'total_cost' => $lineCost,
                    ];
                }

                /*
                |--------------------------------------------------------------------------
                | Discount validation
                |--------------------------------------------------------------------------
                */

                if (!in_array($this->discountType, ['fixed', 'percentage'], true)) {
                    throw new \RuntimeException('نوع الخصم غير صالح.');
                }

                /*
                |--------------------------------------------------------------------------
                | Payment validation
                |--------------------------------------------------------------------------
                */

                if (!in_array($this->paymentMethod, ['cash', 'card', 'bank_transfer', 'cheque'], true)) {
                    throw new \RuntimeException('طريقة الدفع غير صالحة.');
                }

                /*
                |--------------------------------------------------------------------------
                | Discount
                |--------------------------------------------------------------------------
                */

                $discount = $this->discountType === 'percentage' ? round(($subtotal * min(100, max(0, $this->discountRate))) / 100, 2) : min($subtotal, max(0, $this->discountAmount));

                /*
                |--------------------------------------------------------------------------
                | Total
                |--------------------------------------------------------------------------
                */

                $total = max(0, round($subtotal - $discount, 2));

                /*
                |--------------------------------------------------------------------------
                | Paid
                |--------------------------------------------------------------------------
                */

                $paid = round(max(0, (float) $this->paidAmount), 2);

                /*
                |--------------------------------------------------------------------------
                | Payment Status
                |--------------------------------------------------------------------------
                */

                $paymentStatus = $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

                /*
                |--------------------------------------------------------------------------
                | Invoice Number
                |--------------------------------------------------------------------------
                */

                $invoiceNumber = $this->makeInvoiceNumber();

                /*
                |--------------------------------------------------------------------------
                | Lock customer
                |--------------------------------------------------------------------------
                */

                if ($customer) {
                    $customer = Party::query()
                        ->whereKey($customer->id)
                        ->where('tenant_id', $tenantId)
                        ->whereIn('type', ['customer', 'both'])
                        ->where('is_active', true)
                        ->lockForUpdate()
                        ->first();

                    if (!$customer) {
                        throw new \RuntimeException('تعذر قفل سجل العميل أثناء حفظ الفاتورة.');
                    }

                    $previousBalance = $this->customerBalance($customer);
                }

                /*
                |--------------------------------------------------------------------------
                | Create Order
                |--------------------------------------------------------------------------
                */

                $order = Order::create([
                    'tenant_id' => $tenantId,

                    'branch_id' => $branchId,

                    /*
                    |--------------------------------------------------------------------------
                    | Wholesale is independent from Shift
                    |--------------------------------------------------------------------------
                    */

                    'shift_id' => null,

                    'created_by' => $user->id,

                    'customer_id' => $customer?->id,

                    'customer_name' => $customer?->name ?? 'زبون عابر',

                    'customer_phone' => $customer?->phone,

                    'invoice_number' => $invoiceNumber,

                    'type' => 'wholesale',

                    'status' => 'completed',

                    'subtotal' => $subtotal,

                    'discount_type' => $this->discountType,

                    'discount_rate' => $this->discountType === 'percentage' ? $this->discountRate : 0,

                    'discount' => $discount,

                    'total' => $total,

                    'total_cost' => $totalCost,

                    'total_profit' => round($total - $totalCost, 2),

                    'paid_amount' => $paid,

                    'payment_status' => $paymentStatus,

                    'notes' => trim($this->notes) ?: null,
                ]);

                /*
                |--------------------------------------------------------------------------
                | Create Items + decrease stock
                |--------------------------------------------------------------------------
                */

                foreach ($items as $item) {
                    OrderItem::create([
                        'tenant_id' => $tenantId,

                        'order_id' => $order->id,

                        'product_id' => $item['product_id'],

                        'quantity' => $item['quantity'],

                        'unit_price' => $item['unit_price'],

                        'total_price' => $item['total_price'],

                        'cost_price' => $item['cost_price'],

                        'total_cost' => $item['total_cost'],

                        'discount' => 0,
                    ]);

                    /*
                    |--------------------------------------------------------------------------
                    | Decrease stock
                    |--------------------------------------------------------------------------
                    | Current branch is consumed first. If unified stock is enabled,
                    | the remaining quantity is taken from other branches.
                    | If negative stock is allowed and all branches are exhausted,
                    | the remaining amount is applied to the current branch when
                    | available, otherwise to the first branch record.
                    */

                    $remainingQuantity = (float) $item['quantity'];

                    $stockRows = BranchProduct::query()
                        ->where('tenant_id', $tenantId)
                        ->where('product_id', $item['product_id'])
                        ->when(!$this->unifiedStock(), fn($query) => $query->where('branch_id', $branchId))
                        ->lockForUpdate()
                        ->get();

                    if ($this->unifiedStock()) {
                        $stockRows = $stockRows->sortByDesc(fn($row) => (int) $row->branch_id === (int) $branchId)->values();
                    }

                    foreach ($stockRows as $stockRow) {
                        if ($remainingQuantity <= 0) {
                            break;
                        }

                        $rowStock = (float) $stockRow->stock_quantity;

                        if ($rowStock <= 0) {
                            continue;
                        }

                        $take = min($rowStock, $remainingQuantity);

                        $stockRow->decrement('stock_quantity', $take);

                        $remainingQuantity -= $take;
                    }

                    if ($remainingQuantity > 0) {
                        if (!$this->allowNegativeStock()) {
                            throw new \RuntimeException("تعذر تحديث مخزون المنتج {$item['name']}.");
                        }

                        $negativeTarget = $stockRows->firstWhere('branch_id', $branchId) ?: $stockRows->first();

                        if (!$negativeTarget) {
                            throw new \RuntimeException("تعذر تحديد فرع لتسجيل المخزون السالب للمنتج {$item['name']}.");
                        }

                        $negativeTarget->decrement('stock_quantity', $remainingQuantity);
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Payment
                |--------------------------------------------------------------------------
                */

                if ($paid > 0) {
                    $payment = Payment::create([
                        'tenant_id' => $tenantId,

                        'branch_id' => $branchId,

                        /*
                        |--------------------------------------------------------------------------
                        | Wholesale is independent from Shift
                        |--------------------------------------------------------------------------
                        */

                        'shift_id' => null,

                        'created_by' => $user->id,

                        'type' => 'receipt',

                        'voucher_number' => 'RCV-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4)),

                        'party_id' => $customer?->id,

                        'amount' => $paid,

                        'payment_method' => $this->paymentMethod,

                        'order_id' => $order->id,

                        'notes' => 'دفعة على الفاتورة رقم: ' . $order->invoice_number,

                        'payment_date' => now(),
                    ]);
                }

                /*
                |--------------------------------------------------------------------------
                | Customer balance
                |--------------------------------------------------------------------------
                */

                if ($customer) {
                    /*
                    |--------------------------------------------------------------------------
                    | Customer sale increases receivable.
                    | Receipt decreases receivable.
                    |--------------------------------------------------------------------------
                    */

                    $currentBalance = round($previousBalance + $total - $paid, 2);

                    $customer->update([
                        'current_balance' => $currentBalance,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            report($e);

            session()->flash('error', $e instanceof \RuntimeException ? $e->getMessage() : 'تعذر حفظ الفاتورة، لم يتم إجراء أي تغيير.');

            $this->isSaving = false;

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Print
        |--------------------------------------------------------------------------
        */

        if ($shouldPrint && $order) {
            $this->dispatch(
                'do-kiosk-print',
                data: [
                    'header_title' => 'فاتورة مبيعات جملة',

                    'invoice_no' => $order->invoice_number,

                    'customer_name' => $order->customer_name,

                    'customer_phone' => $order->customer_phone,

                    'date' => $order->created_at->format('Y-m-d h:i A'),

                    'items' => $items,

                    'subtotal' => (float) $order->subtotal,

                    'discount' => (float) $order->discount,

                    'total' => (float) $order->total,

                    'paid_amount' => (float) $order->paid_amount,

                    'remaining_amount' => max(0, (float) $order->total - (float) $order->paid_amount),

                    'previous_balance' => $previousBalance,

                    'current_balance' => $currentBalance,

                    'payment_method' => $this->paymentMethodLabel(),

                    'notes' => $order->notes,
                ],
            );
        }

        /*
        |--------------------------------------------------------------------------
        | WhatsApp
        |--------------------------------------------------------------------------
        */

        if ($this->sendWhatsapp && $order) {
            $this->dispatch('open-whatsapp-url', url: $this->whatsappUrl($order, $items));
        }

        $invoiceNumber = $order?->invoice_number;

        $this->clearCart();

        $this->isSaving = false;

        session()->flash('message', "تم حفظ فاتورة الجملة رقم {$invoiceNumber} بنجاح.");
    }

    /*
    |--------------------------------------------------------------------------
    | Invoice Number
    |--------------------------------------------------------------------------
    */

    private function makeInvoiceNumber(): string
    {
        $tenantId = $this->tenantId();

        $lastNumber = Order::query()->where('tenant_id', $tenantId)->where('type', 'wholesale')->where('invoice_number', 'like', 'W-%')->orderByDesc('id')->value('invoice_number');

        $next = 1;

        if (is_string($lastNumber) && preg_match('/^W-(\d+)$/', $lastNumber, $matches)) {
            $next = ((int) $matches[1]) + 1;
        }

        do {
            $number = 'W-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (Order::query()->where('tenant_id', $tenantId)->where('invoice_number', $number)->exists());

        return $number;
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Label
    |--------------------------------------------------------------------------
    */

    private function paymentMethodLabel(): string
    {
        return match ($this->paymentMethod) {
            'card' => 'بطاقة',

            'bank_transfer' => 'تحويل بنكي',

            'cheque' => 'شيك',

            default => 'نقداً',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    */

    private function normalizePhone(?string $phone): string
    {
        $phone = preg_replace('/\D+/', '', (string) $phone);

        if ($phone === '') {
            return '';
        }

        /*
        |--------------------------------------------------------------------------
        | Palestine local number
        |--------------------------------------------------------------------------
        */

        if (str_starts_with($phone, '0')) {
            return '970' . substr($phone, 1);
        }

        if (str_starts_with($phone, '972')) {
            return '970' . substr($phone, 3);
        }

        return $phone;
    }

    private function whatsappUrl(Order $order, array $items): string
    {
        $phone = $this->normalizePhone($order->customer_phone);

        $text = "مرحباً {$order->customer_name}،\n";

        $text .= "تم إصدار فاتورة مبيعات جملة رقم {$order->invoice_number}.\n\n";

        foreach ($items as $item) {
            $text .= "• {$item['name']} × {$item['quantity']} = " . number_format($item['total_price'], 2) . " شيكل\n";
        }

        $text .= "\nالإجمالي: " . number_format($order->total, 2) . " شيكل\n";

        $text .= 'المدفوع: ' . number_format($order->paid_amount, 2) . " شيكل\n";

        $text .= 'المتبقي: ' . number_format(max(0, $order->total - $order->paid_amount), 2) . " شيكل\n";

        if ($order->notes) {
            $text .= "ملاحظات: {$order->notes}\n";
        }

        /*
        |--------------------------------------------------------------------------
        | With a phone number: open the customer's WhatsApp directly.
        | Without a phone number: open WhatsApp's contact/share screen
        | with the invoice message ready, so the user can choose a friend.
        |--------------------------------------------------------------------------
        */

        if ($phone !== '') {
            return 'https://wa.me/' . $phone . '?text=' . urlencode($text);
        }

        return 'https://wa.me/?text=' . urlencode($text);
    }

    /*
    |--------------------------------------------------------------------------
    | Computed Totals
    |--------------------------------------------------------------------------
    */

    public function getSubtotalProperty(): float
    {
        return round(array_reduce($this->cart, fn($sum, $item) => $sum + (float) $item['price'] * (float) $item['quantity'], 0), 2);
    }

    public function getDiscountProperty(): float
    {
        $subtotal = $this->subtotal;

        return $this->discountType === 'percentage' ? round(($subtotal * min(100, max(0, $this->discountRate))) / 100, 2) : min($subtotal, max(0, $this->discountAmount));
    }

    public function getTotalProperty(): float
    {
        return max(0, round($this->subtotal - $this->discount, 2));
    }

    public function getRemainingProperty(): float
    {
        return max(0, round($this->total - max(0, (float) $this->paidAmount), 2));
    }

    public function getSelectedCustomerBalanceProperty(): float
    {
        if (!$this->selectedCustomerId) {
            return 0;
        }

        $customer = Party::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('type', ['customer', 'both'])
            ->find($this->selectedCustomerId);

        return $this->customerBalance($customer);
    }

    /*
    |--------------------------------------------------------------------------
    | Receipt Voucher
    |--------------------------------------------------------------------------
    */

    public function openReceiptForm(): void
    {
        $this->showReceiptForm = true;
        $this->receiptPaymentDate = now()->format('Y-m-d');
        $this->receiptPaymentMethod = 'cash';
        $this->receiptAmount = '';
        $this->receiptNotes = '';
        $this->receiptVoucherNumber = $this->generateReceiptVoucherNumber();

        // إذا كان هناك عميل محدد في الفاتورة، نختاره تلقائياً في سند القبض.
        $this->receiptPartyId = $this->selectedCustomerId;

        $this->receiptPartySearch = '';
        $this->receiptPartyDropdownOpen = false;

        if ($this->receiptPartyId) {
            $customer = Party::query()
                ->where('tenant_id', $this->tenantId())
                ->where('is_active', true)
                ->whereIn('type', ['customer', 'both'])
                ->find($this->receiptPartyId);

            $this->receiptPartySearch = $customer?->name ?? '';
        }
    }

    public function closeReceiptForm(): void
    {
        $this->showReceiptForm = false;
    }

    public function clearReceiptParty(): void
    {
        $this->receiptPartyId = null;
        $this->receiptPartySearch = '';
        $this->receiptPartyDropdownOpen = false;
    }

    public function selectReceiptParty(int $partyId): void
    {
        $tenantId = $this->tenantId();

        $party = Party::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('type', ['customer', 'both'])
            ->find($partyId);

        if (!$party) {
            session()->flash('error', 'العميل المحدد غير صالح.');
            return;
        }

        $this->receiptPartyId = $party->id;
        $this->receiptPartySearch = $party->name;
        $this->receiptPartyDropdownOpen = false;
    }

    public function getReceiptPartyResultsProperty()
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return collect();
        }

        $search = trim($this->receiptPartySearch);

        // عند فتح القائمة بدون كتابة، اعرض أول العملاء مباشرة.
        return Party::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereIn('type', ['customer', 'both'])
            ->when($search !== '', function ($query) use ($search) {
                $like = "%{$search}%";

                // بحث غير حساس لحالة الأحرف (A = a) ويعمل مع PostgreSQL.
                $query->where(function ($q) use ($like) {
                    $q->whereRaw('LOWER(name) LIKE LOWER(?)', [$like])
                        ->orWhereRaw('LOWER(phone) LIKE LOWER(?)', [$like])
                        ->orWhereRaw('LOWER(tax_number) LIKE LOWER(?)', [$like]);
                });
            })
            ->orderBy('name')
            ->limit(30)
            ->get();
    }

    public function updatedReceiptPartySearch(): void
    {
        // لا نفتح القائمة بسبب الكتابة وحدها؛ فتحها يتم عند التركيز/الضغط على الحقل.
        // وإذا تغيّر النص بعد اختيار عميل نلغي الاختيار.

        if ($this->receiptPartyId) {
            $selected = Party::query()->where('tenant_id', $this->tenantId())->find($this->receiptPartyId);

            if (!$selected || $this->receiptPartySearch !== $selected->name) {
                $this->receiptPartyId = null;
            }
        }
    }

    public function openReceiptPartyDropdown(): void
    {
        $this->receiptPartyDropdownOpen = true;
    }

    public function closeReceiptPartyDropdown(): void
    {
        $this->receiptPartyDropdownOpen = false;
    }

    private function generateReceiptVoucherNumber(): string
    {
        $tenantId = $this->tenantId();

        $lastId = Payment::query()->where('tenant_id', $tenantId)->where('type', 'receipt')->max('id');

        return 'REC-' . str_pad((string) (($lastId ?? 0) + 1), 6, '0', STR_PAD_LEFT);
    }

    public function saveReceipt(bool $printAfterSave = false): void
    {
        $tenantId = $this->tenantId();
        $user = $this->currentUser();

        if (!$tenantId || !$user) {
            session()->flash('error', 'لا يوجد متجر أو مستخدم حالي.');
            return;
        }

        $validated = $this->validate([
            'receiptPartyId' => ['required', 'integer'],
            'receiptVoucherNumber' => ['required', 'string', 'max:50'],
            'receiptPaymentDate' => ['required', 'date'],
            'receiptPaymentMethod' => ['required', 'in:cash,card,bank_transfer,cheque'],
            'receiptAmount' => ['required', 'numeric', 'gt:0'],
            'receiptNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $createdReceipt = null;

        try {
            DB::transaction(function () use ($tenantId, $user, $validated, &$createdReceipt) {
                $party = Party::query()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->whereIn('type', ['customer', 'both'])
                    ->lockForUpdate()
                    ->find($validated['receiptPartyId']);

                if (!$party) {
                    throw new \RuntimeException('العميل المحدد غير صالح.');
                }

                $amount = round((float) $validated['receiptAmount'], 2);

                $createdReceipt = Payment::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $this->branchId(),
                    'shift_id' => null,
                    'created_by' => $user->id,
                    'party_id' => $party->id,
                    'type' => 'receipt',
                    'voucher_number' => $validated['receiptVoucherNumber'],
                    'amount' => $amount,
                    'payment_method' => $validated['receiptPaymentMethod'],
                    'order_id' => null,
                    'notes' => $validated['receiptNotes'] ?? null,
                    'payment_date' => $validated['receiptPaymentDate'],
                ]);

                // سند القبض يقلل الرصيد المستحق على العميل.
                $party->current_balance = round((float) $party->current_balance - $amount, 2);

                $party->save();
            });
        } catch (\Throwable $e) {
            report($e);

            session()->flash('error', $e instanceof \RuntimeException ? $e->getMessage() : 'تعذر حفظ سند القبض.');

            return;
        }

        if ($printAfterSave && $createdReceipt) {
            $this->dispatch(
                'print-receipt-voucher',
                data: [
                    'voucher_number' => $createdReceipt->voucher_number,
                    'party_name' => $this->receiptPartySearch,
                    'amount' => (float) $createdReceipt->amount,
                    'payment_method' => $createdReceipt->payment_method,
                    'payment_date' => (string) $createdReceipt->payment_date,
                    'notes' => $createdReceipt->notes,
                ],
            );
        }

        $this->showReceiptForm = false;
        $this->receiptPartyDropdownOpen = false;
        $this->receiptPartySearch = '';
        $this->receiptPartyId = null;
        $this->receiptAmount = '';
        $this->receiptNotes = '';
        $this->receiptVoucherNumber = '';
        $this->receiptPaymentMethod = 'cash';
        $this->receiptPaymentDate = now()->format('Y-m-d');

        session()->flash('message', 'تم إنشاء سند القبض بنجاح.');
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        $tenantId = $this->tenantId();

        $branchId = $this->branchId();

        $term = trim($this->search);

        /*
        |--------------------------------------------------------------------------
        | Products
        |--------------------------------------------------------------------------
        */

        $unifiedStock = $this->unifiedStock();
        $allowNegativeStock = $this->allowNegativeStock();

        $products = Product::query()
            ->where('products.tenant_id', $tenantId ?: 0)

            ->when($branchId, function ($query) use ($branchId, $unifiedStock) {
                $query->whereHas('branchProducts', function ($branchProduct) use ($branchId, $unifiedStock) {
                    if (!$unifiedStock) {
                        $branchProduct->where('branch_id', $branchId);
                    }
                });
            })

            ->when(
                $term !== '',
                function ($query) use ($term) {
                    $like = "%{$term}%";

                    $query->where(function ($sub) use ($like) {
                        $sub->where('name', 'like', $like)->orWhereHas('barcodes', fn($barcode) => $barcode->where('barcode', 'like', $like));
                    });
                },
                fn($query) => $query->whereRaw('1 = 0'),
            )

            ->with([
                'branchProducts' => function ($query) use ($branchId, $unifiedStock) {
                    if (!$unifiedStock) {
                        $query->where('branch_id', $branchId);
                    }
                },
            ])

            ->orderBy('name')

            ->paginate(16);

        /*
        |--------------------------------------------------------------------------
        | Customers
        |--------------------------------------------------------------------------
        */

        $customerTerm = trim($this->customerSearch);

        $customers = Party::query()
            ->where('tenant_id', $tenantId ?: 0)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_active', true)

            ->when($customerTerm !== '', function ($query) use ($customerTerm) {
                $like = "%{$customerTerm}%";

                $query->where(function ($sub) use ($like) {
                    $sub->where('name', 'like', $like)->orWhere('phone', 'like', $like);
                });
            })

            ->orderBy('name')

            ->limit(30)

            ->get();

        return $this->view([
            'products' => $products,

            'customers' => $customers,

            'branchId' => $branchId,

            'unifiedStock' => $unifiedStock,

            'allowNegativeStock' => $allowNegativeStock,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="p-2 sm:p-4" dir="rtl">
    <div class="min-h-[calc(100vh-5rem)] rounded-2xl bg-zinc-50 dark:bg-zinc-950">
        <div class="mx-auto max-w-[1600px] space-y-3">

            {{-- ========================================================= --}}
            {{-- Flash Messages --}}
            {{-- ========================================================= --}}

            @if (session()->has('error'))
                <div
                    class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-300">
                    {{ session('error') }}
                </div>
            @endif

            @if (session()->has('message'))
                <div
                    class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 dark:border-emerald-900/60 dark:bg-emerald-950/30 dark:text-emerald-300">
                    {{ session('message') }}
                </div>
            @endif


            {{-- ========================================================= --}}
            {{-- Branch Warning --}}
            {{-- ========================================================= --}}

            @if (!$this->branchId())
                <div
                    class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300">
                    هذا المستخدم غير مرتبط بفرع.
                    اربطه بفرع أولاً حتى يتم تسجيل المخزون والفاتورة في الفرع الصحيح.
                </div>
            @endif


            {{-- ========================================================= --}}
            {{-- Main POS Grid --}}
            {{-- ========================================================= --}}

            <div class="grid grid-cols-1 gap-3 xl:grid-cols-12">

                {{-- ===================================================== --}}
                {{-- Products --}}
                {{-- ===================================================== --}}

                <section class="flex min-h-0 flex-col gap-3 xl:col-span-7">

                    {{-- Search --}}
                    <div
                        class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        <div class="flex flex-col gap-2 sm:flex-row">

                            <div class="flex-1">
                                <flux:input id="wholesale-product-search" wire:model.live.debounce.320ms="search"
                                    wire:keydown.enter="searchBarcode" icon="magnifying-glass"
                                    placeholder="ابحث باسم الصنف أو امسح الباركود ثم Enter..." autofocus />
                            </div>

                            <div
                                class="flex items-center gap-2 rounded-xl bg-zinc-100 px-3 py-2 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                <flux:icon name="building-storefront" class="size-4" />

                                <span>
                                    {{ auth()->user()?->branch?->name ?? 'غير محدد' }}
                                </span>
                            </div>

                        </div>
                    </div>


                    {{-- Products --}}
                    <div
                        class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <h2 class="font-bold text-zinc-900 dark:text-zinc-100">
                                    أصناف الجملة
                                </h2>

                                <p class="text-xs text-zinc-500">
                                    اختر الصنف لإضافته إلى الفاتورة.
                                    {{ $unifiedStock ? 'المخزون محسوب من جميع الفروع.' : 'الكمية محدودة بمخزون الفرع.' }}
                                </p>
                            </div>

                            @if ($search !== '')
                                <flux:button size="sm" variant="subtle" wire:click="$set('search', '')">
                                    مسح البحث
                                </flux:button>
                            @endif
                        </div>


                        <div class="grid grid-cols-2 gap-2.5 md:grid-cols-3 lg:grid-cols-4">

                            @forelse ($products as $product)
                                @php
                                    $bp =
                                        $product->branchProducts->firstWhere('branch_id', $branchId) ??
                                        ($unifiedStock ? $product->branchProducts->first() : null);

                                    $price =
                                        (float) ($bp?->wholesale_price > 0
                                            ? $bp->wholesale_price
                                            : $bp?->retail_price ?? 0);

                                    $stock = $unifiedStock
                                        ? (float) $product->branchProducts->sum('stock_quantity')
                                        : (float) ($bp?->stock_quantity ?? 0);
                                @endphp

                                <button type="button" wire:click="addToCart({{ $product->id }})"
                                    @disabled(!$bp || (!$allowNegativeStock && $stock <= 0))
                                    class="group rounded-xl border border-zinc-200 bg-zinc-50 p-3 text-right transition hover:-translate-y-0.5 hover:border-indigo-400 hover:bg-white hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-800 dark:bg-zinc-950/60 dark:hover:bg-zinc-900">

                                    <div
                                        class="mb-2 line-clamp-2 min-h-10 text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                                        {{ $product->name }}
                                    </div>

                                    <div
                                        class="flex items-end justify-between gap-2 border-t border-zinc-200 pt-2 dark:border-zinc-800">

                                        <div>
                                            <div class="text-[10px] text-zinc-400">
                                                سعر الجملة
                                            </div>

                                            <div class="font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                {{ number_format($price, 2) }}
                                            </div>
                                        </div>

                                        <div class="text-left">
                                            <div class="text-[10px] text-zinc-400">
                                                المخزون
                                            </div>

                                            <div
                                                class="font-mono text-xs font-semibold {{ $stock <= 0 ? 'text-rose-500' : 'text-emerald-600 dark:text-emerald-400' }}">
                                                {{ rtrim(rtrim(number_format($stock, 2), '0'), '.') }}
                                            </div>
                                        </div>

                                    </div>

                                </button>

                            @empty

                                <div
                                    class="col-span-full rounded-xl border border-dashed border-zinc-300 py-12 text-center text-sm text-zinc-400 dark:border-zinc-700">
                                    {{ $search !== '' ? 'لا توجد أصناف مطابقة للبحث.' : 'ابدأ بالبحث عن صنف أو امسح الباركود.' }}
                                </div>
                            @endforelse

                        </div>


                        @if ($products->hasPages())
                            <div class="mt-3">
                                {{ $products->links() }}
                            </div>
                        @endif

                    </div>

                </section>


                {{-- ===================================================== --}}
                {{-- Invoice --}}
                {{-- ===================================================== --}}

                <aside class="xl:col-span-5">
                    <div
                        class="sticky top-3 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                        {{-- Header --}}
                        <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">

                            <div class="flex items-center justify-between gap-3">

                                <div>
                                    <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                        فاتورة مبيعات جملة
                                    </div>

                                    <div class="text-xs text-zinc-500">
                                        مستقلة عن الشيفتات
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    <flux:button size="sm" variant="subtle" wire:click="openReceiptForm"
                                        :disabled="$isSaving">
                                        سند قبض
                                    </flux:button>

                                    <flux:button size="sm" variant="subtle" wire:click="requestNewInvoice"
                                        :disabled="$isSaving">
                                        فاتورة جديدة
                                    </flux:button>
                                </div>

                            </div>

                        </div>


                        <div class="space-y-3 p-4">

                            {{-- ================================================= --}}
                            {{-- Customer --}}
                            {{-- ================================================= --}}

                            <div class="relative" x-data="{ open: @entangle('showCustomerDropdown') }">

                                <div class="mb-1 flex items-center justify-between">
                                    <label class="text-xs font-medium text-zinc-600 dark:text-zinc-300">
                                        العميل
                                    </label>

                                    @if ($selectedCustomerId)
                                        <button type="button" wire:click="clearCustomer"
                                            class="text-xs text-rose-500 hover:underline">
                                            إزالة
                                        </button>
                                    @endif
                                </div>


                                <div class="relative">

                                    <input type="text" wire:model.live.debounce.300ms="customerSearch"
                                        wire:focus="openCustomerDropdown"
                                        placeholder="ابحث عن العميل بالاسم أو الهاتف..."
                                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm text-zinc-800 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />

                                    @if ($selectedCustomerId)
                                        <div
                                            class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-emerald-500">
                                            ✓
                                        </div>
                                    @endif

                                </div>


                                @if ($showCustomerDropdown)

                                    <div
                                        class="absolute inset-x-0 top-full z-40 mt-1 max-h-64 overflow-y-auto rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900">

                                        @if ($customerSearch === '')
                                            <button type="button" wire:click="clearCustomer"
                                                class="flex w-full items-center justify-between border-b border-zinc-100 px-3 py-3 text-right text-sm hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800">
                                                <span>
                                                    زبون عابر
                                                </span>

                                                <span class="text-xs text-zinc-400">
                                                    بدون حساب
                                                </span>
                                            </button>
                                        @endif


                                        @forelse ($customers as $customer)
                                            <button type="button" wire:click="selectCustomer({{ $customer->id }})"
                                                class="flex w-full items-center justify-between border-b border-zinc-100 px-3 py-3 text-right transition hover:bg-indigo-50 dark:border-zinc-800 dark:hover:bg-indigo-950/30">

                                                <div>
                                                    <div class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                                                        {{ $customer->name }}
                                                    </div>

                                                    @if ($customer->phone)
                                                        <div class="mt-0.5 text-xs text-zinc-400">
                                                            {{ $customer->phone }}
                                                        </div>
                                                    @endif
                                                </div>

                                                <div
                                                    class="font-mono text-xs font-bold {{ $customer->current_balance > 0 ? 'text-rose-500' : 'text-emerald-500' }}">
                                                    {{ number_format($customer->current_balance, 2) }}
                                                </div>

                                            </button>

                                        @empty

                                            <div class="px-4 py-8 text-center text-sm text-zinc-400">
                                                لا يوجد عملاء مطابقون.
                                            </div>
                                        @endforelse

                                    </div>

                                @endif


                                @if ($selectedCustomerId)
                                    <div
                                        class="mt-2 flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800/60">
                                        <span class="text-zinc-500">
                                            الرصيد السابق
                                        </span>

                                        <span
                                            class="font-mono font-bold {{ $this->selectedCustomerBalance > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                            {{ number_format($this->selectedCustomerBalance, 2) }}
                                            ₪
                                        </span>
                                    </div>
                                @endif

                            </div>


                            {{-- ================================================= --}}
                            {{-- Cart --}}
                            {{-- ================================================= --}}

                            <div id="wholesale-cart"
                                class="max-h-[38vh] overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-800">

                                @forelse ($cart as $id => $item)
                                    <div wire:key="wholesale-cart-{{ $id }}"
                                        class="border-b border-zinc-100 p-3 last:border-0 dark:border-zinc-800">

                                        <div class="flex items-start justify-between gap-2">

                                            <div class="min-w-0 flex-1">

                                                <div class="flex items-center gap-1">

                                                    <div
                                                        class="truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100">
                                                        {{ $item['name'] }}
                                                    </div>

                                                    <button type="button"
                                                        wire:click="showLastPrice({{ $id }})"
                                                        title="سجل أسعار هذا العميل"
                                                        class="rounded-md p-1 text-indigo-500 transition hover:bg-indigo-50 hover:text-indigo-700 dark:hover:bg-indigo-950/30">
                                                        <flux:icon name="clock" class="size-3.5" />
                                                    </button>

                                                </div>


                                                {{-- Price --}}
                                                <div class="mt-2 flex items-center gap-2">
                                                    <label class="text-[11px] text-zinc-400">
                                                        السعر
                                                    </label>

                                                    <input type="number" min="0" step="0.01"
                                                        value="{{ $item['price'] }}"
                                                        wire:change="updatePrice({{ $id }}, $event.target.value)"
                                                        class="w-24 rounded-lg border border-zinc-300 bg-zinc-50 px-2 py-1.5 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800" />

                                                    @if (($item['price'] ?? 0) < ($item['cost'] ?? 0))
                                                        <span
                                                            class="rounded-md bg-rose-50 px-1.5 py-1 text-[10px] font-bold text-rose-600 dark:bg-rose-950/30 dark:text-rose-400">
                                                            أقل من التكلفة
                                                        </span>
                                                    @endif

                                                </div>

                                            </div>


                                            {{-- Remove --}}
                                            <button type="button" wire:click="removeFromCart({{ $id }})"
                                                class="rounded-lg p-1.5 text-zinc-400 transition hover:bg-rose-50 hover:text-rose-500 dark:hover:bg-rose-950/30">
                                                <flux:icon name="trash" class="size-4" />
                                            </button>

                                        </div>


                                        {{-- Quantity --}}
                                        <div class="mt-2 flex items-center justify-between">

                                            <div
                                                class="flex items-center gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">

                                                <button type="button"
                                                    wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] - 1 }})"
                                                    class="size-8 rounded-md text-base font-bold transition hover:bg-white dark:hover:bg-zinc-700">
                                                    −
                                                </button>

                                                <span class="min-w-10 text-center text-sm font-bold font-mono">
                                                    {{ rtrim(rtrim(number_format($item['quantity'], 2), '0'), '.') }}
                                                </span>

                                                <button type="button"
                                                    wire:click="updateQuantity({{ $id }}, {{ $item['quantity'] + 1 }})"
                                                    class="size-8 rounded-md text-base font-bold transition hover:bg-white dark:hover:bg-zinc-700">
                                                    +
                                                </button>

                                            </div>


                                            <div class="text-left">

                                                <div class="text-[10px] text-zinc-400">
                                                    {{ number_format($item['price'], 2) }}
                                                    ×
                                                    {{ $item['quantity'] }}
                                                </div>

                                                <div class="font-mono font-bold text-zinc-900 dark:text-zinc-100">
                                                    {{ number_format($item['price'] * $item['quantity'], 2) }}
                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                @empty

                                    <div class="py-12 text-center text-sm text-zinc-400">
                                        لم تتم إضافة أي أصناف.
                                    </div>
                                @endforelse

                            </div>


                            {{-- ================================================= --}}
                            {{-- Discount + Payment --}}
                            {{-- ================================================= --}}

                            <div class="grid grid-cols-2 gap-2">

                                <div>
                                    <label class="mb-1 block text-xs text-zinc-500">
                                        الخصم
                                    </label>

                                    <div class="flex gap-1">

                                        <input type="number" min="0" step="0.01"
                                            wire:model.live.debounce.250ms="{{ $discountType === 'percentage' ? 'discountRate' : 'discountAmount' }}"
                                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800" />

                                        <select wire:model.live="discountType"
                                            class="w-20 rounded-xl border border-zinc-300 bg-white px-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                            <option value="fixed">
                                                ₪
                                            </option>

                                            <option value="percentage">
                                                %
                                            </option>
                                        </select>

                                    </div>
                                </div>


                                <div>
                                    <label class="mb-1 block text-xs text-zinc-500">
                                        طريقة الدفع
                                    </label>

                                    <select wire:model.live="paymentMethod"
                                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                        <option value="cash">
                                            نقداً
                                        </option>

                                        <option value="card">
                                            بطاقة
                                        </option>

                                        <option value="bank_transfer">
                                            تحويل بنكي
                                        </option>

                                        <option value="cheque">
                                            شيك
                                        </option>
                                    </select>
                                </div>

                            </div>


                            {{-- ================================================= --}}
                            {{-- Totals --}}
                            {{-- ================================================= --}}

                            <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">

                                <div class="flex justify-between text-sm">
                                    <span class="text-zinc-500">
                                        الإجمالي قبل الخصم
                                    </span>

                                    <span class="font-mono">
                                        {{ number_format($this->subtotal, 2) }}
                                    </span>
                                </div>

                                <div class="mt-1 flex justify-between text-sm">
                                    <span class="text-zinc-500">
                                        الخصم
                                    </span>

                                    <span class="font-mono text-rose-600">
                                        -
                                        {{ number_format($this->discount, 2) }}
                                    </span>
                                </div>

                                <div
                                    class="mt-2 flex justify-between border-t border-zinc-200 pt-2 text-lg font-black dark:border-zinc-700">
                                    <span>
                                        الصافي
                                    </span>

                                    <span class="font-mono text-indigo-600 dark:text-indigo-400">
                                        {{ number_format($this->total, 2) }}
                                        ₪
                                    </span>
                                </div>

                            </div>


                            {{-- ================================================= --}}
                            {{-- Payment --}}
                            {{-- ================================================= --}}

                            <div>

                                <div class="mb-1 flex items-center justify-between">
                                    <label class="text-xs font-medium text-zinc-600 dark:text-zinc-300">
                                        المبلغ المدفوع
                                    </label>

                                    <button type="button" wire:click="setFullPayment"
                                        class="text-xs font-semibold text-indigo-600 hover:underline">
                                        دفع كامل
                                    </button>
                                </div>


                                <input type="number" min="0" step="0.01" inputmode="decimal"
                                    wire:model.blur="paidAmount"
                                    class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-lg font-bold font-mono dark:border-zinc-700 dark:bg-zinc-800" />


                                {{-- Quick Payment --}}
                                <div class="mt-2 grid grid-cols-4 gap-1.5">

                                    <button type="button" wire:click="setPaymentAmount(50)"
                                        class="rounded-lg border border-zinc-200 bg-zinc-50 py-2 text-xs font-bold hover:bg-white dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                                        50
                                    </button>

                                    <button type="button" wire:click="setPaymentAmount(100)"
                                        class="rounded-lg border border-zinc-200 bg-zinc-50 py-2 text-xs font-bold hover:bg-white dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                                        100
                                    </button>

                                    <button type="button" wire:click="setPaymentAmount(200)"
                                        class="rounded-lg border border-zinc-200 bg-zinc-50 py-2 text-xs font-bold hover:bg-white dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                                        200
                                    </button>

                                    <button type="button" wire:click="setFullPayment"
                                        class="rounded-lg border border-indigo-200 bg-indigo-50 py-2 text-xs font-bold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-900 dark:bg-indigo-950/30 dark:text-indigo-300">
                                        كامل
                                    </button>

                                </div>


                                <div class="mt-1 flex justify-between text-xs">

                                    <span class="text-zinc-500">
                                        المتبقي على الحساب
                                    </span>

                                    <span
                                        class="font-mono font-bold {{ $this->remaining > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                        {{ number_format($this->remaining, 2) }}
                                        ₪
                                    </span>

                                </div>

                            </div>


                            {{-- ================================================= --}}
                            {{-- Notes --}}
                            {{-- ================================================= --}}

                            <textarea wire:model.live.debounce.500ms="notes" rows="2" placeholder="ملاحظات الفاتورة..."
                                class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"></textarea>


                            {{-- ================================================= --}}
                            {{-- WhatsApp --}}
                            {{-- ================================================= --}}

                            <label
                                class="flex cursor-pointer items-center gap-2 text-xs text-zinc-600 dark:text-zinc-300">

                                <input type="checkbox" wire:model.live="sendWhatsapp"
                                    class="rounded border-zinc-300 text-indigo-600" />

                                فتح واتساب للعميل بعد الحفظ إذا كان لديه رقم

                            </label>


                            {{-- ================================================= --}}
                            {{-- Buttons --}}
                            {{-- ================================================= --}}

                            <div class="grid grid-cols-2 gap-2 pt-1">

                                <flux:button variant="filled" class="w-full" wire:click="completeSale(false)"
                                    wire:loading.attr="disabled" wire:target="completeSale"
                                    :disabled="empty($cart) || !$this->branchId()">
                                    <span wire:loading.remove wire:target="completeSale">
                                        حفظ الفاتورة
                                    </span>

                                    <span wire:loading wire:target="completeSale">
                                        جارٍ الحفظ...
                                    </span>
                                </flux:button>


                                <flux:button variant="primary" icon="printer" class="w-full"
                                    wire:click="completeSale(true)" wire:loading.attr="disabled"
                                    wire:target="completeSale" :disabled="empty($cart) || !$this->branchId()">
                                    <span wire:loading.remove wire:target="completeSale">
                                        حفظ وطباعة
                                    </span>

                                    <span wire:loading wire:target="completeSale">
                                        جارٍ الحفظ...
                                    </span>
                                </flux:button>

                            </div>

                        </div>

                    </div>
                </aside>

            </div>
        </div>
    </div>


    {{-- ================================================================ --}}
    {{-- New Invoice Confirmation --}}
    {{-- ================================================================ --}}

    @if ($showNewInvoiceConfirm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
            dir="rtl">

            <div
                class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-5 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">

                <div class="mb-4 text-lg font-bold">
                    بدء فاتورة جديدة؟
                </div>

                <p class="text-sm leading-6 text-zinc-500">
                    توجد أصناف وبيانات في الفاتورة الحالية.
                    بدء فاتورة جديدة سيؤدي إلى مسح البيانات الحالية.
                </p>

                <div class="mt-5 grid grid-cols-2 gap-2">

                    <flux:button variant="subtle" wire:click="cancelNewInvoice">
                        إلغاء
                    </flux:button>

                    <flux:button variant="danger" wire:click="confirmNewInvoice">
                        نعم، ابدأ
                    </flux:button>

                </div>

            </div>

        </div>
    @endif


    {{-- ================================================================ --}}
    {{-- Below Cost Confirmation --}}
    {{-- ================================================================ --}}

    @if ($showBelowCostConfirm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
            dir="rtl">

            <div
                class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-5 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">

                <div class="mb-2 text-lg font-bold text-amber-600">
                    تنبيه: البيع بأقل من التكلفة
                </div>

                <p class="text-sm leading-6 text-zinc-500">
                    السعر الذي أدخلته أقل من تكلفة المنتج.
                    لا يمكن اعتماد هذا السعر إلا إذا كانت لديك الصلاحية المناسبة.
                </p>

                <div class="mt-4 rounded-xl bg-amber-50 p-3 text-sm dark:bg-amber-950/20">
                    السعر:
                    <strong>
                        {{ number_format($pendingBelowCostPrice, 2) }}
                        ₪
                    </strong>
                </div>

                <div class="mt-5 grid grid-cols-2 gap-2">

                    <flux:button variant="subtle" wire:click="cancelBelowCostPrice">
                        إلغاء
                    </flux:button>

                    <flux:button variant="danger" wire:click="confirmBelowCostPrice">
                        اعتماد السعر
                    </flux:button>

                </div>

            </div>

        </div>
    @endif


    {{-- ================================================================ --}}
    {{-- Price History --}}
    {{-- ================================================================ --}}

    @if ($showPriceHistoryModal)

        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
            dir="rtl">

            <div
                class="w-full max-w-lg rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900">

                <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4 dark:border-zinc-800">

                    <div>

                        <div class="font-bold">
                            سجل أسعار البيع
                        </div>

                        <div class="text-xs text-zinc-500">
                            {{ $selectedHistoryItem['product_name'] ?? '-' }}
                            —
                            {{ $selectedHistoryItem['customer_name'] ?? '-' }}
                        </div>

                    </div>

                    <button type="button" wire:click="closePriceHistoryModal"
                        class="rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800">
                        ✕
                    </button>

                </div>


                <div class="p-5">

                    @if ($selectedHistoryItem['has_history'] ?? false)

                        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-800">

                            <table class="w-full text-right text-sm">

                                <thead class="bg-zinc-50 dark:bg-zinc-800/70">

                                    <tr>
                                        <th class="px-3 py-2">
                                            السعر
                                        </th>

                                        <th class="px-3 py-2">
                                            الكمية
                                        </th>

                                        <th class="px-3 py-2">
                                            التاريخ
                                        </th>
                                    </tr>

                                </thead>

                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">

                                    @foreach ($selectedHistoryItem['history'] as $row)
                                        <tr>

                                            <td class="px-3 py-2 font-mono font-bold text-emerald-600">
                                                {{ number_format($row['price'], 2) }}
                                            </td>

                                            <td class="px-3 py-2 font-mono">
                                                {{ rtrim(rtrim(number_format($row['quantity'], 2), '0'), '.') }}
                                            </td>

                                            <td class="px-3 py-2 text-xs text-zinc-500">
                                                {{ $row['date'] }}
                                            </td>

                                        </tr>
                                    @endforeach

                                </tbody>

                            </table>

                        </div>
                    @else
                        <div class="rounded-xl bg-zinc-50 py-10 text-center text-sm text-zinc-400 dark:bg-zinc-800/50">
                            @if (!$selectedCustomerId)
                                اختر العميل أولاً لعرض أسعار البيع السابقة.
                            @else
                                لا يوجد بيع سابق لهذا الصنف مع هذا العميل.
                            @endif
                        </div>

                    @endif

                </div>

            </div>

        </div>

    @endif

    {{-- =========================================================
         سند قبض
         نفس فكرة نموذج سندات القبض الموجود في المحاسبة،
         ولكن داخل صفحة بيع الجملة مباشرة.
    ========================================================== --}}
    @if ($showReceiptForm)
        <div class="fixed inset-0 z-[120] flex items-center justify-center bg-slate-950/60 p-4"
            wire:key="wholesale-receipt-form">
            <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-2xl dark:border-zinc-800 dark:bg-zinc-900"
                dir="rtl" @click.stop>
                <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                    <div>
                        <div class="text-lg font-black text-zinc-900 dark:text-zinc-100">
                            سند قبض
                        </div>
                        <div class="mt-0.5 text-xs text-zinc-500">
                            تسجيل مبلغ مقبوض من العميل وتخفيض رصيده.
                        </div>
                    </div>

                    <button type="button" wire:click="closeReceiptForm"
                        class="rounded-lg px-2 py-1 text-xl font-black text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800">
                        ×
                    </button>
                </div>

                <div class="space-y-4 p-4">
                    <div class="relative" x-data="{ receiptPartyOpen: false }"
                        x-on:click.outside="receiptPartyOpen = false; $wire.closeReceiptPartyDropdown()">
                        <label class="mb-1 block text-xs font-bold text-zinc-600 dark:text-zinc-300">
                            العميل
                        </label>

                        <input type="text" wire:model.live.debounce.250ms="receiptPartySearch"
                            wire:focus="openReceiptPartyDropdown" x-on:focus="receiptPartyOpen = true"
                            x-on:click="receiptPartyOpen = true" placeholder="ابحث عن العميل بالاسم أو الهاتف..."
                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">

                        @if ($receiptPartyId)
                            <button type="button" wire:click="clearReceiptParty"
                                class="absolute left-3 top-8 text-xs font-bold text-rose-500 hover:underline">
                                إزالة
                            </button>
                        @endif

                        @if (!$receiptPartyId)
                            <div x-show="receiptPartyOpen" x-cloak
                                class="absolute inset-x-0 top-full z-50 mt-1 max-h-56 overflow-y-auto rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                                @forelse ($this->receiptPartyResults as $party)
                                    <button type="button" wire:click="selectReceiptParty({{ $party->id }})"
                                        class="flex w-full items-center justify-between border-b border-zinc-100 px-3 py-3 text-right hover:bg-indigo-50 dark:border-zinc-800 dark:hover:bg-indigo-950/30">
                                        <div>
                                            <div class="text-sm font-bold text-zinc-800 dark:text-zinc-100">
                                                {{ $party->name }}
                                            </div>

                                            @if ($party->phone)
                                                <div class="mt-0.5 text-xs text-zinc-400">
                                                    {{ $party->phone }}
                                                </div>
                                            @endif
                                        </div>

                                        <div
                                            class="font-mono text-xs font-bold {{ (float) $party->current_balance > 0 ? 'text-rose-500' : 'text-emerald-500' }}">
                                            {{ number_format((float) $party->current_balance, 2) }}
                                        </div>
                                    </button>
                                @empty
                                    <div class="px-4 py-6 text-center text-sm text-zinc-400">
                                        لا يوجد عملاء مطابقون.
                                    </div>
                                @endforelse
                            </div>
                        @endif
                    </div>

                    @if ($receiptPartyId)
                        @php
                            $receiptParty = Party::query()
                                ->where('tenant_id', $this->tenantId())
                                ->find($receiptPartyId);
                        @endphp

                        @if ($receiptParty)
                            <div
                                class="rounded-xl border border-indigo-100 bg-indigo-50 px-3 py-2 dark:border-indigo-900/40 dark:bg-indigo-950/20">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-bold text-zinc-600 dark:text-zinc-300">
                                        العميل المحدد
                                    </span>
                                    <span class="text-sm font-black text-indigo-700 dark:text-indigo-300">
                                        {{ $receiptParty->name }}
                                    </span>
                                </div>

                                <div class="mt-1 flex items-center justify-between gap-3 text-xs">
                                    <span class="text-zinc-500">الرصيد الحالي</span>
                                    <span
                                        class="font-mono font-black {{ (float) $receiptParty->current_balance > 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                                        {{ number_format((float) $receiptParty->current_balance, 2) }} ₪
                                    </span>
                                </div>
                            </div>
                        @endif
                    @endif

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs font-bold text-zinc-600 dark:text-zinc-300">
                                رقم السند
                            </label>
                            <input type="text" wire:model="receiptVoucherNumber" readonly
                                class="w-full rounded-xl border border-zinc-200 bg-zinc-100 px-3 py-2.5 text-sm font-mono dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-bold text-zinc-600 dark:text-zinc-300">
                                التاريخ
                            </label>
                            <input type="date" wire:model="receiptPaymentDate"
                                class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-zinc-600 dark:text-zinc-300">
                            المبلغ
                        </label>
                        <input type="number" min="0.01" step="0.01" inputmode="decimal"
                            wire:model="receiptAmount" autofocus
                            class="w-full rounded-xl border-2 border-indigo-200 bg-white px-3 py-3 text-center font-mono text-2xl font-black text-indigo-800 outline-none focus:border-indigo-500 dark:bg-zinc-800 dark:text-indigo-300"
                            placeholder="0.00">
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-zinc-600 dark:text-zinc-300">
                            طريقة الدفع
                        </label>

                        <select wire:model="receiptPaymentMethod"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100">
                            <option value="cash">نقداً</option>
                            <option value="card">بطاقة</option>
                            <option value="bank_transfer">تحويل بنكي</option>
                            <option value="cheque">شيك</option>
                        </select>
                    </div>

                    <div>
                        <label class="mb-1 block text-xs font-bold text-zinc-600 dark:text-zinc-300">
                            ملاحظات
                        </label>
                        <textarea wire:model="receiptNotes" rows="2"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-indigo-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            placeholder="ملاحظات اختيارية..."></textarea>
                    </div>

                    @error('receiptPartyId')
                        <div class="text-xs font-bold text-rose-600">{{ $message }}</div>
                    @enderror

                    @error('receiptAmount')
                        <div class="text-xs font-bold text-rose-600">{{ $message }}</div>
                    @enderror

                    <div class="flex flex-col-reverse gap-2 sm:flex-row">
                        <button type="button" wire:click="closeReceiptForm"
                            class="flex-1 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm font-bold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                            إلغاء
                        </button>

                        <button type="button" wire:click="saveReceipt" wire:loading.attr="disabled"
                            class="flex-1 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">
                            حفظ سند القبض
                        </button>

                        <button type="button" wire:click="saveReceipt(true)" wire:loading.attr="disabled"
                            class="flex-1 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white shadow-sm hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">
                            حفظ وطباعة
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</flux:main>


@script
    <script>
        /*
        |--------------------------------------------------------------------------
        | Focus Search
        |--------------------------------------------------------------------------
        */

        Livewire.hook('commit', ({
            respond
        }) => {

            respond(() => {

                const active =
                    document.activeElement;

                const isTyping =
                    active && [
                        'INPUT',
                        'TEXTAREA',
                        'SELECT'
                    ].includes(
                        active.tagName
                    );

                if (!isTyping) {
                    document
                        .getElementById(
                            'wholesale-product-search'
                        )
                        ?.focus();
                }

            });

        });


        /*
        |--------------------------------------------------------------------------
        | Keyboard Shortcuts
        |--------------------------------------------------------------------------
        */

        document.addEventListener(
            'keydown',
            function(event) {

                const target =
                    event.target;

                const isTyping =
                    target && [
                        'INPUT',
                        'TEXTAREA',
                        'SELECT'
                    ].includes(
                        target.tagName
                    );


                /*
                |--------------------------------------------------------------------------
                | F2 = Product Search
                |--------------------------------------------------------------------------
                */

                if (event.key === 'F2') {

                    event.preventDefault();

                    document
                        .getElementById(
                            'wholesale-product-search'
                        )
                        ?.focus();

                    return;
                }


                /*
                |--------------------------------------------------------------------------
                | Escape
                |--------------------------------------------------------------------------
                */

                if (
                    event.key === 'Escape' &&
                    !isTyping
                ) {

                    window.dispatchEvent(
                        new CustomEvent(
                            'close-wholesale-modals'
                        )
                    );

                }

            }
        );


        /*
        |--------------------------------------------------------------------------
        | Close Modals
        |--------------------------------------------------------------------------
        */

        window.addEventListener(
            'close-wholesale-modals',
            function() {

                /*
                |--------------------------------------------------------------------------
                | Livewire 4 dispatch
                |--------------------------------------------------------------------------
                */

                $wire.closePriceHistoryModal();

            }
        );


        /*
        |--------------------------------------------------------------------------
        | Scroll cart to the newest item after adding a product
        |--------------------------------------------------------------------------
        */

        $wire.on(
            'wholesale-cart-added',
            () => {
                setTimeout(() => {
                    const cart = document.getElementById('wholesale-cart');

                    if (!cart) {
                        return;
                    }

                    cart.scrollTo({
                        top: cart.scrollHeight,
                        behavior: 'smooth'
                    });
                }, 80);
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Receipt Voucher Printing - RawBT / Browser
        |--------------------------------------------------------------------------
        */

        $wire.on(
            'print-receipt-voucher',
            (event) => {
                const data = event?.data || {};
                const money = (value) => Number(value || 0).toFixed(2);

                let text = '';
                text += '================================\n';
                text += '          سند قبض\n';
                text += '================================\n';
                text += `رقم السند: ${data.voucher_number || '-'}\n`;
                text += `التاريخ: ${data.payment_date || '-'}\n`;
                text += `العميل: ${data.party_name || '-'}\n`;
                text += '--------------------------------\n';
                text += `المبلغ: ${money(data.amount)} ₪\n`;
                text += `طريقة الدفع: ${data.payment_method || 'cash'}\n`;

                if (data.notes) {
                    text += '--------------------------------\n';
                    text += `ملاحظات: ${data.notes}\n`;
                }

                text += '================================\n';
                text += '       شكراً لتعاملكم معنا\n';
                text += '================================\n\n\n';

                const isAndroid = /Android/i.test(navigator.userAgent || '');

                if (isAndroid) {
                    const intentUrl =
                        'intent:' +
                        encodeURIComponent(text) +
                        '#Intent;' +
                        'scheme=rawbt;' +
                        'package=ru.a402d.rawbtprinter;' +
                        'S.type=text/plain;' +
                        'end;';

                    window.location.href = intentUrl;
                    return;
                }

                const printWindow = window.open('', '_blank', 'width=400,height=600');

                if (!printWindow) {
                    window.print();
                    return;
                }

                printWindow.document.write(`
                <html dir="rtl">
                <head>
                    <meta charset="UTF-8">
                    <title>سند قبض ${data.voucher_number || ''}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 24px; direction: rtl; }
                        .center { text-align: center; }
                        .line { border-top: 1px dashed #000; margin: 12px 0; }
                        .row { display: flex; justify-content: space-between; margin: 8px 0; }
                        .amount { font-size: 24px; font-weight: 900; text-align: center; margin: 20px 0; }
                        @media print { @page { margin: 8mm; } body { padding: 0; } }
                    </style>
                </head>
                <body>
                    <div class="center"><h2>سند قبض</h2></div>
                    <div class="line"></div>
                    <div class="row"><b>رقم السند</b><span>${data.voucher_number || '-'}</span></div>
                    <div class="row"><b>التاريخ</b><span>${data.payment_date || '-'}</span></div>
                    <div class="row"><b>العميل</b><span>${data.party_name || '-'}</span></div>
                    <div class="line"></div>
                    <div class="amount">${money(data.amount)} ₪</div>
                    <div class="row"><b>طريقة الدفع</b><span>${data.payment_method || '-'}</span></div>
                    ${data.notes ? `<div class="line"></div><div><b>ملاحظات:</b> ${data.notes}</div>` : ''}
                    <div class="line"></div>
                    <div class="center">شكراً لتعاملكم معنا</div>
                    <script>window.onload = () => { window.print(); window.onafterprint = () => window.close(); };<\/script>
                </body>
                </html>
            `);
                printWindow.document.close();
            }
        );

        /*
        |--------------------------------------------------------------------------
        | Direct Printing - RawBT
        |--------------------------------------------------------------------------
        */

        $wire.on(
            'do-kiosk-print',
            (event) => {

                const inv = event?.data || {};

                const money = (value) =>
                    Number(value || 0).toFixed(2);

                const shortName = (name) => {
                    const words = String(name || '')
                        .trim()
                        .split(/\s+/)
                        .filter(Boolean);

                    return words.length > 1 ?
                        words[0] + '................' :
                        (words[0] || '................');
                };

                const padRight = (value, width) => {
                    const text = String(value ?? '');
                    return text.length >= width ?
                        text.slice(0, width) :
                        text + ' '.repeat(width - text.length);
                };

                const padLeft = (value, width) => {
                    const text = String(value ?? '');
                    return text.length >= width ?
                        text.slice(-width) :
                        ' '.repeat(width - text.length) + text;
                };


                const line = '-----------------------------';

                let text = '';

                text += `رقم الفاتورة: ${inv.invoice_no || '-'}\n`;
                text += `التاريخ: ${inv.date || '-'}\n`;
                text += `العميل: ${inv.customer_name || 'نقدي'}\n`;
                text += `${line}\n`;

                /* الأصناف */
                (Array.isArray(inv.items) ? inv.items : []).forEach(item => {
                    const name = shortName(item.name || '-');
                    const qty = Number(item.quantity || 0);
                    const price = money(item.unit_price ?? item.price ?? 0);
                    const total = money(item.total_price || 0);

                    text += `${name}`;
                    text += `  ${qty} × ${price} = ${total}\n`;
                });

                const remaining = Math.max(
                    0,
                    Number(inv.total || 0) - Number(inv.paid_amount || 0)
                );

                text += '\n';
                text += line + '\n';
                text += `الإجمالي قبل الخصم: ${money(inv.subtotal)} ₪\n`;
                text += `الخصم: ${money(inv.discount)} ₪\n`;
                text += `الصافي: ${money(inv.total)} ₪\n`;
                text += `المدفوع: ${money(inv.paid_amount)} ₪\n`;
                text += `المتبقي: ${money(remaining)} ₪\n`;
                text += `طريقة الدفع: ${inv.payment_method || 'نقداً'}\n`;

                if (
                    Number(inv.previous_balance || 0) !== 0 ||
                    Number(inv.current_balance || 0) !== 0
                ) {
                    text += `${line}\n`;
                    text += `الرصيد السابق: ${money(inv.previous_balance)} ₪\n`;
                    text += `الرصيد الحالي: ${money(inv.current_balance)} ₪\n`;
                }

                if (inv.notes) {
                    text += `${line}\n`;
                    text += `ملاحظات: ${inv.notes}\n`;
                }

                /*
                |--------------------------------------------------------------------------
                | إرسال الفاتورة مباشرة إلى RawBT بدون فتح معاينة المتصفح
                |--------------------------------------------------------------------------
                */
                /*
                |--------------------------------------------------------------------------
                | Q6 Pro / RawBT direct print
                |--------------------------------------------------------------------------
                | The Q6 Pro opens this page in Android Chrome. RawBT registers the
                | `rawbt` scheme and receives the receipt without opening Chrome's
                | print dialog. RawBT can then use the Q6/iPOS internal printer.
                |
                | Keep the normal browser fallback for PCs only.
                |--------------------------------------------------------------------------
                */
                const isAndroid = /Android/i.test(navigator.userAgent || '');

                if (isAndroid) {
                    const intentUrl =
                        'intent:' +
                        encodeURIComponent(text) +
                        '#Intent;' +
                        'scheme=rawbt;' +
                        'package=ru.a402d.rawbtprinter;' +
                        'S.type=text/plain;' +
                        'end;';

                    window.location.href = intentUrl;
                    return;
                }

                /* Desktop / non-Android fallback only. */
                window.print();
            }
        );

        /*
        |--------------------------------------------------------------------------
        | WhatsApp
        |--------------------------------------------------------------------------
        */

        $wire.on(
            'open-whatsapp-url',
            (event) => {

                if (!event?.url) {
                    return;
                }

                window.open(
                    event.url,
                    '_blank',
                    'noopener,noreferrer'
                );

            }
        );
    </script>
@endscript
