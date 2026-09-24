<?php

use Livewire\Component;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\BranchProduct;
use App\Models\Branch;
use App\Models\Shift;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

new class extends Component {
    public array $receipt = [];
    public string $barcode = '';
    public array $cart = [];
    public $paid_amount = 0;
    public string $notes = '';
    public string $inlineSearchQuery = '';
    public array $inlineSearchResults = [];
    public string $searchInvoiceQuery = '';
    public string $productSearchQuery = '';
    public ?int $selectedBranchId = null;
    public $discount_amount = 0;
    public string $discount_type = 'fixed';
    public $custom_final_total = null;
    public $categories = [];
    public ?int $selectedCategoryId = null;
    public $quickProducts = [];
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
    public ?Shift $activeShift = null;
    public bool $showOpenShiftModal = false;
    public bool $showCloseShiftModal = false;
    public $opening_cash = 0;
    public $actual_cash = 0;
    public string $shift_notes = '';
    public float $shift_opening_cash = 0;
    public float $shift_total_sales = 0;
    public float $shift_total_returns = 0;
    public float $shift_expected_cash = 0;

    public function mount(): void
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();

        $this->selectedBranchId = $user?->branch_id ?: session('active_branch_id');

        if ($tenantId) {
            $this->categories = Category::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
        }

        $this->checkActiveShift();
        $this->loadQuickProducts();
    }

    private function tenantId(): ?int
    {
        $tenantId = session('active_tenant_id');
        return $tenantId ? (int) $tenantId : null;
    }

    private function getActiveBranchId(): ?int
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();

        if (!$tenantId || !$user) {
            return null;
        }

        $branchId = $user->branch_id ?: $this->selectedBranchId;
        if (!$branchId) {
            return null;
        }

        $valid = Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->exists();

        return $valid ? (int) $branchId : null;
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

        if ($branchId && $tenantId && Branch::where('tenant_id', $tenantId)->whereKey($branchId)->exists()) {
            session(['active_branch_id' => $branchId]);
            $this->selectedBranchId = $branchId;
            $this->checkActiveShift();
            $this->loadQuickProducts();
            return;
        }

        $this->selectedBranchId = null;
        session()->forget('active_branch_id');
        $this->activeShift = null;
        $this->quickProducts = [];
        $this->errorMessage = 'الفرع المحدد غير صالح لهذا المتجر.';
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
            ->where(function ($query) use ($search, $tenantId) {
                $query->where('name', 'like', "%{$search}%")->orWhereHas('barcodes', function ($barcodeQuery) use ($search, $tenantId) {
                    $barcodeQuery->where('tenant_id', $tenantId)->where('barcode', 'like', "%{$search}%");
                });
            })
            ->with([
                'barcodes' => fn($q) => $q->where('tenant_id', $tenantId),
                'branchProducts' => fn($q) => $q->where('branch_id', $branchId),
            ])
            ->limit(7)
            ->get()
            ->map(function (Product $product) {
                $branchProduct = $product->branchProducts->first();
                $product->setAttribute('retail_price', (float) ($branchProduct?->retail_price ?? 0));
                $product->setAttribute('stock_quantity', (float) ($branchProduct?->quantity ?? 0));
                $product->setAttribute('barcode_value', $product->barcodes->first()?->barcode);
                return $product;
            })
            ->toArray();
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
            $query->where('category_id', (int) $this->selectedCategoryId);
        }

        $search = trim($this->productSearchQuery);
        if ($search !== '') {
            $query->where(function ($q) use ($search, $tenantId) {
                $q->where('name', 'like', "%{$search}%")->orWhereHas('barcodes', function ($barcodeQuery) use ($search, $tenantId) {
                    $barcodeQuery->where('tenant_id', $tenantId)->where('barcode', 'like', "%{$search}%");
                });
            });
        }

        $this->quickProducts = $query
            ->orderBy('name')
            ->limit(30)
            ->get()
            ->map(function (Product $product) {
                $branchProduct = $product->branchProducts->first();
                $product->setAttribute('retail_price', (float) ($branchProduct?->retail_price ?? 0));
                $product->setAttribute('stock_quantity', (float) ($branchProduct?->quantity ?? 0));
                $product->setAttribute('barcode_value', $product->barcodes->first()?->barcode);
                return $product;
            });
    }

    public function checkActiveShift(): void
    {
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();
        $userId = Auth::id();

        if (!$tenantId || !$branchId || !$userId) {
            $this->activeShift = null;
            return;
        }

        $this->activeShift = Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('opened_by', $userId)->where('status', 'open')->latest('id')->first();
    }

    public function triggerOpenShiftModal(): void
    {
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
            $this->errorMessage = 'يرجى تحديد المتجر وتسجيل الدخول أولاً.';
            return;
        }

        if (!$branchId) {
            $this->errorMessage = 'يرجى اختيار الفرع أولاً لفتح الشيفت.';
            return;
        }

        $openingCash = max(0, (float) $this->opening_cash);

        $existing = Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('opened_by', $user->id)->where('status', 'open')->exists();

        if ($existing) {
            $this->checkActiveShift();
            $this->showOpenShiftModal = false;
            $this->errorMessage = 'يوجد شيفت مفتوح بالفعل لهذا المستخدم في هذا الفرع.';
            return;
        }

        $this->activeShift = Shift::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'opening_cash' => $openingCash,
            'status' => 'open',
            'opened_at' => now(),
            'opened_by' => $user->id,
            'notes' => $this->shift_notes ?: null,
        ]);

        $this->showOpenShiftModal = false;
        $this->successMessage = 'تم فتح الشيفت بنجاح، يمكنك بدء العمل الآن.';
    }

    public function prepareCloseShift(): void
    {
        if (!$this->activeShift) {
            $this->checkActiveShift();
        }

        if (!$this->activeShift) {
            $this->errorMessage = 'لا يوجد شيفت مفتوح لإغلاقه.';
            return;
        }

        $sales = (float) Order::query()->where('tenant_id', $this->tenantId())->where('shift_id', $this->activeShift->id)->where('type', 'pos')->sum('total');

        $returns = (float) Order::query()->where('tenant_id', $this->tenantId())->where('shift_id', $this->activeShift->id)->where('type', 'return')->sum('total');

        $this->shift_opening_cash = (float) $this->activeShift->opening_cash;
        $this->shift_total_sales = $sales;
        $this->shift_total_returns = abs($returns);
        $this->shift_expected_cash = $this->shift_opening_cash + $this->shift_total_sales - $this->shift_total_returns;
        $this->actual_cash = $this->shift_expected_cash;
        $this->showCloseShiftModal = true;
    }

    public function closeShift(): void
    {
        if (!$this->activeShift) {
            return;
        }

        $expected = (float) $this->shift_expected_cash;
        $actual = (float) $this->actual_cash;

        $this->activeShift->update([
            'expected_cash' => $expected,
            'actual_cash' => $actual,
            'difference' => $actual - $expected,
            'notes' => $this->shift_notes ?: null,
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => Auth::id(),
            'total_sales' => $this->shift_total_sales,
            'total_returns' => $this->shift_total_returns,
        ]);

        $this->activeShift = null;
        $this->showCloseShiftModal = false;
        $this->showOpenShiftModal = false;
        $this->clearCartState(false);
        $this->successMessage = 'تم إغلاق الشيفت وتسوية الصندوق بنجاح.';
    }

    public function toggleReturnMode(): void
    {
        if (!empty($this->cart)) {
            $this->errorMessage = 'قم بإنهاء أو تنظيف الفاتورة الحالية قبل تغيير وضع البيع/المرتجع.';
            return;
        }

        $this->isReturnMode = !$this->isReturnMode;
    }

    public function openCostModal(): void
    {
        if (empty($this->cart)) {
            $this->errorMessage = 'السلة فارغة، لا توجد أصناف للاستطلاع.';
            return;
        }

        $this->showCostModal = true;
    }

    public function holdInvoice(): void
    {
        if (empty($this->cart)) {
            $this->errorMessage = 'لا يمكن تعليق فاتورة فارغة.';
            return;
        }

        $this->heldInvoices[] = [
            'id' => count($this->heldInvoices) + 1,
            'cart' => $this->cart,
            'paid_amount' => $this->paid_amount,
            'discount_amount' => $this->discount_amount,
            'discount_type' => $this->discount_type,
            'custom_final_total' => $this->custom_final_total,
            'is_return_mode' => $this->isReturnMode,
            'notes' => $this->notes,
            'time' => now()->format('H:i:s Y-m-d'),
            'total' => $this->total,
        ];

        $this->clearCartState();
        $this->successMessage = 'تم تعليق الفاتورة بنجاح.';
    }

    public function restoreHeldInvoice(int $index): void
    {
        if (!isset($this->heldInvoices[$index])) {
            return;
        }

        $held = $this->heldInvoices[$index];
        $this->cart = $held['cart'];
        $this->paid_amount = $held['paid_amount'];
        $this->discount_amount = $held['discount_amount'] ?? 0;
        $this->discount_type = $held['discount_type'] ?? 'fixed';
        $this->custom_final_total = $held['custom_final_total'] ?? null;
        $this->isReturnMode = $held['is_return_mode'] ?? false;
        $this->notes = $held['notes'] ?? '';
        $this->currentInvoiceId = null;

        unset($this->heldInvoices[$index]);
        $this->heldInvoices = array_values($this->heldInvoices);
        $this->showHeldModal = false;
        $this->errorMessage = null;
        $this->successMessage = 'تم استرجاع الفاتورة المعلقة بنجاح.';
        $this->recalculatePrices();
    }

    public function removeHeldInvoice(int $index): void
    {
        if (isset($this->heldInvoices[$index])) {
            unset($this->heldInvoices[$index]);
            $this->heldInvoices = array_values($this->heldInvoices);
        }
    }

    public function searchInvoice(): void
    {
        $query = trim($this->searchInvoiceQuery);
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        if ($query === '') {
            $this->errorMessage = 'يرجى إدخال رقم الفاتورة للبحث.';
            return;
        }

        if (!$tenantId || !$branchId) {
            $this->errorMessage = 'تعذر تحديد المتجر أو الفرع.';
            return;
        }

        $digitsOnly = preg_replace('/\D/', '', $query);

        $invoice = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['pos', 'return'])
            ->where(function ($q) use ($query, $digitsOnly) {
                $q->where('invoice_number', $query)
                    ->orWhere('invoice_number', 'like', "%{$query}%")
                    ->orWhere('id', is_numeric($query) ? (int) $query : 0);

                if ($digitsOnly !== '') {
                    $q->orWhere('invoice_number', 'like', "%{$digitsOnly}%");
                }
            })
            ->latest('id')
            ->first();

        if (!$invoice) {
            $this->errorMessage = "لم يتم العثور على فاتورة مطابقة للبحث: {$query}";
            return;
        }

        $this->loadInvoice($invoice->id);
        $this->searchInvoiceQuery = '';
    }

    public function loadInvoice(int $invoiceId): void
    {
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

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

        $this->currentInvoiceId = $invoice->id;
        $this->cart = [];

        foreach ($invoice->items as $item) {
            $this->cart[$item->product_id] = [
                'id' => $item->product_id,
                'name' => $item->product?->name ?? 'منتج غير محدد',
                'barcode' => '',
                'price' => (float) $item->unit_price,
                'cost_price' => (float) ($item->cost_price ?? ($item->product?->cost_price ?? 0)),
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->total_price,
                'has_offer' => false,
            ];
        }

        $this->paid_amount = (float) $invoice->paid_amount;
        $this->discount_amount = (float) ($invoice->discount ?? 0);
        $this->discount_type = 'fixed';
        $this->custom_final_total = null;
        $this->notes = $invoice->notes ?? '';
        $this->isReturnMode = $invoice->type === 'return';
        $this->errorMessage = null;
        $this->successMessage = "تم عرض الفاتورة رقم #{$invoice->id} ({$invoice->invoice_number}).";
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

        $this->errorMessage = 'وصلت إلى أول فاتورة، لا توجد فاتورة سابقة.';
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

        $this->clearCart();
        $this->successMessage = 'تم الانتقال إلى واجهة فاتورة جديدة.';
    }

    public function scanBarcode(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;
        $barcode = trim($this->barcode);

        if ($barcode === '') {
            return;
        }

        if (!$this->activeShift) {
            $this->errorMessage = 'يرجى فتح شيفت أولاً قبل مسح المنتجات.';
            $this->showOpenShiftModal = true;
            $this->barcode = '';
            return;
        }

        $tenantId = $this->tenantId();
        $record = ProductBarcode::query()->where('tenant_id', $tenantId)->where('barcode', $barcode)->with('product')->first();

        if (!$record?->product) {
            $this->errorMessage = "عذراً، لم يتم العثور على منتج بالباركود: {$barcode}";
            $this->barcode = '';
            return;
        }

        $this->addToCart((int) $record->product_id, $barcode);
        $this->barcode = '';
    }

    public function addToCart(int $productId, string $scannedBarcode = ''): void
    {
        if (!$this->activeShift) {
            $this->errorMessage = 'يرجى فتح شيفت أولاً لإضافة المنتجات.';
            $this->showOpenShiftModal = true;
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        if (!$tenantId || !$branchId) {
            $this->errorMessage = 'يرجى تحديد المتجر والفرع أولاً.';
            return;
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->with([
                'barcodes' => fn($q) => $q->where('tenant_id', $tenantId),
            ])
            ->find($productId);

        if (!$product) {
            $this->errorMessage = 'المنتج المحدد غير صالح لهذا المتجر.';
            return;
        }

        $branchProduct = BranchProduct::query()->where('branch_id', $branchId)->where('product_id', $product->id)->first();

        if (!$branchProduct) {
            $this->errorMessage = 'المنتج غير مرتبط بالفرع الحالي.';
            return;
        }

        $this->showProductsModal = false;
        $this->currentInvoiceId = null;

        $retailPrice = (float) $branchProduct->retail_price;
        $changeQty = $this->isReturnMode ? -1 : 1;
        $productId = (int) $product->id;

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity'] += $changeQty;

            if ((int) $this->cart[$productId]['quantity'] === 0) {
                unset($this->cart[$productId]);
            }
        } else {
            $this->cart[$productId] = [
                'id' => $productId,
                'name' => $product->name,
                'barcode' => $scannedBarcode ?: $product->barcodes->first()?->barcode ?? '',
                'price' => $retailPrice,
                'cost_price' => (float) ($product->cost_price ?? 0),
                'quantity' => $changeQty,
                'subtotal' => $retailPrice * $changeQty,
                'has_offer' => false,
            ];
        }

        if (empty($this->cart)) {
            $this->clearCartState();
            return;
        }

        $this->recalculatePrices();
        $this->loadQuickProducts();
    }

    public function updateUnitPrice(int $productId, $newPrice): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $price = max(0, (float) $newPrice);
        $this->cart[$productId]['price'] = $price;
        $this->recalculatePrices();
    }

    public function updateCostPrice(int $productId, $newCost): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $cost = max(0, (float) $newCost);
        $this->cart[$productId]['cost_price'] = $cost;
        $this->recalculatePrices();
    }

    public function updateQuantity(int $productId, $qty): void
    {
        if (!isset($this->cart[$productId])) {
            return;
        }

        $qty = (int) $qty;
        if ($qty === 0) {
            $this->removeFromCart($productId);
            return;
        }

        $this->cart[$productId]['quantity'] = $qty;
        $this->recalculatePrices();
    }

    public function removeFromCart(int $productId): void
    {
        unset($this->cart[$productId]);

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
        $this->successMessage = 'تم تنظيف محتويات الفاتورة بنجاح.';
    }

    private function clearCartState(bool $reloadProducts = true): void
    {
        $this->cart = [];
        $this->receipt = [];
        $this->paid_amount = 0;
        $this->discount_amount = 0;
        $this->discount_type = 'fixed';
        $this->custom_final_total = null;
        $this->currentInvoiceId = null;
        $this->isReturnMode = false;
        $this->notes = '';
        $this->searchInvoiceQuery = '';
        $this->productSearchQuery = '';
        $this->inlineSearchQuery = '';
        $this->inlineSearchResults = [];
        $this->showBelowCostModal = false;

        if ($reloadProducts) {
            $user = Auth::user();
            $this->selectedBranchId = $user?->branch_id ?: session('active_branch_id');
            $this->loadQuickProducts();
        }
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

        $this->paid_amount = (float) ($current . $value);
    }

    private function roundUpToNearestHalf(float $amount): float
    {
        $sign = $amount < 0 ? -1 : 1;
        return $sign * (ceil(abs($amount) * 2) / 2);
    }

    private function recalculatePrices(): void
    {
        foreach ($this->cart as $id => $item) {
            $this->cart[$id]['subtotal'] = $this->roundUpToNearestHalf((float) $item['quantity'] * (float) $item['price']);
        }
    }

    public function updatedDiscountAmount(): void
    {
        $this->custom_final_total = null;
    }

    public function updatedDiscountType(): void
    {
        $this->custom_final_total = null;
    }

    public function toggleDiscountType(): void
    {
        $this->discount_type = $this->discount_type === 'fixed' ? 'percentage' : 'fixed';
        $this->custom_final_total = null;
    }

    public function updatedCustomFinalTotal($value): void
    {
        if ($value === '' || $value === null) {
            $this->custom_final_total = null;
            return;
        }

        $target = (float) $value;
        $subtotal = $this->subtotal;

        if ($subtotal > 0 && $target >= 0 && $target <= $subtotal) {
            $this->discount_type = 'fixed';
            $this->discount_amount = $subtotal - $target;
        }
    }

    public function getSubtotalProperty(): float
    {
        return empty($this->cart) ? 0.0 : (float) array_sum(array_column($this->cart, 'subtotal'));
    }

    public function getTotalCostProperty(): float
    {
        $total = 0.0;
        foreach ($this->cart as $item) {
            $total += (float) ($item['cost_price'] ?? 0) * (int) $item['quantity'];
        }
        return $total;
    }

    public function getExpectedProfitProperty(): float
    {
        return empty($this->cart) ? 0.0 : $this->total - $this->total_cost;
    }

    public function getCalculatedDiscountProperty(): float
    {
        if (empty($this->cart) || $this->subtotal <= 0) {
            return 0.0;
        }

        $discount = max(0, (float) ($this->discount_amount ?? 0));

        if ($this->discount_type === 'percentage') {
            return ($this->subtotal * min(100, $discount)) / 100;
        }

        return min($this->subtotal, $discount);
    }

    public function getTotalProperty(): float
    {
        if (empty($this->cart)) {
            return 0.0;
        }

        if ($this->custom_final_total !== null && $this->custom_final_total !== '') {
            return (float) $this->custom_final_total;
        }

        $subtotal = $this->subtotal;
        $discount = $this->calculated_discount;

        if ($subtotal < 0) {
            return $subtotal;
        }

        return max(0, $subtotal - $discount);
    }

    public function getChangeProperty(): float
    {
        if (empty($this->cart)) {
            return 0.0;
        }

        return (float) $this->paid_amount - $this->total;
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

        if (!$this->activeShift) {
            $this->errorMessage = 'لا يمكنك إجراء عملية بيع بدون فتح شيفت أولاً.';
            $this->showOpenShiftModal = true;
            return null;
        }

        if (empty($this->cart)) {
            $this->errorMessage = 'الفاتورة فارغة حالياً.';
            return null;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();
        $user = Auth::user();

        if (!$tenantId || !$branchId || !$user) {
            $this->errorMessage = 'تعذر تحديد المتجر أو الفرع أو المستخدم.';
            return null;
        }

        $shiftId = $this->activeShift->id;
        $invoiceType = $this->total < 0 ? 'return' : 'pos';
        $invoiceNumber = $this->makeInvoiceNumber($invoiceType);

        try {
            $order = DB::transaction(function () use ($tenantId, $branchId, $user, $shiftId, $invoiceType, $invoiceNumber) {
                $validatedItems = [];
                $subtotal = 0.0;

                foreach ($this->cart as $rawItem) {
                    $productId = (int) ($rawItem['id'] ?? 0);
                    $quantity = (int) ($rawItem['quantity'] ?? 0);
                    $price = max(0, (float) ($rawItem['price'] ?? 0));

                    if (!$productId || $quantity === 0) {
                        throw new \RuntimeException('يوجد صنف أو كمية غير صالحة في الفاتورة.');
                    }

                    $product = Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->first();

                    if (!$product) {
                        throw new \RuntimeException('أحد المنتجات لم يعد متاحاً في هذا المتجر.');
                    }

                    $branchProduct = BranchProduct::query()->where('branch_id', $branchId)->where('product_id', $productId)->lockForUpdate()->first();

                    if (!$branchProduct) {
                        throw new \RuntimeException("المنتج {$product->name} غير مرتبط بالفرع الحالي.");
                    }

                    $costPrice = (float) ($product->cost_price ?? 0);
                    $lineTotal = $this->roundUpToNearestHalf($price * $quantity);
                    $subtotal += $lineTotal;

                    if ($quantity > 0) {
                        $available = (float) ($branchProduct->quantity ?? 0);
                        if ($available < $quantity) {
                            throw new \RuntimeException("الكمية المتوفرة من المنتج {$product->name} غير كافية.");
                        }
                    }

                    $validatedItems[] = [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price' => $price,
                        'cost_price' => $costPrice,
                        'total_price' => $lineTotal,
                    ];
                }

                $discount = $subtotal > 0 ? min($subtotal, max(0, (float) $this->calculated_discount)) : 0.0;
                $total = $subtotal < 0 ? $subtotal : max(0, $subtotal - $discount);

                if ($this->custom_final_total !== null && $this->custom_final_total !== '') {
                    $customTotal = (float) $this->custom_final_total;
                    if ($subtotal >= 0 && $customTotal >= 0 && $customTotal <= $subtotal) {
                        $total = $customTotal;
                        $discount = $subtotal - $customTotal;
                    }
                }

                $paid = (float) $this->paid_amount;
                if ($paid == 0.0 && $total >= 0) {
                    $paid = $total;
                }

                $order = Order::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'shift_id' => $shiftId,
                    'user_id' => $user->id,
                    'customer_id' => null,
                    'invoice_number' => $invoiceNumber,
                    'type' => $invoiceType,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'total' => $total,
                    'paid_amount' => $paid,
                    'payment_status' => 'paid',
                    'notes' => $this->notes ?: null,
                    'created_at' => now(),
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

                    $branchQuery = BranchProduct::query()->where('branch_id', $branchId)->where('product_id', $item['product_id']);

                    if ($item['quantity'] > 0) {
                        $branchQuery->decrement('quantity', $item['quantity']);
                    } else {
                        $branchQuery->increment('quantity', abs($item['quantity']));
                    }
                }

                return $order;
            });


            $this->clearCartState();
            $this->checkActiveShift();
            $this->loadQuickProducts();

            $this->successMessage = $invoiceType === 'return' ? 'تمت عملية الإرجاع وحفظ الفاتورة بنجاح.' : 'تمت عملية البيع وحفظ الفاتورة بنجاح.';

            return $order;
        } catch (\Throwable $e) {
            Log::error('POS checkout failed', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),

            ]);

            $this->errorMessage = $e->getMessage();//'تعذر حفظ الفاتورة. يرجى المحاولة مرة أخرى.';
            return null;
        }
    }

    private function makeInvoiceNumber(string $type): string
    {
        $prefix = $type === 'return' ? 'RET-' : 'POS-';
        return $prefix . now()->format('YmdHis') . '-' . random_int(100, 999);
    }

    private function prepareReceiptFromOrder(Order $order): void
    {
        $order->loadMissing('items.product');

        $items = [];
        foreach ($order->items as $index => $item) {
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
            'store_name' => 'فانوس',
            'notice' => 'راجع فاتورتك وتأكد من مشترياتك قبل مغادرة المعرض',
            'copy_type' => $order->type === 'return' ? 'فاتورة مرتجع' : 'النسخة الأصلية',
            'invoice_no' => (string) $order->invoice_number,
            'date' => $createdAt->format('Y/m/d'),
            'time' => $createdAt->format('h:i A'),
            'items' => $items,
            'total_qty' => array_sum(array_map(fn($item) => (int) $item['quantity'], $order->items->toArray())),
            'total_amount' => number_format((float) $order->subtotal, 2),
            'net_amount' => number_format((float) $order->total, 2),
            'currency' => 'ش.ض',
            'system_name' => 'الشامل لايت للمحاسبة',
        ];
    }

    public function printReceipt(): void
    {
        if ($this->currentInvoiceId) {
            $tenantId = $this->tenantId();
            $branchId = $this->getActiveBranchId();
            $order = Order::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->whereKey($this->currentInvoiceId)->with('items.product')->first();

            if ($order) {
                $this->prepareReceiptFromOrder($order);
                $this->dispatch('print-receipt');
                return;
            }
        }

        if (empty($this->receipt)) {
            $this->errorMessage = 'لا توجد فاتورة محفوظة لطباعتها.';
            return;
        }

        $this->dispatch('print-receipt');
    }

    public function getInvoiceCreatorProperty(): string
    {
        if ($this->currentInvoiceId) {
            $invoice = Order::query()->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())->with('user')->find($this->currentInvoiceId);

            return $invoice?->user?->name ?? 'غير محدد';
        }

        return Auth::user()?->name ?? 'الكاشير الحالي';
    }

    public function getInvoiceDateProperty(): string
    {
        if ($this->currentInvoiceId) {
            $invoice = Order::query()->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())->find($this->currentInvoiceId);

            if ($invoice?->created_at) {
                return $invoice->created_at->locale('ar')->isoFormat('dddd، YYYY-MM-DD - h:mm A');
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
            $branches = Branch::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
        }

        return $this->view([
            'branches' => $branches,
        ])->layout('layouts::pos');
    }
};
?>


