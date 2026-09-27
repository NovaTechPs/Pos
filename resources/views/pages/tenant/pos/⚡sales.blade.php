<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shift;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component {
    public array $receipt = [];
    public string $barcode = '';
    public array $cart = [];
    public float $paid_amount = 0;
    public string $payment_method = 'cash';
    public string $notes = '';
    public string $inlineSearchQuery = '';
    public array $inlineSearchResults = [];
    public string $searchInvoiceQuery = '';
    public string $productSearchQuery = '';
    public ?int $selectedBranchId = null;
    public float $discount_amount = 0;
    public string $discount_type = 'fixed';
    public ?float $custom_final_total = null;
    public array $categories = [];
    public ?int $selectedCategoryId = null;
    public array $quickProducts = [];
    public ?string $errorMessage = null;
    public ?string $successMessage = null;
    public ?int $currentInvoiceId = null;
    public bool $isEditingInvoice = false;
    public array $heldInvoices = [];
    public bool $showHeldModal = false;
    public bool $showCostModal = false;
    public bool $showBelowCostModal = false;
    public string $pendingCheckoutMode = 'checkout';
    public bool $showProductsModal = false;
    public bool $isReturnMode = false;
    public ?int $activeShiftId = null;
    public bool $showOpenShiftModal = false;
    public bool $showCloseShiftModal = false;
    public float $opening_cash = 0;
    public float $actual_cash = 0;
    public string $shift_notes = '';
    public float $shift_opening_cash = 0;
    public float $shift_total_sales = 0;
    public float $shift_total_returns = 0;
    public float $shift_cash_receipts = 0;
    public float $shift_cash_payments = 0;
    public float $shift_expected_cash = 0;

    public function mount(): void
    {
        $user = Auth::user();
        $this->selectedBranchId = $user?->branch_id ?: session('active_branch_id');

        $tenantId = $this->tenantId();

        if ($tenantId) {
            $this->categories = Category::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get()
                ->toArray();
        }

        $this->loadHeldInvoices();
        $this->checkActiveShift();
        $this->loadQuickProducts();
    }

    protected function tenantId(): ?int
    {
        $tenantId = session('active_tenant_id') ?? Auth::user()?->tenant_id;

        return $tenantId ? (int) $tenantId : null;
    }

    private function getActiveBranchId(): ?int
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();

        if (!$tenantId || !$user) {
            return null;
        }

        $branchId = $user->branch_id ?: $this->selectedBranchId ?: session('active_branch_id');

        if (!$branchId) {
            return null;
        }

        $branchId = (int) $branchId;

        return Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($branchId)
            ->exists()
            ? $branchId
            : null;
    }

    private function heldSessionKey(): ?string
    {
        $tenantId = $this->tenantId();
        $userId = Auth::id();
        $branchId = $this->getActiveBranchId();

        if (!$tenantId || !$userId || !$branchId) {
            return null;
        }

        return "pos.held.{$tenantId}.{$branchId}.{$userId}";
    }

    private function saveHeldInvoices(): void
    {
        $key = $this->heldSessionKey();

        if ($key) {
            session([$key => $this->heldInvoices]);
        }
    }

    private function loadHeldInvoices(): void
    {
        $key = $this->heldSessionKey();
        $this->heldInvoices = $key ? (array) session($key, []) : [];
    }

    public function activeShift(): ?Shift
    {
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();
        $userId = Auth::id();

        if (!$tenantId || !$branchId || !$userId) {
            return null;
        }

        $shift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('opened_by', $userId)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if (!$shift) {
            return null;
        }

        $sales = (float) Order::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('shift_id', $shift->id)
            ->where('type', 'pos')
            ->where('status', 'completed')
            ->sum('total');

        $returns = abs((float) Order::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('shift_id', $shift->id)
            ->where('type', 'return')
            ->where('status', 'completed')
            ->sum('total'));

        $cashSales = (float) Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->where('type', 'receipt')
            ->where('payment_method', 'cash')
            ->sum('amount');

        $cashReturns = (float) Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->where('type', 'payment')
            ->where('payment_method', 'cash')
            ->sum('amount');

        $expectedCash = (float) $shift->opening_cash + $cashSales - $cashReturns;

        $shift->setAttribute('live_total_sales', $sales);
        $shift->setAttribute('live_total_returns', $returns);
        $shift->setAttribute('live_cash_sales', $cashSales);
        $shift->setAttribute('live_cash_returns', $cashReturns);
        $shift->setAttribute('live_expected_cash', $expectedCash);

        return $shift;
    }

    public function updatedSelectedBranchId($value): void
    {
        $user = Auth::user();

        if ($user?->branch_id) {
            $this->selectedBranchId = (int) $user->branch_id;
            return;
        }

        $branchId = $value ? (int) $value : null;
        $tenantId = $this->tenantId();

        if (
            $branchId &&
            $tenantId &&
            Branch::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($branchId)
                ->exists()
        ) {
            session(['active_branch_id' => $branchId]);
            $this->selectedBranchId = $branchId;
            $this->activeShiftId = null;
            $this->loadHeldInvoices();
            $this->checkActiveShift();
            $this->loadQuickProducts();
            return;
        }

        $this->selectedBranchId = null;
        $this->activeShiftId = null;
        session()->forget('active_branch_id');
        $this->quickProducts = [];
        $this->heldInvoices = [];
        $this->errorMessage = 'الفرع المحدد غير صالح لهذا المتجر.';
    }

    public function checkActiveShift(): void
    {
        $shift = $this->activeShift();
        $this->activeShiftId = $shift?->id;
    }

    public function triggerOpenShiftModal(): void
    {
        if ($this->activeShift()) {
            $this->showOpenShiftModal = false;
            $this->successMessage = 'الشيفت الحالي مفتوح بالفعل.';
            return;
        }

        $this->opening_cash = 0;
        $this->shift_notes = '';
        $this->showOpenShiftModal = true;
    }

    public function openShift(): void
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();
        $branchId = $this->getActiveBranchId();

        if (!$tenantId || !$user) {
            $this->errorMessage = 'يرجى تسجيل الدخول وتحديد المتجر أولاً.';
            return;
        }

        if (!$branchId) {
            $this->errorMessage = 'يرجى اختيار الفرع أولاً.';
            return;
        }

        try {
            $shift = DB::transaction(function () use ($tenantId, $branchId, $user): Shift {
                $existing = Shift::query()
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $branchId)
                    ->where('opened_by', $user->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($existing) {
                    return $existing;
                }

                return Shift::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'opening_cash' => max(0, $this->opening_cash),
                    'status' => 'open',
                    'opened_at' => now(),
                    'opened_by' => $user->id,
                    'notes' => trim($this->shift_notes) ?: null,
                ]);
            });

            $this->activeShiftId = $shift->id;
            $this->showOpenShiftModal = false;
            $this->errorMessage = null;
            $this->successMessage = 'تم فتح الشيفت بنجاح. رقم الشيفت: #' . $shift->id;
        } catch (\Throwable $e) {
            Log::error('POS shift opening failed', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            $this->errorMessage = 'تعذر فتح الشيفت. يرجى المحاولة مرة أخرى.';
        }
    }

    public function prepareCloseShift(): void
    {
        $shift = $this->activeShift();

        if (!$shift) {
            $this->checkActiveShift();
            $shift = $this->activeShift();
        }

        if (!$shift) {
            $this->errorMessage = 'لا يوجد شيفت مفتوح لهذا المستخدم في الفرع الحالي.';
            $this->showOpenShiftModal = true;
            return;
        }

        $tenantId = $this->tenantId();

        $this->shift_opening_cash = (float) $shift->opening_cash;
        $this->shift_total_sales = (float) Order::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->where('type', 'pos')
            ->where('status', 'completed')
            ->sum('total');

        $this->shift_total_returns = abs((float) Order::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->where('type', 'return')
            ->where('status', 'completed')
            ->sum('total'));

        $this->shift_cash_receipts = (float) Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->where('type', 'receipt')
            ->where('payment_method', 'cash')
            ->sum('amount');

        $this->shift_cash_payments = (float) Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('shift_id', $shift->id)
            ->where('type', 'payment')
            ->where('payment_method', 'cash')
            ->sum('amount');

        $this->shift_expected_cash = $this->shift_opening_cash
            + $this->shift_cash_receipts
            - $this->shift_cash_payments;

        $this->actual_cash = $this->shift_expected_cash;
        $this->shift_notes = $shift->notes ?? '';
        $this->showCloseShiftModal = true;
    }

    public function closeShift(): void
    {
        $shift = $this->activeShift();

        if (!$shift) {
            $this->errorMessage = 'لا يوجد شيفت مفتوح لإغلاقه.';
            return;
        }

        try {
            DB::transaction(function () use ($shift): void {
                $lockedShift = Shift::query()
                    ->whereKey($shift->id)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedShift || $lockedShift->status !== 'open') {
                    throw new \RuntimeException('الشيفت مغلق بالفعل.');
                }

                $lockedShift->update([
                    'expected_cash' => $this->shift_expected_cash,
                    'actual_cash' => max(0, $this->actual_cash),
                    'difference' => $this->actual_cash - $this->shift_expected_cash,
                    'notes' => trim($this->shift_notes) ?: null,
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => Auth::id(),
                    'total_sales' => $this->shift_total_sales,
                    'total_returns' => $this->shift_total_returns,
                ]);
            });

            $this->activeShiftId = null;
            $this->showCloseShiftModal = false;
            $this->clearCartState(false);
            $this->successMessage = 'تم إغلاق الشيفت وتسوية الصندوق بنجاح.';
        } catch (\Throwable $e) {
            Log::error('POS shift closing failed', [
                'shift_id' => $shift?->id,
                'message' => $e->getMessage(),
            ]);

            $this->errorMessage = 'تعذر إغلاق الشيفت. قد يكون أُغلق من جلسة أخرى.';
        }
    }

    public function updatedProductSearchQuery(): void
    {
        $this->loadQuickProducts();
    }

    public function updatedInlineSearchQuery(): void
    {
        $search = trim($this->inlineSearchQuery);

        if ($search === '') {
            $this->inlineSearchResults = [];
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        if (!$tenantId || !$branchId) {
            $this->inlineSearchResults = [];
            return;
        }

        $this->inlineSearchResults = Product::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($search, $tenantId): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', function ($barcodeQuery) use ($search, $tenantId): void {
                        $barcodeQuery
                            ->where('tenant_id', $tenantId)
                            ->where('barcode', 'like', "%{$search}%");
                    });
            })
            ->with([
                'barcodes' => fn ($query) => $query->where('tenant_id', $tenantId),
                'branchProducts' => fn ($query) => $query->where('branch_id', $branchId),
            ])
            ->limit(8)
            ->get()
            ->map(function (Product $product): array {
                $branchProduct = $product->branchProducts->first();

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'barcode' => $product->barcodes->first()?->barcode,
                    'price' => (float) ($branchProduct?->retail_price ?? 0),
                    'stock' => (float) ($branchProduct?->stock_quantity ?? 0),
                ];
            })
            ->all();
    }

    public function selectInlineProduct(int $productId): void
    {
        $this->addToCart($productId);
        $this->inlineSearchQuery = '';
        $this->inlineSearchResults = [];
    }

    public function selectCategory(?int $categoryId = null): void
    {
        $this->selectedCategoryId = $categoryId;
        $this->loadQuickProducts();
    }

    public function updatedSelectedCategoryId(): void
    {
        $this->loadQuickProducts();
    }

    public function openCostModal(): void
    {
        if (empty($this->cart)) {
            $this->errorMessage = 'السلة فارغة، لا توجد تكلفة لمراجعتها.';
            return;
        }

        $this->showCostModal = true;
    }

    public function toggleReturnMode(): void
    {
        if (!empty($this->cart)) {
            $this->errorMessage = 'أنشئ فاتورة جديدة قبل تغيير وضع البيع والمرتجع.';
            return;
        }

        $this->isReturnMode = !$this->isReturnMode;
    }

    public function loadQuickProducts(): void
    {
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        if (!$tenantId || !$branchId) {
            $this->quickProducts = [];
            return;
        }

        $query = Product::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'barcodes' => fn ($q) => $q->where('tenant_id', $tenantId),
                'branchProducts' => fn ($q) => $q->where('branch_id', $branchId),
            ]);

        if ($this->selectedCategoryId) {
            $query->where('category_id', $this->selectedCategoryId);
        }

        $search = trim($this->productSearchQuery);

        if ($search !== '') {
            $query->where(function ($q) use ($search, $tenantId): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', function ($barcodeQuery) use ($search, $tenantId): void {
                        $barcodeQuery
                            ->where('tenant_id', $tenantId)
                            ->where('barcode', 'like', "%{$search}%");
                    });
            });
        }

        $this->quickProducts = $query
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->map(function (Product $product): Product {
                $branchProduct = $product->branchProducts->first();

                $product->setAttribute('retail_price', (float) ($branchProduct?->retail_price ?? 0));
                $product->setAttribute('stock_quantity', (float) ($branchProduct?->stock_quantity ?? 0));
                $product->setAttribute('barcode_value', $product->barcodes->first()?->barcode);
                $product->setAttribute('offer_quantity_value', (float) ($branchProduct?->offer_quantity ?? 0));
                $product->setAttribute(
                    'offer_price_value',
                    $branchProduct?->offer_price !== null
                        ? (float) $branchProduct->offer_price
                        : null
                );

                return $product;
            })
            ->all();
    }

    public function scanBarcode(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        $barcode = trim($this->barcode);

        if ($barcode === '') {
            return;
        }

        if (!$this->activeShift()) {
            $this->errorMessage = 'افتح الشيفت أولاً قبل البيع أو الإرجاع.';
            $this->showOpenShiftModal = true;
            $this->barcode = '';
            return;
        }

        $record = ProductBarcode::query()
            ->where('tenant_id', $this->tenantId())
            ->where('barcode', $barcode)
            ->with('product')
            ->first();

        if (!$record?->product) {
            $this->errorMessage = "لم يتم العثور على منتج بالباركود: {$barcode}";
            $this->barcode = '';
            return;
        }

        $this->addToCart((int) $record->product_id, $barcode);
        $this->barcode = '';
    }

    public function addToCart(int $productId, string $scannedBarcode = ''): void
    {
        if (!$this->activeShift()) {
            $this->errorMessage = 'افتح الشيفت أولاً لإضافة المنتجات.';
            $this->showOpenShiftModal = true;
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        if (!$tenantId || !$branchId) {
            $this->errorMessage = 'تعذر تحديد المتجر أو الفرع.';
            return;
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->with(['barcodes' => fn ($q) => $q->where('tenant_id', $tenantId)])
            ->find($productId);

        $branchProduct = BranchProduct::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->first();

        if (!$product || !$branchProduct) {
            $this->errorMessage = 'المنتج غير مرتبط بالفرع الحالي أو لم يعد متاحاً.';
            return;
        }

        $this->showProductsModal = false;

        // مهم: لا نمسح currentInvoiceId هنا.
        // إذا كانت فاتورة موجودة قيد التعديل، يجب أن يبقى الـ ID حتى يحدث UPDATE لا CREATE.
        $changeQty = $this->isReturnMode ? -1 : 1;

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity'] += $changeQty;

            if ((int) $this->cart[$productId]['quantity'] === 0) {
                unset($this->cart[$productId]);
            }
        } else {
            $this->cart[$productId] = [
                'id' => $productId,
                'name' => $product->name,
                'barcode' => $scannedBarcode ?: ($product->barcodes->first()?->barcode ?? ''),
                'price' => (float) $branchProduct->retail_price,
                'cost_price' => (float) ($product->cost_price ?? 0),
                'quantity' => $changeQty,
                'subtotal' => $this->roundMoney(
                    (float) $branchProduct->retail_price * $changeQty
                ),
            ];
        }

        if (empty($this->cart)) {
            // لا تستخدم clearCartState() هنا لأنه سيمسح currentInvoiceId.
            $this->recalculatePrices();
            return;
        }

        $this->recalculatePrices();
        $this->loadQuickProducts();
    }

    public function updateQuantity(int $productId, $qty): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $quantity = (int) $qty;

        if ($quantity === 0) {
            $this->removeFromCart($productId);
            return;
        }

        if (!$this->isReturnMode && $quantity < 0) {
            $quantity = abs($quantity);
        }

        if ($this->isReturnMode && $quantity > 0) {
            $quantity = -$quantity;
        }

        $this->cart[$productId]['quantity'] = $quantity;
        $this->recalculatePrices();
    }

    public function updateUnitPrice(int $productId, $newPrice): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $this->cart[$productId]['price'] = max(0, (float) $newPrice);
        $this->recalculatePrices();
    }

    public function updateCostPrice(int $productId, $newCost): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $this->cart[$productId]['cost_price'] = max(0, (float) $newCost);
        $this->recalculatePrices();
    }

    public function removeFromCart(int $productId): void
    {
        unset($this->cart[$productId]);

        if (empty($this->cart)) {
            // لا تمسح currentInvoiceId عند تعديل آخر سطر من الفاتورة.
            $this->recalculatePrices();
            return;
        }

        $this->recalculatePrices();
    }

    public function clearCart(): void
    {
        $this->startNewInvoice();
    }

    private function clearCartState(bool $reloadProducts = true): void
    {
        $this->cart = [];
        $this->receipt = [];
        $this->paid_amount = 0;
        $this->payment_method = 'cash';
        $this->discount_amount = 0;
        $this->discount_type = 'fixed';
        $this->custom_final_total = null;
        $this->currentInvoiceId = null;
        $this->isEditingInvoice = false;
        $this->isReturnMode = false;
        $this->notes = '';
        $this->searchInvoiceQuery = '';
        $this->barcode = '';
        $this->inlineSearchQuery = '';
        $this->inlineSearchResults = [];
        $this->showBelowCostModal = false;

        if ($reloadProducts) {
            $this->loadQuickProducts();
        }
    }

    public function holdInvoice(): void
    {
        if (empty($this->cart)) {
            $this->errorMessage = 'لا يمكن تعليق فاتورة فارغة.';
            return;
        }

        if ($this->currentInvoiceId) {
            $this->errorMessage = 'الفاتورة الحالية محفوظة بالفعل. أنشئ فاتورة جديدة قبل التعليق.';
            return;
        }

        $this->heldInvoices[] = [
            'id' => (string) str()->uuid(),
            'cart' => $this->cart,
            'paid_amount' => $this->paid_amount,
            'payment_method' => $this->payment_method,
            'discount_amount' => $this->discount_amount,
            'discount_type' => $this->discount_type,
            'custom_final_total' => $this->custom_final_total,
            'is_return_mode' => $this->isReturnMode,
            'notes' => $this->notes,
            'time' => now()->format('Y-m-d H:i:s'),
            'total' => $this->total,
        ];

        $this->saveHeldInvoices();
        $this->clearCartState();
        $this->successMessage = 'تم تعليق الفاتورة. يمكنك استرجاعها لاحقاً.';
    }

    public function restoreHeldInvoice(int $index): void
    {
        if (!isset($this->heldInvoices[$index])) {
            return;
        }

        $held = $this->heldInvoices[$index];

        $this->cart = $held['cart'];
        $this->paid_amount = (float) ($held['paid_amount'] ?? 0);
        $this->payment_method = $held['payment_method'] ?? 'cash';
        $this->discount_amount = (float) ($held['discount_amount'] ?? 0);
        $this->discount_type = $held['discount_type'] ?? 'fixed';
        $this->custom_final_total = isset($held['custom_final_total'])
            ? (float) $held['custom_final_total']
            : null;
        $this->isReturnMode = (bool) ($held['is_return_mode'] ?? false);
        $this->notes = $held['notes'] ?? '';
        $this->currentInvoiceId = null;

        unset($this->heldInvoices[$index]);
        $this->heldInvoices = array_values($this->heldInvoices);
        $this->saveHeldInvoices();

        $this->showHeldModal = false;
        $this->errorMessage = null;
        $this->successMessage = 'تم استرجاع الفاتورة المعلقة.';
        $this->recalculatePrices();
    }

    public function removeHeldInvoice(int $index): void
    {
        if (!isset($this->heldInvoices[$index])) {
            return;
        }

        unset($this->heldInvoices[$index]);
        $this->heldInvoices = array_values($this->heldInvoices);
        $this->saveHeldInvoices();
    }

    public function searchInvoice(): void
    {
        $query = trim($this->searchInvoiceQuery);
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        if ($query === '') {
            $this->errorMessage = 'اكتب رقم الفاتورة أو رقمها الداخلي للبحث.';
            return;
        }

        if (!$tenantId || !$branchId) {
            $this->errorMessage = 'تعذر تحديد المتجر أو الفرع.';
            return;
        }

        $digitsOnly = preg_replace('/\D+/', '', $query) ?: '';

        $invoice = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['pos', 'return'])
            ->where(function ($q) use ($query, $digitsOnly): void {
                $q->where('invoice_number', $query)
                    ->orWhere('invoice_number', 'like', "%{$query}%");

                if (is_numeric($query)) {
                    $q->orWhere('id', (int) $query);
                }

                if ($digitsOnly !== '') {
                    $q->orWhere('invoice_number', 'like', "%{$digitsOnly}%");
                }
            })
            ->latest('id')
            ->first();

        if (!$invoice) {
            $this->errorMessage = "لم يتم العثور على فاتورة مطابقة: {$query}";
            return;
        }

        $this->loadInvoice($invoice->id);
        $this->searchInvoiceQuery = '';
    }

    public function loadInvoice(int $invoiceId): void
    {
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        if (!$tenantId || !$branchId) {
            $this->errorMessage = 'تعذر تحديد المتجر أو الفرع.';
            return;
        }

        $invoice = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['pos', 'return'])
            ->with('items.product')
            ->find($invoiceId);

        if (!$invoice) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة المطلوبة.';
            return;
        }

        // هذا هو المفتاح الذي يحدد أن الحفظ القادم UPDATE وليس CREATE.
        $this->currentInvoiceId = $invoice->id;
        $this->isEditingInvoice = true;

        $this->cart = [];

        foreach ($invoice->items as $item) {
            $this->cart[$item->product_id] = [
                'id' => $item->product_id,
                'name' => $item->product?->name ?? 'منتج غير محدد',
                'barcode' => '',
                'price' => (float) $item->unit_price,
                'cost_price' => (float) (
                    $item->cost_price
                    ?? ($item->product?->cost_price ?? 0)
                ),
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->total_price,
            ];
        }

        $this->paid_amount = (float) $invoice->paid_amount;

        $payment = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('order_id', $invoice->id)
            ->latest('id')
            ->first();

        $this->payment_method = $payment?->payment_method ?? 'cash';

        $this->discount_amount = (float) ($invoice->discount ?? 0);
        $this->discount_type = $invoice->discount_type ?? 'fixed';
        $this->custom_final_total = null;
        $this->notes = $invoice->notes ?? '';
        $this->isReturnMode = $invoice->type === 'return';
        $this->errorMessage = null;
        $this->successMessage = "تم تحميل الفاتورة {$invoice->invoice_number}. يمكنك تعديلها ثم حفظها.";

        $this->recalculatePrices();
    }

    public function startNewInvoice(): void
    {
        $this->clearCartState();
        $this->errorMessage = null;
        $this->successMessage = 'تم فتح فاتورة جديدة.';
    }

    private function invoiceNavigationQuery()
    {
        return Order::query()
            ->where('tenant_id', $this->tenantId())
            ->where('branch_id', $this->getActiveBranchId())
            ->whereIn('type', ['pos', 'return']);
    }

    public function previousInvoice(): void
    {
        $previous = $this->invoiceNavigationQuery()
            ->when(
                $this->currentInvoiceId,
                fn ($q) => $q->where('id', '<', $this->currentInvoiceId)
            )
            ->latest('id')
            ->first();

        if ($previous) {
            $this->loadInvoice($previous->id);
            return;
        }

        $this->errorMessage = 'لا توجد فاتورة أقدم.';
    }

    public function nextInvoice(): void
    {
        if (!$this->currentInvoiceId) {
            return;
        }

        $next = $this->invoiceNavigationQuery()
            ->where('id', '>', $this->currentInvoiceId)
            ->oldest('id')
            ->first();

        if ($next) {
            $this->loadInvoice($next->id);
            return;
        }

        $this->startNewInvoice();
    }

    public function appendNumpad(string $value): void
    {
        if ($value === 'C') {
            $this->paid_amount = 0;
            return;
        }

        $current = (string) $this->paid_amount;

        if ($current === '0') {
            $current = '';
        }

        if ($value === '.' && str_contains($current, '.')) {
            return;
        }

        $this->paid_amount = (float) ($current . $value);
    }

    public function updatedDiscountAmount(): void
    {
        $this->custom_final_total = null;
    }

    public function updatedDiscountType(): void
    {
        $this->custom_final_total = null;
    }

    public function updatedCustomFinalTotal($value): void
    {
        if ($value === '' || $value === null) {
            $this->custom_final_total = null;
            return;
        }

        $target = max(0, (float) $value);

        if ($this->subtotal > 0 && $target <= $this->subtotal) {
            $this->discount_type = 'fixed';
            $this->discount_amount = $this->subtotal - $target;
        }
    }

    public function toggleDiscountType(): void
    {
        $this->discount_type = $this->discount_type === 'fixed'
            ? 'percentage'
            : 'fixed';

        $this->custom_final_total = null;
    }

    public function getSubtotalProperty(): float
    {
        return $this->roundMoney(
            array_sum(array_column($this->cart, 'subtotal'))
        );
    }

    public function getTotalCostProperty(): float
    {
        $total = 0;

        foreach ($this->cart as $item) {
            $total += (float) ($item['cost_price'] ?? 0) * (int) $item['quantity'];
        }

        return $this->roundMoney($total);
    }

    public function getExpectedProfitProperty(): float
    {
        return $this->roundMoney($this->total - $this->total_cost);
    }

    public function getCalculatedDiscountProperty(): float
    {
        if ($this->subtotal <= 0) {
            return 0;
        }

        $discount = max(0, $this->discount_amount);

        if ($this->discount_type === 'percentage') {
            return $this->roundMoney(
                ($this->subtotal * min(100, $discount)) / 100
            );
        }

        return $this->roundMoney(
            min($this->subtotal, $discount)
        );
    }

    public function getTotalProperty(): float
    {
        if (empty($this->cart)) {
            return 0;
        }

        if ($this->isReturnMode) {
            return -abs($this->subtotal);
        }

        if ($this->custom_final_total !== null) {
            return $this->roundMoney(
                max(0, min($this->subtotal, $this->custom_final_total))
            );
        }

        return $this->roundMoney(
            max(0, $this->subtotal - $this->calculated_discount)
        );
    }

    public function getAmountDueProperty(): float
    {
        return abs($this->total);
    }

    public function getChangeProperty(): float
    {
        if ($this->amountDue <= 0) {
            return 0;
        }

        return $this->roundMoney(
            max(0, $this->paid_amount - $this->amountDue)
        );
    }

    public function getRemainingProperty(): float
    {
        return $this->roundMoney(
            max(0, $this->amountDue - $this->paid_amount)
        );
    }

    public function getHasBelowCostItemProperty(): bool
    {
        foreach ($this->cart as $item) {
            if (
                (int) $item['quantity'] > 0 &&
                (float) $item['price'] < (float) ($item['cost_price'] ?? 0)
            ) {
                return true;
            }
        }

        return false;
    }

    public function checkout(): ?Order
    {
        if ($this->has_below_cost_item && !$this->showBelowCostModal) {
            $this->pendingCheckoutMode = 'checkout';
            $this->showBelowCostModal = true;
            return null;
        }

        $this->showBelowCostModal = false;
        return $this->processCheckout();
    }

    public function checkoutAndPrint(): void
    {
        if ($this->has_below_cost_item && !$this->showBelowCostModal) {
            $this->pendingCheckoutMode = 'checkoutAndPrint';
            $this->showBelowCostModal = true;
            return;
        }

        $this->showBelowCostModal = false;
        $order = $this->processCheckout();

        if ($order) {
            $this->prepareReceiptFromOrder($order);
            $this->dispatch('print-receipt');
        }
    }

    public function confirmBelowCostCheckout(): void
    {
        $mode = $this->pendingCheckoutMode;
        $this->showBelowCostModal = false;

        $order = $this->processCheckout();

        if ($order && $mode === 'checkoutAndPrint') {
            $this->prepareReceiptFromOrder($order);
            $this->dispatch('print-receipt');
        }
    }

    private function processCheckout(): ?Order
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if (empty($this->cart)) {
            $this->errorMessage = 'الفاتورة فارغة.';
            return null;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();
        $user = Auth::user();

        if (!$tenantId || !$branchId || !$user) {
            $this->errorMessage = 'تعذر تحديد المتجر أو الفرع أو المستخدم.';
            return null;
        }

        if (!in_array($this->payment_method, ['cash', 'card', 'bank_transfer', 'cheque'], true)) {
            $this->errorMessage = 'طريقة الدفع غير صالحة.';
            return null;
        }

        $invoiceType = $this->isReturnMode ? 'return' : 'pos';
        $paidInput = max(0, (float) $this->paid_amount);
        $editingInvoiceId = $this->currentInvoiceId;
        $invoiceNumber = $editingInvoiceId
            ? null
            : $this->makeInvoiceNumber($invoiceType, $tenantId);

        // الفاتورة الجديدة تحتاج شيفت مفتوح.
        // تعديل فاتورة محفوظة لا يشترط شيفتاً جديداً، بل يحافظ على شيفتها الأصلية.
        $currentShift = null;

        if (!$editingInvoiceId) {
            $currentShift = $this->activeShift();

            if (!$currentShift) {
                $this->checkActiveShift();
                $currentShift = $this->activeShift();
            }

            if (!$currentShift) {
                $this->errorMessage = 'لا يمكنك حفظ فاتورة جديدة بدون شيفت مفتوح.';
                $this->showOpenShiftModal = true;
                return null;
            }
        }

        try {
            $order = DB::transaction(function () use (
                $tenantId,
                $branchId,
                $user,
                $currentShift,
                $invoiceType,
                $paidInput,
                $editingInvoiceId,
                $invoiceNumber
            ): Order {
                $lockedShift = null;

                /*
                 * الفاتورة الجديدة تستخدم الشيفت المفتوح الحالي.
                 */
                if (!$editingInvoiceId) {
                    $lockedShift = Shift::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->whereKey($currentShift->id)
                        ->lockForUpdate()
                        ->first();

                    if (
                        !$lockedShift ||
                        $lockedShift->status !== 'open' ||
                        (int) $lockedShift->opened_by !== (int) $user->id
                    ) {
                        throw new \RuntimeException(
                            'الشيفت غير مفتوح أو لم يعد تابعاً للمستخدم الحالي.'
                        );
                    }
                }

                $order = null;
                $targetShiftId = $lockedShift?->id;

                /*
                 * ============================================================
                 * EDIT: تحميل نفس الفاتورة وقفلها
                 * ============================================================
                 */
                if ($editingInvoiceId) {
                    $order = Order::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->whereIn('type', ['pos', 'return'])
                        ->whereKey($editingInvoiceId)
                        ->lockForUpdate()
                        ->first();

                    if (!$order) {
                        throw new \RuntimeException(
                            'الفاتورة المطلوب تعديلها غير موجودة.'
                        );
                    }

                    /*
                     * لا ننقل الفاتورة إلى شيفت آخر أثناء التعديل.
                     * نحافظ على shift_id الأصلي حتى لو كان الشيفت مغلقاً.
                     */
                    $targetShiftId = $order->shift_id;

                    if ($targetShiftId) {
                        $originalShift = Shift::query()
                            ->whereKey($targetShiftId)
                            ->lockForUpdate()
                            ->first();

                        if (!$originalShift) {
                            // الشيفت لم يعد موجوداً؛ نترك shift_id كما هو في الفاتورة
                            // ولا ننشئ شيفتاً بديلاً فقط من أجل التعديل.
                            $targetShiftId = $order->shift_id;
                        }
                    }

                    /*
                     * ================================================
                     * 1) عكس تأثير الأصناف القديمة على المخزون
                     * ================================================
                     */
                    $oldItems = $order->items()
                        ->lockForUpdate()
                        ->get();

                    foreach ($oldItems as $oldItem) {
                        $oldQuantity = (int) $oldItem->quantity;

                        if ($oldQuantity === 0) {
                            continue;
                        }

                        $oldBranchProduct = BranchProduct::query()
                            ->where('tenant_id', $tenantId)
                            ->where('branch_id', $branchId)
                            ->where('product_id', $oldItem->product_id)
                            ->lockForUpdate()
                            ->first();

                        if (!$oldBranchProduct) {
                            throw new \RuntimeException(
                                "الصنف رقم {$oldItem->product_id} غير مرتبط بالفرع الحالي."
                            );
                        }

                        if ($oldQuantity > 0) {
                            // كانت عملية بيع: نعيد الكمية للمخزون.
                            $oldBranchProduct->increment(
                                'stock_quantity',
                                $oldQuantity
                            );
                        } else {
                            // كان مرتجعاً: نعكس المرتجع بسحب الكمية من المخزون.
                            $restoreQuantity = abs($oldQuantity);

                            if (
                                (float) $oldBranchProduct->stock_quantity
                                < $restoreQuantity
                            ) {
                                throw new \RuntimeException(
                                    "لا يمكن عكس المرتجع القديم للصنف رقم {$oldItem->product_id} لأن المخزون الحالي غير كافٍ."
                                );
                            }

                            $oldBranchProduct->decrement(
                                'stock_quantity',
                                $restoreQuantity
                            );
                        }
                    }

                    /*
                     * ================================================
                     * 2) حذف OrderItems القديمة
                     * ================================================
                     */
                    $order->items()->delete();

                }

                /*
                 * ============================================================
                 * تجهيز الأصناف الجديدة والتحقق منها
                 * ============================================================
                 */
                $validatedItems = [];
                $subtotal = 0.0;
                $totalCost = 0.0;

                foreach ($this->cart as $rawItem) {
                    $productId = (int) ($rawItem['id'] ?? 0);
                    $quantity = (int) ($rawItem['quantity'] ?? 0);
                    $price = max(0, (float) ($rawItem['price'] ?? 0));

                    if (!$productId || $quantity === 0) {
                        throw new \RuntimeException(
                            'يوجد صنف أو كمية غير صالحة في الفاتورة.'
                        );
                    }

                    $product = Product::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($productId)
                        ->first();

                    if (!$product) {
                        throw new \RuntimeException(
                            'أحد المنتجات لم يعد متاحاً.'
                        );
                    }

                    $branchProduct = BranchProduct::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    if (!$branchProduct) {
                        throw new \RuntimeException(
                            "المنتج {$product->name} غير مرتبط بالفرع الحالي."
                        );
                    }

                    $costPrice = max(
                        0,
                        (float) (
                            $rawItem['cost_price']
                            ?? ($product->cost_price ?? 0)
                        )
                    );

                    $lineTotal = $this->roundMoney(
                        $price * $quantity
                    );

                    if (
                        $quantity > 0 &&
                        (float) $branchProduct->stock_quantity < $quantity
                    ) {
                        throw new \RuntimeException(
                            "المخزون غير كافٍ للمنتج {$product->name}. المتوفر: {$branchProduct->stock_quantity}."
                        );
                    }

                    $subtotal += $lineTotal;
                    $totalCost += $costPrice * $quantity;

                    $validatedItems[] = [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'cost_price' => $costPrice,
                        'total_price' => $lineTotal,
                    ];
                }

                $subtotal = $this->roundMoney($subtotal);
                $totalCost = $this->roundMoney($totalCost);

                /*
                 * ============================================================
                 * الإجمالي والخصم
                 * ============================================================
                 */
                $discount = $invoiceType === 'pos'
                    ? $this->calculated_discount
                    : 0;

                $total = $invoiceType === 'return'
                    ? -abs($subtotal)
                    : $this->roundMoney(
                        max(0, $subtotal - $discount)
                    );

                if (
                    $invoiceType === 'pos' &&
                    $this->custom_final_total !== null
                ) {
                    $customTotal = max(
                        0,
                        min(
                            $subtotal,
                            (float) $this->custom_final_total
                        )
                    );

                    $total = $this->roundMoney($customTotal);
                    $discount = $this->roundMoney(
                        $subtotal - $customTotal
                    );
                }

                /*
                 * ============================================================
                 * الدفع
                 * ============================================================
                 */
                $requiredPayment = abs($total);

                // في الفاتورة الجديدة: 0 تعني دفع كامل كما هو السلوك الحالي.
                // في التعديل: 0 تعني فعلاً صفر، حتى يستطيع المستخدم تحويل الفاتورة إلى آجلة.
                $paid = $editingInvoiceId
                    ? min($requiredPayment, $paidInput)
                    : min(
                        $requiredPayment,
                        $paidInput > 0 ? $paidInput : $requiredPayment
                    );

                $paymentStatus =
                    $requiredPayment <= 0 || $paid >= $requiredPayment
                        ? 'paid'
                        : ($paid > 0 ? 'partial' : 'unpaid');

                /*
                 * ============================================================
                 * UPDATE أو CREATE
                 * ============================================================
                 */
                if ($order) {
                    /*
                     * UPDATE نفس السجل.
                     * لا invoice_number جديد.
                     * لا Order جديد.
                     */
                    $order->update([
                        'branch_id' => $branchId,
                        'shift_id' => $targetShiftId,
                        'type' => $invoiceType,
                        'status' => 'completed',
                        'subtotal' => $subtotal,
                        'tax_amount' => 0,
                        'discount_type' => $this->discount_type,
                        'discount_rate' => $this->discount_type === 'percentage'
                            ? min(
                                100,
                                max(0, (float) $this->discount_amount)
                            )
                            : 0,
                        'discount' => $discount,
                        'total' => $total,
                        'total_cost' => $totalCost,
                        'total_profit' => $this->roundMoney(
                            $total - $totalCost
                        ),
                        'paid_amount' => $paid,
                        'payment_status' => $paymentStatus,
                        'notes' => trim($this->notes) ?: null,
                    ]);
                } else {
                    /*
                     * CREATE فاتورة جديدة فقط إذا لم يكن هناك currentInvoiceId.
                     */
                    $order = Order::query()->create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'shift_id' => $lockedShift->id,
                        'created_by' => $user->id,
                        'customer_id' => null,
                        'invoice_number' => $invoiceNumber,
                        'type' => $invoiceType,
                        'status' => 'completed',
                        'subtotal' => $subtotal,
                        'tax_amount' => 0,
                        'discount_type' => $this->discount_type,
                        'discount_rate' => $this->discount_type === 'percentage'
                            ? min(
                                100,
                                max(0, (float) $this->discount_amount)
                            )
                            : 0,
                        'discount' => $discount,
                        'total' => $total,
                        'total_cost' => $totalCost,
                        'total_profit' => $this->roundMoney(
                            $total - $totalCost
                        ),
                        'paid_amount' => $paid,
                        'payment_status' => $paymentStatus,
                        'notes' => trim($this->notes) ?: null,
                    ]);
                }

                /*
                 * ============================================================
                 * OrderItems الجديدة + المخزون
                 * ============================================================
                 */
                foreach ($validatedItems as $item) {
                    OrderItem::create([
                        'tenant_id' => $tenantId,
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['total_price'],
                        'cost_price' => $item['cost_price'],
                        'discount' => 0,
                        'total_cost' => $this->roundMoney(
                            $item['cost_price'] * $item['quantity']
                        ),
                    ]);

                    $branchProductQuery = BranchProduct::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->where('product_id', $item['product_id']);

                    if ($item['quantity'] > 0) {
                        $branchProductQuery->decrement(
                            'stock_quantity',
                            $item['quantity']
                        );
                    } else {
                        $branchProductQuery->increment(
                            'stock_quantity',
                            abs($item['quantity'])
                        );
                    }
                }

                /*
                 * ============================================================
                 * Payment — إنشاء أو تحديث نفس السجل
                 * ============================================================
                 * لا نحذف Payment عند التعديل، لأن payments.voucher_number
                 * مقيّد بـ UNIQUE، وقد يكون الحذف Soft Delete فيبقى القيد.
                 */
                $voucherNumber = 'PAY-' . $order->invoice_number;

                $paymentQuery = Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('order_id', $order->id)
                    ->latest('id');

                $existingPayment = $paymentQuery->lockForUpdate()->first();

                // إذا كان السجل القديم Soft Deleted، نبحث عنه أيضاً ثم نستعيده.
                $usesSoftDeletes = in_array(
                    \Illuminate\Database\Eloquent\SoftDeletes::class,
                    class_uses_recursive(Payment::class),
                    true
                );

                if (!$existingPayment && $usesSoftDeletes) {
                    $existingPayment = Payment::withTrashed()
                        ->where('tenant_id', $tenantId)
                        ->where('order_id', $order->id)
                        ->latest('id')
                        ->lockForUpdate()
                        ->first();
                }

                if ($paid > 0) {
                    if ($existingPayment) {
                        if (
                            method_exists($existingPayment, 'trashed') &&
                            $existingPayment->trashed() &&
                            method_exists($existingPayment, 'restore')
                        ) {
                            $existingPayment->restore();
                        }

                        $existingPayment->update([
                            'tenant_id' => $tenantId,
                            'branch_id' => $branchId,
                            'shift_id' => $targetShiftId,
                            'type' => $invoiceType === 'return'
                                ? 'payment'
                                : 'receipt',
                            'voucher_number' => $voucherNumber,
                            'amount' => $paid,
                            'payment_method' => $this->payment_method,
                            'order_id' => $order->id,
                            'notes' => trim($this->notes) ?: null,
                            'payment_date' => now(),
                        ]);
                    } else {
                        Payment::create([
                            'tenant_id' => $tenantId,
                            'branch_id' => $branchId,
                            'shift_id' => $targetShiftId,
                            'created_by' => $user->id,
                            'type' => $invoiceType === 'return'
                                ? 'payment'
                                : 'receipt',
                            'voucher_number' => $voucherNumber,
                            'amount' => $paid,
                            'payment_method' => $this->payment_method,
                            'order_id' => $order->id,
                            'notes' => trim($this->notes) ?: null,
                            'payment_date' => now(),
                        ]);
                    }
                } elseif ($existingPayment) {
                    // نحافظ على السجل ونحوّل قيمته إلى صفر بدلاً من حذفه.
                    $existingPayment->update([
                        'branch_id' => $branchId,
                        'shift_id' => $targetShiftId,
                        'type' => $invoiceType === 'return'
                            ? 'payment'
                            : 'receipt',
                        'voucher_number' => $voucherNumber,
                        'amount' => 0,
                        'payment_method' => $this->payment_method,
                        'order_id' => $order->id,
                        'notes' => trim($this->notes) ?: null,
                        'payment_date' => now(),
                    ]);
                }

                return $order->fresh([
                    'items.product',
                ]);
            });

            $wasEditing = (bool) $editingInvoiceId;

            $this->clearCartState();
            $this->checkActiveShift();

            $this->isEditingInvoice = false;

            $this->successMessage = $wasEditing
                ? "تم تحديث الفاتورة {$order->invoice_number} بنجاح وتحديث المخزون."
                : (
                    $invoiceType === 'return'
                        ? "تم حفظ المرتجع {$order->invoice_number} وتحديث المخزون."
                        : "تم حفظ الفاتورة {$order->invoice_number} وتحديث المخزون."
                );

            return $order;
        } catch (\Throwable $e) {
            Log::error('POS checkout failed', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'shift_id' => $shift?->id,
                'editing_invoice_id' => $editingInvoiceId,
                'message' => $e->getMessage(),
            ]);

            $this->errorMessage = app()->environment('local')
                ? 'تعذر حفظ الفاتورة: ' . $e->getMessage()
                : 'تعذر حفظ الفاتورة. يرجى المحاولة مرة أخرى.';

            return null;
        }
    }

    private function makeInvoiceNumber(string $type, int $tenantId): string
    {
        $prefix = $type === 'return' ? 'RET-' : 'POS-';

        do {
            $number = $prefix
                . now()->format('YmdHis')
                . '-'
                . random_int(1000, 9999);
        } while (
            Order::query()
                ->where('tenant_id', $tenantId)
                ->where('invoice_number', $number)
                ->exists()
        );

        return $number;
    }

    private function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }

    private function recalculatePrices(): void
    {
        foreach ($this->cart as $id => $item) {
            $this->cart[$id]['subtotal'] = $this->roundMoney(
                (float) $item['quantity'] * (float) $item['price']
            );
        }
    }

    private function prepareReceiptFromOrder(Order $order): void
    {
        $order->loadMissing('items.product', 'user', 'branch');

        $items = [];
        $totalQty = 0;

        foreach ($order->items as $index => $item) {
            $totalQty += abs((int) $item->quantity);

            $items[] = [
                'id' => $index + 1,
                'name' => $item->product?->name ?? 'منتج غير محدد',
                'qty' => $item->quantity,
                'price' => number_format((float) $item->unit_price, 2),
                'total' => number_format((float) $item->total_price, 2),
            ];
        }

        $createdAt = $order->created_at ?: now();

        $this->receipt = [
            'store_name' => $order->branch?->name ?? 'نقطة البيع',
            'copy_type' => $order->type === 'return' ? 'فاتورة مرتجع' : 'فاتورة بيع',
            'invoice_no' => (string) $order->invoice_number,
            'date' => $createdAt->format('Y/m/d'),
            'time' => $createdAt->format('h:i A'),
            'cashier' => $order->user?->name ?? 'الكاشير',
            'items' => $items,
            'total_qty' => $totalQty,
            'subtotal' => number_format((float) $order->subtotal, 2),
            'discount' => number_format((float) $order->discount, 2),
            'total_amount' => number_format(abs((float) $order->total), 2),
            'paid' => number_format((float) $order->paid_amount, 2),
            'change' => number_format(
                max(
                    0,
                    (float) $order->paid_amount - abs((float) $order->total)
                ),
                2
            ),
            'currency' => 'ش.ض',
            'notice' => 'شكراً لتعاملكم معنا',
        ];
    }

    public function printReceipt(): void
    {
        if ($this->currentInvoiceId) {
            $order = Order::query()
                ->where('tenant_id', $this->tenantId())
                ->where('branch_id', $this->getActiveBranchId())
                ->whereKey($this->currentInvoiceId)
                ->with('items.product', 'user', 'branch')
                ->first();

            if ($order) {
                $this->prepareReceiptFromOrder($order);
                $this->dispatch('print-receipt');
                return;
            }
        }

        if (empty($this->receipt)) {
            $this->errorMessage = 'لا توجد فاتورة جاهزة للطباعة.';
            return;
        }

        $this->dispatch('print-receipt');
    }

    public function getInvoiceCreatorProperty(): string
    {
        if ($this->currentInvoiceId) {
            return Order::query()
                ->where('tenant_id', $this->tenantId())
                ->where('branch_id', $this->getActiveBranchId())
                ->with('user')
                ->find($this->currentInvoiceId)?->user?->name
                ?? 'غير محدد';
        }

        return Auth::user()?->name ?? 'الكاشير الحالي';
    }

    public function getInvoiceDateProperty(): string
    {
        if ($this->currentInvoiceId) {
            $date = Order::query()
                ->where('tenant_id', $this->tenantId())
                ->where('branch_id', $this->getActiveBranchId())
                ->find($this->currentInvoiceId)?->created_at;

            if ($date) {
                return $date->locale('ar')->isoFormat('dddd، YYYY-MM-DD - h:mm A');
            }
        }

        return now()->locale('ar')->isoFormat('dddd، YYYY-MM-DD');
    }

    public function render()
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();
        $branches = [];

        if ($tenantId && !$user?->branch_id) {
            $branches = Branch::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get();
        }

        return $this->view([
            'branches' => $branches,
        ])->layout('layouts::pos');
    }
};
?>

