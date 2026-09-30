<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Party;
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
    public $paid_amount = 0;
    public string $payment_method = 'cash';
    public string $notes = '';
    public bool $showCustomerModal = false;
    public string $customerSearch = '';
    public ?int $selectedCustomerId = null;
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
    public bool $allowNegativeStock = false;
    public bool $mergeSimilarProducts = true;


    public function mount(): void
    {
        $this->allowNegativeStock = (bool) config('app.allow_negative_stock', env('ALLOW_NEGATIVE_STOCK', false));
        $user = Auth::user();
        $this->selectedBranchId = $user?->branch_id ?: session('active_branch_id');
        $tenantId = $this->tenantId();

        if ($tenantId) {
            $this->categories = Category::query()->where('tenant_id', $tenantId)->orderBy('name')->get()->toArray();
        }

        $this->loadHeldInvoices();
        $this->checkActiveShift();
        $this->loadQuickProducts();
    }
    public function updateCartField(string $lineKey, string $field, $value): void
    {
        if (!isset($this->cart[$lineKey])) {
            return;
        }

        // توحيد الفاصلة العشرية حتى يعمل التعديل المتكرر بنفس الطريقة
        // سواء أدخل المستخدم 12.50 أو 12,50.
        $value = str_replace(',', '.', trim((string) $value));

        switch ($field) {
            case 'quantity':
                $this->updateQuantity($lineKey, $value);
                break;

            case 'price':
                $this->updateUnitPrice($lineKey, $value);
                break;

            case 'subtotal':
                $this->updateLineTotal($lineKey, $value);
                break;

            case 'cost_price':
                $this->updateCostPrice($lineKey, $value);
                break;

            default:
                return;
        }
    }

    public function toggleMergeSimilarProducts(): void
    {
        $this->mergeSimilarProducts = !$this->mergeSimilarProducts;

        if (empty($this->cart)) {
            return;
        }

        if ($this->mergeSimilarProducts) {
            // تشغيل التجميع: اجمع أيضاً كل الأسطر الموجودة حالياً.
            $this->mergeSimilarCartLines();
        } else {
            // إيقاف التجميع: فك كل الكميات الموجودة حالياً
            // بحيث تصبح كل وحدة في سطر مستقل.
            $this->splitSimilarCartLines();
        }

        $this->recalculatePrices();
    }

    private function splitSimilarCartLines(): void
    {
        $split = [];

        foreach ($this->cart as $item) {
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($quantity === 0) {
                continue;
            }

            $unitCount = abs($quantity);
            $unitQuantity = $quantity < 0 ? -1 : 1;
            $unitPrice = (float) ($item['price'] ?? 0);

            for ($i = 0; $i < $unitCount; $i++) {
                $lineKey = 'line_' . str()->uuid()->toString();

                $newItem = $item;
                $newItem['quantity'] = $unitQuantity;
                $newItem['price'] = $unitPrice;
                $newItem['subtotal'] = $this->roundMoney($unitPrice * $unitQuantity);

                $split[$lineKey] = $newItem;
            }
        }

        $this->cart = $split;
    }

    private function mergeSimilarCartLines(): void
    {
        $merged = [];

        foreach ($this->cart as $item) {
            $productId = (int) ($item['id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if (!$productId || $quantity === 0) {
                continue;
            }

            // لا نخلط سطر بيع مع سطر مرتجع لنفس المنتج.
            $groupKey = $productId . ':' . ($quantity < 0 ? 'return' : 'sale');

            if (!isset($merged[$groupKey])) {
                $merged[$groupKey] = $item;
                continue;
            }

            $oldQuantity = (int) $merged[$groupKey]['quantity'];
            $oldSubtotal = (float) ($merged[$groupKey]['subtotal'] ?? 0);
            $newSubtotal = (float) ($item['subtotal'] ?? 0);

            $merged[$groupKey]['quantity'] = $oldQuantity + $quantity;
            $merged[$groupKey]['subtotal'] = $this->roundMoney($oldSubtotal + $newSubtotal);

            $totalQuantity = (int) $merged[$groupKey]['quantity'];
            if ($totalQuantity !== 0) {
                $merged[$groupKey]['price'] = $this->roundMoney(
                    abs((float) $merged[$groupKey]['subtotal'] / $totalQuantity)
                );
            }
        }

        $this->cart = [];
        foreach ($merged as $groupKey => $item) {
            $this->cart[(string) $groupKey] = $item;
        }
    }

    protected function tenantId(): ?int
    {
        return session('active_tenant_id') ?? Auth::user()?->tenant_id;
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

        return Branch::query()->where('tenant_id', $tenantId)->whereKey((int) $branchId)->exists() ? (int) $branchId : null;
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

        $shift = Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('opened_by', $userId)->where('status', 'open')->latest('id')->first();

        if (!$shift) {
            return null;
        }

        /*
    |--------------------------------------------------------------------------
    | المبيعات
    |--------------------------------------------------------------------------
    | نحسبها مباشرة من الفواتير الخاصة بهذا الشيفت.
    */
        $sales = (float) Order::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('shift_id', $shift->id)->where('type', 'pos')->sum('total');

        /*
    |--------------------------------------------------------------------------
    | المرتجعات
    |--------------------------------------------------------------------------
    | فواتير المرتجع غالباً تكون قيمتها سالبة،
    | لذلك نعرضها كمبلغ موجب.
    */
        $returns = abs((float) Order::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('shift_id', $shift->id)->where('type', 'return')->sum('total'));

        /*
    |--------------------------------------------------------------------------
    | المقبوض النقدي
    |--------------------------------------------------------------------------
    */
        $cashSales = (float) Payment::query()->where('tenant_id', $tenantId)->where('shift_id', $shift->id)->where('type', 'receipt')->where('payment_method', 'cash')->sum('amount');

        /*
    |--------------------------------------------------------------------------
    | المدفوع النقدي للمرتجعات
    |--------------------------------------------------------------------------
    */
        $cashReturns = (float) Payment::query()->where('tenant_id', $tenantId)->where('shift_id', $shift->id)->where('type', 'payment')->where('payment_method', 'cash')->sum('amount');

        /*
    |--------------------------------------------------------------------------
    | الكاش المتوقع
    |--------------------------------------------------------------------------
    */
        $expectedCash = (float) $shift->opening_cash + $cashSales - $cashReturns;

        /*
    |--------------------------------------------------------------------------
    | نضع القيم على نسخة الشيفت الموجودة في الذاكرة
    | بدون UPDATE على قاعدة البيانات.
    |--------------------------------------------------------------------------
    */
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

        if ($branchId && $tenantId && Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->exists()) {
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
        $this->activeShiftId = $this->activeShift()?->id;
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
                $existing = Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('opened_by', $user->id)->where('status', 'open')->lockForUpdate()->latest('id')->first();

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
        $this->shift_total_sales = (float) Order::query()->where('tenant_id', $tenantId)->where('shift_id', $shift->id)->where('type', 'pos')->where('status', 'completed')->sum('total');
        $this->shift_total_returns = abs((float) Order::query()->where('tenant_id', $tenantId)->where('shift_id', $shift->id)->where('type', 'return')->where('status', 'completed')->sum('total'));
        $this->shift_cash_receipts = (float) Payment::query()->where('tenant_id', $tenantId)->where('shift_id', $shift->id)->where('type', 'receipt')->where('payment_method', 'cash')->sum('amount');
        $this->shift_cash_payments = (float) Payment::query()->where('tenant_id', $tenantId)->where('shift_id', $shift->id)->where('type', 'payment')->where('payment_method', 'cash')->sum('amount');
        $this->shift_expected_cash = $this->shift_opening_cash + $this->shift_cash_receipts - $this->shift_cash_payments;
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
                $lockedShift = Shift::query()->whereKey($shift->id)->lockForUpdate()->first();

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
                'shift_id' => $shift->id,
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

        // البحث بالكلمات المفتاحية: يجب أن يحتوي الصنف على كل الكلمات المكتوبة.
        // مثال: "اكياس 5" يبحث عن الصنف حتى لو لم تكن الكلمات متجاورة.
        $keywords = preg_split('/\\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);

        $this->inlineSearchResults = Product::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($keywords, $tenantId): void {
                foreach ($keywords as $keyword) {
                    $query->where(function ($keywordQuery) use ($keyword, $tenantId): void {
                        $keywordQuery
                            ->where('name', 'like', "%{$keyword}%")
                            ->orWhereHas('barcodes', function ($barcodeQuery) use ($keyword, $tenantId): void {
                                $barcodeQuery
                                    ->where('tenant_id', $tenantId)
                                    ->where('barcode', 'like', "%{$keyword}%");
                            });
                    });
                }
            })
            ->with([
                'barcodes' => fn($query) => $query->where('tenant_id', $tenantId),
                'branchProducts' => fn($query) => $query->where('branch_id', $branchId),
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
                'barcodes' => fn($q) => $q->where('tenant_id', $tenantId),
                'branchProducts' => fn($q) => $q->where('branch_id', $branchId),
            ]);

        if ($this->selectedCategoryId) {
            $query->where('category_id', $this->selectedCategoryId);
        }

        $search = trim($this->productSearchQuery);
        if ($search !== '') {
            // البحث بالكلمات المفتاحية: كل كلمة يجب أن تطابق الاسم أو الباركود.
            $keywords = preg_split('/\\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);

            $query->where(function ($q) use ($keywords, $tenantId): void {
                foreach ($keywords as $keyword) {
                    $q->where(function ($keywordQuery) use ($keyword, $tenantId): void {
                        $keywordQuery
                            ->where('name', 'like', "%{$keyword}%")
                            ->orWhereHas('barcodes', function ($barcodeQuery) use ($keyword, $tenantId): void {
                                $barcodeQuery
                                    ->where('tenant_id', $tenantId)
                                    ->where('barcode', 'like', "%{$keyword}%");
                            });
                    });
                }
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
                $product->setAttribute('offer_price_value', $branchProduct?->offer_price !== null ? (float) $branchProduct->offer_price : null);
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

        $record = ProductBarcode::query()->where('tenant_id', $this->tenantId())->where('barcode', $barcode)->with('product')->first();

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
            ->with(['barcodes' => fn($q) => $q->where('tenant_id', $tenantId)])
            ->find($productId);
        $branchProduct = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('product_id', $productId)->first();

        if (!$product || !$branchProduct) {
            $this->errorMessage = 'المنتج غير مرتبط بالفرع الحالي أو لم يعد متاحاً.';
            return;
        }

        $this->showProductsModal = false;
        $this->currentInvoiceId = null;
        $changeQty = $this->isReturnMode ? -1 : 1;

        $existingLineKey = null;

        if ($this->mergeSimilarProducts) {
            foreach ($this->cart as $lineKey => $item) {
                if (
                    (int) ($item['id'] ?? 0) === $productId &&
                    (((int) ($item['quantity'] ?? 0) < 0) === ($changeQty < 0))
                ) {
                    $existingLineKey = (string) $lineKey;
                    break;
                }
            }
        }

        if ($existingLineKey !== null) {
            $this->cart[$existingLineKey]['quantity'] += $changeQty;

            if ((int) $this->cart[$existingLineKey]['quantity'] === 0) {
                unset($this->cart[$existingLineKey]);
            }
        } else {
            $lineKey = $this->mergeSimilarProducts
                ? (string) $productId
                : 'line_' . str()->uuid()->toString();

            $this->cart[$lineKey] = [
                'id' => $productId,
                'name' => $product->name,
                'barcode' => $scannedBarcode ?: $product->barcodes->first()?->barcode ?? '',
                'price' => (float) $branchProduct->retail_price,
                'cost_price' => (float) ($product->cost_price ?? 0),
                'quantity' => $changeQty,
                'subtotal' => $this->roundMoney((float) $branchProduct->retail_price * $changeQty),
            ];
        }

        if (empty($this->cart)) {
            $this->clearCartState();
            return;
        }

        $this->recalculatePrices();
        $this->loadQuickProducts();
    }

    public function updateQuantity(string $lineKey, $qty): void
    {
        if (!isset($this->cart[$lineKey])) {
            return;
        }

        $quantity = (int) $qty;
        if ($quantity === 0) {
            $this->removeFromCart($lineKey);
            return;
        }

        if (!$this->isReturnMode && $quantity < 0) {
            $quantity = abs($quantity);
        }

        if ($this->isReturnMode && $quantity > 0) {
            $quantity = -$quantity;
        }

        $this->cart[$lineKey]['quantity'] = $quantity;
        $this->currentInvoiceId = null;
        $this->recalculatePrices();
    }

    public function updateUnitPrice(string $lineKey, $newPrice): void
    {
        if (!isset($this->cart[$lineKey])) {
            return;
        }

        $this->cart[$lineKey]['price'] = max(0, (float) $newPrice);
        $this->cart[$lineKey]['subtotal'] = $this->roundMoney(
            (float) $this->cart[$lineKey]['quantity'] * (float) $this->cart[$lineKey]['price']
        );
        $this->currentInvoiceId = null;
        $this->recalculatePrices();
    }

    /**
     * تعديل إجمالي الصنف مباشرة.
     * يتم تحويل الإجمالي الجديد إلى سعر وحدة مع الحفاظ على إشارة المرتجع.
     */
    public function updateLineTotal(string $lineKey, $newTotal): void
    {
        if (!isset($this->cart[$lineKey])) {
            return;
        }

        $quantity = (int) ($this->cart[$lineKey]['quantity'] ?? 0);

        if ($quantity === 0) {
            return;
        }

        $targetTotal = (float) $newTotal;

        // في وضع المرتجع يبقى إجمالي الصنف سالباً.
        if ($quantity < 0) {
            $targetTotal = -abs($targetTotal);
        } else {
            $targetTotal = max(0, $targetTotal);
        }

        $this->cart[$lineKey]['price'] = $this->roundMoney(abs($targetTotal / $quantity));
        $this->cart[$lineKey]['subtotal'] = $this->roundMoney($targetTotal);
        $this->currentInvoiceId = null;
    }

    public function updateCostPrice(string $lineKey, $newCost): void
    {
        if (!isset($this->cart[$lineKey])) {
            return;
        }

        $this->cart[$lineKey]['cost_price'] = max(0, (float) $newCost);
        $this->currentInvoiceId = null;
        $this->recalculatePrices();
    }

    public function removeFromCart(string $lineKey): void
    {
        unset($this->cart[$lineKey]);
        $this->currentInvoiceId = null;

        if (empty($this->cart)) {
            $this->clearCartState();
            return;
        }

        $this->recalculatePrices();
    }

    public function clearCart(): void
    {
        $this->clearCartState();
        $this->errorMessage = null;
        $this->successMessage = 'تم تجهيز فاتورة جديدة.';
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
        $this->isReturnMode = false;
        $this->notes = '';
        $this->selectedCustomerId = null;
        $this->customerSearch = '';
        $this->showCustomerModal = false;
        $this->searchInvoiceQuery = '';
        $this->barcode = '';
        $this->inlineSearchQuery = '';
        $this->inlineSearchResults = [];
        $this->showBelowCostModal = false;

        // مهم: تنظيف القيم المحسوبة المخزنة مؤقتاً في Livewire
        unset($this->subtotal, $this->total_cost, $this->expected_profit, $this->calculated_discount, $this->total, $this->amountDue, $this->change, $this->remaining, $this->hasBelowCostItem);
    }

    public function holdInvoice(): void
    {
        if (empty($this->cart)) {
            $this->errorMessage = 'لا يمكن تعليق فاتورة فارغة.';
            return;
        }

        if ($this->currentInvoiceId) {
            $this->errorMessage = 'الفاتورة المعروضة محفوظة بالفعل. أنشئ فاتورة جديدة قبل التعليق.';
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
            'selected_customer_id' => $this->selectedCustomerId,
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
        $this->custom_final_total = isset($held['custom_final_total']) ? (float) $held['custom_final_total'] : null;
        $this->isReturnMode = (bool) ($held['is_return_mode'] ?? false);
        $this->notes = $held['notes'] ?? '';
        $this->selectedCustomerId = isset($held['selected_customer_id']) ? (int) $held['selected_customer_id'] : null;
        $this->customerSearch = '';
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
                $q->where('invoice_number', $query)->orWhere('invoice_number', 'like', "%{$query}%");

                if (is_numeric($query)) {
                    $q->orWhereKey((int) $query);
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
        $invoice = Order::query()
            ->where('tenant_id', $this->tenantId())
            ->where('branch_id', $this->getActiveBranchId())
            ->whereIn('type', ['pos', 'return'])
            ->with('items.product')
            ->find($invoiceId);

        if (!$invoice) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة المطلوبة.';
            return;
        }

        $this->currentInvoiceId = $invoice->id;
        $this->cart = [];

        foreach ($invoice->items as $item) {
            $lineKey = $this->mergeSimilarProducts
                ? (string) $item->product_id
                : 'invoice_' . $item->id;

            if (
                $this->mergeSimilarProducts &&
                isset($this->cart[$lineKey])
            ) {
                $this->cart[$lineKey]['quantity'] += (int) $item->quantity;
                $this->cart[$lineKey]['subtotal'] = $this->roundMoney(
                    (float) $this->cart[$lineKey]['subtotal'] + (float) $item->total_price
                );

                $quantity = (int) $this->cart[$lineKey]['quantity'];
                if ($quantity !== 0) {
                    $this->cart[$lineKey]['price'] = $this->roundMoney(
                        abs((float) $this->cart[$lineKey]['subtotal'] / $quantity)
                    );
                }

                continue;
            }

            $this->cart[$lineKey] = [
                'id' => $item->product_id,
                'name' => $item->product?->name ?? 'منتج غير محدد',
                'barcode' => '',
                'price' => (float) $item->unit_price,
                'cost_price' => (float) ($item->cost_price ?? ($item->product?->cost_price ?? 0)),
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->total_price,
            ];
        }

        $this->paid_amount = (float) $invoice->paid_amount;
        $this->payment_method = 'cash';
        $this->discount_amount = (float) ($invoice->discount ?? 0);
        $this->discount_type = $invoice->discount_type ?? 'fixed';
        $this->custom_final_total = null;
        $this->notes = $invoice->notes ?? '';
        $this->selectedCustomerId = $invoice->customer_id ? (int) $invoice->customer_id : null;
        $this->customerSearch = '';
        $this->isReturnMode = $invoice->type === 'return';
        $this->errorMessage = null;
        $this->successMessage = "تم عرض الفاتورة {$invoice->invoice_number} للقراءة فقط.";
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
        $previous = $this->invoiceNavigationQuery()->when($this->currentInvoiceId, fn($q) => $q->where('id', '<', $this->currentInvoiceId))->latest('id')->first();

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

        $next = $this->invoiceNavigationQuery()->where('id', '>', $this->currentInvoiceId)->oldest('id')->first();

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

    public function updatedCustomerSearch(): void
    {
        // النتائج تُقرأ من computed property customerResults.
    }

    public function getCustomerResultsProperty()
    {
        $search = trim($this->customerSearch);
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return collect();
        }

        return Party::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_active', true)
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(30)
            ->get();
    }

    public function selectCustomer(int $customerId): void
    {
        $tenantId = $this->tenantId();

        $customer = Party::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('type', ['customer', 'both'])
            ->where('is_active', true)
            ->find($customerId);

        if (!$customer) {
            $this->errorMessage = 'العميل المحدد غير صالح.';
            return;
        }

        $this->selectedCustomerId = (int) $customer->id;
        $this->customerSearch = '';
        $this->showCustomerModal = false;
        $this->errorMessage = null;
    }

    public function clearCustomer(): void
    {
        $this->selectedCustomerId = null;
        $this->customerSearch = '';
        $this->showCustomerModal = false;
    }

    public function openCustomerModal(): void
    {
        $this->customerSearch = '';
        $this->showCustomerModal = true;
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
        $this->discount_type = $this->discount_type === 'fixed' ? 'percentage' : 'fixed';
        $this->custom_final_total = null;
    }

    public function getSubtotalProperty(): float
    {
        return $this->roundMoney(array_sum(array_column($this->cart, 'subtotal')));
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
            return $this->roundMoney(($this->subtotal * min(100, $discount)) / 100);
        }

        return $this->roundMoney(min($this->subtotal, $discount));
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
            return $this->roundMoney(max(0, min($this->subtotal, $this->custom_final_total)));
        }

        return $this->roundMoney(max(0, $this->subtotal - $this->calculated_discount));
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

        $paidAmount = (float) ($this->paid_amount ?: 0);

        return $this->roundMoney(
            max(0, $paidAmount - $this->amountDue)
        );
    }

    public function getRemainingProperty(): float
    {
        $paidAmount = (float) ($this->paid_amount ?: 0);

        return $this->roundMoney(
            max(0, $this->amountDue - $paidAmount)
        );
    }

    public function getHasBelowCostItemProperty(): bool
    {
        foreach ($this->cart as $item) {
            if ((int) $item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0)) {
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

        if ($this->currentInvoiceId) {
            $this->errorMessage = 'هذه فاتورة محفوظة للعرض فقط. اضغط «فاتورة جديدة» قبل الحفظ.';
            return null;
        }

        $shift = $this->activeShift();
        if (!$shift) {
            $this->checkActiveShift();
            $shift = $this->activeShift();
        }

        if (!$shift) {
            $this->errorMessage = 'لا يمكنك الحفظ بدون شيفت مفتوح.';
            $this->showOpenShiftModal = true;
            return null;
        }

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
        $paidInput = max(0, (float) ($this->paid_amount ?: 0));
        $invoiceNumber = $this->makeInvoiceNumber($invoiceType, $tenantId);

        $customer = null;
        if ($this->selectedCustomerId) {
            $customer = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'both'])
                ->where('is_active', true)
                ->find($this->selectedCustomerId);

            if (!$customer) {
                $this->errorMessage = 'العميل المحدد غير موجود أو غير نشط.';
                return null;
            }
        }

        try {
            $order = DB::transaction(function () use ($tenantId, $branchId, $user, $shift, $invoiceType, $invoiceNumber, $paidInput, $customer): Order {
                $lockedShift = Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->whereKey($shift->id)->lockForUpdate()->first();

                if (!$lockedShift || $lockedShift->status !== 'open' || (int) $lockedShift->opened_by !== (int) $user->id) {
                    throw new \RuntimeException('الشيفت غير مفتوح أو لم يعد تابعاً للمستخدم الحالي.');
                }

                $validatedItems = [];
                $subtotal = 0;
                $totalCost = 0;

                foreach ($this->cart as $rawItem) {
                    $productId = (int) ($rawItem['id'] ?? 0);
                    $quantity = (int) ($rawItem['quantity'] ?? 0);
                    $price = max(0, (float) ($rawItem['price'] ?? 0));

                    if (!$productId || $quantity === 0) {
                        throw new \RuntimeException('يوجد صنف أو كمية غير صالحة في الفاتورة.');
                    }

                    $product = Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->first();

                    if (!$product) {
                        throw new \RuntimeException('أحد المنتجات لم يعد متاحاً.');
                    }

                    $branchProduct = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('product_id', $productId)->lockForUpdate()->first();

                    if (!$branchProduct) {
                        throw new \RuntimeException("المنتج {$product->name} غير مرتبط بالفرع الحالي.");
                    }

                    $costPrice = max(0, (float) ($rawItem['cost_price'] ?? ($product->cost_price ?? 0)));

                    // الإجمالي دائماً ناتج عن السعر × الكمية.
                    // عند تعديل الإجمالي يدوياً يتم تحويله أولاً إلى سعر وحدة.
                    $lineTotal = $this->roundMoney($price * $quantity);

                    if ($quantity < 0) {
                        $lineTotal = -abs($lineTotal);
                    } else {
                        $lineTotal = max(0, $lineTotal);
                    }

                    if (!$this->allowNegativeStock && $quantity > 0 && (float) $branchProduct->stock_quantity < $quantity) {
                        throw new \RuntimeException("المخزون غير كافٍ للمنتج {$product->name}. المتوفر: {$branchProduct->stock_quantity}.");
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
                $discount = $invoiceType === 'pos' ? $this->calculated_discount : 0;
                $total = $invoiceType === 'return' ? -abs($subtotal) : $this->roundMoney(max(0, $subtotal - $discount));

                if ($invoiceType === 'pos' && $this->custom_final_total !== null) {
                    $customTotal = max(0, min($subtotal, (float) $this->custom_final_total));
                    $total = $this->roundMoney($customTotal);
                    $discount = $this->roundMoney($subtotal - $customTotal);
                }

                $requiredPayment = abs($total);
                $paid = min($requiredPayment, $paidInput > 0 ? $paidInput : $requiredPayment);
                $paymentStatus = $requiredPayment <= 0 || $paid >= $requiredPayment ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

                $order = Order::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'shift_id' => $lockedShift->id,
                    'created_by' => $user->id,
                    'customer_id' => $customer?->id,
                    'customer_name' => $customer?->name ?? 'زبون عام',
                    'customer_phone' => $customer?->phone,
                    'customer_address' => $customer?->address,
                    'invoice_number' => $invoiceNumber,
                    'type' => $invoiceType,
                    'status' => 'completed',
                    'subtotal' => $subtotal,
                    'tax_amount' => 0,
                    'discount_type' => $this->discount_type,
                    'discount_rate' => $this->discount_type === 'percentage' ? min(100, max(0, $this->discount_amount)) : 0,
                    'discount' => $discount,
                    'total' => $total,
                    'total_cost' => $totalCost,
                    'total_profit' => $this->roundMoney($total - $totalCost),
                    'paid_amount' => $paid,
                    'payment_status' => $paymentStatus,
                    'notes' => trim($this->notes) ?: null,
                ]);

                foreach ($validatedItems as $item) {
                    OrderItem::create([
                        'tenant_id' => $tenantId,
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'cost_price' => $item['cost_price'],
                        'total_price' => $item['total_price'],
                    ]);

                    $branchProductQuery = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('product_id', $item['product_id']);

                    if ($item['quantity'] > 0) {
                        $branchProductQuery->decrement('stock_quantity', $item['quantity']);
                    } else {
                        $branchProductQuery->increment('stock_quantity', abs($item['quantity']));
                    }
                }

                if ($paid > 0) {
                    Payment::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'shift_id' => $lockedShift->id,
                        'created_by' => $user->id,
                        'type' => $invoiceType === 'return' ? 'payment' : 'receipt',
                        'voucher_number' => 'PAY-' . $order->invoice_number,
                        'amount' => $paid,
                        'payment_method' => $this->payment_method,
                        'order_id' => $order->id,
                        'notes' => trim($this->notes) ?: null,
                        'payment_date' => now(),
                    ]);
                }

                return $order;
            });

            $this->clearCartState();
            $this->activeShiftId = $shift->id;
            $this->successMessage = $invoiceType === 'return' ? "تم حفظ المرتجع {$order->invoice_number} وتحديث المخزون." : "تم حفظ الفاتورة {$order->invoice_number} وتحديث المخزون.";

            return $order;
        } catch (\Throwable $e) {
            Log::error('POS checkout failed', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'shift_id' => $shift->id,
                'message' => $e->getMessage(),
            ]);

            $this->errorMessage = app()->environment('local') ? 'تعذر حفظ الفاتورة: ' . $e->getMessage() : 'تعذر حفظ الفاتورة. يرجى المحاولة مرة أخرى.';

            return null;
        }
    }

    private function makeInvoiceNumber(string $type, int $tenantId): string
    {
        $prefix = $type === 'return' ? 'RET-' : 'POS-';

        do {
            $number = $prefix . now()->format('YmdHis') . '-' . random_int(1000, 9999);
        } while (Order::query()->where('tenant_id', $tenantId)->where('invoice_number', $number)->exists());

        return $number;
    }

    private function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }

    private function recalculatePrices(): void
    {
        foreach ($this->cart as $id => $item) {
            $this->cart[$id]['subtotal'] = $this->roundMoney((float) ($item['quantity'] ?? 0) * (float) ($item['price'] ?? 0));
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
            'notes' => trim((string) ($order->notes ?? '')),
            'items' => $items,
            'total_qty' => $totalQty,
            'subtotal' => number_format((float) $order->subtotal, 2),
            'discount' => number_format((float) $order->discount, 2),
            'total_amount' => number_format(abs((float) $order->total), 2),
            'paid' => number_format((float) $order->paid_amount, 2),
            'change' => number_format(max(0, (float) $order->paid_amount - abs((float) $order->total)), 2),
            'currency' => 'ش.ض',
            'notice' => 'شكراً لتعاملكم معنا',
        ];
    }

    public function printReceipt(): void
    {
        if ($this->currentInvoiceId) {
            $order = Order::query()->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())->whereKey($this->currentInvoiceId)->with('items.product', 'user', 'branch')->first();

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
            return Order::query()->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())->with('user')->find($this->currentInvoiceId)?->user?->name ?? 'غير محدد';
        }

        return Auth::user()?->name ?? 'الكاشير الحالي';
    }

    public function getInvoiceDateProperty(): string
    {
        if ($this->currentInvoiceId) {
            $date = Order::query()->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())->find($this->currentInvoiceId)?->created_at;

            if ($date) {
                return $date->locale('ar')->isoFormat('dddd، YYYY-MM-DD - h:mm A');
            }
        }

        return now()->locale('ar')->isoFormat('dddd، YYYY-MM-DD');
    }
    public function downloadInvoicePdf(): mixed
{
    $orderId = $this->currentInvoiceId;

    if (!$orderId) {
        $this->errorMessage = 'يجب حفظ الفاتورة أولاً قبل إنشاء PDF.';
        return null;
    }

    $tenantId = $this->tenantId();
    $branchId = $this->getActiveBranchId();

    if (!$tenantId || !$branchId) {
        $this->errorMessage = 'لم يتم تحديد المتجر أو الفرع.';
        return null;
    }

    $order = Order::query()
        ->where('tenant_id', $tenantId)
        ->where('branch_id', $branchId)
        ->whereKey($orderId)
        ->with([
            'items.product',
            'user',
            'branch',
        ])
        ->first();

    if (!$order) {
        $this->errorMessage = 'لم يتم العثور على الفاتورة.';
        return null;
    }

    return response()->streamDownload(
        function () use ($order) {

            $pdf = app('dompdf.wrapper');

            $pdf->loadView(
                'pages.tenant.pos.partials.invoice-pdf',
                [
                    'order' => $order,
                ]
            );

            echo $pdf->output();
        },
        'invoice-' . $order->invoice_number . '.pdf'
    );
}

    public function render()
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();
        $branches = [];

        if ($tenantId && !$user?->branch_id) {
            $branches = Branch::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
        }

        return $this->view(['branches' => $branches])->layout('layouts::pos');
    }
};
?>
<div>
    <div dir="rtl" class="h-[calc(100vh-4rem)] overflow-hidden bg-slate-100 font-sans select-none">

        <div x-data x-cloak
            x-on:keydown.window.escape="$wire.set('showHeldModal', false); $wire.set('showCostModal', false);"
            x-on:keydown.window.f1.prevent="$wire.set('showHeldModal', !$wire.showHeldModal)"
            x-on:keydown.window.f2.prevent="$wire.holdInvoice()" x-on:keydown.window.f3.prevent="$wire.checkout()"
            x-on:keydown.window.f4.prevent="$wire.clearCart()" x-on:keydown.window.f6.prevent="$wire.checkoutAndPrint()"
            x-on:keydown.window.f10.prevent="$nextTick(() => $el.querySelector('[data-pos-product-panel-search]')?.focus())"
            class="flex h-full min-h-0 flex-col gap-1.5">
            @include('pages.tenant.pos.partials.toolbar')

            @if ($errorMessage || $successMessage)
                <div class="grid shrink-0 gap-2 md:grid-cols-2">
                    @if ($errorMessage)
                        <div
                            class="flex items-center justify-between rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-800 shadow-sm">
                            <span>⚠ {{ $errorMessage }}</span>
                            <button wire:click="$set('errorMessage', null)" class="mr-2 text-rose-500">✕</button>
                        </div>
                    @endif
                    @if ($successMessage)
                        <div
                            class="flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800 shadow-sm">
                            <span>✓ {{ $successMessage }}</span>
                            <button wire:click="$set('successMessage', null)" class="mr-2 text-emerald-500">✕</button>
                        </div>
                    @endif
                </div>
            @endif

            @if ($this->has_below_cost_item)
                <div
                    class="flex shrink-0 items-center justify-between rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-900 shadow-sm">
                    <span>⚠ توجد أصناف بسعر بيع أقل من التكلفة.</span>
                    <button wire:click="openCostModal" class="underline">عرض التفاصيل</button>
                </div>
            @endif

            {{-- الفاتورة بعرض الشاشة بالكامل --}}
            <div class="min-h-0 flex-1">
                <section class="h-full min-h-0">
                    <div class="grid h-full min-h-0 grid-cols-1 gap-2 lg:grid-cols-[minmax(0,1fr)_minmax(320px,32%)]" dir="ltr">
                        @include('pages.tenant.pos.partials.cart')


                    {{-- =========================================================
                         المنتجات والتصنيفات داخل شاشة الـPOS
                    ========================================================== --}}
                    <aside class="min-h-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" dir="rtl">
                        <div class="flex h-full min-h-0 flex-col">

                            <div class="shrink-0 border-b border-slate-200 bg-slate-50 p-2.5">
                                <div class="flex items-center justify-between gap-2">
                                    <div>
                                        <div class="text-sm font-black text-slate-800">الأصناف</div>
                                        <div class="text-[9px] font-bold text-slate-400">
                                            {{ count($quickProducts) }} صنف
                                        </div>
                                    </div>

                                    <button
                                        type="button"
                                        wire:click="loadQuickProducts"
                                        class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[10px] font-black text-slate-600 hover:bg-slate-100"
                                    >
                                        ↻ تحديث
                                    </button>
                                </div>

                                <input
                                    data-pos-product-panel-search
                                    wire:model.live.debounce.250ms="productSearchQuery"
                                    type="text"
                                    autocomplete="off"
                                    placeholder="ابحث عن الصنف أو الباركود..."
                                    class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-800 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                                >
                            </div>

                            <div class="shrink-0 border-b border-slate-200 bg-white p-2">
                                <div class="flex gap-1.5 overflow-x-auto pb-1" style="scrollbar-width: thin;">
                                    <button
                                        type="button"
                                        wire:click="selectCategory(null)"
                                        wire:key="pos-category-all"
                                        class="shrink-0 rounded-lg border px-3 py-2 text-[10px] font-black transition {{ !$selectedCategoryId ? 'border-indigo-500 bg-indigo-600 text-white shadow-sm' : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-indigo-300 hover:bg-indigo-50' }}"
                                    >
                                        الكل
                                    </button>

                                    @foreach ($categories as $category)
                                        @php
                                            $categoryId = (int) ($category['id'] ?? $category->id);
                                            $categoryName = $category['name'] ?? $category->name;
                                        @endphp

                                        <button
                                            type="button"
                                            wire:click="selectCategory({{ $categoryId }})"
                                            wire:key="pos-category-{{ $categoryId }}"
                                            class="shrink-0 rounded-lg border px-3 py-2 text-[10px] font-black transition {{ (int) $selectedCategoryId === $categoryId ? 'border-indigo-500 bg-indigo-600 text-white shadow-sm' : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-indigo-300 hover:bg-indigo-50' }}"
                                        >
                                            {{ $categoryName }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>

                            <div class="min-h-0 flex-1 overflow-y-auto bg-slate-100 p-2">
                                <div class="grid grid-cols-2 gap-2 xl:grid-cols-3">
                                    @forelse ($quickProducts as $product)
                                        <button
                                            type="button"
                                            wire:click="selectInlineProduct({{ $product->id }})"
                                            wire:key="pos-quick-product-{{ $product->id }}"
                                            class="min-h-[78px] rounded-xl border border-slate-200 bg-white p-2 text-right shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-400 hover:shadow-md active:scale-[0.98]"
                                        >
                                            <div class="line-clamp-2 min-h-[30px] text-[11px] font-black leading-4 text-slate-800">
                                                {{ $product->name }}
                                            </div>

                                            <div class="mt-2 flex items-end justify-between gap-1">
                                                <span class="font-mono text-sm font-black text-indigo-700">
                                                    {{ number_format((float) $product->retail_price, 2) }}
                                                </span>

                                                <span class="rounded-md px-1.5 py-0.5 text-[8px] font-black {{ (float) $product->stock_quantity > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                                    {{ number_format((float) $product->stock_quantity, 0) }}
                                                </span>
                                            </div>
                                        </button>
                                    @empty
                                        <div class="col-span-full flex min-h-[220px] items-center justify-center text-center">
                                            <div>
                                                <div class="text-3xl opacity-30">📦</div>
                                                <div class="mt-2 text-xs font-black text-slate-400">لا توجد أصناف</div>
                                                @if ($selectedCategoryId || trim($productSearchQuery) !== '')
                                                    <button
                                                        type="button"
                                                        wire:click="selectCategory(null)"
                                                        class="mt-2 rounded-lg bg-indigo-50 px-3 py-1.5 text-[9px] font-black text-indigo-700"
                                                    >
                                                        عرض الكل
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    @endforelse
                                </div>
                            </div>

                        </div>
                    </aside>

                    </div>


                    <style>
                        input[type="number"]::-webkit-inner-spin-button,
                        input[type="number"]::-webkit-outer-spin-button {
                            -webkit-appearance: none;
                            margin: 0;
                        }

                        input[type="number"] {
                            -moz-appearance: textfield;
                            appearance: none;
                        }
                    </style>


                    <script>
                        (function() {

                            /*
                            |--------------------------------------------------------------------------
                            | Prevent duplicate initialization
                            |--------------------------------------------------------------------------
                            */
                            if (window.__posCartKeyboardNavigation) {
                                return;
                            }

                            window.__posCartKeyboardNavigation = true;


                            /*
                            |--------------------------------------------------------------------------
                            | Selectors
                            |--------------------------------------------------------------------------
                            */
                            const FIELD_SELECTOR = '[data-pos-field]';
                            const BARCODE_SELECTOR = '[data-pos-barcode-input]';


                            /*
                            |--------------------------------------------------------------------------
                            | Cart rows
                            |--------------------------------------------------------------------------
                            */
                            function rows() {

                                const grouped = new Map();


                                document.querySelectorAll(FIELD_SELECTOR).forEach(function(field) {

                                    const row = Number(field.dataset.rowIndex);

                                    if (!Number.isFinite(row)) {
                                        return;
                                    }


                                    if (!grouped.has(row)) {
                                        grouped.set(row, {});
                                    }


                                    grouped.get(row)[field.dataset.posField] = field;

                                });


                                return Array
                                    .from(grouped.entries())
                                    .sort(function(a, b) {
                                        return a[0] - b[0];
                                    })
                                    .map(function(entry) {

                                        return {
                                            index: entry[0],
                                            quantity: entry[1].quantity || null,
                                            price: entry[1].price || null,
                                            subtotal: entry[1].subtotal || null
                                        };

                                    });

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Focus input
                            |--------------------------------------------------------------------------
                            */
                            function focusInput(input) {

                                if (!input || !document.contains(input)) {
                                    return;
                                }


                                input.focus({
                                    preventScroll: true
                                });


                                if (typeof input.select === 'function') {
                                    input.select();
                                }


                                input.scrollIntoView({
                                    behavior: 'auto',
                                    block: 'nearest',
                                    inline: 'nearest'
                                });

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Focus barcode
                            |--------------------------------------------------------------------------
                            */
                            function focusBarcode(selectText = false) {

                                const input =
                                    document.querySelector(BARCODE_SELECTOR);


                                if (!input) {
                                    return;
                                }


                                input.focus({
                                    preventScroll: true
                                });


                                if (
                                    selectText &&
                                    typeof input.select === 'function'
                                ) {
                                    input.select();
                                }

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Get Livewire component
                            |--------------------------------------------------------------------------
                            */
                            function getLivewireComponent() {

                                const barcodeInput =
                                    document.querySelector(BARCODE_SELECTOR);


                                if (!barcodeInput) {
                                    return null;
                                }


                                const root =
                                    barcodeInput.closest('[wire\\:id]');


                                if (!root) {
                                    return null;
                                }


                                const componentId =
                                    root.getAttribute('wire:id');


                                if (!componentId) {
                                    return null;
                                }


                                if (
                                    window.Livewire &&
                                    typeof window.Livewire.find === 'function'
                                ) {

                                    return window.Livewire.find(componentId);

                                }


                                return null;
                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Keyboard navigation
                            |
                            | Quantity <-> Price
                            |
                            | ↑ ↓ = rows
                            | ← → = quantity / price
                            | Enter = next row
                            | Home = first
                            | End = last
                            |--------------------------------------------------------------------------
                            */
                            document.addEventListener('keydown', function(event) {

                                const current =
                                    event.target?.closest?.(FIELD_SELECTOR);

                                const key = event.key;


                                /*
                                |--------------------------------------------------------------------------
                                | ArrowUp outside the cart
                                |--------------------------------------------------------------------------
                                */
                                if (!current && key === 'ArrowUp') {

                                    const allRows = rows();


                                    if (!allRows.length) {
                                        return;
                                    }


                                    event.preventDefault();


                                    focusInput(
                                        allRows[allRows.length - 1].quantity ||
                                        allRows[allRows.length - 1].price
                                    );


                                    return;
                                }


                                /*
                                |--------------------------------------------------------------------------
                                | Ignore unrelated keys
                                |--------------------------------------------------------------------------
                                */
                                if (
                                    !current ||
                                    ![
                                        'ArrowUp',
                                        'ArrowDown',
                                        'ArrowLeft',
                                        'ArrowRight',
                                        'Enter',
                                        'Home',
                                        'End'
                                    ].includes(key)
                                ) {
                                    return;
                                }


                                const allRows = rows();


                                if (!allRows.length) {
                                    return;
                                }


                                const rowIndex =
                                    Number(current.dataset.rowIndex);

                                const type =
                                    current.dataset.posField;


                                const pos =
                                    allRows.findIndex(function(row) {
                                        return row.index === rowIndex;
                                    });


                                if (pos < 0) {
                                    return;
                                }


                                let target = null;


                                /*
                                |--------------------------------------------------------------------------
                                | Left / Right
                                |--------------------------------------------------------------------------
                                */
                                if (
                                    key === 'ArrowLeft' ||
                                    key === 'ArrowRight'
                                ) {

                                    /*
                                     * الترتيب ثابت وواضح:
                                     *
                                     * → الكمية  →  السعر  →  الإجمالي  →  كمية الصنف التالي
                                     * ← الإجمالي →  السعر  →  الكمية  →  إجمالي الصنف السابق
                                     *
                                     * لا نستخدم هنا دوراناً عاماً بين الحقول حتى لا يقفز
                                     * التركيز من الكمية إلى الإجمالي بالخطأ.
                                     */

                                    if (key === 'ArrowLeft') {

                                        if (type === 'quantity') {
                                            target = allRows[pos].price;
                                        } else if (type === 'price') {
                                            target = allRows[pos].subtotal;
                                        } else if (type === 'subtotal') {
                                            const nextPos =
                                                pos >= allRows.length - 1 ?
                                                0 :
                                                pos + 1;

                                            target = allRows[nextPos].quantity;
                                        }

                                    } else {

                                        if (type === 'subtotal') {
                                            target = allRows[pos].price;
                                        } else if (type === 'price') {
                                            target = allRows[pos].quantity;
                                        } else if (type === 'quantity') {
                                            const previousPos =
                                                pos <= 0 ?
                                                allRows.length - 1 :
                                                pos - 1;

                                            target = allRows[previousPos].subtotal;
                                        }

                                    }

                                }


                                /*
                                |--------------------------------------------------------------------------
                                | Up / Down / Enter / Home / End
                                |--------------------------------------------------------------------------
                                */
                                else {

                                    let targetPos = pos;


                                    if (key === 'ArrowUp') {

                                        targetPos =
                                            pos <= 0 ?
                                            allRows.length - 1 :
                                            pos - 1;

                                    }


                                    if (key === 'Enter') {

                                        // داخل نفس الصنف: الكمية ← السعر ← الإجمالي
                                        // وبعد الإجمالي ننتقل إلى كمية الصنف التالي.
                                        const fields = ['quantity', 'price', 'subtotal'];
                                        const currentFieldIndex = fields.indexOf(type);

                                        if (currentFieldIndex >= 0 && currentFieldIndex < fields.length - 1) {
                                            target = allRows[pos][fields[currentFieldIndex + 1]];
                                        } else {
                                            targetPos =
                                                pos >= allRows.length - 1 ?
                                                0 :
                                                pos + 1;
                                        }

                                    }


                                    if (key === 'ArrowDown') {

                                        targetPos =
                                            pos >= allRows.length - 1 ?
                                            0 :
                                            pos + 1;

                                    }


                                    if (key === 'Home') {
                                        targetPos = 0;
                                    }


                                    if (key === 'End') {
                                        targetPos = allRows.length - 1;
                                    }


                                    target =
                                        allRows[targetPos][type];

                                }


                                if (target) {

                                    event.preventDefault();
                                    event.stopPropagation();

                                    // لا نرسل الحفظ يدوياً هنا.
                                    // كل حقل يستخدم wire:change، وإرسال طلب ثانٍ من keydown
                                    // كان يسبب تعارض طلبات Livewire عند تعديل الحقل أكثر من مرة.
                                    focusInput(target);

                                }

                            }, true);


                            /*
                            |--------------------------------------------------------------------------
                            | Barcode Scanner
                            |
                            | مهم جداً:
                            |
                            | إذا كان scanner يكتب داخل quantity أو price:
                            |
                            | 1. نحفظ القيمة الأصلية.
                            | 2. نسمح مؤقتاً للـ scanner بإرسال الأحرف.
                            | 3. عند التأكد أنه Scanner نمنع استمرارها.
                            | 4. عند Enter نعيد الحقل للقيمة الأصلية.
                            | 5. نرسل الباركود إلى scanBarcode().
                            |
                            |--------------------------------------------------------------------------
                            */

                            let scannerBuffer = '';
                            let scannerTimer = null;
                            let scannerStartedAt = 0;

                            let scannerTarget = null;
                            let scannerOriginalValue = '';

                            let scannerOriginalSelectionStart = null;
                            let scannerOriginalSelectionEnd = null;


                            /*
                            |--------------------------------------------------------------------------
                            | Scanner configuration
                            |--------------------------------------------------------------------------
                            */
                            const SCANNER_MAX_GAP = 70;
                            const SCANNER_MIN_LENGTH = 3;


                            /*
                            |--------------------------------------------------------------------------
                            | Is cart editable field?
                            |--------------------------------------------------------------------------
                            */
                            function isCartEditableField(target) {

                                if (!target) {
                                    return false;
                                }


                                return !!target.closest?.(FIELD_SELECTOR);

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Reset scanner
                            |--------------------------------------------------------------------------
                            */
                            function resetScannerBuffer() {

                                scannerBuffer = '';
                                scannerStartedAt = 0;

                                scannerTarget = null;
                                scannerOriginalValue = '';

                                scannerOriginalSelectionStart = null;
                                scannerOriginalSelectionEnd = null;


                                if (scannerTimer) {

                                    clearTimeout(scannerTimer);

                                    scannerTimer = null;

                                }

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Save target state
                            |--------------------------------------------------------------------------
                            */
                            function saveScannerTarget(target) {

                                scannerTarget =
                                    target?.closest?.(
                                        'input, textarea'
                                    ) || null;


                                if (!scannerTarget) {
                                    return;
                                }


                                scannerOriginalValue =
                                    scannerTarget.value ?? '';


                                try {

                                    scannerOriginalSelectionStart =
                                        scannerTarget.selectionStart;

                                    scannerOriginalSelectionEnd =
                                        scannerTarget.selectionEnd;

                                } catch (e) {

                                    scannerOriginalSelectionStart = null;
                                    scannerOriginalSelectionEnd = null;

                                }

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Restore original value
                            |--------------------------------------------------------------------------
                            */
                            function restoreScannerTarget() {

                                if (
                                    !scannerTarget ||
                                    !document.contains(scannerTarget)
                                ) {
                                    return;
                                }


                                /*
                                | Restore DOM value.
                                */
                                scannerTarget.value =
                                    scannerOriginalValue;


                                /*
                                | Restore selection.
                                */
                                try {

                                    if (
                                        scannerOriginalSelectionStart !== null &&
                                        scannerOriginalSelectionEnd !== null &&
                                        typeof scannerTarget.setSelectionRange === 'function'
                                    ) {

                                        scannerTarget.setSelectionRange(
                                            scannerOriginalSelectionStart,
                                            scannerOriginalSelectionEnd
                                        );

                                    }

                                } catch (e) {
                                    // Ignore selection errors.
                                }

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Submit scanned barcode
                            |--------------------------------------------------------------------------
                            */
                            function submitScannedBarcode(value) {

                                value =
                                    String(value || '').trim();


                                if (
                                    value.length <
                                    SCANNER_MIN_LENGTH
                                ) {
                                    return false;
                                }


                                const component =
                                    getLivewireComponent();


                                if (!component) {
                                    return false;
                                }


                                try {

                                    /*
                                    | Livewire v3
                                    */
                                    if (
                                        component.$wire &&
                                        typeof component.$wire.scanBarcode === 'function'
                                    ) {

                                        component.$wire.scanBarcode(value);

                                        return true;
                                    }


                                    /*
                                    | Fallback
                                    */
                                    if (
                                        typeof component.call === 'function'
                                    ) {

                                        component.call(
                                            'scanBarcode',
                                            value
                                        );

                                        return true;
                                    }

                                } catch (error) {

                                    console.error(
                                        'POS barcode scanner error:',
                                        error
                                    );

                                }


                                return false;

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Global scanner listener
                            |--------------------------------------------------------------------------
                            */
                            document.addEventListener('keydown', function(event) {

                                const key = event.key;
                                const target = event.target;


                                /*
                                |--------------------------------------------------------------------------
                                | ENTER
                                |--------------------------------------------------------------------------
                                |
                                | إذا كان لدينا barcode buffer:
                                | لا نسمح لـ wire:change أن يعمل.
                                |--------------------------------------------------------------------------
                                */
                                if (key === 'Enter') {

                                    if (
                                        scannerBuffer.length >=
                                        SCANNER_MIN_LENGTH
                                    ) {

                                        event.preventDefault();
                                        event.stopPropagation();


                                        const value =
                                            scannerBuffer;


                                        /*
                                        | مهم جداً:
                                        | إعادة الكمية/السعر قبل تنفيذ المسح.
                                        */
                                        restoreScannerTarget();


                                        /*
                                        | إرسال الباركود.
                                        */
                                        const submitted =
                                            submitScannedBarcode(value);


                                        /*
                                        | تنظيف.
                                        */
                                        resetScannerBuffer();


                                        if (submitted) {

                                            setTimeout(function() {

                                                focusBarcode(false);

                                            }, 80);

                                        }


                                        return;
                                    }


                                    resetScannerBuffer();

                                    return;
                                }


                                /*
                                |--------------------------------------------------------------------------
                                | Ignore navigation
                                |--------------------------------------------------------------------------
                                */
                                if (
                                    key === 'ArrowUp' ||
                                    key === 'ArrowDown' ||
                                    key === 'ArrowLeft' ||
                                    key === 'ArrowRight' ||
                                    key === 'Home' ||
                                    key === 'End' ||
                                    key === 'Tab' ||
                                    key === 'Escape'
                                ) {

                                    return;

                                }


                                /*
                                |--------------------------------------------------------------------------
                                | Ignore shortcuts
                                |--------------------------------------------------------------------------
                                */
                                if (
                                    event.ctrlKey ||
                                    event.altKey ||
                                    event.metaKey
                                ) {

                                    return;

                                }


                                /*
                                |--------------------------------------------------------------------------
                                | Cart editable fields
                                |--------------------------------------------------------------------------
                                |
                                | مهم جداً: لا نتعامل مع الكمية / السعر / الإجمالي كأنها باركود.
                                | المستخدم يجب أن يستطيع كتابة أكثر من رقم وتعديل الحقل مراراً.
                                |
                                | سابقاً كان scannerBuffer يلتقط الرقم الثاني بسرعة، ثم يمنع
                                | الإدخال ويعيد القيمة القديمة، لذلك كان يبدو أن الحقل توقف
                                | عن العمل بعد تعديل الإجمالي.
                                |--------------------------------------------------------------------------
                                */
                                if (isCartEditableField(target)) {
                                    return;
                                }


                                /*
                                |--------------------------------------------------------------------------
                                | Only printable characters
                                |--------------------------------------------------------------------------
                                */
                                if (
                                    typeof key !== 'string' ||
                                    key.length !== 1
                                ) {

                                    return;

                                }


                                const now =
                                    Date.now();


                                /*
                                |--------------------------------------------------------------------------
                                | New sequence
                                |--------------------------------------------------------------------------
                                */
                                if (
                                    scannerStartedAt === 0 ||
                                    now - scannerStartedAt >
                                    SCANNER_MAX_GAP
                                ) {

                                    scannerBuffer = '';

                                    scannerStartedAt = now;

                                    saveScannerTarget(target);

                                }


                                /*
                                |--------------------------------------------------------------------------
                                | Add character
                                |--------------------------------------------------------------------------
                                */
                                scannerBuffer += key;


                                /*
                                |--------------------------------------------------------------------------
                                | إذا كان الحقل كمية أو سعر:
                                |
                                | بعد وصول عدة أحرف بسرعة نعرف أنه Scanner.
                                |
                                | نمنع بقية الأحرف من الدخول للحقل.
                                |--------------------------------------------------------------------------
                                */
                                if (
                                    isCartEditableField(target) &&
                                    scannerBuffer.length >= 2 &&
                                    now - scannerStartedAt <= SCANNER_MAX_GAP
                                ) {

                                    event.preventDefault();
                                    event.stopPropagation();


                                    /*
                                    | أعد الحقل إلى القيمة الأصلية.
                                    */
                                    restoreScannerTarget();

                                }


                                /*
                                |--------------------------------------------------------------------------
                                | Keep scanner alive
                                |--------------------------------------------------------------------------
                                */
                                if (scannerTimer) {

                                    clearTimeout(scannerTimer);

                                }


                                scannerTimer =
                                    setTimeout(function() {

                                        /*
                                        | إذا انتهى الوقت بدون Enter،
                                        | نعتبرها كتابة عادية.
                                        */
                                        resetScannerBuffer();

                                    }, SCANNER_MAX_GAP + 30);

                            }, true);


                            /*
                            |--------------------------------------------------------------------------
                            | Initial barcode focus
                            |--------------------------------------------------------------------------
                            */
                            function initialBarcodeFocus() {

                                setTimeout(function() {

                                    const active =
                                        document.activeElement;


                                    /*
                                    | لا نأخذ التركيز إذا المستخدم بالفعل
                                    | داخل حقل آخر.
                                    */
                                    if (
                                        active &&
                                        (
                                            active.matches?.(
                                                FIELD_SELECTOR
                                            ) ||
                                            active.matches?.(
                                                'input:not([data-pos-barcode-input])'
                                            ) ||
                                            active.matches?.('textarea')
                                        )
                                    ) {

                                        return;

                                    }


                                    focusBarcode(false);

                                }, 100);

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | Initial load
                            |--------------------------------------------------------------------------
                            */
                            if (
                                document.readyState ===
                                'loading'
                            ) {

                                document.addEventListener(
                                    'DOMContentLoaded',
                                    initialBarcodeFocus, {
                                        once: true
                                    }
                                );

                            } else {

                                initialBarcodeFocus();

                            }


                            /*
                            |--------------------------------------------------------------------------
                            | F9 = Barcode focus
                            |--------------------------------------------------------------------------
                            */
                            document.addEventListener('keydown', function(event) {

                                if (event.key !== 'F9') {
                                    return;
                                }


                                event.preventDefault();

                                focusBarcode(true);

                            });


                            /*
                            |--------------------------------------------------------------------------
                            | Livewire navigated
                            |
                            | لا نخطف التركيز من quantity / price.
                            |--------------------------------------------------------------------------
                            */
                            document.addEventListener(
                                'livewire:navigated',
                                function() {

                                    setTimeout(function() {

                                        const active =
                                            document.activeElement;


                                        if (
                                            active &&
                                            (
                                                active.matches?.(
                                                    FIELD_SELECTOR
                                                ) ||
                                                active.matches?.(
                                                    'input:not([data-pos-barcode-input])'
                                                ) ||
                                                active.matches?.(
                                                    'textarea'
                                                )
                                            )
                                        ) {

                                            return;

                                        }


                                        focusBarcode(false);

                                    }, 100);

                                }
                            );


                        })();
                    </script>

                </section>
            </div>

            {{-- ملخص الدفع أسفل الفاتورة بعرض الشاشة --}}
            <div class="shrink-0">
                @include('pages.tenant.pos.partials.payment')
            </div>

            @include('pages.tenant.pos.partials.shift-open-modal')
            @include('pages.tenant.pos.partials.shift-close-modal')
            @include('pages.tenant.pos.partials.held-invoices-modal')
            @include('pages.tenant.pos.partials.cost-modal')
            @include('pages.tenant.pos.partials.below-cost-modal')
        </div>

        @include('pages.tenant.pos.partials.thermal-receipt')
    </div>
</div>
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
