<?php

use Livewire\Component;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

new class extends Component {

    /*
    |--------------------------------------------------------------------------
    | الفاتورة
    |--------------------------------------------------------------------------
    */

    public ?int $currentOrderId = null;

    public bool $invoiceEditMode = true;

    #[\Livewire\Attributes\Locked]
    public float $savedPaidAmount = 0.0;

    public string $invoiceNumber = '';

    public string $invoiceDate = '';

    public ?int $selectedBranchId = null;

    public ?int $selectedCustomerId = null;

    public bool $showCustomerStatementModal = false;

    public array $customerStatementRows = [];

    public float $customerStatementOpeningBalance = 0.0;

    public float $customerStatementCurrentBalance = 0.0;

    public float $customerStatementCalculatedBalance = 0.0;

    public string $customerSearch = '';

    public string $rowSearch = '';

    public ?int $selectedProductId = null;

    public string $selectedProductName = '';

    public float $selectedPrice = 0.0;

    public float $selectedCost = 0.0;

    public float $selectedStock = 0.0;

    public float $selectedQuantity = 1.0;

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
        $this->selectedPrice = 0;
        $this->selectedCost = 0;
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
        $this->invoiceEditMode = true;
        $this->savedPaidAmount = 0.0;
        $this->currentOrderId = null;
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
            $this->errorMessage = 'المنتج المحدد غير متاح.';
            return;
        }

        $branchProduct = $product->branchProducts->first();

        $this->selectedProductId = $product->id;
        $this->selectedProductName = $product->name;
        $this->selectedCost = (float) $product->cost_price;
        $this->selectedStock = (float) ($branchProduct?->stock_quantity ?? 0);

        if ($branchProduct) {
            $this->selectedPrice = (float) (
                $branchProduct->wholesale_price > 0
                    ? $branchProduct->wholesale_price
                    : $branchProduct->retail_price
            );
        } else {
            $this->selectedPrice = 0.0;
        }

        $this->selectedQuantity = 1.0;
        $this->rowSearch = $product->name;
        $this->errorMessage = null;

        $this->dispatch('focus-quantity');
    }

    public function selectProductByBarcode(string $barcode): bool
    {
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
        }
    }

    public function commitRowToCart(): void
    {
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

        $cartKey = $this->selectedProductId . '-' . Str::uuid()->toString();

        $this->cart[$cartKey] = [
            'id' => $this->selectedProductId,
            'name' => $this->selectedProductName,
            'price' => round((float) $this->selectedPrice, 2),
            'cost' => round((float) $this->selectedCost, 2),
            'quantity' => round((float) $this->selectedQuantity, 2),
            'discount' => 0.0,
            'stock' => round((float) $this->selectedStock, 2),
        ];

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPrice = 0.0;
        $this->selectedCost = 0.0;
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

            $lineTotal = max(
                0,
                ($price * $quantity) - $lineDiscount
            );

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

        $grandTotal = $taxableAmount;

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => round($invoiceDiscount, 2),
            'tax' => round($taxAmount, 2),
            'total' => round($grandTotal, 2),
            'total_cost' => round($totalCost, 2),
            'profit' => round($grandTotal - $taxAmount - $totalCost, 2),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | حفظ الفاتورة
    |--------------------------------------------------------------------------
    */

    public function saveInvoice(bool $shouldPrint = false): void
    {
        if ($this->currentOrderId && ! $this->invoiceEditMode) {
            $this->errorMessage = 'هذه فاتورة محفوظة للعرض فقط. اضغط «تعديل» أولاً لتغييرها.';

            return;
        }

        $this->authorize($this->currentOrderId ? 'sales_invoices.edit' : 'sales_invoices.create');
        $this->errorMessage = null;
        $this->successMessage = null;

        $this->validate(['invoiceDate' => ['required', 'date']]);

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

        $customer = null;

        if ($this->selectedCustomerId) {
            $customer = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'supplier', 'both'])
                ->where('is_active', true)
                ->find($this->selectedCustomerId);

            if (!$customer) {
                $this->errorMessage = 'العميل المحدد غير صالح.';
                return;
            }
        }

        $totals = $this->calculateTotals();

        try {
            $order = DB::transaction(function () use ($tenantId, $customer, $totals) {
                if ($this->currentOrderId) {
                    $order = Order::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($this->currentOrderId)
                        ->lockForUpdate()
                        ->first();

                    if (!$order) {
                        throw new \RuntimeException('الفاتورة المطلوب تعديلها غير موجودة.');
                    }

                    $order->update([
                        'branch_id' => $this->selectedBranchId,
                        'customer_id' => $customer?->id,
                        'customer_name' => $customer?->name ?? 'زبون عام',
                        'customer_phone' => $customer?->phone,
                        'customer_address' => $customer?->address,
                        'invoice_number' => $this->invoiceNumber,
                        'invoice_date' => $this->invoiceDate,
                        'type' => 'wholesale',
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
                        'paid_amount' => (float) $order->paid_amount,
                        'payment_status' => (float) $order->paid_amount >= $totals['total'] ? 'paid' : ((float) $order->paid_amount > 0 ? 'partial' : 'unpaid'),
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
                        'paid_amount' => $totals['total'],
                        'payment_status' => 'paid',
                        'notes' => $this->notes ?: null,
                    ]);
                }

                foreach ($this->cart as $item) {
                    $quantity = (float) ($item['quantity'] ?? 0);
                    $price = (float) ($item['price'] ?? 0);
                    $lineDiscount = (float) ($item['discount'] ?? 0);
                    $cost = (float) ($item['cost'] ?? 0);

                    $lineTotal = max(
                        0,
                        ($price * $quantity) - $lineDiscount
                    );

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
                    ]);
                }

                return $order->fresh(['items.product']);
            });

            $this->currentOrderId = $order->id;
            $this->savedPaidAmount = (float) $order->paid_amount;
            $this->invoiceNumber = $order->invoice_number;
            $this->lastSavedOrderId = $order->id;
            $this->lastSavedCustomerId = $order->customer_id;
            $this->lastSavedInvoiceNumber = $order->invoice_number;
            $this->lastSavedReceiptAmount = max(
                0,
                (float) $order->total - (float) Payment::query()
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

            $this->startNewInvoice(false);
            $this->successMessage = 'تم حفظ الفاتورة بنجاح وفتح فاتورة جديدة.';

        } catch (\Throwable $e) {
            report($e);
            $this->errorMessage = 'تعذر حفظ الفاتورة. تأكد من البيانات وحاول مرة أخرى.';
        }
    }

    public function saveAndPrint(): void
    {
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
            ->where('type', 'wholesale')
            ->where(function ($query) use ($search) {
                $query->where('invoice_number', 'like', "%{$search}%")
                    ->orWhere('id', is_numeric($search) ? (int) $search : 0);
            })
            ->orderByRaw('CASE WHEN invoice_number = ? THEN 0 ELSE 1 END', [$search])
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

    public function loadOrder(Order $order): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId || (int) $order->tenant_id !== $tenantId) {
            $this->errorMessage = 'لا يمكنك تحميل هذه الفاتورة.';
            return;
        }

        if ($order->type !== 'wholesale') {
            $this->errorMessage = 'هذه ليست فاتورة مبيعات من هذا النوع.';
            return;
        }

        $order->load('items.product');

        $this->currentOrderId = $order->id;
        $this->invoiceEditMode = false;
        $this->invoiceNumber = $order->invoice_number;
        $this->invoiceDate = $order->invoice_date?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');
        $this->selectedBranchId = $order->branch_id;
        $this->selectedCustomerId = $order->customer_id;
        $this->savedPaidAmount = (float) $order->paid_amount;
        $this->customerSearch = $order->customer_name ?: '';
        $this->discount = (float) ($order->discount ?? 0);
        $this->taxPercent = max(0, (float) ($order->tax_percent ?? 16));
        $this->taxable = true;
        $this->notes = $order->notes ?? '';

        $this->cart = [];

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
            ];
        }

        $this->selectedProductId = null;
        $this->selectedProductName = '';
        $this->selectedPrice = 0;
        $this->selectedCost = 0;
        $this->selectedStock = 0;
        $this->selectedQuantity = 1;
        $this->rowSearch = '';
        $this->successMessage = "تم تحميل الفاتورة {$order->invoice_number}.";
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
            ->where('type', 'wholesale');

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
            ->where('type', 'wholesale')
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

        $items = [];

        foreach ($order->items as $item) {
            $items[] = [
                'name' => $item->product?->name ?? 'مادة غير محددة',
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->unit_price,
                'discount' => (float) $item->discount,
                'total' => (float) $item->total_price,
            ];
        }

        $this->dispatch(
            'print-sales-invoice',
            invoice: [
                'store_name' => Tenant::query()->whereKey($order->tenant_id)->value('name') ?: 'المتجر',
                'number' => $order->invoice_number,
                'date' => $order->invoice_date?->format('Y-m-d') ?? $order->created_at?->format('Y-m-d H:i'),
                'customer' => $order->customer_name ?: 'زبون عام',
                'phone' => $order->customer_phone,
                'notes' => $order->notes,
                'items' => $items,
                'subtotal' => $totals['subtotal'],
                'discount' => $totals['discount'],
                'tax' => $totals['tax'],
                'total' => $totals['total'],
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
            ->first();

        if (! $order) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة.';

            return null;
        }

        return response()->streamDownload(function () use ($order): void {
            $pdf = app('dompdf.wrapper');
            $pdf->loadView('pages.tenant.pos.partials.invoice-pdf', [
                'order' => $order,
                'storeName' => Tenant::query()->whereKey($order->tenant_id)->value('name') ?: $order->branch?->name ?: 'المتجر',
            ]);

            echo $pdf->output();
        }, 'sales-invoice-'.$order->invoice_number.'.pdf');
    }

    public function shareInvoicePdf(): void
    {
        if (! $this->currentOrderId) {
            $this->errorMessage = 'احفظ الفاتورة أولاً قبل إرسال PDF عبر واتساب.';

            return;
        }

        $this->dispatch(
            'share-invoice-pdf',
            url: route('tenant.sale-invoices.pdf', ['orderId' => $this->currentOrderId]),
            filename: 'sales-invoice-'.$this->invoiceNumber.'.pdf',
            message: 'مرفق فاتورة مبيعات رقم '.$this->invoiceNumber
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
                ->tap(function ($query) use ($search, $tenantId): void {
                    app(\App\Services\PosProductSearch::class)->apply($query->getQuery(), $search, $tenantId);
                })
                ->orderBy('name')
                ->limit(10)
                ->get();
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
        $linkedReceiptAmount = $tenantId && $this->currentOrderId
            ? (float) Payment::query()->where('tenant_id', $tenantId)->where('order_id', $this->currentOrderId)->where('type', 'receipt')->sum('amount')
            : 0.0;

        return $this->view([
            'branches' => $branches,
            'productResults' => $productResults,
            'customerResults' => $customerResults,
            'selectedCustomer' => $selectedCustomer,
            'subtotal' => $totals['subtotal'],
            'invoiceDiscount' => $totals['discount'],
            'taxAmount' => $totals['tax'],
            'grandTotal' => $totals['total'],
            'linkedReceiptAmount' => $linkedReceiptAmount,
            'totalCost' => $totals['total_cost'],
            'profit' => $totals['profit'],
            'userBranchLocked' => (bool) $user?->branch_id,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="min-h-[calc(100vh-4rem)] bg-slate-100 p-2 sm:p-3 md:p-4" dir="rtl">

    <div
        class="mx-auto max-w-[1700px]"
        x-data="salesInvoiceKeyboard()"
        x-init="init()"
    >

        <livewire:product-history />
        {{-- ================================================================
             شريط الأدوات الرئيسي
        ================================================================= --}}

        <div class="mb-3 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

            <div class="flex flex-col gap-3 bg-slate-950 p-3 text-white lg:flex-row lg:items-center lg:justify-between">

                <div class="flex min-w-0 items-center gap-3">

                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white/10 text-xl">
                        🧾
                    </div>

                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h1 class="truncate text-base font-black sm:text-lg">
                                فاتورة مبيعات
                            </h1>

                            @if ($currentOrderId)
                                <span class="rounded-full border border-amber-300/30 bg-amber-400/15 px-2.5 py-1 text-[10px] font-black text-amber-300">
                                    تعديل فاتورة
                                </span>
                            @else
                                <span class="rounded-full border border-emerald-300/30 bg-emerald-400/15 px-2.5 py-1 text-[10px] font-black text-emerald-300">
                                    فاتورة جديدة
                                </span>
                            @endif
                        </div>

                        <div class="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-slate-300">
                            <span>رقم: <strong class="font-mono text-white">{{ $invoiceNumber }}</strong></span>
                            <span>التاريخ: <strong class="text-white">{{ $invoiceDate }}</strong></span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">

                    <button
                        type="button"
                        wire:click="startNewInvoice"
                        class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-black transition hover:bg-emerald-500"
                    >
                        + جديدة
                    </button>

                    <button
                        type="button"
                        wire:click="toggleInvoiceSearch"
                        class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15"
                    >
                        🔎 استعلام
                    </button>

                    <button
                        type="button"
                        wire:click="previousInvoice"
                        class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15"
                        title="الفاتورة السابقة"
                    >
                        ← السابقة
                    </button>

                    <button
                        type="button"
                        wire:click="nextInvoice"
                        class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15"
                        title="الفاتورة التالية"
                    >
                        التالية →
                    </button>

                    @if ($currentOrderId)
                        <button type="button" wire:click="printCurrentInvoice" class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15">🖨 طباعة</button>
                        <button type="button" wire:click="downloadInvoicePdf" class="rounded-xl border border-white/10 bg-white/10 px-3 py-2 text-xs font-black transition hover:bg-white/15">📄 PDF</button>
                        <button type="button" wire:click="shareInvoicePdf" class="rounded-xl border border-emerald-300/30 bg-emerald-500/20 px-3 py-2 text-xs font-black text-emerald-100 transition hover:bg-emerald-500/30">🟢 واتساب PDF</button>
                        @if (! $invoiceEditMode)
                            <button type="button" wire:click="editLoadedInvoice" class="rounded-xl bg-amber-500 px-3 py-2 text-xs font-black text-slate-950 transition hover:bg-amber-400">✎ تعديل</button>
                        @endif
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
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">F8</kbd> العميل</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">F9</kbd> المنتج</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">Enter</kbd> اختيار / التالي</span>
                <span><kbd class="rounded border border-slate-300 bg-white px-1.5 py-0.5 text-slate-700">Esc</kbd> إغلاق القوائم</span>
            </div>
        </div>

        {{-- ================================================================
             الرسائل
        ================================================================= --}}

        @if ($errorMessage)
            <div class="mb-3 flex items-center justify-between gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-bold text-rose-700">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-rose-100">!</span>
                    <span>{{ $errorMessage }}</span>
                </div>

                <button type="button" wire:click="$set('errorMessage', null)" class="rounded-lg px-2 py-1 hover:bg-rose-100">✕</button>
            </div>
        @endif

        @if ($successMessage)
            <div class="mb-3 flex items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-100">✓</span>
                    <span>{{ $successMessage }}</span>
                </div>

                <div class="flex items-center gap-2">
                    @if ($lastSavedOrderId && $lastSavedCustomerId)
                        <a href="{{ route('tenant.receipts', ['party_id' => $lastSavedCustomerId, 'order_id' => $lastSavedOrderId, 'amount' => $lastSavedReceiptAmount, 'notes' => 'سند قبض لفاتورة '.$lastSavedInvoiceNumber]) }}" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-black text-white hover:bg-emerald-700">إضافة سند قبض</a>
                    @endif
                    <button type="button" wire:click="$set('successMessage', null)" class="rounded-lg px-2 py-1 hover:bg-emerald-100">✕</button>
                </div>
            </div>
        @endif

        {{-- ================================================================
             البحث عن فاتورة
        ================================================================= --}}

        @if ($showInvoiceSearch)
            <div class="mb-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                <div class="flex flex-col gap-2 sm:flex-row">
                    <div class="relative flex-1">
                        <input
                            id="invoice-search-input"
                            x-ref="invoiceSearch"
                            type="text"
                            wire:model.live.debounce.200ms="searchInvoiceQuery"
                            wire:keydown.enter="searchInvoice"
                            placeholder="رقم الفاتورة أو رقمها الداخلي..."
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:border-slate-400 focus:bg-white"
                        />
                    </div>

                    <button
                        type="button"
                        wire:click="searchInvoice"
                        class="rounded-xl bg-slate-900 px-5 py-3 text-sm font-black text-white hover:bg-slate-800"
                    >
                        بحث
                    </button>

                    <button
                        type="button"
                        wire:click="$set('showInvoiceSearch', false)"
                        class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-black text-slate-600 hover:bg-slate-50"
                    >
                        إغلاق
                    </button>
                </div>
            </div>
        @endif

        {{-- ================================================================
             بيانات رأس الفاتورة
        ================================================================= --}}

        <div class="mb-3 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">

            <div class="mb-3 flex items-center justify-between gap-2 border-b border-slate-100 pb-3">
                <div>
                    <div class="text-sm font-black text-slate-900">بيانات الفاتورة</div>
                    <div class="mt-1 text-[11px] font-semibold text-slate-400">أدخل العميل والفرع ثم ابدأ بإضافة المواد</div>
                </div>

                <div class="rounded-xl bg-slate-50 px-3 py-2 text-left">
                    <div class="text-[10px] font-bold text-slate-400">رقم الفاتورة</div>
                    <div class="font-mono text-sm font-black text-slate-900">{{ $invoiceNumber }}</div>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-12">

                {{-- العميل --}}
                <div class="relative lg:col-span-5" data-invoice-search-group x-data="{ partySearchOpen: false }" x-on:focusout="if (!$el.contains($event.relatedTarget)) partySearchOpen = false" x-on:click.outside="partySearchOpen = false" x-on:keydown.escape.stop="partySearchOpen = false">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">العميل / المورد</label>

                    @if ($selectedCustomer)
                        <div class="flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-black text-emerald-900">{{ $selectedCustomer->name }}</div>
                                <div class="mt-0.5 text-[11px] font-semibold text-emerald-700">
                                    {{ $selectedCustomer->phone ?: 'بدون هاتف' }}
                                </div>
                                <div class="mt-0.5 text-[11px] font-bold text-slate-600">الرصيد الحالي: {{ number_format((float) $selectedCustomer->current_balance, 2) }}</div>
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-1">
                                @if (auth()->user()->can('reports.analytics') && auth()->user()->can('customers.view'))
                                    <button type="button" wire:click="openCustomerStatement" class="rounded-lg bg-white px-2 py-1 text-[10px] font-black text-indigo-700 shadow-sm">كشف الحساب</button>
                                @endif
                                @if ($currentOrderId)
                                    <a href="{{ route('tenant.receipts', ['party_id' => $selectedCustomerId, 'order_id' => $currentOrderId, 'amount' => max(0, $grandTotal - $linkedReceiptAmount), 'notes' => 'سند قبض لفاتورة '.$invoiceNumber]) }}" class="rounded-lg bg-white px-2 py-1 text-[10px] font-black text-emerald-700 shadow-sm">إضافة سند قبض</a>
                                @endif
                                <button type="button" wire:click="clearCustomer" class="rounded-lg px-2 py-1 text-xs font-black text-emerald-700 hover:bg-emerald-100">تغيير</button>
                            </div>
                        </div>
                    @else
                        <input
                            id="customer-search-input" autofocus x-init="$nextTick(() => requestAnimationFrame(() => $el.focus({ preventScroll: true })))" x-on:focus="partySearchOpen = true" x-on:input="customerIndex = -1; refreshResultClasses(); partySearchOpen = true"
                            x-ref="customerSearch"
                            type="text"
                            wire:model.live.debounce.180ms="customerSearch"
                            placeholder="ابحث باسم العميل أو المورد أو الهاتف..."
                            autocomplete="off"
                            class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-bold text-slate-900 outline-none focus:border-slate-400 focus:bg-white"
                        />

                        @if (trim($customerSearch) !== '')
                            <div data-invoice-search-results x-cloak x-show="partySearchOpen" x-on:click="partySearchOpen = false" class="absolute right-0 top-full z-40 mt-1 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                                <div class="max-h-64 overflow-y-auto p-1.5">
                                    @forelse ($customerResults as $customer)
                                        <button
                                            type="button"
                                            data-customer-result
                                            data-result-index="{{ $loop->index }}"
                                            wire:click="selectCustomer({{ $customer->id }})"
                                            class="group flex w-full items-center justify-between gap-3 rounded-xl px-3 py-3 text-right transition hover:bg-slate-50"
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

                {{-- الفرع --}}
                <div class="lg:col-span-3">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">الفرع</label>

                    <select
                        wire:model.live="selectedBranchId"
                        @disabled($userBranchLocked)
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700 outline-none focus:border-slate-400 focus:bg-white disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- التاريخ --}}
                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">التاريخ</label>
                    <input
                        type="date"
                        wire:model="invoiceDate"
                        class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-black text-slate-700 outline-none focus:border-slate-400 focus:bg-white"
                    />
                </div>

                {{-- الحالة --}}
                <div class="lg:col-span-2">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">نوع العملية</label>
                    <div class="flex h-[46px] items-center rounded-xl border border-indigo-200 bg-indigo-50 px-3">
                        <span class="h-2.5 w-2.5 rounded-full bg-indigo-500"></span>
                        <span class="mr-2 text-xs font-black text-indigo-800">مبيعات</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ================================================================
             منطقة إدخال المواد
        ================================================================= --}}

        <div class="mb-3 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

            <div class="flex flex-col gap-3 border-b border-slate-200 bg-slate-50 p-3 lg:flex-row lg:items-end">

                <div class="min-w-0 flex-1">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">إضافة مادة / باركود</label>

                    <div class="relative" data-invoice-search-group x-data="{ productSearchOpen: false }" x-on:focusout="if (!$el.contains($event.relatedTarget)) productSearchOpen = false" x-on:click.outside="productSearchOpen = false" x-on:keydown.escape.stop="productSearchOpen = false">
                        <button type="button" x-ref="priceHistoryTrigger" data-entry-price-history aria-label="أسعار الصنف والتكلفة (F2)" title="أسعار الصنف والتكلفة (F2)" @disabled(!$selectedProductId) x-on:click.stop="$dispatch('open-product-price-history', { productId: {{ $selectedProductId ?? 'null' }}, customerId: {{ $selectedCustomerId ?? 'null' }} })" class="absolute left-2 top-1/2 z-10 -translate-y-1/2 rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1.5 text-xs font-bold text-indigo-700 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-40">الأسعار F2</button>
                        <input
                            id="product-search-input"
                            x-ref="productSearch"
                            type="text"
                            wire:model.live.debounce.250ms="rowSearch"
                            maxlength="120" x-on:input="productIndex = -1; refreshResultClasses(); productSearchOpen = true" x-on:focus="productSearchOpen = true"
                            autocomplete="off"
                            placeholder="ابحث باسم الصنف أو رقمه أو امسح الباركود ثم اضغط Enter..."
                            class="w-full rounded-xl border-2 border-indigo-200 bg-white px-4 py-3 pl-28 text-sm font-black text-slate-900 outline-none transition focus:border-indigo-500"
                        />

                        @if (count($productResults) > 0)
                            <div data-invoice-search-results x-cloak x-show="productSearchOpen" x-on:click="if ($event.target.closest('[data-product-result]')) productSearchOpen = false" class="absolute right-0 top-full z-50 mt-1 w-full overflow-hidden rounded-2xl border border-indigo-200 bg-white shadow-2xl">
                                <div class="max-h-80 overflow-auto" x-ref="productResults">
                                    <table class="w-full text-right text-sm">
                                        <thead class="sticky top-0 bg-slate-900 text-xs text-white"><tr><th class="p-2">رقم الصنف</th><th class="p-2">اسم الصنف</th><th class="p-2">السعر</th><th class="p-2">المخزون</th></tr></thead>
                                        <tbody>
                                            @foreach ($productResults as $product)
                                                @php
                                                    $bp = $product->branchProducts->first();
                                                    $resultPrice = (float) ($bp?->wholesale_price > 0 ? $bp?->wholesale_price : ($bp?->retail_price ?? 0));
                                                    $stock = (float) ($bp?->stock_quantity ?? 0);
                                                @endphp
                                                <tr data-product-result data-result-index="{{ $loop->index }}" x-on:mousedown.prevent wire:click="selectProduct({{ $product->id }})" wire:loading.attr="disabled" class="cursor-pointer border-t border-slate-100 hover:bg-indigo-50">
                                                    <td class="p-2 font-mono">{{ $product->product_number ?? $product->id }}</td>
                                                    <td class="p-2 font-bold">{{ $product->name }}</td>
                                                    <td class="p-2 font-mono">{{ number_format($resultPrice, 2) }}</td>
                                                    <td class="p-2 tabular-nums">{{ number_format($stock, 2) }}</td>
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
                        x-ref="quantityInput"
                        type="number"
                        min="0.01"
                        step="0.01"
                        wire:model="selectedQuantity"
                        x-on:keydown.enter.prevent="$nextTick(() => $refs.priceInput?.focus())"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-sm font-black text-slate-900 outline-none focus:border-indigo-500"
                    />
                </div>

                <div class="w-full lg:w-40">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">السعر</label>
                    <input
                        x-ref="priceInput"
                        type="number"
                        step="0.01"
                        min="0"
        wire:model="selectedPrice"
                        wire:keydown.enter.prevent="commitRowToCart"
                        class="w-full rounded-xl border border-slate-200 bg-white px-3 py-3 text-center text-sm font-black text-indigo-700 outline-none focus:border-indigo-500"
                    />
                </div>

                <div class="w-full lg:w-40">
                    <label class="mb-1.5 block text-xs font-black text-slate-600">الإجمالي</label>
                    <div class="rounded-xl border border-slate-200 bg-white px-3 py-3 text-center font-mono text-sm font-black text-slate-900">
                        {{ number_format(max(0, $selectedPrice * $selectedQuantity), 2) }}
                    </div>
                </div>

                <button
                    type="button"
                    wire:click="commitRowToCart"
                    class="flex h-[46px] items-center justify-center rounded-xl bg-indigo-600 px-5 text-sm font-black text-white transition hover:bg-indigo-500 disabled:opacity-50"
                >
                    + إضافة
                </button>
            </div>

            {{-- ============================================================
                 جدول الفاتورة
            ============================================================== --}}

            <div class="overflow-x-auto">
                <div class="app-table-scroll" role="region" aria-label="جدول البيانات — اسحب لعرض بقية الأعمدة" tabindex="0">
                <table class="w-full min-w-[1050px] border-collapse text-right">

                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-900 text-[11px] font-black text-white">
                            <th class="w-12 px-3 py-3 text-center">#</th>
                            <th class="w-24 px-3 py-3 text-center">رمز المادة</th>
                            <th class="px-3 py-3">اسم المادة</th>
                            <th class="w-28 px-3 py-3 text-center">المخزون</th>
                            <th class="w-28 px-3 py-3 text-center">الكمية</th>
                            <th class="w-32 px-3 py-3 text-center">السعر</th>
                            <th class="w-28 px-3 py-3 text-center">الخصم</th>
                            <th class="w-36 px-3 py-3 text-center">الإجمالي</th>
                            <th class="w-16 px-3 py-3 text-center">حذف</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        @forelse ($cart as $key => $item)
                            @php
                                $lineTotal = max(
                                    0,
                                    ((float) $item['price'] * (float) $item['quantity'])
                                        - (float) ($item['discount'] ?? 0)
                                );
                            @endphp

                            <tr
                                data-invoice-row="{{ $loop->index }}"
                                wire:key="invoice-item-{{ $key }}"
                                class="group transition hover:bg-slate-50"
                            >
                                <td class="px-3 py-2.5 text-center">
                                    <div class="mx-auto flex h-8 w-8 items-center justify-center rounded-lg bg-slate-100 text-xs font-black text-slate-500">
                                        {{ $loop->iteration }}
                                    </div>
                                </td>

                                <td class="px-3 py-2.5 text-center font-mono text-xs font-black text-slate-500">
                                    {{ $item['id'] }}
                                </td>

                                <td class="px-3 py-2.5">
                                    <div class="text-sm font-black text-slate-800"><button type="button" data-price-history-button x-on:click.stop="$dispatch('open-product-price-history', { productId: {{ $item['id'] }}, customerId: {{ $selectedCustomerId ?? 'null' }} })" title="سجل الأسعار والتكلفة" aria-label="سجل الأسعار والتكلفة" style="float: left; width: 20px; height: 20px; padding: 0; border: 0; background: transparent; color: #4f46e5; cursor: pointer;"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="width: 18px; height: 18px;" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8m3-6h-4a2 2 0 0 0 0 4h2a2 2 0 0 0 0-4M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg></button>{{ $item['name'] }} <button type="button" wire:click="$dispatch('show-product-history', { productId: {{ $item['id'] }} })" class="rounded px-2 py-1 text-xs text-indigo-700">حركة الصنف</button></div>
                                    <div class="mt-0.5 text-[10px] font-semibold text-slate-400">
                                        تكلفة الوحدة: {{ number_format((float) $item['cost'], 2) }}
                                    </div>
                                </td>

                                <td class="px-3 py-2.5 text-center">
                                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-black text-slate-600">
                                        {{ number_format((float) ($item['stock'] ?? 0), 2) }}
                                    </span>
                                </td>

                                <td class="px-3 py-2.5 text-center">
                                    <input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        value="{{ $item['quantity'] }}"
                                        data-grid-input
                                        data-row="{{ $loop->index }}"
                                        data-col="0"
                                        wire:change="updateItemQuantity('{{ $key }}', $event.target.value)"
                                        class="w-24 rounded-lg border border-slate-200 bg-white px-2 py-2 text-center text-sm font-black text-slate-900 outline-none focus:border-indigo-500"
                                    />
                                </td>

                                <td class="px-3 py-2.5 text-center">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value="{{ $item['price'] }}"
                                        data-grid-input
                                        data-row="{{ $loop->index }}"
                                        data-col="1"
                                        wire:change="updateItemPrice('{{ $key }}', $event.target.value)"
                                        class="w-28 rounded-lg border border-slate-200 bg-white px-2 py-2 text-center text-sm font-black text-indigo-700 outline-none focus:border-indigo-500"
                                    />
                                </td>

                                <td class="px-3 py-2.5 text-center">
                                    <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        value="{{ $item['discount'] }}"
                                        data-grid-input
                                        data-row="{{ $loop->index }}"
                                        data-col="2"
                                        wire:change="updateItemDiscount('{{ $key }}', $event.target.value)"
                                        class="w-24 rounded-lg border border-slate-200 bg-white px-2 py-2 text-center text-sm font-black text-rose-600 outline-none focus:border-indigo-500"
                                    />
                                </td>

                                <td class="px-3 py-2.5 text-center">
                                    <div class="font-mono text-sm font-black text-slate-900">
                                        {{ number_format($lineTotal, 2) }}
                                    </div>
                                </td>

                                <td class="px-3 py-2.5 text-center">
                                    <button
                                        type="button"
                                        wire:click="removeItem('{{ $key }}')"
                                        class="rounded-lg p-2 text-rose-600 transition hover:bg-rose-50"
                                        title="حذف السطر"
                                    >
                                        ✕
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-6 py-20 text-center">
                                    <div class="mx-auto max-w-md">
                                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-slate-100 text-2xl">＋</div>
                                        <div class="mt-4 text-base font-black text-slate-800">الفاتورة فارغة</div>
                                        <div class="mt-1 text-sm font-semibold text-slate-400">ابدأ بالبحث عن مادة أو امسح الباركود لإضافتها.</div>
                                        <button
                                            type="button"
                                            @click="$refs.productSearch?.focus(); $refs.productSearch?.select()"
                                            class="mt-4 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-black text-white hover:bg-indigo-500"
                                        >
                                            ابدأ إضافة المواد
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        </div>

        {{-- ================================================================
             أسفل الفاتورة
        ================================================================= --}}

        <div class="grid gap-3 xl:grid-cols-12">

            {{-- ملاحظات --}}
            <div class="xl:col-span-5">
                <div class="h-full rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div class="mb-3 flex items-center justify-between">
                        <div>
                            <div class="text-sm font-black text-slate-900">ملاحظات الفاتورة</div>
                            <div class="mt-1 text-[11px] font-semibold text-slate-400">أي ملاحظة تظهر مع الفاتورة المطبوعة</div>
                        </div>

                        <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[10px] font-black text-slate-500">
                            اختياري
                        </span>
                    </div>

                    <textarea
                        wire:model="notes"
                        rows="5"
                        placeholder="اكتب ملاحظة أو شروط البيع..."
                        class="w-full resize-none rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm font-semibold text-slate-800 outline-none focus:border-slate-400 focus:bg-white"
                    ></textarea>

                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button
                            type="button"
                            wire:click="saveInvoice"
                            @disabled(empty($cart))
                            class="rounded-xl bg-slate-900 px-4 py-3 text-sm font-black text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {{ $currentOrderId ? 'حفظ التعديل' : 'حفظ الفاتورة' }}
                        </button>

                        <button
                            type="button"
                            wire:click="saveAndPrint"
                            @disabled(empty($cart))
                            class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            حفظ وطباعة
                        </button>
                    </div>
                </div>
            </div>

            {{-- الحسابات --}}
            <div class="xl:col-span-4">
                <div class="h-full rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <div class="text-sm font-black text-slate-900">الحسابات</div>
                            <div class="mt-1 text-[11px] font-semibold text-slate-400">قيمة الفاتورة قبل الحفظ</div>
                        </div>

                        <span class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-[11px] font-black text-emerald-700">الأسعار شاملة الضريبة</span>
                    </div>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-500">المجموع الفرعي</span>
                            <span class="font-mono text-sm font-black text-slate-900">{{ number_format($subtotal, 2) }}</span>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-xs font-bold text-slate-500">خصم إضافي</span>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                wire:model.live="discount"
                                class="w-28 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-sm font-black text-rose-600 outline-none focus:border-slate-400 focus:bg-white"
                            />
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <span class="text-xs font-bold text-slate-500">قيمة الضريبة المضمنة</span>
                            <div class="flex items-center gap-2">
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    wire:model.live.debounce.500ms="taxPercent"
                                    class="w-20 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2 text-center text-xs font-black text-slate-700 outline-none focus:border-slate-400 focus:bg-white disabled:opacity-50"
                                />
                                <span class="text-xs font-black text-slate-500">%</span>
                                <span class="font-mono text-sm font-black text-amber-600">{{ number_format($taxAmount, 2) }}</span>
                            </div>
                        </div>

                        <div class="border-t border-slate-100 pt-3">
                            <div class="flex items-center justify-between rounded-2xl bg-slate-950 px-4 py-4 text-white">
                                <div>
                                    <div class="text-[11px] font-bold text-slate-400">الإجمالي النهائي</div>
                                    <div class="mt-1 text-xs font-black text-slate-200">شامل الخصم والضريبة</div>
                                </div>
                                <div class="font-mono text-2xl font-black">{{ number_format($grandTotal, 2) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- الملخص والإجراءات --}}
            <div class="xl:col-span-3">
                <div class="h-full rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                    <div class="mb-4 text-sm font-black text-slate-900">ملخص الفاتورة</div>

                    @if ($currentOrderId)
                        <div class="mb-4 space-y-2 rounded-xl bg-slate-50 p-3 text-sm font-bold">
                            <div class="flex justify-between"><span>المدفوع</span><span>{{ number_format($savedPaidAmount, 2) }}</span></div>
                            <div class="flex justify-between text-rose-600"><span>المتبقي على الزبون</span><span>{{ number_format(max(0, $grandTotal - $savedPaidAmount), 2) }}</span></div>
                        </div>
                        @if ($selectedCustomerId)
                            <a href="{{ route('tenant.receipts', ['party_id' => $selectedCustomerId, 'order_id' => $currentOrderId, 'amount' => max(0, $grandTotal - $linkedReceiptAmount), 'notes' => 'سند قبض لفاتورة '.$invoiceNumber]) }}" class="mb-4 block rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-center text-sm font-black text-emerald-700 hover:bg-emerald-100">إضافة سند قبض لهذه الفاتورة</a>
                        @endif
                    @endif

                    <div class="space-y-2">
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5">
                            <span class="text-[11px] font-bold text-slate-500">عدد السطور</span>
                            <span class="font-mono text-sm font-black text-slate-900">{{ count($cart) }}</span>
                        </div>

                        <div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5">
                            <span class="text-[11px] font-bold text-slate-500">إجمالي التكلفة</span>
                            <span class="font-mono text-sm font-black text-slate-900">{{ number_format($totalCost, 2) }}</span>
                        </div>

                        <div class="flex items-center justify-between rounded-xl bg-emerald-50 px-3 py-2.5">
                            <span class="text-[11px] font-bold text-emerald-700">الربح المتوقع</span>
                            <span class="font-mono text-sm font-black text-emerald-700">{{ number_format($profit, 2) }}</span>
                        </div>
                    </div>

                    <div class="mt-4 space-y-2">
                        <button
                            type="button"
                            wire:click="printCurrentInvoice"
                            @disabled(!$currentOrderId)
                            class="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-black text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            🖨️ طباعة الفاتورة
                        </button>

                        <button
                            type="button"
                            wire:click="startNewInvoice"
                            class="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-black text-slate-700 hover:bg-slate-50"
                        >
                            ↻ فاتورة جديدة
                        </button>
                    </div>

                    <div class="mt-4 rounded-xl border border-indigo-100 bg-indigo-50 p-3">
                        <div class="text-[10px] font-black text-indigo-500">طريقة الحفظ الحالية</div>
                        <div class="mt-1 text-xs font-bold leading-5 text-indigo-900">
                            تُحفظ الفاتورة مع الأصناف والمبالغ المسددة والمتبقية، وتظهر حالة الدفع حسب المبلغ المدفوع فعليًا.
                        </div>
                    </div>
                </div>
            </div>
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
                            <a href="{{ route('tenant.receipts', ['party_id' => $selectedCustomerId, 'order_id' => $currentOrderId, 'amount' => max(0, $grandTotal - $linkedReceiptAmount), 'notes' => 'سند قبض لفاتورة '.$invoiceNumber]) }}" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-black text-white hover:bg-emerald-700">إضافة سند قبض</a>
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
                                <tr><td class="whitespace-nowrap p-3">{{ \Illuminate\Support\Carbon::parse($transaction['date'])->format('Y-m-d H:i') }}</td><td class="p-3">{{ $transaction['description'] }}</td><td class="p-3 tabular-nums">{{ $transaction['debit'] > 0 ? number_format($transaction['debit'], 2) : '—' }}</td><td class="p-3 tabular-nums">{{ $transaction['credit'] > 0 ? number_format($transaction['credit'], 2) : '—' }}</td><td class="p-3 font-bold tabular-nums">{{ number_format($transaction['balance'], 2) }}</td></tr>
                            @empty
                                <tr><td colspan="5" class="p-8 text-center font-semibold text-slate-400">لا توجد حركات بعد الرصيد الافتتاحي.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endif
    <livewire:product-price-history-modal />