<flux:main dir="rtl" class="h-[calc(100vh-4rem)] overflow-hidden bg-slate-100 font-sans select-none">
    <div
        x-data="posKeyboard()"
        x-cloak
        x-on:keydown.window.escape="closePopups()"
        x-on:keydown.window="handleGlobalKeydown($event)"
        class="flex h-full min-h-0 flex-col gap-2 p-2 md:p-3"
    >
        @include('pages.tenant.pos.partials.toolbar')

        @if ($isEditingInvoice && $currentInvoiceId)
            <div class="flex shrink-0 items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-black text-amber-900 shadow-sm">
                <div class="flex items-center gap-2">
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-100">✎</span>
                    <span>أنت الآن تعدّل الفاتورة المحفوظة رقم #{{ $currentInvoiceId }}. عند الحفظ سيتم تحديث نفس الفاتورة وليس إنشاء فاتورة جديدة.</span>
                </div>
                <button type="button" wire:click="startNewInvoice" class="shrink-0 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-[11px] font-black text-amber-800 hover:bg-amber-100">فاتورة جديدة</button>
            </div>
        @endif

        @if ($errorMessage || $successMessage)
            <div class="grid shrink-0 gap-2 md:grid-cols-2">
                @if ($errorMessage)
                    <div class="flex items-center justify-between rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-800 shadow-sm">
                        <span>⚠ {{ $errorMessage }}</span>
                        <button wire:click="$set('errorMessage', null)" class="mr-2 text-rose-500">✕</button>
                    </div>
                @endif

                @if ($successMessage)
                    <div class="flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800 shadow-sm">
                        <span>✓ {{ $successMessage }}</span>
                        <button wire:click="$set('successMessage', null)" class="mr-2 text-emerald-500">✕</button>
                    </div>
                @endif
            </div>
        @endif

        @if ($this->has_below_cost_item)
            <div class="flex shrink-0 items-center justify-between rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900 shadow-sm">
                <span>⚠ توجد أصناف بسعر بيع أقل من التكلفة.</span>
                <button wire:click="openCostModal" class="underline">عرض التفاصيل</button>
            </div>
        @endif

        <div class="grid min-h-0 flex-1 grid-cols-1 gap-2 lg:grid-cols-12">
            <section class="min-h-0 lg:col-span-8">
                @include('pages.tenant.pos.partials.cart')
            </section>

            <aside class="flex min-h-0 flex-col gap-2 overflow-y-auto lg:col-span-4">
                @include('pages.tenant.pos.partials.payment')
                @include('pages.tenant.pos.partials.shift-summary')
            </aside>
        </div>

        @include('pages.tenant.pos.partials.products-modal')
        @include('pages.tenant.pos.partials.shift-open-modal')
        @include('pages.tenant.pos.partials.shift-close-modal')
        @include('pages.tenant.pos.partials.held-invoices-modal')
        @include('pages.tenant.pos.partials.cost-modal')
        @include('pages.tenant.pos.partials.below-cost-modal')
    </div>

    @include('pages.tenant.pos.partials.thermal-receipt')
