<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductOffer;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shift;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use App\Models\TenantSetting;

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
    public bool $showCustomerPhoneModal = false;
    public string $customerPhoneInput = '';
    public string $inlineSearchQuery = '';
    public array $inlineSearchResults = [];
    public string $searchInvoiceQuery = '';
    public string $productSearchQuery = '';
    public ?int $selectedBranchId = null;
    public float $discount_amount = 0;
    public string $discount_type = 'fixed';
    public float $delivery_fee = 0;
    public ?float $custom_final_total = null;
    public array $categories = [];
    public ?int $selectedCategoryId = null;
    public array $quickProducts = [];
    public ?string $errorMessage = null;
    public ?string $successMessage = null;
    public ?int $currentInvoiceId = null;
    // معرف الفاتورة التي تم تحميلها للتعديل، مستقل عن عرض الفاتورة الحالي.
    public ?int $editingInvoiceId = null;
    public bool $invoiceEditMode = false;
    public array $heldInvoices = [];
    public bool $showHeldModal = false;
    public bool $showCostModal = false;
    public bool $showBelowCostModal = false;

    // نافذة الدفع التي تظهر عند حفظ فاتورة مرتبطة بزبون
    public bool $showCustomerPaymentModal = false;
    public float $customerPaymentAmount = 0;
    public string $pendingCustomerPaymentMode = 'checkout';
    public bool $customerPaymentConfirmed = false;

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
    public bool $unifiedStock = false;
    public bool $mergeSimilarProducts = true;

    public function mount(): void
    {
        $user = Auth::user();
        $this->selectedBranchId = $user?->branch_id ?: session('active_branch_id');
        $tenantId = $this->tenantId();

        if ($tenantId) {
            $this->categories = Category::query()->where('tenant_id', $tenantId)->orderBy('name')->get()->toArray();
        }
        $this->allowNegativeStock = TenantSetting::getBool(
            (int) $tenantId,
            'allow_negative_stock',
            false
        );

        $this->unifiedStock = TenantSetting::getBool(
            (int) $tenantId,
            'unified_stock',
            false
        );

        $this->loadHeldInvoices();
        $this->checkActiveShift();
        $this->loadQuickProducts();
    }
    /**
     * تحويل القيمة القادمة من واجهة السلة إلى مفتاح السطر الحقيقي داخل $cart.
     * بعض نسخ cart partial ترسل product id بدلاً من array key.
     */
    private function resolveCartLineKey(string $lineKeyOrProductId): ?string
    {
        if (array_key_exists($lineKeyOrProductId, $this->cart)) {
            return $lineKeyOrProductId;
        }

        $productId = (int) $lineKeyOrProductId;

        if ($productId <= 0) {
            return null;
        }

        foreach ($this->cart as $key => $item) {
            if ((int) ($item['id'] ?? 0) === $productId) {
                return (string) $key;
            }
        }

        return null;
    }

    public function updateCartField(string $lineKey, string $field, $value): void
    {
        $resolvedKey = $this->resolveCartLineKey($lineKey);

        if ($resolvedKey === null || !isset($this->cart[$resolvedKey])) {
            return;
        }

        $lineKey = $resolvedKey;

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

        // مهم: لا نعيد التركيز إلى الباركود بعد تعديل الحقل.
        // عند استخدام الأسهم يجب أن يبقى التركيز في مكانه للتنقل بين
        // الكمية / السعر / الإجمالي وباقي الأصناف.
        // الانتقال إلى الباركود يتم فقط عند الضغط على Enter.
    }

    public function toggleMergeSimilarProducts(): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
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
            $quantity = (float) ($item['quantity'] ?? 0);

            if ($quantity === 0) {
                continue;
            }

            $absoluteQuantity = abs($quantity);
            $sign = $quantity < 0 ? -1 : 1;
            $unitPrice = (float) ($item['price'] ?? 0);

            // عند إيقاف التجميع نقسم الوحدات الصحيحة إلى أسطر مستقلة،
            // ونُبقي الكسر (مثل 0.5 أو 1.5) دون تحويله إلى عدد صحيح.
            $wholeUnits = (int) floor($absoluteQuantity);
            $fraction = round($absoluteQuantity - $wholeUnits, 3);

            for ($i = 0; $i < $wholeUnits; $i++) {
                $lineKey = 'line_' . str()->uuid()->toString();

                $newItem = $item;
                $newItem['quantity'] = $sign;
                $newItem['price'] = $unitPrice;
                $newItem['subtotal'] = $this->roundMoney($unitPrice * $sign);

                $split[$lineKey] = $newItem;
            }

            if ($fraction > 0) {
                $lineKey = 'line_' . str()->uuid()->toString();

                $newItem = $item;
                $fractionQuantity = $fraction * $sign;
                $newItem['quantity'] = $fractionQuantity;
                $newItem['price'] = $unitPrice;
                $newItem['subtotal'] = $this->roundMoney($unitPrice * $fractionQuantity);

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
            $quantity = (float) ($item['quantity'] ?? 0);

            if (!$productId || $quantity === 0) {
                continue;
            }

            // داخل الفاتورة الواحدة وضع البيع/المرتجع موحد، لذلك مفتاح الصنف هو product_id.
            // هذا يتوافق مع cart partial الذي يرسل product id إلى دوال التعديل.
            $groupKey = (string) $productId;

            if (!isset($merged[$groupKey])) {
                $merged[$groupKey] = $item;
                continue;
            }

            $oldQuantity = (float) $merged[$groupKey]['quantity'];
            $oldSubtotal = (float) ($merged[$groupKey]['subtotal'] ?? 0);
            $newSubtotal = (float) ($item['subtotal'] ?? 0);

            $merged[$groupKey]['quantity'] = $oldQuantity + $quantity;
            $merged[$groupKey]['subtotal'] = $this->roundMoney($oldSubtotal + $newSubtotal);

            $totalQuantity = (float) $merged[$groupKey]['quantity'];
            if ($totalQuantity !== 0) {
                $merged[$groupKey]['price'] = $this->roundMoney(abs((float) $merged[$groupKey]['subtotal'] / $totalQuantity));
            }
        }

        $this->cart = [];
        foreach ($merged as $groupKey => $item) {
            $this->cart[(string) $groupKey] = $item;
        }
    }

    public function invoiceIsLocked(): bool
    {
        return $this->editingInvoiceId !== null && !$this->invoiceEditMode;
    }

    private function ensureInvoiceEditable(): bool
    {
        if (!$this->invoiceIsLocked()) {
            return true;
        }

        $this->errorMessage = 'هذه فاتورة محفوظة للعرض فقط. التعديل يحتاج «تعديل»، أما الطباعة وPDF وواتساب والنسخ فتعمل مباشرة.';
        return false;
    }

    public function editLoadedInvoice(): void
    {
        if (!$this->editingInvoiceId) {
            return;
        }

        $this->invoiceEditMode = true;
        $this->errorMessage = null;
        $this->successMessage = 'تم تفعيل تعديل الفاتورة. يمكنك الآن تغيير الأصناف والمبالغ ثم الضغط على «حفظ».';
        $this->dispatch('pos-focus-barcode');
    }

    public function saveOrEditInvoice(): ?Order
    {
        if ($this->invoiceIsLocked()) {
            $this->editLoadedInvoice();
            return null;
        }

        return $this->checkout();
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
            $this->dispatch('pos-sound', type: 'shift-open');
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
            $this->dispatch('pos-sound', type: 'shift-close');
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

        // بحث بالكلمات المفتاحية:
        // يجب أن تكون كل الكلمات موجودة داخل اسم الصنف نفسه.
        // مثال: "سطل تركي 10 لتر" لا يظهر إلا إذا احتوى اسم الصنف
        // على الكلمات الأربع كلها، بغض النظر عن ترتيبها.
        $keywords = preg_split('/\\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);

        $this->inlineSearchResults = Product::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($keywords, $search, $tenantId): void {
                $query
                    ->where(function ($nameQuery) use ($keywords): void {
                        foreach ($keywords as $keyword) {
                            $nameQuery->where('name', 'like', "%{$keyword}%");
                        }
                    })
                    ->orWhereHas('barcodes', function ($barcodeQuery) use ($search, $tenantId): void {
                        $barcodeQuery->where('tenant_id', $tenantId)->where('barcode', 'like', "%{$search}%");
                    });
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

        // بعد اختيار الصنف من البحث:
        // تفريغ البحث والعودة مباشرة إلى خانة الباركود.
        $this->inlineSearchQuery = '';
        $this->productSearchQuery = '';
        $this->inlineSearchResults = [];

        $this->dispatch('pos-focus-barcode');
    }

    public function selectCategory(?int $categoryId = null): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
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
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        // نوع الفاتورة يحدد الفاتورة كاملة، ولا يمكن تغييره بعد إضافة أول صنف.
        // جميع وظائف البيع/المرتجع الأخرى تبقى كما هي: إضافة صنف، الباركود،
        // تعديل الكمية والسعر والإجمالي، الحذف، الخصم، الدفع والطباعة.
        if (!empty($this->cart)) {
            $this->errorMessage = 'لا يمكن تغيير نوع الفاتورة بعد إضافة أصناف. أنشئ فاتورة جديدة أولاً.';
            return;
        }

        $this->isReturnMode = !$this->isReturnMode;
        $this->errorMessage = null;
        $this->successMessage = $this->isReturnMode ? 'تم تفعيل وضع المرتجع. هذه الفاتورة بالكامل مرتجع.' : 'تم تفعيل وضع البيع. هذه الفاتورة بالكامل بيع.';
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
            // بحث بالكلمات المفتاحية:
            // كل الكلمات يجب أن تكون داخل اسم الصنف نفسه.
            // والباركود يبقى مسار بحث مستقل عند إدخال باركود كامل/جزئي.
            $keywords = preg_split('/\\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);

            $query->where(function ($q) use ($keywords, $search, $tenantId): void {
                $q->where(function ($nameQuery) use ($keywords): void {
                    foreach ($keywords as $keyword) {
                        $nameQuery->where('name', 'like', "%{$keyword}%");
                    }
                })->orWhereHas('barcodes', function ($barcodeQuery) use ($search, $tenantId): void {
                    $barcodeQuery->where('tenant_id', $tenantId)->where('barcode', 'like', "%{$search}%");
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
                $product->setAttribute('offer_price_value', $branchProduct?->offer_price !== null ? (float) $branchProduct->offer_price : null);
                return $product;
            })
            ->all();
    }

    public function scanBarcode(): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
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
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
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
        $changeQty = $this->isReturnMode ? -1 : 1;

        $activeOffer = $this->getActiveProductOffer($productId, (int) $tenantId, (int) $branchId);
        $offerQuantity = $activeOffer?->offer_quantity !== null
            ? (float) $activeOffer->offer_quantity
            : (float) ($branchProduct->offer_quantity ?? 0);
        $offerPrice = $activeOffer?->offer_price !== null
            ? (float) $activeOffer->offer_price
            : ($branchProduct->offer_price !== null ? (float) $branchProduct->offer_price : null);

        $existingLineKey = null;

        if ($this->mergeSimilarProducts) {
            foreach ($this->cart as $lineKey => $item) {
                if ((int) ($item['id'] ?? 0) === $productId && (float) ($item['quantity'] ?? 0) < 0 === $changeQty < 0) {
                    $existingLineKey = (string) $lineKey;
                    break;
                }
            }
        }

        if ($existingLineKey !== null) {
            $this->cart[$existingLineKey]['quantity'] += $changeQty;

            if (abs((float) $this->cart[$existingLineKey]['quantity']) < 0.000001) {
                unset($this->cart[$existingLineKey]);
            }
        } else {
            $lineKey = $this->mergeSimilarProducts ? (string) $productId : 'line_' . str()->uuid()->toString();

            $this->cart[$lineKey] = [
                'id' => $productId,
                'name' => $product->name,
                'barcode' => $scannedBarcode ?: $product->barcodes->first()?->barcode ?? '',
                // سعر القطعة العادي يبقى ظاهرًا في خانة السعر.
                // إجمالي السطر يُحسب من العرض داخل recalculatePrices().
                'price' => (float) $branchProduct->retail_price,
                'cost_price' => (float) ($product->cost_price ?? 0),
                'quantity' => $changeQty,
                'subtotal' => $this->roundMoney((float) $branchProduct->retail_price * $changeQty),
                'price_manual' => false,
                'offer_quantity' => $offerQuantity,
                'offer_price' => $offerPrice,
            ];
        }

        if (empty($this->cart)) {
            $this->clearCartState();
            return;
        }

        $this->recalculatePrices();
        $this->loadQuickProducts();

        // النزول تلقائياً إلى آخر صنف تمت إضافته
        $this->dispatch('pos-scroll-cart-bottom');
    }

    public function updateQuantity(string $lineKey, $qty): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $resolvedKey = $this->resolveCartLineKey($lineKey);

        if ($resolvedKey === null) {
            return;
        }

        $lineKey = $resolvedKey;

        if (!isset($this->cart[$lineKey])) {
            return;
        }

        $quantity = round((float) str_replace(',', '.', (string) $qty), 3);
        if (abs($quantity) < 0.000001) {
            $this->removeFromCart($lineKey);
            return;
        }

        if (!$this->isReturnMode && $quantity < 0) {
            $quantity = abs($quantity);
        }

        if ($this->isReturnMode && $quantity > 0) {
            $quantity = -$quantity;
        }

        $this->cart[$lineKey]['quantity'] = round($quantity, 3);
        $this->cart[$lineKey]['subtotal'] = $this->roundMoney((float) $this->cart[$lineKey]['quantity'] * (float) ($this->cart[$lineKey]['price'] ?? 0));

        $this->recalculatePrices();
    }

    public function updateUnitPrice(string $lineKey, $newPrice): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $resolvedKey = $this->resolveCartLineKey($lineKey);

        if ($resolvedKey === null) {
            return;
        }

        $lineKey = $resolvedKey;

        if (!isset($this->cart[$lineKey])) {
            return;
        }

        $this->cart[$lineKey]['price'] = max(0, (float) $newPrice);
        // عندما يعدل الكاشير سعر الوحدة يدويًا، لا نعيد تطبيق العرض تلقائيًا
        // على هذا السطر حتى لا نمسح تعديل الكاشير عند تغيير الكمية.
        $this->cart[$lineKey]['price_manual'] = true;
        $this->cart[$lineKey]['subtotal'] = $this->roundMoney((float) $this->cart[$lineKey]['quantity'] * (float) $this->cart[$lineKey]['price']);
        $this->recalculatePrices();
    }

    /**
     * تعديل إجمالي الصنف مباشرة.
     *
     * مهم: لا نستدعي recalculatePrices() هنا، لأن تلك الدالة تعيد
     * حساب subtotal من (quantity × price) وقد تلغي الإجمالي الذي أدخله
     * المستخدم، خصوصاً مع الكميات العشرية.
     */
    public function updateLineTotal(string $lineKey, $newTotal): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $resolvedKey = $this->resolveCartLineKey($lineKey);

        if ($resolvedKey === null || !isset($this->cart[$resolvedKey])) {
            return;
        }

        $lineKey = $resolvedKey;

        $quantity = (float) ($this->cart[$lineKey]['quantity'] ?? 0);

        if (abs($quantity) < 0.000001) {
            return;
        }

        $targetTotal = (float) str_replace(',', '.', (string) $newTotal);

        // في وضع المرتجع يبقى إجمالي السطر سالباً.
        if ($quantity < 0) {
            $targetTotal = -abs($targetTotal);
        } else {
            $targetTotal = max(0, $targetTotal);
        }

        $targetTotal = $this->roundMoney($targetTotal);

        // نحتفظ بدقة كافية في سعر الوحدة حتى لا يضيع الإجمالي
        // المدخل عند وجود كمية عشرية.
        $unitPrice = abs($targetTotal / $quantity);

        $this->cart[$lineKey]['price'] = round($unitPrice, 6);
        $this->cart[$lineKey]['price_manual'] = true;
        $this->cart[$lineKey]['subtotal'] = $targetTotal;

        // لا تستدعِ recalculatePrices() هنا، لأنه سيعيد كتابة subtotal
        // من quantity × price وقد يحول 20.02 مثلاً إلى 20.01 مع بعض الكسور.
    }

    public function updateCostPrice(string $lineKey, $newCost): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $resolvedKey = $this->resolveCartLineKey($lineKey);

        if ($resolvedKey === null) {
            return;
        }

        $lineKey = $resolvedKey;

        if (!isset($this->cart[$lineKey])) {
            return;
        }

        $this->cart[$lineKey]['cost_price'] = max(0, (float) $newCost);
        $this->recalculatePrices();
    }

    public function removeFromCart(string $lineKey): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $resolvedKey = $this->resolveCartLineKey($lineKey);

        if ($resolvedKey === null) {
            return;
        }

        $lineKey = $resolvedKey;

        unset($this->cart[$lineKey]);

        $this->dispatch('pos-sound', type: 'delete');

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
        $this->delivery_fee = 0;
        $this->custom_final_total = null;
        $this->currentInvoiceId = null;
        $this->editingInvoiceId = null;
        $this->invoiceEditMode = false;
        $this->isReturnMode = false;
        $this->notes = '';
        $this->selectedCustomerId = null;
        $this->customerSearch = '';
        $this->showCustomerModal = false;
        $this->showCustomerPhoneModal = false;
        $this->customerPhoneInput = '';
        $this->searchInvoiceQuery = '';
        $this->barcode = '';
        $this->inlineSearchQuery = '';
        $this->inlineSearchResults = [];
        $this->showBelowCostModal = false;
        $this->showCustomerPaymentModal = false;
        $this->customerPaymentAmount = 0;
        $this->pendingCustomerPaymentMode = 'checkout';
        $this->customerPaymentConfirmed = false;

        // مهم: تنظيف القيم المحسوبة المخزنة مؤقتاً في Livewire
        unset($this->subtotal, $this->total_cost, $this->expected_profit, $this->calculated_discount, $this->total, $this->amountDue, $this->change, $this->remaining, $this->hasBelowCostItem);
    }

    public function holdInvoice(): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
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
            'delivery_fee' => $this->delivery_fee,
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
        $this->delivery_fee = (float) ($held['delivery_fee'] ?? 0);
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
        $this->editingInvoiceId = $invoice->id;
        $this->invoiceEditMode = false;
        $this->cart = [];

        foreach ($invoice->items as $item) {
            $lineKey = $this->mergeSimilarProducts ? (string) $item->product_id : 'invoice_' . $item->id;

            if ($this->mergeSimilarProducts && isset($this->cart[$lineKey])) {
                $this->cart[$lineKey]['quantity'] += (float) $item->quantity;
                $this->cart[$lineKey]['subtotal'] = $this->roundMoney((float) $this->cart[$lineKey]['subtotal'] + (float) $item->total_price);

                $quantity = (float) $this->cart[$lineKey]['quantity'];
                if ($quantity !== 0) {
                    $this->cart[$lineKey]['price'] = $this->roundMoney(abs((float) $this->cart[$lineKey]['subtotal'] / $quantity));
                }

                continue;
            }

            $this->cart[$lineKey] = [
                'id' => $item->product_id,
                'name' => $item->product?->name ?? 'منتج غير محدد',
                'barcode' => '',
                'price' => (float) $item->unit_price,
                'cost_price' => (float) ($item->cost_price ?? ($item->product?->cost_price ?? 0)),
                'quantity' => (float) $item->quantity,
                'subtotal' => (float) $item->total_price,
                'price_manual' => false,
                'offer_quantity' => 0,
                'offer_price' => null,
                'normal_total' => $this->roundMoney((float) $item->quantity * (float) $item->unit_price),
                'promotion_savings' => 0,
            ];
        }

        $this->paid_amount = (float) $invoice->paid_amount;

        $this->payment_method = Payment::query()->where('tenant_id', $this->tenantId())->where('order_id', $invoice->id)->latest('id')->value('payment_method') ?: 'cash';
        $this->discount_amount = (float) ($invoice->discount ?? 0);
        $this->discount_type = $invoice->discount_type ?? 'fixed';
        $this->delivery_fee = (float) ($invoice->delivery_fee ?? 0);

        // إظهار الصافي الحالي داخل حقل الصافي عند تحميل فاتورة محفوظة.
        $this->custom_final_total = $this->roundMoney(max(0, $this->subtotal - $this->calculated_discount + $this->delivery_fee));
        $this->notes = $invoice->notes ?? '';
        $this->selectedCustomerId = $invoice->customer_id ? (int) $invoice->customer_id : null;
        $this->customerSearch = '';
        $this->isReturnMode = $invoice->type === 'return';
        $this->errorMessage = null;
        $this->successMessage = "تم تحميل الفاتورة {$invoice->invoice_number} للعرض فقط. اضغط «تعديل» للسماح بالتغيير.";
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
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
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
                    $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->limit(30)
            ->get();
    }

    public function selectCustomer(int $customerId): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
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
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $this->selectedCustomerId = null;
        $this->customerSearch = '';
        $this->showCustomerModal = false;
        $this->showCustomerPhoneModal = false;
        $this->customerPhoneInput = '';
    }

    public function openCustomerPhoneModal(): void
    {
        if (!$this->selectedCustomerId) {
            $this->errorMessage = 'يرجى اختيار الزبون أولاً.';
            return;
        }

        $customer = Party::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('type', ['customer', 'both'])
            ->where('is_active', true)
            ->find($this->selectedCustomerId);

        if (!$customer) {
            $this->errorMessage = 'الزبون المحدد غير صالح.';
            return;
        }

        $this->customerPhoneInput = (string) ($customer->phone ?? '');
        $this->showCustomerPhoneModal = true;
    }

    public function saveCustomerPhone(): void
    {
        if (!$this->selectedCustomerId) {
            $this->errorMessage = 'لم يتم تحديد زبون.';
            return;
        }

        $validated = $this->validate(
            [
                'customerPhoneInput' => ['required', 'string', 'max:40'],
            ],
            [
                'customerPhoneInput.required' => 'أدخل رقم الهاتف.',
            ],
        );

        $customer = Party::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('type', ['customer', 'both'])
            ->where('is_active', true)
            ->findOrFail($this->selectedCustomerId);

        $customer->phone = trim($validated['customerPhoneInput']);
        $customer->save();

        $this->customerPhoneInput = $customer->phone;
        $this->showCustomerPhoneModal = false;
        $this->errorMessage = null;

        // بعد حفظ الرقم، اطلب من المتصفح فتح واتساب مباشرة.
        // رقم الهاتف فقط يرسل من PHP، أما نص الفاتورة فيؤخذ من payment.blade.php.
        $whatsappPhone = preg_replace('/\D+/', '', (string) $customer->phone);

        if (str_starts_with($whatsappPhone, '00')) {
            $whatsappPhone = substr($whatsappPhone, 2);
        }

        if (str_starts_with($whatsappPhone, '0')) {
            $whatsappPhone = '972' . substr($whatsappPhone, 1);
        }

        $this->dispatch('customer-phone-saved', phone: $whatsappPhone);
        $this->dispatch('toast', type: 'success', message: 'تم حفظ رقم هاتف الزبون.');
    }

    public function openCustomerModal(): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $this->customerSearch = '';
        $this->showCustomerModal = true;
    }

    public function updatedDiscountAmount(): void
    {
        if ($this->subtotal <= 0) {
            $this->custom_final_total = null;
            return;
        }

        // الصافي النهائي دائماً يساوي الإجمالي بعد الخصم.
        // لذلك عند تعديل الخصم يتحدث الصافي مباشرة.
        $this->custom_final_total = $this->roundMoney(max(0, $this->subtotal - $this->calculated_discount + $this->delivery_fee));
    }

    public function updatedDiscountType(): void
    {
        if ($this->subtotal <= 0) {
            $this->custom_final_total = null;
            return;
        }

        // عند تغيير نوع الخصم، حافظ على الصافي الحالي المحسوب.
        $this->custom_final_total = $this->roundMoney(max(0, $this->subtotal - $this->calculated_discount + $this->delivery_fee));
    }

    public function updatedCustomFinalTotal($value): void
    {
        if ($value === '' || $value === null) {
            $this->custom_final_total = null;
            return;
        }

        if ($this->subtotal <= 0) {
            $this->custom_final_total = null;
            return;
        }

        // الصافي النهائي يشمل التوصيل، لذلك الحد الأعلى هو المنتجات بعد إضافة التوصيل.
        $maxTotal = $this->roundMoney($this->subtotal + max(0, $this->delivery_fee));
        $minTotal = $this->roundMoney(max(0, $this->delivery_fee));
        $target = max($minTotal, min($maxTotal, (float) $value));

        $target = $this->roundMoney($target);

        // تعديل الصافي يغيّر الخصم على المنتجات فقط، ولا يخصم من رسوم التوصيل.
        $this->discount_type = 'fixed';
        $this->discount_amount = $this->roundMoney(max(0, min($this->subtotal, $this->subtotal + $this->delivery_fee - $target)));

        // حافظ على القيمة التي أدخلها المستخدم.
        $this->custom_final_total = $target;
    }

    public function updatedDeliveryFee($value): void
    {
        $this->delivery_fee = $this->roundMoney(max(0, (float) $value));

        if ($this->subtotal <= 0) {
            $this->custom_final_total = $this->delivery_fee > 0 ? $this->delivery_fee : null;
            return;
        }

        $this->custom_final_total = $this->roundMoney(
            max(0, $this->subtotal - $this->calculated_discount + $this->delivery_fee)
        );
    }

    public function toggleDiscountType(): void
    {
        $this->discount_type = $this->discount_type === 'fixed' ? 'percentage' : 'fixed';

        if ($this->subtotal > 0) {
            $this->custom_final_total = $this->roundMoney(max(0, $this->subtotal - $this->calculated_discount + $this->delivery_fee));
        } else {
            $this->custom_final_total = null;
        }
    }

    public function getSubtotalProperty(): float
    {
        return $this->roundMoney(array_sum(array_column($this->cart, 'subtotal')));
    }

    /**
     * إجمالي ما وفره العميل من عروض الكمية داخل الفاتورة.
     * لا يدخل ضمن الخصم اليدوي؛ هو مؤشر توضيحي فقط.
     */
    public function getTotalPromotionSavingsProperty(): float
    {
        $total = 0;

        foreach ($this->cart as $item) {
            $total += max(0, (float) ($item['promotion_savings'] ?? 0));
        }

        return $this->roundMoney($total);
    }

    public function getTotalCostProperty(): float
    {
        $total = 0;
        foreach ($this->cart as $item) {
            $total += (float) ($item['cost_price'] ?? 0) * (float) $item['quantity'];
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
            $maxTotal = $this->roundMoney($this->subtotal + max(0, $this->delivery_fee));
            $minTotal = $this->roundMoney(max(0, $this->delivery_fee));

            return $this->roundMoney(
                max($minTotal, min($maxTotal, (float) $this->custom_final_total))
            );
        }

        // الصافي = المنتجات - الخصم + التوصيل.
        return $this->roundMoney(
            max(0, $this->subtotal - $this->calculated_discount + max(0, $this->delivery_fee))
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

        $paidAmount = (float) ($this->paid_amount ?: 0);

        return $this->roundMoney(max(0, $paidAmount - $this->amountDue));
    }

    public function getRemainingProperty(): float
    {
        $paidAmount = (float) ($this->paid_amount ?: 0);

        return $this->roundMoney(max(0, $this->amountDue - $paidAmount));
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

    /**
     * فتح نافذة الدفع قبل حفظ الفاتورة عندما يكون هناك زبون محدد.
     * المبلغ الذي يحدده المستخدم هو نفسه الذي يسجل في الفاتورة وسند القبض.
     */
    private function openCustomerPaymentModal(string $mode): void
    {
        $this->pendingCustomerPaymentMode = $mode;
        $this->customerPaymentConfirmed = false;

        $amountDue = abs((float) $this->amountDue);
        $currentPaid = max(0, (float) ($this->paid_amount ?: 0));

        $this->customerPaymentAmount = $this->roundMoney(min($amountDue, $currentPaid));

        $this->showCustomerPaymentModal = true;
    }

    public function setCustomerPaymentAmount($amount): void
    {
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $amountDue = abs((float) $this->amountDue);
        $amount = (float) str_replace(',', '.', (string) $amount);

        $this->customerPaymentAmount = $this->roundMoney(max(0, min($amountDue, $amount)));
    }

    public function confirmCustomerPayment(): ?Order
    {
        $amountDue = abs((float) $this->amountDue);
        $paid = (float) $this->customerPaymentAmount;

        if ($paid < 0 || $paid > $amountDue) {
            $this->errorMessage = 'المبلغ المدفوع يجب أن يكون بين صفر وإجمالي الفاتورة.';
            return null;
        }

        $this->paid_amount = $this->roundMoney($paid);
        $this->customerPaymentConfirmed = true;

        $mode = $this->pendingCustomerPaymentMode;
        $this->showCustomerPaymentModal = false;

        if ($this->has_below_cost_item && !$this->showBelowCostModal) {
            $this->pendingCheckoutMode = $mode;
            $this->showBelowCostModal = true;
            return null;
        }

        if ($mode === 'checkoutAndPrint') {
            $order = $this->processCheckout();

            if ($order) {
                $this->prepareReceiptFromOrder($order);
                $this->dispatch('print-receipt');
            }

            return $order;
        }

        return $this->processCheckout();
    }

    public function checkout(): ?Order
    {
        if ($this->invoiceIsLocked()) {
            $this->errorMessage = 'هذه فاتورة محفوظة للعرض فقط. اضغط «تعديل» أولاً.';
            return null;
        }
        if ($this->selectedCustomerId && !$this->customerPaymentConfirmed) {
            $this->openCustomerPaymentModal('checkout');
            return null;
        }

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
        if ($this->invoiceIsLocked()) {
            $this->errorMessage = 'هذه فاتورة محفوظة للعرض فقط. اضغط «تعديل» أولاً.';
            return;
        }
        if ($this->selectedCustomerId && !$this->customerPaymentConfirmed) {
            $this->openCustomerPaymentModal('checkoutAndPrint');
            return;
        }

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

        // نستخدم المعرف المحمّل للتعديل، ولا نعتمد على رقم الفاتورة المعروض فقط.
        $editingInvoiceId = $this->editingInvoiceId ?: ($this->currentInvoiceId ? (int) $this->currentInvoiceId : null);

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
            $order = DB::transaction(function () use ($tenantId, $branchId, $user, $shift, $invoiceType, $paidInput, $customer, $editingInvoiceId): Order {
                $lockedShift = Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->whereKey($shift->id)->lockForUpdate()->first();

                if (!$lockedShift || $lockedShift->status !== 'open' || (int) $lockedShift->opened_by !== (int) $user->id) {
                    throw new \RuntimeException('الشيفت غير مفتوح أو لم يعد تابعاً للمستخدم الحالي.');
                }

                $order = null;

                /*
                 |--------------------------------------------------------------------------
                 | تحميل الفاتورة الأصلية عند التعديل
                 |--------------------------------------------------------------------------
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
                        throw new \RuntimeException('الفاتورة الأصلية لم تعد موجودة. رقمها الداخلي: ' . $editingInvoiceId);
                    }

                    /*
                     |--------------------------------------------------------------------------
                     | إعادة المخزون إلى وضع ما قبل الفاتورة القديمة
                     |--------------------------------------------------------------------------
                     */
                    // إعادة مخزون الفاتورة القديمة إلى الفرع الحالي.
                    // لا نحتاج لتتبع الفرع الذي أُخذت منه الكمية؛ المخزون الموحد
                    // يُعامل كمخزون واحد، والفرع الحالي هو سجل الخصم/الإرجاع.
                    $oldItems = OrderItem::query()
                        ->where('tenant_id', $tenantId)
                        ->where('order_id', $order->id)
                        ->get();

                    foreach ($oldItems as $oldItem) {
                        $oldQuantity = (float) $oldItem->quantity;

                        if (abs($oldQuantity) < 0.000001) {
                            continue;
                        }

                        $oldBranchProduct = BranchProduct::query()
                            ->where('tenant_id', $tenantId)
                            ->where('branch_id', $branchId)
                            ->where('product_id', $oldItem->product_id)
                            ->lockForUpdate()
                            ->first();

                        if (!$oldBranchProduct) {
                            $productName = Product::query()
                                ->where('tenant_id', $tenantId)
                                ->whereKey($oldItem->product_id)
                                ->value('name') ?? $oldItem->product_id;

                            throw new \RuntimeException(
                                "المنتج {$productName} في الفاتورة القديمة غير مرتبط بالفرع الحالي."
                            );
                        }

                        if ($oldQuantity > 0) {
                            $oldBranchProduct->increment('stock_quantity', $oldQuantity);
                        } else {
                            $oldBranchProduct->decrement('stock_quantity', abs($oldQuantity));
                        }
                    }

                    OrderItem::query()
                        ->where('tenant_id', $tenantId)
                        ->where('order_id', $order->id)
                        ->delete();
                    }

                $validatedItems = [];
                $subtotal = 0;
                $totalCost = 0;

                /*
                 |--------------------------------------------------------------------------
                 | التحقق من السلة الجديدة
                 |--------------------------------------------------------------------------
                 */
                foreach ($this->cart as $rawItem) {
                    $productId = (int) ($rawItem['id'] ?? 0);
                    $quantity = (float) ($rawItem['quantity'] ?? 0);
                    $price = max(0, (float) ($rawItem['price'] ?? 0));

                    if (!$productId || $quantity === 0) {
                        throw new \RuntimeException('يوجد صنف أو كمية غير صالحة في الفاتورة.');
                    }

                    // الفاتورة لها وضع واحد فقط: بيع أو مرتجع.
                    // في المرتجع يجب أن تكون كل السطور مرتجعة (كمية سالبة)،
                    // وفي البيع يجب أن تكون كل السطور مبيعات (كمية موجبة).
                    // هذا تحقق نهائي على الخادم لمنع خلط النوعين حتى لو تم استدعاء
                    // دالة Livewire مباشرة أو تغيرت قيمة الحقل من الواجهة.
                    if ($invoiceType === 'return' && $quantity > 0) {
                        throw new \RuntimeException('لا يمكن إضافة صنف بيع إلى فاتورة مرتجع. افتح فاتورة جديدة إذا أردت البيع.');
                    }

                    if ($invoiceType === 'pos' && $quantity < 0) {
                        throw new \RuntimeException('لا يمكن إضافة صنف مرتجع إلى فاتورة بيع. افتح فاتورة جديدة إذا أردت الإرجاع.');
                    }

                    $product = Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->first();

                    if (!$product) {
                        throw new \RuntimeException('أحد المنتجات لم يعد متاحاً.');
                    }

                    $costPrice = max(0, (float) ($rawItem['cost_price'] ?? ($product->cost_price ?? 0)));

                    /*
                     * subtotal هو المبلغ الفعلي للسطر بعد العرض.
                     * مهم جداً: لا نعيد حسابه من quantity × price هنا،
                     * لأن price يبقى السعر الأصلي الظاهر للكاشير.
                     * مثال: 155 × 4 = 620، لكن العرض قد يجعل الإجمالي 255.
                     */
                    $lineTotal = array_key_exists('subtotal', $rawItem)
                        ? $this->roundMoney((float) $rawItem['subtotal'])
                        : $this->roundMoney($price * $quantity);

                    if ($quantity < 0) {
                        $lineTotal = -abs($lineTotal);
                    } else {
                        $lineTotal = max(0, $lineTotal);
                    }

                    if ($quantity > 0) {
                        $this->validateSaleStockAvailability(
                            $tenantId,
                            $branchId,
                            $productId,
                            $quantity,
                            $product->name
                        );
                    } elseif (!$this->unifiedStock) {
                        $branchProduct = BranchProduct::query()
                            ->where('tenant_id', $tenantId)
                            ->where('branch_id', $branchId)
                            ->where('product_id', $productId)
                            ->lockForUpdate()
                            ->first();

                        if (!$branchProduct) {
                            throw new \RuntimeException("المنتج {$product->name} غير مرتبط بالفرع الحالي.");
                        }
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
                $deliveryFee = $invoiceType === 'pos'
                    ? $this->roundMoney(max(0, (float) $this->delivery_fee))
                    : 0;

                $discount = $invoiceType === 'pos' ? $this->calculated_discount : 0;
                $total = $invoiceType === 'return'
                    ? -abs($subtotal)
                    : $this->roundMoney(max(0, $subtotal - $discount + $deliveryFee));

                if ($invoiceType === 'pos' && $this->custom_final_total !== null) {
                    $maxTotal = $this->roundMoney($subtotal + $deliveryFee);
                    $minTotal = $deliveryFee;
                    $customTotal = max($minTotal, min($maxTotal, (float) $this->custom_final_total));

                    $total = $this->roundMoney($customTotal);
                    // رسوم التوصيل ليست خصماً، لذلك نستخرج الخصم من قيمة المنتجات فقط.
                    $discount = $this->roundMoney(
                        max(0, min($subtotal, $subtotal + $deliveryFee - $customTotal))
                    );
                }

                $requiredPayment = abs($total);
                $paid = min($requiredPayment, $paidInput > 0 ? $paidInput : $requiredPayment);

                $paymentStatus = $requiredPayment <= 0 || $paid >= $requiredPayment ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');

                /*
                 |--------------------------------------------------------------------------
                 | إنشاء فاتورة جديدة أو تحديث نفس الفاتورة القديمة
                 |--------------------------------------------------------------------------
                 | نستخدم query builder في التحديث لتجاوز أي مشكلة fillable في Model Order.
                 */
                if (!$order) {
                    $order = Order::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'shift_id' => $lockedShift->id,
                        'created_by' => $user->id,
                        'customer_id' => $customer?->id,
                        'customer_name' => $customer?->name ?? 'زبون عام',
                        'customer_phone' => $customer?->phone,
                        'customer_address' => $customer?->address,
                        'invoice_number' => $this->makeInvoiceNumber($invoiceType, $tenantId),
                        'type' => $invoiceType,
                        'status' => 'completed',
                        'subtotal' => $subtotal,
                        'tax_amount' => 0,
                        'discount_type' => $this->discount_type,
                        'discount_rate' => $this->discount_type === 'percentage' ? min(100, max(0, $this->discount_amount)) : 0,
                        'discount' => $discount,
                        'delivery_fee' => $deliveryFee,
                        'total' => $total,
                        'total_cost' => $totalCost,
                        'total_profit' => $this->roundMoney(($total - $deliveryFee) - $totalCost),
                        'paid_amount' => $paid,
                        'payment_status' => $paymentStatus,
                        'notes' => trim($this->notes) ?: null,
                    ]);
                } else {
                    $updateData = [
                        'customer_id' => $customer?->id,
                        'customer_name' => $customer?->name ?? 'زبون عام',
                        'customer_phone' => $customer?->phone,
                        'customer_address' => $customer?->address,
                        'type' => $invoiceType,
                        'status' => 'completed',
                        'subtotal' => $subtotal,
                        'tax_amount' => 0,
                        'discount_type' => $this->discount_type,
                        'discount_rate' => $this->discount_type === 'percentage' ? min(100, max(0, $this->discount_amount)) : 0,
                        'discount' => $discount,
                        'delivery_fee' => $deliveryFee,
                        'total' => $total,
                        'total_cost' => $totalCost,
                        'total_profit' => $this->roundMoney(($total - $deliveryFee) - $totalCost),
                        'paid_amount' => $paid,
                        'payment_status' => $paymentStatus,
                        'notes' => trim($this->notes) ?: null,
                        'updated_at' => now(),
                    ];

                    $affected = Order::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->whereKey($order->id)->update($updateData);

                    if ($affected === 0) {
                        // عدم وجود صف متأثر لا يعني فشلًا بالضرورة إذا لم تتغير القيم،
                        // لذلك نتحقق فعليًا من قاعدة البيانات بعد التحديث.
                        $verify = Order::query()->whereKey($order->id)->first();

                        if (!$verify) {
                            throw new \RuntimeException('تعذر العثور على الفاتورة بعد محاولة تحديثها.');
                        }

                        $sameValues = $this->roundMoney((float) $verify->subtotal) === $subtotal && $this->roundMoney((float) $verify->total) === $total && $this->roundMoney((float) $verify->paid_amount) === $paid;

                        if (!$sameValues) {
                            throw new \RuntimeException('لم يتم تحديث بيانات الفاتورة فعلياً.');
                        }
                    }

                    $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();

                    if (!$order) {
                        throw new \RuntimeException('اختفت الفاتورة أثناء عملية التحديث.');
                    }
                }

                /*
                 |--------------------------------------------------------------------------
                 | إضافة تفاصيل الفاتورة الجديدة وتطبيق المخزون
                 |--------------------------------------------------------------------------
                 */
                foreach ($validatedItems as $item) {
                    $orderItem = OrderItem::create([
                        'tenant_id' => $tenantId,
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'cost_price' => $item['cost_price'],
                        'total_price' => $item['total_price'],
                    ]);

                    // لا نحتاج معرفة من أي فرع أُخذت الكمية.
                    // عند تفعيل المخزون الموحد نتحقق من مجموع مخزون جميع الفروع،
                    // ثم نسجل أثر البيع على الفرع الحالي فقط.
                    $branchProduct = BranchProduct::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->where('product_id', $item['product_id'])
                        ->lockForUpdate()
                        ->first();

                    if (!$branchProduct) {
                        throw new \RuntimeException(
                            "المنتج {$item['product_id']} غير مرتبط بالفرع الحالي."
                        );
                    }

                    $itemQuantity = (float) $item['quantity'];

                    if ($itemQuantity > 0) {
                        $branchProduct->decrement('stock_quantity', $itemQuantity);
                    } elseif ($itemQuantity < 0) {
                        $branchProduct->increment('stock_quantity', abs($itemQuantity));
                    }
                }

                /*
                 |--------------------------------------------------------------------------
                 | تحديث الدفعة الموجودة بنفس voucher_number
                 |--------------------------------------------------------------------------
                 */
                $voucherNumber = 'PAY-' . $order->invoice_number;

                $existingPayment = Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where(function ($query) use ($voucherNumber, $order): void {
                        $query->where('voucher_number', $voucherNumber)->orWhere('order_id', $order->id);
                    })
                    ->lockForUpdate()
                    ->first();

                if ($paid > 0) {
                    $paymentData = [
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'shift_id' => (int) ($order->shift_id ?: $lockedShift->id),
                        'created_by' => $user->id,
                        'party_id' => $customer?->id,
                        'type' => $invoiceType === 'return' ? 'payment' : 'receipt',
                        'voucher_number' => $voucherNumber,
                        'amount' => $paid,
                        'payment_method' => $this->payment_method,
                        'order_id' => $order->id,
                        'notes' => 'سند قبض للفاتورة #' . $order->invoice_number,
                        'payment_date' => now(),
                        'updated_at' => now(),
                    ];

                    if ($existingPayment) {
                        $existingPayment->update($paymentData);
                    } else {
                        Payment::create($paymentData);
                    }
                } elseif ($existingPayment) {
                    $existingPayment->delete();
                }

                return Order::query()
                    ->whereKey($order->id)
                    ->with(['items.product', 'user', 'branch'])
                    ->firstOrFail();
            });

            $savedInvoiceNumber = (string) $order->invoice_number;
            $wasEditing = (bool) $editingInvoiceId;

            $this->activeShiftId = $shift->id;

            // لا نحتفظ بمعرف الفاتورة القديمة بعد نجاح F3؛ نجهز فاتورة جديدة مباشرة.
            $this->clearCartState();
            $this->activeShiftId = $shift->id;

            $this->successMessage = $wasEditing ? ($invoiceType === 'return' ? "تم تعديل المرتجع {$savedInvoiceNumber} بنجاح وتم فتح فاتورة جديدة." : "تم تعديل الفاتورة {$savedInvoiceNumber} بنجاح وتم فتح فاتورة جديدة.") : ($invoiceType === 'return' ? "تم حفظ المرتجع {$savedInvoiceNumber} وتم فتح فاتورة جديدة." : "تم حفظ الفاتورة {$savedInvoiceNumber} وتم فتح فاتورة جديدة.");

            $this->dispatch('pos-sound', type: $wasEditing ? 'save-edit' : 'save');

            return $order;
        } catch (\Throwable $e) {
            Log::error('POS checkout failed', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'shift_id' => $shift->id,
                'editing_invoice_id' => $editingInvoiceId,
                'message' => $e->getMessage(),
                'cart' => $this->cart,
            ]);

            $this->errorMessage = app()->environment('local') ? 'تعذر حفظ الفاتورة: ' . $e->getMessage() : 'تعذر حفظ الفاتورة. يرجى المحاولة مرة أخرى.';

            return null;
        }
    }

    private function validateSaleStockAvailability(
        int $tenantId,
        int $currentBranchId,
        int $productId,
        float $quantity,
        string $productName
    ): void {
        $branchProducts = BranchProduct::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->get()
            ->sortByDesc(fn ($row) => (int) $row->branch_id === $currentBranchId)
            ->values();

        if (!$this->unifiedStock) {
            $current = $branchProducts->first(
                fn ($row) => (int) $row->branch_id === $currentBranchId
            );

            if (!$current) {
                throw new \RuntimeException(
                    "المنتج {$productName} غير مرتبط بالفرع الحالي."
                );
            }

            if (!$this->allowNegativeStock && (float) $current->stock_quantity < $quantity) {
                throw new \RuntimeException(
                    "المخزون غير كافٍ للمنتج {$productName}. المتوفر: {$current->stock_quantity}."
                );
            }

            return;
        }

        if ($branchProducts->isEmpty()) {
            throw new \RuntimeException(
                "المنتج {$productName} غير مرتبط بأي فرع في هذا المتجر."
            );
        }

        $available = 0.0;
        foreach ($branchProducts as $branchProduct) {
            $available += max(0, (float) $branchProduct->stock_quantity);
        }

        if (!$this->allowNegativeStock && $available + 0.000001 < $quantity) {
            throw new \RuntimeException(
                "المخزون الموحد غير كافٍ للمنتج {$productName}. المتوفر في جميع الفروع: {$available}."
            );
        }
    }

    private function makeInvoiceNumber(string $type, int $tenantId): string
    {
        // البيع يبدأ بحرف A، والمرتجع يبدأ بحرف R.
        // مثال: A100001858
        $prefix = $type === 'return' ? 'R' : 'A';

        do {
            // 9 أرقام بعد الحرف.
            $number = $prefix . random_int(100000000, 999999999);
        } while (Order::query()->where('tenant_id', $tenantId)->where('invoice_number', $number)->exists());

        return $number;
    }

    private function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }

    /**
     * حساب إجمالي السطر مع نظام العروض:
     *
     * مثال: سعر القطعة 4، والعرض 3 قطع بـ 10:
     * 1 = 4, 2 = 8, 3 = 10, 4 = 14, 5 = 18,
     * 6 = 20, 7 = 24, 8 = 28, 9 = 30.
     *
     * أي أن كل مجموعة كاملة من offer_quantity تُحسب بسعر offer_price،
     * والكمية المتبقية تُحسب بسعر القطعة العادي.
     */
    private function calculateOfferTotal(float $quantity, float $unitPrice, ?float $offerQuantity, ?float $offerPrice): float
    {
        $sign = $quantity < 0 ? -1 : 1;
        $absoluteQuantity = abs($quantity);
        $offerQuantity = (float) ($offerQuantity ?? 0);
        $offerPrice = $offerPrice !== null ? (float) $offerPrice : null;

        // لا يوجد عرض صالح.
        if ($absoluteQuantity <= 0 || $offerQuantity <= 0 || $offerPrice === null || $offerPrice < 0) {
            return $this->roundMoney($quantity * $unitPrice);
        }

        // لا نطبق عرضاً أغلى من السعر الطبيعي للمجموعة.
        // هذا يمنع أن يتحول العرض بالخطأ إلى زيادة في السعر.
        if ($offerPrice >= ($offerQuantity * $unitPrice)) {
            return $this->roundMoney($quantity * $unitPrice);
        }

        // العروض الكمية تعمل على المجموعات الكاملة فقط.
        $fullOffers = (int) floor($absoluteQuantity / $offerQuantity);
        $remainder = round($absoluteQuantity - ($fullOffers * $offerQuantity), 6);

        $total = ($fullOffers * $offerPrice) + ($remainder * $unitPrice);

        return $this->roundMoney($total * $sign);
    }

    /**
     * جلب العرض النشط الحالي للمنتج من product_offers.
     * العرض الجديد له الأولوية، وإذا لم يوجد نستخدم بيانات العرض القديمة
     * الموجودة في branch_products للتوافق مع الفواتير/البيانات القديمة.
     */
    private function getActiveProductOffer(int $productId, int $tenantId, int $branchId): ?ProductOffer
    {
        if ($productId <= 0 || $tenantId <= 0 || $branchId <= 0) {
            return null;
        }

        $now = now();

        return ProductOffer::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->where('start_at', '<=', $now)
            ->where(function ($query) use ($now) {
                $query->whereNull('end_at')
                    ->orWhere('end_at', '>=', $now);
            })
            ->orderByDesc('start_at')
            ->first();
    }

    private function recalculatePrices(): void
    {
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        foreach ($this->cart as $id => $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['price'] ?? 0);

            // السعر الطبيعي بدون أي عرض.
            $normalTotal = $this->roundMoney($quantity * $unitPrice);

            // السعر المعدل يدوياً له الأولوية على العرض.
            if (($item['price_manual'] ?? false) === true) {
                $this->cart[$id]['subtotal'] = $normalTotal;
                $this->cart[$id]['normal_total'] = $normalTotal;
                $this->cart[$id]['promotion_savings'] = 0;
                continue;
            }

            $offerQuantity = isset($item['offer_quantity'])
                ? (float) $item['offer_quantity']
                : 0;

            $offerPrice = array_key_exists('offer_price', $item) && $item['offer_price'] !== null
                ? (float) $item['offer_price']
                : null;

            // العروض الجديدة تُحفظ في product_offers، لذلك نبحث أولاً
            // عن العرض النشط الحالي. وإذا لم يوجد نستخدم العرض القديم
            // الموجود في branch_products للتوافق مع البيانات السابقة.
            if ($tenantId && $branchId && (int) ($item['id'] ?? 0) > 0) {
                $productId = (int) $item['id'];
                $activeOffer = $this->getActiveProductOffer($productId, (int) $tenantId, (int) $branchId);

                if ($activeOffer) {
                    $offerQuantity = (float) ($activeOffer->offer_quantity ?? 0);
                    $offerPrice = $activeOffer->offer_price !== null
                        ? (float) $activeOffer->offer_price
                        : null;
                } else {
                    $branchProduct = BranchProduct::query()
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->where('product_id', $productId)
                        ->first();

                    if ($branchProduct) {
                        $offerQuantity = $branchProduct->offer_quantity !== null
                            ? (float) $branchProduct->offer_quantity
                            : 0;

                        $offerPrice = $branchProduct->offer_price !== null
                            ? (float) $branchProduct->offer_price
                            : null;
                    }
                }

                $this->cart[$id]['offer_quantity'] = $offerQuantity;
                $this->cart[$id]['offer_price'] = $offerPrice;
            }

            $finalTotal = $this->calculateOfferTotal(
                $quantity,
                $unitPrice,
                $offerQuantity,
                $offerPrice
            );

            $this->cart[$id]['subtotal'] = $finalTotal;
            $this->cart[$id]['normal_total'] = $normalTotal;

            // يظهر التوفير فقط عندما يكون العرض فعلاً أوفر من السعر الطبيعي.
            $savings = 0;
            if ($quantity > 0 && abs($finalTotal) < abs($normalTotal)) {
                $savings = abs($normalTotal) - abs($finalTotal);
            }

            $this->cart[$id]['promotion_savings'] = $this->roundMoney($savings);
        }

        // إبقاء حقل الصافي متزامناً مع الإجمالي والخصم.
        if (!empty($this->cart)) {
            $this->custom_final_total = $this->roundMoney(
                max(0, $this->subtotal - $this->calculated_discount + $this->delivery_fee)
            );
        } else {
            $this->custom_final_total = null;
        }
    }

    private function prepareReceiptFromOrder(Order $order): void
    {
        $order->loadMissing('items.product', 'user', 'branch');

        /*
        |--------------------------------------------------------------------------
        | تجهيز بيانات الطباعة مع نفس منطق العروض الموجود في شاشة الـPOS
        |--------------------------------------------------------------------------
        | السعر في OrderItem هو سعر القطعة الظاهر للكاشير، بينما total_price
        | هو المبلغ الفعلي بعد العرض. لذلك نستطيع إظهار:
        |
        | السعر الطبيعي = سعر الوحدة × الكمية
        | المبلغ الفعلي = total_price
        | التوفير       = السعر الطبيعي - المبلغ الفعلي
        |
        | لكن لا نعتبر كل فرق خصماً/عرضاً تلقائياً؛ نتحقق أولاً أن إجمالي السطر
        | يطابق عرض branch_products الحالي. هذا يمنع اعتبار تخفيض يدوي كعرض.
        */
        $productIds = $order->items
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $branchProducts = collect();

        if ($productIds->isNotEmpty()) {
            $branchProducts = BranchProduct::query()
                ->where('tenant_id', $this->tenantId())
                ->where('branch_id', $order->branch_id)
                ->whereIn('product_id', $productIds)
                ->get()
                ->keyBy('product_id');
        }

        $groupedItems = [];

        foreach ($order->items as $item) {
            $productId = (int) $item->product_id;
            $quantity = (float) $item->quantity;
            $lineTotal = (float) $item->total_price;
            $unitPrice = abs((float) $item->unit_price);

            if ($productId <= 0) {
                $groupKey = 'item_' . $item->id;
            } else {
                $groupKey = 'product_' . $productId;
            }

            if (!isset($groupedItems[$groupKey])) {
                $groupedItems[$groupKey] = [
                    'name' => $item->product?->name ?? 'منتج غير محدد',
                    'qty' => $quantity,
                    'total' => $lineTotal,
                    'normal_total' => abs($quantity) * $unitPrice,
                    'unit_price' => $unitPrice,
                ];
            } else {
                $groupedItems[$groupKey]['qty'] += $quantity;
                $groupedItems[$groupKey]['total'] += $lineTotal;
                $groupedItems[$groupKey]['normal_total'] += abs($quantity) * $unitPrice;
            }
        }

        $items = [];
        $totalQty = 0;
        $totalPromotionSavings = 0;
        $index = 1;

        foreach ($groupedItems as $groupKey => $groupedItem) {
            $quantity = (float) $groupedItem['qty'];
            $total = (float) $groupedItem['total'];
            $normalTotal = $this->roundMoney((float) $groupedItem['normal_total']);

            if (abs($quantity) < 0.000001) {
                continue;
            }

            $totalQty += abs($quantity);

            $productId = str_starts_with($groupKey, 'product_')
                ? (int) str_replace('product_', '', $groupKey)
                : 0;

            $promotionSavings = 0;
            $offerQuantity = 0;
            $offerPrice = null;

            if ($productId > 0 && $quantity > 0) {
                $branchProduct = $branchProducts->get($productId);

                if ($branchProduct) {
                    $offerQuantity = (float) ($branchProduct->offer_quantity ?? 0);
                    $offerPrice = $branchProduct->offer_price !== null
                        ? (float) $branchProduct->offer_price
                        : null;

                    if (
                        $offerQuantity > 0 &&
                        $offerPrice !== null &&
                        $offerPrice >= 0
                    ) {
                        $expectedOfferTotal = $this->calculateOfferTotal(
                            $quantity,
                            (float) $groupedItem['unit_price'],
                            $offerQuantity,
                            $offerPrice
                        );

                        // العرض يجب أن يطابق المبلغ المحفوظ فعلياً في الفاتورة.
                        if (
                            abs(abs($total) - abs($expectedOfferTotal)) < 0.01 &&
                            $normalTotal > abs($total) + 0.001
                        ) {
                            $promotionSavings = $this->roundMoney(
                                $normalTotal - abs($total)
                            );
                        }
                    }
                }
            }

            $totalPromotionSavings += $promotionSavings;

            // سعر الوحدة الفعلي الذي ظهر في الفاتورة.
            $unitPriceDisplay = abs($quantity) > 0
                ? abs($total / $quantity)
                : 0;

            $items[] = [
                'id' => $index++,
                'name' => $groupedItem['name'],
                'qty' => $quantity,
                'price' => number_format($unitPriceDisplay, 2),
                'total' => number_format($total, 2),
                'normal_total' => number_format($normalTotal, 2),
                'promotion_savings' => number_format($promotionSavings, 2),
                'has_promotion' => $promotionSavings > 0,
                'offer_label' => $promotionSavings > 0
                    ? 'عرض ' . rtrim(rtrim(number_format($offerQuantity, 2), '0'), '.') .
                      ' بـ ' . number_format(abs((float) $offerPrice), 2)
                    : '',
            ];
        }

        $totalPromotionSavings = $this->roundMoney($totalPromotionSavings);

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
            'total_promotion_savings' => number_format($totalPromotionSavings, 2),
            'subtotal' => number_format((float) $order->subtotal, 2),
            'discount' => number_format((float) $order->discount, 2),
            'delivery_fee' => number_format((float) ($order->delivery_fee ?? 0), 2),
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

    /**
     * نسخ الفاتورة الحالية إلى فاتورة جديدة قابلة للتعديل.
     * يتم نسخ الأصناف والكميات والأسعار والزبون فقط،
     * بينما يبدأ الدفع والخصم من الصفر لأنها فاتورة جديدة.
     */
    public function copyLoadedInvoice(): void
    {
        if (!$this->currentInvoiceId) {
            $this->errorMessage = 'لا توجد فاتورة محفوظة لنسخها.';
            return;
        }

        $invoice = Order::query()
            ->where('tenant_id', $this->tenantId())
            ->where('branch_id', $this->getActiveBranchId())
            ->whereIn('type', ['pos', 'return'])
            ->with('items.product')
            ->find($this->currentInvoiceId);

        if (!$invoice) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة المطلوبة لنسخها.';
            return;
        }

        $this->cart = [];

        foreach ($invoice->items as $item) {
            $lineKey = $this->mergeSimilarProducts
                ? (string) $item->product_id
                : 'copy_' . str()->uuid()->toString();

            $quantity = (float) $item->quantity;
            $subtotal = (float) $item->total_price;

            if ($this->mergeSimilarProducts && isset($this->cart[$lineKey])) {
                $this->cart[$lineKey]['quantity'] += $quantity;
                $this->cart[$lineKey]['subtotal'] = $this->roundMoney(
                    (float) $this->cart[$lineKey]['subtotal'] + $subtotal
                );

                if ((float) $this->cart[$lineKey]['quantity'] !== 0.0) {
                    $this->cart[$lineKey]['price'] = round(
                        abs($this->cart[$lineKey]['subtotal'] / $this->cart[$lineKey]['quantity']),
                        6
                    );
                }

                continue;
            }

            $this->cart[$lineKey] = [
                'id' => (int) $item->product_id,
                'name' => $item->product?->name ?? 'منتج غير محدد',
                'barcode' => '',
                'price' => (float) $item->unit_price,
                'cost_price' => (float) ($item->cost_price ?? ($item->product?->cost_price ?? 0)),
                'quantity' => $quantity,
                'subtotal' => $subtotal,
            ];
        }

        // فاتورة جديدة: لا نربطها بالفاتورة الأصلية.
        $this->currentInvoiceId = null;
        $this->editingInvoiceId = null;
        $this->invoiceEditMode = false;

        // نسخ الزبون فقط، مع تصفير بيانات الدفع والخصم.
        $this->selectedCustomerId = $invoice->customer_id ? (int) $invoice->customer_id : null;
        $this->customerSearch = '';
        $this->paid_amount = 0;
        $this->payment_method = 'cash';
        $this->discount_amount = 0;
        $this->discount_type = 'fixed';
        $this->custom_final_total = null;
        $this->customerPaymentAmount = 0;
        $this->customerPaymentConfirmed = false;
        $this->showCustomerPaymentModal = false;
        $this->showBelowCostModal = false;
        $this->notes = '';
        $this->receipt = [];
        $this->isReturnMode = $invoice->type === 'return';
        $this->errorMessage = null;
        $this->successMessage = "تم نسخ الفاتورة {$invoice->invoice_number} إلى فاتورة جديدة. تم نسخ الأصناف والزبون فقط.";

        $this->recalculatePrices();
        $this->dispatch('pos-focus-barcode');
    }

    /**
     * إنشاء نص واتساب مرتب وواضح مع سعر الوحدة وإجمالي كل صنف.
     */
    private function buildWhatsappInvoiceMessage(Order $order): string
    {
        $order->loadMissing('items.product');

        $lines = [];
        // بدون رموز Emoji حتى لا تظهر كرمز � في بعض أجهزة/متصفحات واتساب.
        $lines[] = $order->type === 'return' ? 'فاتورة مرتجع' : 'فاتورة بيع';
        $lines[] = 'رقم الفاتورة: ' . $order->invoice_number;

        if ($order->customer_id) {
            $customerName = Party::query()
                ->where('tenant_id', $this->tenantId())
                ->whereKey($order->customer_id)
                ->value('name');

            if ($customerName) {
                $lines[] = 'الزبون: ' . $customerName;
            }
        }

        $lines[] = '';
        $lines[] = 'الأصناف:';

        foreach ($order->items as $item) {
            $quantity = (float) $item->quantity;
            $quantityText = rtrim(rtrim(number_format(abs($quantity), 3, '.', ''), '0'), '.');

            // نأخذ أول كلمتين فقط من اسم الصنف.
            $productName = trim((string) ($item->product?->name ?? 'منتج غير محدد'));
            $nameWords = preg_split('/\s+/u', $productName, -1, PREG_SPLIT_NO_EMPTY);
            $shortName = implode(' ', array_slice($nameWords ?: ['منتج'], 0, 2));

            $unitPrice = number_format(abs((float) $item->unit_price), 2);
            $lineTotal = number_format(abs((float) $item->total_price), 2);
            $prefix = $quantity < 0 ? 'مرتجع: ' : '• ';

            // الشكل المطلوب: أول كلمتين ... السعر × الكمية = الإجمالي
            $lines[] = $prefix . $shortName
                . ' ... ' . $unitPrice
                . ' × ' . $quantityText
                . ' = ' . $lineTotal;
        }

        $lines[] = '';
        $lines[] = 'الإجمالي: ' . number_format(abs((float) $order->subtotal), 2);
        $lines[] = 'الخصم: ' . number_format((float) $order->discount, 2);
        $lines[] = 'الصافي: ' . number_format(abs((float) $order->total), 2);
        $lines[] = 'المدفوع: ' . number_format((float) $order->paid_amount, 2);
        $lines[] = 'الباقي: ' . number_format(max(0, abs((float) $order->total) - (float) $order->paid_amount), 2);

        if (trim((string) $order->notes) !== '') {
            $lines[] = '';
            $lines[] = 'ملاحظات: ' . trim((string) $order->notes);
        }

        $lines[] = '';
        $lines[] = 'شكراً لتعاملكم معنا';

        return implode("\n", $lines);
    }

    /**
     * حفظ الفاتورة عند الحاجة قبل تنفيذ إجراء خارجي مثل واتساب أو PDF.
     *
     * - فاتورة محفوظة وقديمة ومقفلة: نستخدمها مباشرة بدون إعادة حفظ.
     * - فاتورة جديدة: نحفظها أولاً.
     * - فاتورة قديمة تم الضغط على «تعديل» فيها: نحفظ التعديلات أولاً.
     */
    private function saveInvoiceBeforeAction(): ?Order
    {
        if ($this->currentInvoiceId && !$this->invoiceEditMode) {
            return Order::query()
                ->where('tenant_id', $this->tenantId())
                ->where('branch_id', $this->getActiveBranchId())
                ->whereIn('type', ['pos', 'return'])
                ->whereKey($this->currentInvoiceId)
                ->with(['items.product', 'user', 'branch'])
                ->first();
        }

        if (empty($this->cart)) {
            $this->errorMessage = 'الفاتورة فارغة ولا توجد فاتورة محفوظة لتنفيذ هذا الإجراء.';
            return null;
        }

        // نحفظ مباشرة باستخدام نفس منطق الحفظ الأساسي في POS.
        // هذا يحافظ على المخزون، الدفع، الخصم، العميل، الشيفت، والتعديل.
        return $this->processCheckout();
    }

    /**
     * إرسال الفاتورة عبر واتساب.
     * إذا كانت الفاتورة جديدة أو عليها تعديلات غير محفوظة، يتم حفظها أولاً.
     * إذا كان للعميل رقم محفوظ يفتح محادثته مباشرة، وإلا يفتح واتساب لاختيار العميل.
     */
    public function sendInvoiceWhatsApp(): void
    {
        $order = $this->saveInvoiceBeforeAction();

        if (!$order) {
            if (!$this->errorMessage) {
                $this->errorMessage = 'تعذر حفظ الفاتورة قبل إرسالها عبر واتساب.';
            }
            return;
        }

        $message = $this->buildWhatsappInvoiceMessage($order);
        $phone = null;

        if ($order->customer_id) {
            $phone = Party::query()
                ->where('tenant_id', $this->tenantId())
                ->whereKey($order->customer_id)
                ->value('phone');

            $phone = preg_replace('/\D+/', '', (string) $phone);

            if (str_starts_with($phone, '00')) {
                $phone = substr($phone, 2);
            }

            if (str_starts_with($phone, '0')) {
                $phone = '972' . substr($phone, 1);
            }

            $phone = $phone !== '' ? $phone : null;
        }

        $this->successMessage = 'تم حفظ الفاتورة وتجهيزها للإرسال عبر واتساب.';
        $this->dispatch('whatsapp-invoice', phone: $phone, message: $message);
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
        // إذا كانت الفاتورة جديدة أو عليها تعديلات، احفظها أولاً ثم أنشئ PDF.
        $order = $this->saveInvoiceBeforeAction();

        if (!$order) {
            if (!$this->errorMessage) {
                $this->errorMessage = 'تعذر حفظ الفاتورة قبل إنشاء PDF.';
            }
            return null;
        }

        return response()->streamDownload(function () use ($order) {
            $pdf = app('dompdf.wrapper');

            $pdf->loadView('pages.tenant.pos.partials.invoice-pdf', [
                'order' => $order,
            ]);

            echo $pdf->output();
        }, 'invoice-' . $order->invoice_number . '.pdf');
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
            x-on:keydown.window.f2.prevent="$wire.holdInvoice()"
            x-on:keydown.window.f3.prevent="
                (async () => {
                    const field = document.activeElement?.closest?.('[data-pos-field]');
                    if (field && !field.disabled && field.dataset.lineKey && field.dataset.posField) {
                        await $wire.updateCartField(field.dataset.lineKey, field.dataset.posField, field.value);
                    }
                    await $wire.saveOrEditInvoice();
                })()
            "
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
            @if ($this->invoiceIsLocked())
                <div
                    class="shrink-0 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-center text-xs font-black text-amber-800">
                    🔒 الفاتورة القديمة للعرض فقط — التعديل يحتاج «تعديل»، أما الطباعة وPDF وواتساب والنسخ فتعمل مباشرة.
                </div>
            @endif

            {{--
                أزرار الفاتورة القديمة:
                نضع هنا زر "نسخ" فقط لأن الطباعة وPDF وواتساب والتعديل
                موجودة أصلًا في شريط الإجراءات السفلي، ولا نكرر الأزرار.
            --}}
            @if ($currentInvoiceId)
                <div
                    class="shrink-0 flex flex-wrap items-center gap-1.5 rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm"
                    dir="rtl"
                >
                    <div class="ml-auto px-2 text-[10px] font-black text-slate-500">
                        فاتورة #{{ $this->receipt['invoice_no'] ?? $currentInvoiceId }}
                    </div>

                    {{-- نسخ الفاتورة فقط --}}
                    <button
                        type="button"
                        wire:click="copyLoadedInvoice"
                        class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-[10px] font-black text-violet-700 hover:bg-violet-100"
                    >
                        📋 نسخ
                    </button>
                </div>
            @endif

            <div class="min-h-0 flex-1">
                <section class="h-full min-h-0">
                    <div class="grid h-full min-h-0 grid-cols-1 gap-2 lg:grid-cols-[minmax(0,1fr)_minmax(320px,32%)]"
                        dir="ltr">
                        @include('pages.tenant.pos.partials.cart')


                        {{-- =========================================================
                         المنتجات والتصنيفات داخل شاشة الـPOS
                    ========================================================== --}}
                        <aside
                            class="min-h-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm {{ $this->invoiceIsLocked() ? 'opacity-70' : '' }}"
                            dir="rtl">
                            <div class="flex h-full min-h-0 flex-col">

                                <div class="shrink-0 border-b border-slate-200 bg-slate-50 p-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <div>
                                            <div class="text-sm font-black text-slate-800">الأصناف</div>
                                            <div class="text-[9px] font-bold text-slate-400">
                                                {{ count($quickProducts) }} صنف
                                            </div>
                                        </div>

                                        <button type="button" wire:click="loadQuickProducts"
                                            @disabled($this->invoiceIsLocked())
                                            class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[10px] font-black text-slate-600 hover:bg-slate-100">
                                            ↻ تحديث
                                        </button>
                                    </div>

                                    <input data-pos-product-panel-search
                                        x-on:keydown.arrow-down.prevent="$nextTick(() => $el.closest('aside')?.querySelector('[data-pos-product]')?.focus())"
                                        wire:model.live.debounce.250ms="productSearchQuery" @disabled($this->invoiceIsLocked())
                                        type="text" autocomplete="off" placeholder="ابحث عن الصنف أو الباركود..."
                                        class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-800 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                </div>

                                <div class="shrink-0 border-b border-slate-200 bg-white p-2">
                                    <div class="flex gap-1.5 overflow-x-auto pb-1" style="scrollbar-width: thin;">
                                        <button type="button" wire:click="selectCategory(null)"
                                            @disabled($this->invoiceIsLocked()) wire:key="pos-category-all"
                                            class="shrink-0 rounded-lg border px-3 py-2 text-[10px] font-black transition {{ !$selectedCategoryId ? 'border-indigo-500 bg-indigo-600 text-white shadow-sm' : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-indigo-300 hover:bg-indigo-50' }}">
                                            الكل
                                        </button>

                                        @foreach ($categories as $category)
                                            @php
                                                $categoryId = (int) ($category['id'] ?? $category->id);
                                                $categoryName = $category['name'] ?? $category->name;
                                            @endphp

                                            <button type="button" wire:click="selectCategory({{ $categoryId }})"
                                                @disabled($this->invoiceIsLocked()) wire:key="pos-category-{{ $categoryId }}"
                                                class="shrink-0 rounded-lg border px-3 py-2 text-[10px] font-black transition {{ (int) $selectedCategoryId === $categoryId ? 'border-indigo-500 bg-indigo-600 text-white shadow-sm' : 'border-slate-200 bg-slate-50 text-slate-700 hover:border-indigo-300 hover:bg-indigo-50' }}">
                                                {{ $categoryName }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                <div data-pos-products-panel class="min-h-0 flex-1 overflow-y-auto bg-slate-100 p-2"
                                    tabindex="0">
                                    <div data-pos-product-grid class="grid grid-cols-2 gap-2 xl:grid-cols-3">
                                        @forelse ($quickProducts as $product)
                                            <button type="button" data-pos-product
                                                data-product-index="{{ $loop->index }}"
                                                wire:click="selectInlineProduct({{ $product->id }})"
                                                @disabled($this->invoiceIsLocked())
                                                wire:key="pos-quick-product-{{ $product->id }}"
                                                class="min-h-[78px] rounded-xl border border-slate-200 bg-white p-2 text-right shadow-sm transition hover:-translate-y-0.5 hover:border-indigo-400 hover:shadow-md active:scale-[0.98] focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1">
                                                <div
                                                    class="line-clamp-2 min-h-[30px] text-[11px] font-black leading-4 text-slate-800">
                                                    {{ $product->name }}
                                                </div>

                                                <div class="mt-2 flex items-end justify-between gap-1">
                                                    <span class="font-mono text-sm font-black text-indigo-700">
                                                        {{ number_format((float) $product->retail_price, 2) }}
                                                    </span>

                                                    <span
                                                        class="rounded-md px-1.5 py-0.5 text-[8px] font-black {{ (float) $product->stock_quantity > 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}">
                                                        {{ number_format((float) $product->stock_quantity, 0) }}
                                                    </span>
                                                </div>
                                            </button>
                                        @empty
                                            <div
                                                class="col-span-full flex min-h-[220px] items-center justify-center text-center">
                                                <div>
                                                    <div class="text-3xl opacity-30">📦</div>
                                                    <div class="mt-2 text-xs font-black text-slate-400">لا توجد أصناف
                                                    </div>
                                                    @if ($selectedCategoryId || trim($productSearchQuery) !== '')
                                                        <button type="button" wire:click="selectCategory(null)"
                                                            class="mt-2 rounded-lg bg-indigo-50 px-3 py-1.5 text-[9px] font-black text-indigo-700">
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
                        /*
                                                                |--------------------------------------------------------------------------
                                                                | Keyboard navigation - Product list ONLY
                                                                |--------------------------------------------------------------------------
                                                                | ↑ / ↓  = move between products
                                                                | Enter  = add selected product
                                                                |
                                                                | مهم:
                                                                | - هذا النظام يعمل فقط عندما يكون التركيز داخل قائمة المنتجات.
                                                                | - لا يتدخل في أسهم الكمية/السعر/الإجمالي داخل السلة.
                                                                | - لا يغيّر wire:click الموجود على كروت المنتجات.
                                                                |--------------------------------------------------------------------------
                                                                */
                        (function() {
                            'use strict';

                            const PANEL_SELECTOR = '[data-pos-products-panel]';
                            const PRODUCT_SELECTOR = '[data-pos-product]';
                            const SEARCH_SELECTOR = '[data-pos-product-panel-search]';

                            /*
                             * بعد اختيار صنف من قائمة البحث:
                             * - تفريغ خانة البحث يتم من Livewire.
                             * - يعود التركيز مباشرة إلى خانة الباركود.
                             */
                            window.addEventListener('pos-focus-barcode', function() {
                                setTimeout(function() {
                                    const barcode = document.querySelector(
                                        '[data-pos-barcode-input]'
                                    );

                                    if (!barcode) {
                                        return;
                                    }

                                    barcode.focus();
                                    barcode.select?.();
                                }, 50);
                            });

                            function getPanel() {
                                return document.querySelector(PANEL_SELECTOR);
                            }

                            function getProducts(panel) {
                                if (!panel) {
                                    return [];
                                }

                                return Array.from(
                                    panel.querySelectorAll(PRODUCT_SELECTOR)
                                );
                            }

                            function focusProduct(product, selectIndex = true) {
                                if (!product || !document.contains(product)) {
                                    return;
                                }

                                product.focus({
                                    preventScroll: true
                                });

                                if (selectIndex) {
                                    const panel = product.closest(PANEL_SELECTOR);

                                    if (panel) {
                                        panel.dataset.selectedProductIndex =
                                            product.dataset.productIndex ?? '0';
                                    }
                                }

                                product.scrollIntoView({
                                    behavior: 'auto',
                                    block: 'nearest',
                                    inline: 'nearest'
                                });
                            }

                            function getColumnCount(panel) {
                                const grid = panel?.querySelector('[data-pos-product-grid]');

                                if (!grid) {
                                    return 1;
                                }

                                const products = getProducts(panel);

                                if (products.length < 2) {
                                    return 1;
                                }

                                const firstTop = products[0].getBoundingClientRect().top;
                                let columns = 0;

                                for (const product of products) {
                                    const top = product.getBoundingClientRect().top;

                                    if (Math.abs(top - firstTop) <= 2) {
                                        columns++;
                                    } else {
                                        break;
                                    }
                                }

                                return Math.max(1, columns);
                            }

                            function moveProduct(panel, direction) {
                                const products = getProducts(panel);

                                if (!products.length) {
                                    return;
                                }

                                const active = document.activeElement;
                                let currentIndex = products.indexOf(active);

                                if (currentIndex < 0) {
                                    const savedIndex = Number(
                                        panel.dataset.selectedProductIndex ?? 0
                                    );

                                    currentIndex = Number.isFinite(savedIndex) ?
                                        Math.min(
                                            Math.max(savedIndex, 0),
                                            products.length - 1
                                        ) :
                                        0;
                                }

                                /*
                                 * بسبب وجود عمودين أو ثلاثة حسب عرض الشاشة:
                                 * ArrowUp / ArrowDown يتحركان صفاً كاملاً،
                                 * وليس منتجاً واحداً فقط.
                                 */
                                const columns = getColumnCount(panel);

                                let targetIndex;

                                if (direction === 'up') {
                                    targetIndex = currentIndex - columns;

                                    if (targetIndex < 0) {
                                        targetIndex = 0;
                                    }
                                } else {
                                    targetIndex = currentIndex + columns;

                                    if (targetIndex >= products.length) {
                                        targetIndex = products.length - 1;
                                    }
                                }

                                focusProduct(products[targetIndex]);
                            }

                            function activateCurrentProduct(panel) {
                                const products = getProducts(panel);

                                if (!products.length) {
                                    return;
                                }

                                let current = document.activeElement;

                                if (!current?.matches?.(PRODUCT_SELECTOR)) {
                                    const savedIndex = Number(
                                        panel.dataset.selectedProductIndex ?? 0
                                    );

                                    current =
                                        products[
                                            Number.isFinite(savedIndex) ?
                                            Math.min(
                                                Math.max(savedIndex, 0),
                                                products.length - 1
                                            ) :
                                            0
                                        ];
                                }

                                if (current) {
                                    current.click();
                                }
                            }

                            document.addEventListener('focusin', function(event) {
                                const product = event.target?.closest?.(
                                    PRODUCT_SELECTOR
                                );

                                if (!product) {
                                    return;
                                }

                                const panel = product.closest(PANEL_SELECTOR);

                                if (panel) {
                                    panel.dataset.selectedProductIndex =
                                        product.dataset.productIndex ?? '0';
                                }
                            });

                            document.addEventListener('keydown', function(event) {
                                const key = event.key;

                                /*
                                 * لا نتدخل إطلاقاً في حقول السلة.
                                 * هذا يحافظ على ArrowUp/Down/Left/Right الموجودة
                                 * للكمية والسعر والإجمالي.
                                 */
                                if (event.target?.closest?.('[data-pos-field]')) {
                                    return;
                                }

                                /*
                                 * عندما يكون التركيز داخل لوحة المنتجات،
                                 * لا نسمح لأي نظام لوحة مفاتيح آخر في الصفحة
                                 * بالتقاط الأسهم بدلاً من المنتجات.
                                 */
                                const panelTarget = event.target?.closest?.(PANEL_SELECTOR);

                                /*
                                 * إذا كان التركيز على مساحة قائمة الأصناف نفسها،
                                 * أول ضغطة سهم تدخل إلى أول صنف بدلاً من تمرير الصفحة.
                                 */
                                if (panelTarget && !event.target?.closest?.(PRODUCT_SELECTOR)) {
                                    const products = getProducts(panelTarget);

                                    if (products.length && ['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(key)) {
                                        event.preventDefault();
                                        event.stopPropagation();
                                        event.stopImmediatePropagation();

                                        const savedIndex = Number(
                                            panelTarget.dataset.selectedProductIndex ?? 0
                                        );

                                        const startIndex = Number.isFinite(savedIndex) ?
                                            Math.min(Math.max(savedIndex, 0), products.length - 1) :
                                            0;

                                        focusProduct(products[startIndex]);
                                        return;
                                    }
                                }

                                const product = event.target?.closest?.(
                                    PRODUCT_SELECTOR
                                );

                                /*
                                 * عندما يكون التركيز على مربع بحث المنتجات:
                                 * ArrowDown يبدأ قائمة المنتجات.
                                 * باقي الأسهم تبقى طبيعية داخل حقل البحث.
                                 */
                                if (
                                    !product &&
                                    event.target?.matches?.(SEARCH_SELECTOR) &&
                                    key === 'ArrowDown'
                                ) {
                                    const panel = event.target.closest(PANEL_SELECTOR);
                                    const products = getProducts(panel);

                                    if (!products.length) {
                                        return;
                                    }

                                    event.preventDefault();
                                    event.stopPropagation();
                                    event.stopImmediatePropagation();

                                    focusProduct(products[0]);
                                    return;
                                }

                                if (!product) {
                                    return;
                                }

                                const panel = product.closest(PANEL_SELECTOR);

                                if (!panel) {
                                    return;
                                }

                                if (key === 'ArrowDown') {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    moveProduct(panel, 'down');
                                    return;
                                }

                                if (key === 'ArrowUp') {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    moveProduct(panel, 'up');
                                    return;
                                }

                                /*
                                 * داخل شبكة المنتجات:
                                 * ← / → يتحركان بين المنتجات في نفس الصف.
                                 * ↑ / ↓ يتحركان بين الصفوف.
                                 *
                                 * نستخدم موقع العنصر الحقيقي في الشبكة حتى يعمل
                                 * بشكل صحيح مع RTL ومع عمودين أو ثلاثة أعمدة.
                                 */
                                if (key === 'ArrowLeft' || key === 'ArrowRight') {
                                    const products = getProducts(panel);

                                    if (!products.length) {
                                        return;
                                    }

                                    const currentIndex = products.indexOf(product);

                                    if (currentIndex < 0) {
                                        return;
                                    }

                                    const currentRect =
                                        product.getBoundingClientRect();

                                    const sameRow = products
                                        .map((item, index) => ({
                                            item,
                                            index,
                                            rect: item.getBoundingClientRect(),
                                        }))
                                        .filter(({
                                                rect
                                            }) =>
                                            Math.abs(
                                                rect.top - currentRect.top
                                            ) <= 2
                                        )
                                        .sort((a, b) => a.rect.left - b.rect.left);

                                    const rowPosition = sameRow.findIndex(
                                        ({
                                            index
                                        }) => index === currentIndex
                                    );

                                    if (rowPosition < 0) {
                                        return;
                                    }

                                    /*
                                     * في RTL:
                                     * ArrowLeft  -> المنتج الموجود إلى اليسار.
                                     * ArrowRight -> المنتج الموجود إلى اليمين.
                                     */
                                    const step = key === 'ArrowLeft' ? -1 : 1;
                                    const targetPosition =
                                        rowPosition + step;

                                    if (
                                        targetPosition >= 0 &&
                                        targetPosition < sameRow.length
                                    ) {
                                        event.preventDefault();
                                        event.stopPropagation();

                                        focusProduct(
                                            sameRow[targetPosition].item
                                        );
                                    }

                                    return;
                                }

                                if (key === 'Enter') {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    activateCurrentProduct(panel);
                                    return;
                                }
                            }, true);

                            /*
                             * بعد إعادة رسم Livewire للمنتجات، نحافظ على آخر
                             * فهرس محدد قدر الإمكان، بدون لمس تركيز السلة.
                             */
                            document.addEventListener('livewire:navigated', function() {
                                const panel = getPanel();

                                if (!panel) {
                                    return;
                                }

                                const products = getProducts(panel);

                                if (!products.length) {
                                    return;
                                }

                                const index = Number(
                                    panel.dataset.selectedProductIndex ?? 0
                                );

                                if (
                                    Number.isFinite(index) &&
                                    products[index]
                                ) {
                                    panel.dataset.selectedProductIndex =
                                        String(
                                            Math.min(
                                                Math.max(index, 0),
                                                products.length - 1
                                            )
                                        );
                                }
                            });
                        })();
                    </script>

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

                                        // Enter من أي حقل داخل السلة يذهب مباشرة إلى الباركود.
                                        // الأسهم تبقى للتنقل بحرية بين الأصناف والحقول.
                                        target = document.querySelector(BARCODE_SELECTOR);

                                        if (target) {
                                            event.preventDefault();
                                            event.stopPropagation();
                                            focusInput(target);
                                            return;
                                        }

                                        return;
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

            {{-- =========================================================
                 نافذة الدفع داخل sales.blade.php
                 تظهر عند حفظ فاتورة مرتبطة بزبون، مع الحفاظ على
                 إمكانية تعديل الإجمالي/الصافي قبل الحفظ.
            ========================================================== --}}
            @if ($showCustomerPaymentModal)
                <div class="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/60 p-4"
                    wire:key="sales-customer-payment-modal">
                    <div class="w-full max-w-md overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl"
                        dir="rtl" @click.stop>
                        <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                            <div>
                                <div class="text-sm font-black text-slate-900">
                                    كم دفع الزبون؟
                                </div>
                                <div class="mt-0.5 text-[10px] font-bold text-slate-400">
                                    يمكنك تسجيل صفر، جزء من المبلغ، أو كامل الفاتورة
                                </div>
                            </div>

                            <button type="button" wire:click="$set('showCustomerPaymentModal', false)"
                                class="rounded-lg px-2 py-1 text-lg font-black text-slate-400 hover:bg-slate-100">
                                ×
                            </button>
                        </div>

                        <div class="p-4">
                            <div class="mb-3 grid grid-cols-2 gap-2">
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-center">
                                    <div class="text-[10px] font-black text-slate-500">إجمالي الفاتورة</div>
                                    <div class="mt-1 font-mono text-xl font-black text-slate-900">
                                        {{ number_format($this->amountDue, 2) }}
                                    </div>
                                </div>

                                <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-3 text-center">
                                    <div class="text-[10px] font-black text-indigo-600">المبلغ المدفوع</div>
                                    <div class="mt-1 font-mono text-xl font-black text-indigo-700">
                                        {{ number_format((float) $customerPaymentAmount, 2) }}
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3 grid grid-cols-3 gap-2">
                                <button type="button" wire:click="setCustomerPaymentAmount(0)"
                                    class="rounded-xl border border-slate-200 bg-white px-2 py-3 text-xs font-black text-slate-700 hover:border-slate-400 hover:bg-slate-50">
                                    لم يدفع
                                    <span class="mt-1 block font-mono text-[10px] text-slate-400">0.00</span>
                                </button>

                                <button type="button"
                                    wire:click="setCustomerPaymentAmount({{ $this->amountDue / 2 }})"
                                    class="rounded-xl border border-amber-200 bg-amber-50 px-2 py-3 text-xs font-black text-amber-800 hover:bg-amber-100">
                                    جزء
                                    <span class="mt-1 block text-[10px] text-amber-600">نصف المبلغ</span>
                                </button>

                                <button type="button" wire:click="setCustomerPaymentAmount({{ $this->amountDue }})"
                                    class="rounded-xl border border-emerald-200 bg-emerald-50 px-2 py-3 text-xs font-black text-emerald-800 hover:bg-emerald-100">
                                    كامل
                                    <span class="mt-1 block text-[10px] text-emerald-600">دفع كامل</span>
                                </button>
                            </div>

                            <label class="mb-1 block text-[10px] font-black text-slate-600">
                                أو أدخل المبلغ يدوياً
                            </label>

                            <input type="number" min="0" max="{{ $this->amountDue }}" step="0.01"
                                inputmode="decimal" wire:model.live.debounce.300ms="customerPaymentAmount"
                                class="h-12 w-full rounded-xl border-2 border-indigo-200 bg-white px-3 text-center font-mono text-xl font-black text-indigo-800 outline-none focus:border-indigo-500"
                                autofocus>

                            <div
                                class="mt-3 rounded-xl bg-slate-50 px-3 py-2 text-center text-[10px] font-bold text-slate-500">
                                المتبقي بعد الدفع:
                                <span class="font-mono font-black text-rose-600">
                                    {{ number_format(max(0, (float) $this->amountDue - (float) $customerPaymentAmount), 2) }}
                                </span>
                            </div>

                            <div class="mt-4 grid grid-cols-2 gap-2">
                                <button type="button" wire:click="$set('showCustomerPaymentModal', false)"
                                    class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs font-black text-slate-600 hover:bg-slate-50">
                                    إلغاء
                                </button>

                                <button type="button" wire:click="confirmCustomerPayment"
                                    class="rounded-xl bg-indigo-600 px-4 py-3 text-xs font-black text-white shadow-sm hover:bg-indigo-700">
                                    تأكيد وحفظ الفاتورة
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

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

<script>
    /* POS Sounds - بدون ملفات صوتية خارجية */
    (() => {
        let audioContext = null;

        const getAudioContext = () => {
            const AudioCtx = window.AudioContext || window.webkitAudioContext;
            if (!AudioCtx) return null;
            if (!audioContext) audioContext = new AudioCtx();
            if (audioContext.state === 'suspended') audioContext.resume().catch(() => {});
            return audioContext;
        };

        const beep = (frequency, duration, volume, wave = 'sine', delay = 0) => {
            const ctx = getAudioContext();
            if (!ctx) return;
            const start = ctx.currentTime + delay;
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            oscillator.type = wave;
            oscillator.frequency.setValueAtTime(frequency, start);
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(volume, start + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
            oscillator.connect(gain);
            gain.connect(ctx.destination);
            oscillator.start(start);
            oscillator.stop(start + duration + 0.02);
        };

        const playPosSound = (type) => {
            switch (type) {
                case 'success':
                    beep(880, 0.08, 0.07);
                    beep(1175, 0.10, 0.06, 'sine', 0.09);
                    break;
                case 'error':
                    beep(220, 0.16, 0.09, 'square');
                    beep(165, 0.20, 0.07, 'square', 0.13);
                    break;
                case 'save':
                    beep(660, 0.08, 0.06);
                    beep(990, 0.12, 0.06, 'sine', 0.10);
                    break;
                case 'save-edit':
                    beep(660, 0.07, 0.05);
                    beep(880, 0.07, 0.05, 'sine', 0.08);
                    beep(1175, 0.12, 0.06, 'sine', 0.16);
                    break;
                case 'delete':
                    beep(330, 0.08, 0.05, 'triangle');
                    beep(220, 0.10, 0.04, 'triangle', 0.08);
                    break;
                case 'shift-open':
                    beep(523, 0.09, 0.06);
                    beep(659, 0.09, 0.06, 'sine', 0.10);
                    beep(784, 0.14, 0.07, 'sine', 0.20);
                    break;
                case 'shift-close':
                    beep(784, 0.09, 0.06);
                    beep(659, 0.09, 0.06, 'sine', 0.10);
                    beep(523, 0.14, 0.07, 'sine', 0.20);
                    break;
                case 'warning':
                    beep(440, 0.10, 0.07, 'triangle');
                    beep(440, 0.10, 0.07, 'triangle', 0.14);
                    break;
            }
        };

        const bindPosSounds = () => {
            if (!window.Livewire || window.__posSoundsRegistered) return;
            window.__posSoundsRegistered = true;
            window.Livewire.on('pos-sound', (event) => {
                const type = event?.type ?? event?.detail?.type ?? 'success';
                playPosSound(type);
            });
        };

        if (window.Livewire) bindPosSounds();
        document.addEventListener('livewire:init', bindPosSounds, { once: true });

        const unlockAudio = () => {
            const ctx = getAudioContext();
            if (ctx?.state === 'suspended') ctx.resume().catch(() => {});
        };
        document.addEventListener('pointerdown', unlockAudio, { once: true });
        document.addEventListener('keydown', unlockAudio, { once: true });
    })();
</script>

<script>
    (function () {
        function registerPosInvoiceActions() {
            if (!window.Livewire || window.__posInvoiceActionsRegistered) {
                return;
            }

            window.__posInvoiceActionsRegistered = true;

            Livewire.on('whatsapp-invoice', function (event) {
                const phone = event?.phone ?? event?.detail?.phone ?? null;
                const message = event?.message ?? event?.detail?.message ?? '';

                if (!message) {
                    return;
                }

                const target = phone
                    ? 'https://wa.me/' + String(phone) + '?text=' + encodeURIComponent(message)
                    : 'https://wa.me/?text=' + encodeURIComponent(message);

                const popup = window.__posWhatsAppWindow;
                window.__posWhatsAppWindow = null;

                if (popup && !popup.closed) {
                    popup.location.href = target;
                    try {
                        popup.focus();
                    } catch (e) {}
                    return;
                }

                // fallback إذا منع المتصفح فتح نافذة جديدة.
                window.location.href = target;
            });
        }

        document.addEventListener('livewire:init', registerPosInvoiceActions);
        if (window.Livewire) {
            registerPosInvoiceActions();
        }
    })();
</script>