</flux:main>

@script
<script>
    Alpine.data('salesInvoiceKeyboard', () => ({
        productIndex: -1,
        customerIndex: -1,
        resultRefreshTimer: null,
        keyboardController: null,

        init() {
            this.keyboardController = new AbortController();
            window.addEventListener('keydown', (event) => this.globalKeydown(event), {
                signal: this.keyboardController.signal,
            });

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
                }));
            });

            Livewire.on('focus-quantity', () => {
                setTimeout(() => {
                    const input = this.$refs.quantityInput;
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
                    const input = this.$refs.invoiceSearch;
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

        isTypingElement(element) {
            if (!element) return false;

            return element.matches('input, textarea, select, button, [contenteditable="true"]');
        },

        productResults() {
            return Array.from(this.$el.querySelectorAll('[data-product-result]')).filter(element => element.getClientRects().length > 0);
        },

        customerResults() {
            return Array.from(this.$el.querySelectorAll('[data-customer-result]')).filter(element => element.getClientRects().length > 0);
        },

        refreshResultClasses() {
            const products = this.productResults();
            const customers = this.customerResults();

            products.forEach((element, index) => {
                element.classList.toggle('bg-indigo-50', index === this.productIndex);
                element.classList.toggle('ring-1', index === this.productIndex);
                element.classList.toggle('ring-inset', index === this.productIndex);
                element.classList.toggle('ring-indigo-300', index === this.productIndex);
            });

            customers.forEach((element, index) => {
                element.classList.toggle('bg-slate-50', index === this.customerIndex);
                element.classList.toggle('ring-1', index === this.customerIndex);
                element.classList.toggle('ring-inset', index === this.customerIndex);
                element.classList.toggle('ring-slate-300', index === this.customerIndex);
            });
        },

        moveResult(type, direction) {
            const list = type === 'product'
                ? this.productResults()
                : this.customerResults();

            if (!list.length) return;

            const key = type === 'product' ? 'productIndex' : 'customerIndex';
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
                : this.customerResults();

            if (!list.length) return false;

            const key = type === 'product' ? 'productIndex' : 'customerIndex';
            const selected = list[this[key]] ?? (this[key] < 0 ? list[0] : null);
            if (!selected) return false;

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
                    this.$refs.productSearch?.focus();
                    this.$refs.productSearch?.select();
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
                this.$refs.productSearch?.focus();
                this.$refs.productSearch?.select();
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
                this.$wire.toggleInvoiceSearch();
                return;
            }

            if (key === 'F8') {
                event.preventDefault();
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
                    this.$refs.productSearch?.focus();
                    this.$refs.productSearch?.select();
                }
            }
        }
    }));

    Livewire.on('print-sales-invoice', ({ invoice }) => {
        if (!invoice) return;

        const items = Array.isArray(invoice.items) ? invoice.items : [];

        const itemRows = items.map((item, index) => `
            <tr>
                <td>${index + 1}</td>
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
                <title>فاتورة مبيعات - ${escapeHtml(invoice.number ?? '')}</title>
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
                            <div class="label">العميل</div>
                            <div class="value">${escapeHtml(invoice.customer ?? 'زبون عام')}</div>
                            ${invoice.phone ? `<div class="meta">${escapeHtml(invoice.phone)}</div>` : ''}
                        </div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th style="width:40px">#</th>
                                <th>الصنف</th>
                                <th style="width:85px">الكمية</th>
                                <th style="width:100px">السعر</th>
                                <th style="width:90px">الخصم</th>
                                <th style="width:110px">الإجمالي</th>
                            </tr>
                        </thead>
                        <tbody>${itemRows}</tbody>
                    </table>

                    <div class="summary">
                        <div class="summary-row"><span>المجموع الفرعي</span><strong>${Number(invoice.subtotal ?? 0).toFixed(2)}</strong></div>
                        <div class="summary-row"><span>الخصم</span><strong>${Number(invoice.discount ?? 0).toFixed(2)}</strong></div>
                        <div class="summary-row"><span>الضريبة</span><strong>${Number(invoice.tax ?? 0).toFixed(2)}</strong></div>
                        <div class="total summary-row"><span>الإجمالي النهائي</span><strong>${Number(invoice.total ?? 0).toFixed(2)}</strong></div>
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