</flux:main>

<script>
    function posKeyboard() {
        return {
            closePopups() {
                this.$wire.set('showProductsModal', false);
                this.$wire.set('showHeldModal', false);
                this.$wire.set('showCostModal', false);
                this.$wire.set('showBelowCostModal', false);
            },

            focusProductSearch() {
                const input = document.querySelector('input[wire\:model="barcode"]');
                if (input) {
                    input.focus({ preventScroll: true });
                    input.select?.();
                }
            },

            async deleteFocusedItem() {
                const input = document.activeElement?.closest?.('[data-pos-field]');
                if (!input) return;

                const productId = Number(input.dataset.productId);
                if (!productId) return;

                const field = input.dataset.posField;
                const row = Number(input.dataset.rowIndex);

                await this.$wire.removeFromCart(productId);

                requestAnimationFrame(() => {
                    const candidates = Array.from(document.querySelectorAll('[data-pos-field]'))
                        .filter(el => el.dataset.posField === field)
                        .sort((a,b) => Number(a.dataset.rowIndex) - Number(b.dataset.rowIndex));

                    const target = candidates.find(el => Number(el.dataset.rowIndex) >= row)
                        || candidates[candidates.length - 1];

                    if (target) {
                        target.focus({ preventScroll: true });
                        target.select?.();
                    } else {
                        this.focusProductSearch();
                    }
                });
            },

            async handleGlobalKeydown(event) {
                const key = event.key;
                if (!['F1','F2','F3','F4','F6','F7','F8','F9','F10'].includes(key)) return;

                event.preventDefault();
                event.stopPropagation();

                switch (key) {
                    case 'F1':
                        this.$wire.set('showHeldModal', !this.$wire.showHeldModal);
                        break;
                    case 'F2':
                        this.$wire.startNewInvoice();
                        break;
                    case 'F3':
                        this.$wire.holdInvoice();
                        break;
                    case 'F4':
                        this.$wire.startNewInvoice();
                        break;
                    case 'F6':
                        this.$wire.checkout();
                        break;
                    case 'F7':
                        this.$wire.checkoutAndPrint();
                        break;
                    case 'F8':
                        await this.deleteFocusedItem();
                        break;
                    case 'F9':
                        this.focusProductSearch();
                        break;
                    case 'F10':
                        this.$wire.set('showProductsModal', true);
                        break;
                }
            }
        };
    }