<flux:main class="h-[calc(100vh-4rem)] p-2 bg-slate-100 font-sans select-none overflow-hidden">
    <div x-data x-on:keydown.window.f1.prevent="$wire.set('showHeldModal', !$wire.showHeldModal)"
        x-on:keydown.window.f2.prevent="$wire.holdInvoice()" x-on:keydown.window.f3.prevent="$wire.checkout()"
        x-on:keydown.window.f6.prevent="$wire.checkoutAndPrint()" x-on:keydown.window.f4.prevent="$wire.clearCart()"
        x-on:keydown.window.f10.prevent="$wire.set('showProductsModal', !$wire.showProductsModal)" class="h-full">
        <div class="grid grid-cols-12 gap-2 h-full">


            <!-- ==================== قسم الفاتورة والحسابات (على اليسار) ==================== -->
            <div class="col-span-12 lg:col-span-12 flex flex-col h-full space-y-2 min-h-0">
            @include('pages.tenant.pos.partials.toolbar')

                @if ($errorMessage)
                    <div
                        class="bg-rose-50 border border-rose-200 text-rose-700 p-2 rounded-lg text-xs font-semibold flex items-center justify-between">
                        <span>{{ $errorMessage }}</span>
                        <button wire:click="$set('errorMessage', null)" class="text-rose-500 font-bold">✕</button>
                    </div>
                @endif
                @if ($successMessage)
                    <div
                        class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-2 rounded-lg text-xs font-semibold flex items-center justify-between">
                        <span>{{ $successMessage }}</span>
                        <button wire:click="$set('successMessage', null)" class="text-emerald-600 font-bold">✕</button>
                    </div>
                @endif

                @if ($this->has_below_cost_item)
                    <div
                        class="bg-rose-100 border-l-4 border-rose-600 text-rose-900 p-2 rounded-lg text-xs font-bold flex items-center justify-between shadow-sm animate-pulse">
                        <div class="flex items-center gap-2">
                            <span class="text-sm">⚠️</span>
                            <span>تنبيه: يوجد منتج (أو أكثر) بسعر بيع أقل من سعر التكلفة!</span>
                        </div>
                    </div>
                @endif


                @include('pages.tenant.pos.partials.cart')

                @include('pages.tenant.pos.partials.payment')

            </div>
        </div>
        @include('pages.tenant.pos.partials.products-modal')
        @include('pages.tenant.pos.partials.shift-open-modal')
        @include('pages.tenant.pos.partials.shift-close-modal')
        @include('pages.tenant.pos.partials.held-invoices-modal')
        @include('pages.tenant.pos.partials.cost-modal')
        @include('pages.tenant.pos.partials.below-cost-modal')
    </div>
    <!-- ==================== قالب الفاتورة الحرارية للطباعة المباشرة ==================== -->
    @include('pages.tenant.pos.partials.thermal-receipt')