</script>

<style>
    [x-cloak] {
        display: none !important;
    }

    body,
    input,
    button,
    select,
    textarea {
        font-family: Tahoma, Arial, sans-serif;
    }

    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button {
        opacity: .45;
    }

    @media (max-width: 1023px) {
        flux-main {
            overflow-y: auto !important;
        }
    }

    @media print {
        body * {
            visibility: hidden !important;
        }

        #thermal-receipt,
        #thermal-receipt * {
            visibility: visible !important;
        }

        #thermal-receipt {
            display: block !important;
            position: absolute !important;
            right: 0 !important;
            top: 0 !important;
            width: 80mm !important;
            margin: 0 !important;
            padding: 2mm !important;
        }

        @page {
            size: 80mm auto;
            margin: 0;
        }
    }
</style>

<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('print-receipt', () => {
            const receiptElement = document.getElementById('thermal-receipt');
            const printFrame = document.getElementById('silent-print-frame');

            if (!receiptElement || !printFrame) return;

            const frameDoc = printFrame.contentWindow.document;
            frameDoc.open();
            frameDoc.write(`
                <html dir="rtl">
                    <head>
                        <title>فاتورة</title>
                        <style>
                            body{font-family:Tahoma,Arial,sans-serif;width:80mm;margin:0;padding:2mm;font-size:10px;color:#000;direction:rtl}
                            .center{text-align:center}.bold{font-weight:700}.black{font-weight:900}.between{display:flex;justify-content:space-between}
                            .line{border-top:1px solid #000}.dash{border-top:1px dashed #000}.small{font-size:9px}.tiny{font-size:8px}
                            table{width:100%;border-collapse:collapse;text-align:right}th,td{padding:2px 0}
                            @page{size:80mm auto;margin:0}
                        </style>
                    </head>
                    <body>${receiptElement.innerHTML}</body>
                </html>
            `);
            frameDoc.close();

            setTimeout(() => {
                printFrame.contentWindow.focus();
                printFrame.contentWindow.print();
            }, 120);
        });
    });
</script>