</flux:main>
<style>
    @media print {

        /* إخفاء كل عناصر الواجهة والشاشة */
        body * {
            visibility: hidden !important;
        }

        /* إظهار الفاتورة الحرارية فقط */
        #thermal-receipt,
        #thermal-receipt * {
            visibility: visible !important;
        }

        #thermal-receipt {
            display: block !important;
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 80mm !important;
            /* عرض ورقة طابعة الفواتير الحرارية */
            margin: 0 !important;
            padding: 2mm !important;
        }

        @page {
            size: 80mm auto;
            margin: 0;
        }
    }
</style>

<!-- إطار مخفي للطباعة الفورية -->

<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('print-receipt', () => {
            // التقاط محتوى الفاتورة من قالب thermal-receipt
            const receiptElement = document.getElementById('thermal-receipt');
            if (!receiptElement) return;

            const receiptHtml = receiptElement.innerHTML;
            const printFrame = document.getElementById('silent-print-frame');
            const frameDoc = printFrame.contentWindow.document;

            frameDoc.open();
            frameDoc.write(`
                <html>
                <head>
                    <title>Print Receipt</title>
                    <style>
                        body {
                            font-family: monospace;
                            width: 80mm;
                            margin: 0;
                            padding: 2mm;
                            font-size: 11px;
                            color: #000;
                        }
                        .text-center { text-align: center; }
                        .font-bold { font-weight: bold; }
                        .font-black { font-weight: 900; }
                        .flex { display: flex; }
                        .justify-between { justify-content: space-between; }
                        .border-b { border-bottom: 1px solid #000; }
                        .border-t { border-top: 1px solid #000; }
                        .border-dashed { border-style: dashed; }
                        .my-1 { margin-top: 4px; margin-bottom: 4px; }
                        .my-2 { margin-top: 8px; margin-bottom: 8px; }
                        .py-1 { padding-top: 4px; padding-bottom: 4px; }
                        .pt-1 { padding-top: 4px; }
                        .mt-1 { margin-top: 4px; }
                        .mt-4 { margin-top: 16px; }
                        table { width: 100%; border-collapse: collapse; text-align: right; }
                        th, td { padding: 2px 0; }
                        @page { size: 80mm auto; margin: 0; }
                    </style>
                </head>
                <body>
                    ${receiptHtml}
                </body>
                </html>
            `);
            frameDoc.close();

            // إعطاء أمر الطباعة المباشر
            setTimeout(() => {
                printFrame.contentWindow.focus();
                printFrame.contentWindow.print();
            }, 150);
        });
    });
</script>
