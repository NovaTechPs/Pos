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
            $this->categories = Category::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get();
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

        $valid = Branch::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($branchId)
            ->exists();

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
                $query->where('name', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', function ($barcodeQuery) use ($search, $tenantId) {
                        $barcodeQuery->where('tenant_id', $tenantId)
                            ->where('barcode', 'like', "%{$search}%");
                    });
            })
            ->with([
                'barcodes' => fn ($q) => $q->where('tenant_id', $tenantId),
                'branchProducts' => fn ($q) => $q->where('branch_id', $branchId),
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
                'barcodes' => fn ($q) => $q->where('tenant_id', $tenantId),
                'branchProducts' => fn ($q) => $q->where('branch_id', $branchId),
            ]);

        if ($this->selectedCategoryId) {
            $query->where('category_id', (int) $this->selectedCategoryId);
        }

        $search = trim($this->productSearchQuery);
        if ($search !== '') {
            $query->where(function ($q) use ($search, $tenantId) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', function ($barcodeQuery) use ($search, $tenantId) {
                        $barcodeQuery->where('tenant_id', $tenantId)
                            ->where('barcode', 'like', "%{$search}%");
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

        $this->activeShift = Shift::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('opened_by', $userId)
            ->where('status', 'open')
            ->latest('id')
            ->first();
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

        $existing = Shift::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->where('opened_by', $user->id)
            ->where('status', 'open')
            ->exists();

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

        $sales = (float) Order::query()
            ->where('tenant_id', $this->tenantId())
            ->where('shift_id', $this->activeShift->id)
            ->where('type', 'pos')
            ->sum('total');

        $returns = (float) Order::query()
            ->where('tenant_id', $this->tenantId())
            ->where('shift_id', $this->activeShift->id)
            ->where('type', 'return')
            ->sum('total');

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
                'cost_price' => (float) ($item->cost_price ?? $item->product?->cost_price ?? 0),
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
        $previous = $this->invoiceNavigationQuery()
            ->when($this->currentInvoiceId, fn ($q) => $q->where('id', '<', $this->currentInvoiceId))
            ->latest('id')
            ->first();

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

        $next = $this->invoiceNavigationQuery()
            ->where('id', '>', $this->currentInvoiceId)
            ->oldest('id')
            ->first();

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
        $record = ProductBarcode::query()
            ->where('tenant_id', $tenantId)
            ->where('barcode', $barcode)
            ->with('product')
            ->first();

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
                'barcodes' => fn ($q) => $q->where('tenant_id', $tenantId),
            ])
            ->find($productId);

        if (!$product) {
            $this->errorMessage = 'المنتج المحدد غير صالح لهذا المتجر.';
            return;
        }

        $branchProduct = BranchProduct::query()
            ->where('branch_id', $branchId)
            ->where('product_id', $product->id)
            ->first();

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
                'barcode' => $scannedBarcode ?: ($product->barcodes->first()?->barcode ?? ''),
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
            $this->cart[$id]['subtotal'] = $this->roundUpToNearestHalf(
                (float) $item['quantity'] * (float) $item['price']
            );
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

                    $product = Product::query()
                        ->where('tenant_id', $tenantId)
                        ->whereKey($productId)
                        ->first();

                    if (!$product) {
                        throw new \RuntimeException('أحد المنتجات لم يعد متاحاً في هذا المتجر.');
                    }

                    $branchProduct = BranchProduct::query()
                        ->where('branch_id', $branchId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

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

                    $branchQuery = BranchProduct::query()
                        ->where('branch_id', $branchId)
                        ->where('product_id', $item['product_id']);

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

            $this->successMessage = $invoiceType === 'return'
                ? 'تمت عملية الإرجاع وحفظ الفاتورة بنجاح.'
                : 'تمت عملية البيع وحفظ الفاتورة بنجاح.';

            return $order;
        } catch (\Throwable $e) {
            Log::error('POS checkout failed', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            $this->errorMessage = 'تعذر حفظ الفاتورة. يرجى المحاولة مرة أخرى.';
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
            'total_qty' => array_sum(array_map(fn ($item) => (int) $item['quantity'], $order->items->toArray())),
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
            $order = Order::query()
                ->where('tenant_id', $tenantId)
                ->where('branch_id', $branchId)
                ->whereKey($this->currentInvoiceId)
                ->with('items.product')
                ->first();

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
            $invoice = Order::query()
                ->where('tenant_id', $this->tenantId())
                ->where('branch_id', $this->getActiveBranchId())
                ->with('user')
                ->find($this->currentInvoiceId);

            return $invoice?->user?->name ?? 'غير محدد';
        }

        return Auth::user()?->name ?? 'الكاشير الحالي';
    }

    public function getInvoiceDateProperty(): string
    {
        if ($this->currentInvoiceId) {
            $invoice = Order::query()
                ->where('tenant_id', $this->tenantId())
                ->where('branch_id', $this->getActiveBranchId())
                ->find($this->currentInvoiceId);

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
            $branches = Branch::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get();
        }

        return $this->view([
            'branches' => $branches,
        ])->layout('layouts::tenant');
    }
};
?>


<flux:main class="h-[calc(100vh-4rem)] p-2 bg-slate-100 font-sans select-none overflow-hidden">
    <div x-data x-on:keydown.window.f1.prevent="$wire.set('showHeldModal', !$wire.showHeldModal)"
        x-on:keydown.window.f2.prevent="$wire.holdInvoice()" x-on:keydown.window.f3.prevent="$wire.checkout()"
        x-on:keydown.window.f6.prevent="$wire.checkoutAndPrint()" x-on:keydown.window.f4.prevent="$wire.clearCart()"
        x-on:keydown.window.f10.prevent="$wire.set('showProductsModal', !$wire.showProductsModal)" class="h-full">
        <div class="grid grid-cols-12 gap-2 h-full">

            <!-- ==================== قسم الأصناف والأقسام (على اليمين) ==================== -->
            {{-- <div class="col-span-12 lg:col-span-5 flex flex-col h-full bg-white border border-slate-300 rounded-xl p-2.5 shadow-sm min-h-0 space-y-2">

                <!-- حقل المباشرة لمسح الباركود بالأجهزة -->
                <form wire:submit.prevent="scanBarcode" class="shrink-0">
                    <input wire:model="barcode"
                        placeholder="{{ $isReturnMode ? 'امسح الباركود لإرجاعه...' : 'امسح الباركود هنا لإضافته المباشرة...' }}"
                        autofocus
                        class="w-full bg-slate-50 border {{ $isReturnMode ? 'border-rose-400 focus:outline-rose-600' : 'border-slate-300 focus:outline-indigo-600' }} rounded-lg p-2 text-xs font-semibold">
                </form>

                <!-- حقل البحث في الأصناف القائمة اليمينية -->
                <div class="relative shrink-0">
                    <input type="text"
                        wire:model.live.debounce.250ms="productSearchQuery"
                        placeholder="بحث في الأصناف (بالاسم أو الباركود)... 🔍"
                        class="w-full bg-indigo-50/50 border border-indigo-200 rounded-lg p-2 text-xs font-bold text-indigo-900 focus:outline-indigo-600 focus:bg-white placeholder-indigo-400">
                    @if (!empty($productSearchQuery))
                        <button type="button" wire:click="$set('productSearchQuery', '')"
                            class="absolute left-2.5 top-2 text-slate-400 hover:text-rose-600 font-bold text-xs">
                            ✕
                        </button>
                    @endif
                </div>

                <!-- أزرار الأقسام -->
                <div class="flex gap-1 overflow-x-auto pb-1 shrink-0 scrollbar-none">
                    <button wire:click="selectCategory(null)"
                        class="px-2.5 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap {{ is_null($selectedCategoryId) ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                        الكل
                    </button>
                    @foreach ($categories as $cat)
                        <button wire:click="selectCategory({{ $cat->id }})"
                            class="px-2.5 py-1.5 rounded-lg text-xs font-bold whitespace-nowrap {{ $selectedCategoryId === $cat->id ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">
                            {{ $cat->name }}
                        </button>
                    @endforeach
                </div>

                <!-- شبكة الأصناف السريعة -->
                <div class="flex-1 overflow-y-auto min-h-0 border border-slate-200 rounded-lg p-2 bg-slate-50">
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                        @forelse($quickProducts as $p)
                            <button wire:click="selectInlineProduct({{ $p->id }})"
                                class="{{ $isReturnMode ? 'bg-rose-700 hover:bg-rose-800 border-rose-900' : 'bg-indigo-700 hover:bg-indigo-800 border-indigo-900' }} text-white p-2 rounded-lg shadow-sm flex flex-col justify-between items-start text-right transition-all h-20 active:scale-95 border">
                                <span class="text-xs font-bold line-clamp-2 leading-tight">{{ $p->name }}</span>
                                <span class="text-xs font-mono font-black text-amber-300 mt-1">{{ number_format($p->retail_price, 2) }}</span>
                            </button>
                        @empty
                            <div class="col-span-full text-center py-16 text-slate-400 text-xs font-semibold">
                                لا توجد منتجات مطابقة لنتيجة البحث
                            </div>
                        @endforelse
                    </div>
                </div>
            </div> --}}

            <!-- ==================== قسم الفاتورة والحسابات (على اليسار) ==================== -->
            <div class="col-span-12 lg:col-span-12 flex flex-col h-full space-y-2 min-h-0">
                <div
                    class="bg-white border border-slate-300 rounded-xl p-2 flex flex-wrap items-center justify-between gap-2 shadow-sm shrink-0">

                    @if (!Auth::user()->branch_id)
                        <div class="flex items-center gap-2" wire:key="branch-selector-container">
                            <span class="text-xs font-bold text-slate-700">الفرع:</span>
                            <select wire:model.live="selectedBranchId" wire:key="branch-select-input"
                                class="bg-slate-50 border border-slate-300 text-xs font-bold rounded-lg p-1.5 focus:outline-indigo-600">
                                <option value="">-- اختر الفرع --</option>
                                @foreach ($branches as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <!-- نموذج البحث عن فاتورة -->
                    <form wire:submit.prevent="searchInvoice" class="flex items-center gap-1">
                        <input type="text" wire:model="searchInvoiceQuery" placeholder="رقم الفاتورة أو ID..."
                            class="w-36 bg-slate-50 border border-slate-300 rounded-lg p-1.5 text-xs font-bold focus:outline-indigo-600">
                        <button type="submit"
                            class="px-2.5 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-bold active:scale-95 transition-all">
                            بحث 🔍
                        </button>
                    </form>

                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="previousInvoice"
                            class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded-lg text-xs font-bold text-slate-700 flex items-center gap-1 active:scale-95 transition-all">
                            <span>➔</span>
                            <span>الفاتورة السابقة</span>
                        </button>
                        <button type="button" wire:click="nextInvoice"
                            @if (!$currentInvoiceId) disabled @endif
                            class="px-3 py-1.5 bg-slate-100 hover:bg-slate-200 disabled:opacity-50 border border-slate-300 rounded-lg text-xs font-bold text-slate-700 flex items-center gap-1 active:scale-95 transition-all">
                            <span>الفاتورة التالية</span>
                            <span>⬅</span>
                        </button>
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($activeShift)
                            <button type="button" wire:click="prepareCloseShift"
                                class="px-3 py-1.5 bg-rose-700 hover:bg-rose-800 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1 shadow-sm">
                                <span>🔒</span>
                                <span>إغلاق الشيفت</span>
                            </button>
                        @else
                            <button type="button" wire:click="triggerOpenShiftModal"
                                class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1 shadow-sm">
                                <span>🔓</span>
                                <span>فتح شيفت جديد</span>
                            </button>
                        @endif

                        <button type="button" wire:click="toggleReturnMode"
                            class="px-3 py-1.5 rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1 border {{ $isReturnMode ? 'bg-rose-600 text-white border-rose-700 animate-pulse' : 'bg-slate-100 text-slate-700 border-slate-300 hover:bg-slate-200' }}">
                            <span>🔄</span>
                            <span>{{ $isReturnMode ? 'وضع المرتجع (مفعل)' : 'وضع المرتجع' }}</span>
                        </button>

                        <button type="button" wire:click="openCostModal"
                            @if (count($cart) === 0) disabled @endif
                            class="px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1">
                            <span>🔍 استطلاع التكلفة</span>
                        </button>

                        <button type="button" wire:click="holdInvoice"
                            @if (count($cart) === 0 || $currentInvoiceId) disabled @endif
                            class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 disabled:opacity-50 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1">
                            <span>تعليق</span>
                            <span class="text-[10px] bg-amber-700 text-white px-1.5 py-0.5 rounded font-mono">F2</span>
                        </button>

                        <button type="button" wire:click="$set('showHeldModal', true)"
                            class="relative px-3 py-1.5 bg-slate-700 hover:bg-slate-800 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1">
                            <span>معلقة</span>
                            <span class="text-[10px] bg-slate-900 text-white px-1.5 py-0.5 rounded font-mono">F1</span>
                            @if (count($heldInvoices) > 0)
                                <span
                                    class="absolute -top-1.5 -right-1.5 bg-rose-500 text-white text-[10px] rounded-full w-4 h-4 flex items-center justify-center font-bold">
                                    {{ count($heldInvoices) }}
                                </span>
                            @endif
                        </button>

                        <button type="button" wire:click="$set('showProductsModal', true)"
                            class="px-3 py-1.5 bg-purple-700 hover:bg-purple-800 text-white rounded-lg text-xs font-bold active:scale-95 transition-all flex items-center gap-1 shadow-sm">
                            <span>📦 الأصناف</span>
                            <span
                                class="text-[10px] bg-purple-900 text-white px-1.5 py-0.5 rounded font-mono">F10</span>
                        </button>
                        <div
                            class="flex items-center gap-1.5 bg-slate-100 border border-slate-300 px-2.5 py-1 rounded-md text-xs font-bold text-slate-700">
                            <span>📅</span>
                            <span class="font-mono text-slate-900">{{ $this->invoiceDate }}</span>
                        </div>
                        <div
                            class="flex items-center gap-1.5 bg-indigo-50 border border-indigo-200 px-2.5 py-1 rounded-md text-xs font-bold text-indigo-900">
                            <span>👤</span>
                            <span>بواسطة: {{ $this->invoiceCreator }}</span>
                        </div>
                        @if ($currentInvoiceId)
                            <span
                                class="bg-amber-100 text-amber-800 border border-amber-300 px-2.5 py-1 rounded-md text-xs font-bold font-mono">
                                عرض فاتورة #{{ $currentInvoiceId }}
                            </span>
                        @else
                            <span
                                class="bg-emerald-100 text-emerald-800 border border-emerald-300 px-2.5 py-1 rounded-md text-xs font-bold">
                                فاتورة جديدة
                            </span>
                        @endif
                    </div>
                </div>

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

                <div
                    class="flex-1 bg-white border border-slate-300 rounded-xl overflow-hidden shadow-sm flex flex-col min-h-0">

                    <!-- ==================== حقل البحث السريع المباشر فوق جدول الفاتورة ==================== -->
                    <!-- ==================== حقل البحث ومسح الباركود المباشر فوق جدول الفاتورة ==================== -->
                    <div class="p-2 bg-slate-50 border-b border-slate-200 relative shrink-0">
                        <div class="relative flex items-center gap-2">
                            <!-- حقل مسح الباركود التلقائي -->
                            <!-- حقل مسح الباركود التلقائي والسريع -->
                            <input type="text" wire:model="barcode" wire:keydown.enter.prevent="scanBarcode"
                                placeholder="{{ $isReturnMode ? 'امسح الباركود لإرجاعه... 🔄' : 'امسح الباركود هنا للإضافة المباشرة... 📦' }}"
                                autofocus
                                class="w-full bg-white border {{ $isReturnMode ? 'border-rose-400 focus:outline-rose-600' : 'border-indigo-300 focus:outline-indigo-600' }} rounded-lg py-1.5 px-3 text-xs font-bold text-slate-800 placeholder-slate-400 shadow-sm">
                            @if (!empty($barcode))
                                <button type="button" wire:click="$set('barcode', '')"
                                    class="absolute left-2.5 top-2 text-slate-400 hover:text-rose-600 font-bold text-xs">
                                    ✕
                                </button>
                            @endif
                        </div>
                    </div>

                    <div class="overflow-y-auto flex-1">
                        <table class="w-full text-right text-xs">
                            <thead class="bg-slate-200 sticky top-0 font-bold text-slate-800 border-b border-slate-300">
                                <tr>
                                    <th class="p-2">اسم المنتج</th>
                                    <th class="p-2 text-center">الكمية</th>
                                    <th class="p-2 text-center">التكلفة</th>
                                    <th class="p-2 text-center">سعر البيع</th>
                                    <th class="p-2 text-center">الإجمالي</th>
                                    <th class="p-2 text-center">حذف</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse($cart as $item)
                                    @php
                                        $isBelowCost =
                                            $item['quantity'] > 0 &&
                                            (float) $item['price'] < (float) ($item['cost_price'] ?? 0);
                                    @endphp
                                    <tr class="{{ $item['quantity'] < 0 ? 'bg-rose-50/80 hover:bg-rose-100' : ($isBelowCost ? 'bg-rose-100/50 hover:bg-rose-100' : 'hover:bg-indigo-50/50') }}"
                                        wire:key="cart-item-{{ $item['id'] }}">
                                        <td class="p-2 font-bold text-slate-900">
                                            {{ $item['name'] }}
                                            @if ($item['quantity'] < 0)
                                                <span
                                                    class="inline-block bg-rose-200 text-rose-800 text-[10px] px-1.5 py-0.5 rounded font-bold mr-1">مرتجع</span>
                                            @elseif ($isBelowCost)
                                                <span
                                                    class="inline-block bg-rose-600 text-white text-[10px] px-1.5 py-0.5 rounded font-bold mr-1 animate-bounce">تحت
                                                    التكلفة</span>
                                            @endif
                                        </td>
                                        <td class="p-2 text-center">
                                            <div
                                                class="inline-flex items-center gap-1 border border-slate-300 rounded-md bg-slate-50 px-1">
                                                <button
                                                    wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] - 1 }})"
                                                    class="px-1.5 font-bold text-rose-600 hover:bg-slate-200 rounded">-</button>
                                                <span
                                                    class="font-bold px-1 font-mono text-xs {{ $item['quantity'] < 0 ? 'text-rose-600' : '' }}">{{ $item['quantity'] }}</span>
                                                <button
                                                    wire:click="updateQuantity({{ $item['id'] }}, {{ $item['quantity'] + 1 }})"
                                                    class="px-1.5 font-bold text-emerald-600 hover:bg-slate-200 rounded">+</button>
                                            </div>
                                        </td>
                                        <td class="p-2 text-center">
                                            <input type="number" step="0.01"
                                                value="{{ $item['cost_price'] ?? 0 }}"
                                                wire:change="updateCostPrice({{ $item['id'] }}, $event.target.value)"
                                                class="w-20 text-center font-mono font-bold bg-rose-50/50 border border-rose-200 text-rose-700 rounded p-1 text-xs focus:bg-white focus:outline-rose-600">
                                        </td>
                                        <td class="p-2 text-center">
                                            <input type="number" step="0.01" value="{{ $item['price'] }}"
                                                wire:change="updateUnitPrice({{ $item['id'] }}, $event.target.value)"
                                                class="w-20 text-center font-mono font-bold {{ $isBelowCost ? 'bg-rose-200 text-rose-900 border-rose-500' : 'bg-slate-50 border-slate-300' }} border rounded p-1 text-xs focus:bg-white focus:outline-indigo-600">
                                        </td>
                                        <td
                                            class="p-2 text-center font-mono font-black {{ $item['subtotal'] < 0 ? 'text-rose-600' : 'text-indigo-700' }}">
                                            {{ number_format($item['subtotal'], 2) }}
                                        </td>
                                        <td class="p-2 text-center">
                                            <button wire:click="removeFromCart({{ $item['id'] }})"
                                                class="text-rose-500 hover:text-rose-700 font-bold text-sm">×</button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="py-20 text-center text-slate-400 font-semibold">
                                            الفاتورة فارغة.. اختر منتجات من القائمة أو امسح الباركود
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="bg-slate-100 border-t border-slate-300 p-2 text-xs space-y-2">
                        <div class="grid grid-cols-12 gap-2 items-center">
                            <div class="col-span-6 flex items-center gap-1">
                                <span class="font-bold text-slate-700 whitespace-nowrap">الخصم:</span>
                                <div class="relative flex-1 flex items-center">
                                    <input type="number" step="0.01"
                                        wire:model.live.debounce.300ms="discount_amount"
                                        placeholder="{{ $discount_type === 'percentage' ? '%' : '' }}"
                                        class="w-full bg-white border border-slate-300 rounded p-1 pl-7 font-mono font-bold text-slate-800 focus:outline-indigo-600">
                                    @if ($discount_type === 'percentage')
                                        <span class="absolute left-2 text-slate-400 font-bold text-xs">%</span>
                                    @endif
                                </div>
                                <button type="button" wire:click="toggleDiscountType"
                                    class="px-2 py-1 rounded font-bold text-xs border transition-colors {{ $discount_type === 'percentage' ? 'bg-indigo-600 text-white border-indigo-700' : 'bg-slate-200 text-slate-800 border-slate-300 hover:bg-slate-300' }}">
                                    {{ $discount_type === 'percentage' ? '%' : 'مبلغ' }}
                                </button>
                            </div>
                            <div class="col-span-6 flex items-center gap-1">
                                <span class="font-bold text-slate-700 whitespace-nowrap">الإجمالي المطلوب:</span>
                                <input type="number" step="0.01"
                                    wire:model.live.debounce.300ms="custom_final_total"
                                    value="{{ empty($cart) ? '0.00' : $custom_final_total }}"
                                    placeholder="{{ number_format($this->total, 2) }}"
                                    class="w-full bg-white border border-slate-300 rounded p-1 font-mono font-black text-indigo-700">
                            </div>

                            <div class="col-span-12 flex items-center gap-1">
                                <span class="font-bold text-slate-700 whitespace-nowrap">ملاحظات:</span>
                                <input type="text" wire:model.live="notes"
                                    placeholder="أضف أي ملاحظات إضافية للفاتورة..."
                                    class="w-full bg-white border border-slate-300 rounded p-1 text-xs font-semibold text-slate-800 focus:outline-indigo-600">
                            </div>
                        </div>
                    </div>
                    <div class="bg-slate-900 text-white p-2.5 flex items-center justify-between text-xs font-bold">
                        <div>
                            <span>المجموع: </span><span
                                class="font-mono text-slate-300 mr-1">{{ number_format($this->subtotal, 2) }}</span>
                            @if ($this->calculated_discount > 0)
                                <span class="text-rose-400 mr-2">(خصم:
                                    {{ number_format($this->calculated_discount, 2) }})</span>
                            @endif
                        </div>
                        <div>
                            <span>{{ $this->total < 0 ? 'المسترد للزبون:' : 'المطلوب:' }}</span>
                            <span
                                class="{{ $this->total < 0 ? 'text-rose-400' : 'text-amber-400' }} font-mono text-lg mr-1">
                                {{ number_format(abs($this->total), 2) }}
                            </span>
                        </div>
                        <div>المتبقي: <span
                                class="text-emerald-400 font-mono text-lg mr-1">{{ number_format($this->change, 2) }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-1.5 shrink-0" x-data="{ showNumpad: false }">
                    <!-- حقل المبلغ المدفوع ومحاكي لوحة الأرقام المنبثقة -->
                    <div class="relative">
                        <div class="flex justify-between items-center mb-0.5">
                            <label class="text-[10px] font-bold text-slate-600">المبلغ المدفوع (نقداً):</label>
                            <button type="button" @click="showNumpad = !showNumpad"
                                class="text-[10px] text-indigo-600 font-bold underline">
                                [لوحة الأرقام]
                            </button>
                        </div>
                        <input type="number" wire:model.live="paid_amount" @focus="showNumpad = true"
                            class="w-full text-lg font-black font-mono text-left bg-slate-50 border border-slate-300 rounded-lg p-1 focus:outline-none focus:border-indigo-600 text-indigo-900">

                        <!-- لوحة الأرقام تظهر فقط عند الحاجة بأسلوب Popover -->
                        <div x-show="showNumpad" @click.outside="showNumpad = false" x-cloak
                            class="absolute bottom-full mb-1 left-0 right-0 bg-white border border-slate-300 shadow-2xl rounded-lg p-2 z-50">
                            <div class="grid grid-cols-3 gap-1 font-bold font-mono text-xs">
                                @foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '.', 'C'] as $num)
                                    <button type="button" wire:click="appendNumpad('{{ $num }}')"
                                        class="py-1.5 bg-slate-100 hover:bg-slate-200 border rounded text-center active:bg-slate-300">
                                        {{ $num }}
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <!-- أزرار الإجراءات بأقصى توفير للمساحة -->
                    <div class="grid grid-cols-2 gap-1.5">
                        <button wire:click="checkoutAndPrint" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif
                            class="col-span-2 {{ $this->total < 0 ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700' }} disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg font-bold py-2 px-3 shadow active:scale-95 transition-all text-xs flex justify-between items-center">
                            <span>🖨️ {{ $this->total < 0 ? 'حفظ وطباعة المرتجع' : 'حفظ وطباعة' }}</span>
                            <span
                                class="text-[9px] {{ $this->total < 0 ? 'bg-rose-800' : 'bg-emerald-800' }} text-white px-1.5 py-0.5 rounded font-mono">F6</span>
                        </button>

                        <button wire:click="checkout" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif
                            class="bg-slate-700 hover:bg-slate-800 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg font-bold py-1.5 px-2 shadow active:scale-95 transition-all text-[11px] flex justify-between items-center">
                            <span>حفظ فقط</span>
                            <span class="text-[9px] bg-slate-900 text-white px-1 rounded font-mono">F3</span>
                        </button>

                        <button wire:click="clearCart" @if (count($cart) === 0) disabled @endif
                            class="bg-slate-500 hover:bg-slate-600 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg font-bold py-1.5 px-2 shadow active:scale-95 transition-all text-[11px] flex justify-between items-center">
                            <span>تنظيف السلة</span>
                            <span class="text-[9px] bg-slate-700 text-white px-1 rounded font-mono">F4</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <!-- ==================== مودال عرض الأصناف (F10) ==================== -->
        @if ($showProductsModal)
            <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-2 sm:p-4"
                x-data="{ show: @entangle('showProductsModal') }" x-effect="if (show) { setTimeout(() => $refs.f10SearchInput.focus(), 100) }">

                <div
                    class="bg-slate-200 rounded-xl shadow-2xl w-full max-w-6xl overflow-hidden border border-slate-400 flex flex-col h-[90vh]">

                    <!-- شريط العنوان والبحث العلوي -->
                    <div
                        class="bg-slate-300 border-b border-slate-400 p-2 flex flex-wrap items-center justify-between gap-2 shrink-0">
                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="$set('showProductsModal', false)"
                                class="px-2.5 py-1 bg-slate-100 hover:bg-slate-50 border border-slate-400 rounded text-xs font-bold text-slate-800 shadow-sm active:scale-95">
                                (Esc) إلغاء / إغلاق
                            </button>
                        </div>

                        <!-- حقل البحث والاختيار -->
                        <div class="flex items-center gap-2 flex-1 max-w-2xl">
                            <select wire:model.live="selectedCategoryId"
                                class="bg-white border border-slate-400 text-xs font-bold rounded p-1.5 focus:outline-indigo-600">
                                <option value="">الكل</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </select>

                            <div class="relative flex-1">
                                <!-- حقل البحث المستقل والخاص بـ F10 مع التركيز التلقائي -->
                                <input type="text" x-ref="f10SearchInput"
                                    wire:model.live.debounce.200ms="productSearchQuery"
                                    placeholder="ابحث بالاسم أو الباركود... 🔍"
                                    class="w-full bg-white border border-slate-400 text-xs font-bold text-slate-900 rounded p-1.5 pl-7 focus:outline-indigo-600 shadow-inner">

                                @if (!empty($productSearchQuery))
                                    <button type="button" wire:click="$set('productSearchQuery', '')"
                                        class="absolute left-2 top-1.5 text-slate-400 hover:text-rose-600 font-bold text-xs">✕</button>
                                @endif
                            </div>

                            <button type="button" wire:click="loadQuickProducts"
                                class="p-1.5 bg-slate-100 hover:bg-slate-50 border border-slate-400 rounded text-xs font-bold text-slate-700">
                                🔄
                            </button>
                        </div>

                        <button wire:click="$set('showProductsModal', false)"
                            class="text-slate-600 hover:text-rose-600 font-bold text-base px-2">✕</button>
                    </div>

                    <!-- جدول عرض الأصناف (نمط برنامج الشامل) -->
                    <div class="flex-1 overflow-y-auto bg-slate-200 min-h-0 p-1">
                        <table class="w-full text-right text-xs border-collapse bg-slate-300">
                            <thead class="bg-slate-300 sticky top-0 font-bold text-slate-900 border-b-2 border-slate-400 shadow-sm">
                                <tr>
                                    <th class="p-1.5 border border-slate-400 text-center w-14">الرقم</th>
                                    <th class="p-1.5 border border-slate-400">الاسم</th>
                                    <th class="p-1.5 border border-slate-400 text-center w-24">المخزون</th>
                                    <th class="p-1.5 border border-slate-400 text-center w-28">سعر البيع</th>
                                    <th class="p-1.5 border border-slate-400">الباركود</th>
                                    <th class="p-1.5 border border-slate-400 text-center w-28">التاريخ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-300 font-bold">
                                @forelse($quickProducts as $index => $p)
                                    <tr wire:click="selectInlineProduct({{ $p->id }})"
                                        wire:key="modal-prod-{{ $p->id }}"
                                        class="cursor-pointer transition-colors border-b border-slate-300 {{ $index % 2 === 0 ? 'bg-slate-200' : 'bg-slate-100' }} hover:bg-indigo-100/80 active:bg-indigo-200">
                                        <td class="p-1.5 border border-slate-300 text-center font-mono text-slate-800">
                                            {{ $p->id }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-slate-900 font-bold">
                                            {{ $p->name }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-center font-mono {{ ($p->stock_quantity ?? 0) <= 0 ? 'text-rose-600' : 'text-slate-800' }}">
                                            {{ number_format((float) ($p->stock_quantity ?? 0), 0) }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-center font-mono text-slate-900">
                                            {{ number_format((float) ($p->retail_price ?? 0), 2) }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-slate-600 text-[11px] font-normal">
                                            {{ $p->barcode_value ?? '' }}
                                        </td>
                                        <td class="p-1.5 border border-slate-300 text-center font-mono text-[10px] text-slate-600">
                                            {{ $p->created_at?->format('Y-m-d') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="py-16 text-center text-slate-500 font-bold text-xs bg-slate-100">
                                            لا توجد أصناف مطابقة للبحث
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- الشريط السفلي -->
                    <div
                        class="p-2 bg-slate-300 border-t border-slate-400 flex justify-between items-center text-xs font-bold text-slate-700 shrink-0">
                        <span>عدد الأصناف المعروضة: {{ count($quickProducts) }}</span>
                        <button wire:click="$set('showProductsModal', false)"
                            class="px-4 py-1 bg-slate-100 hover:bg-slate-50 border border-slate-400 rounded text-xs font-bold text-slate-800 shadow-sm active:scale-95">
                            إغلاق (Esc)
                        </button>
                    </div>
                </div>
            </div>
        @endif
        <!-- مودال فتح الشيفت -->
        @if ($showOpenShiftModal)
            <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden border border-slate-300">
                    <div class="bg-indigo-900 text-white p-3.5 flex justify-between items-center font-bold text-sm">
                        <span>🔓 فتح شِفت جديد / بداية الدوام</span>
                        <button wire:click="$set('showOpenShiftModal', false)"
                            class="text-slate-300 hover:text-white font-bold">✕</button>
                    </div>
                    <div class="p-4 space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1">الرصيد الافتتاحي في الدرج
                                (الفكة):</label>
                            <input type="number" step="0.01" wire:model="opening_cash"
                                class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-lg font-black font-mono text-center focus:outline-indigo-600">
                        </div>
                        <button wire:click="openShift"
                            class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold p-3 rounded-xl text-xs shadow transition-all active:scale-95">
                            بدء العمل وفتح الصندوق
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- مودال إغلاق الشيفت ومطابقة الصندوق -->
        @if ($showCloseShiftModal)
            <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-md z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden border border-slate-300">
                    <div class="bg-slate-900 text-white p-3 flex justify-between items-center">
                        <h3 class="text-sm font-bold flex items-center gap-1.5">
                            <span>🔒</span>
                            <span>إغلاق الشيفت ومطابقة الصندوق</span>
                        </h3>
                        <button wire:click="$set('showCloseShiftModal', false)"
                            class="text-slate-400 hover:text-white font-bold">✕</button>
                    </div>

                    <div class="p-4 space-y-3 text-xs">
                        <div
                            class="grid grid-cols-2 gap-2 bg-slate-50 p-3 rounded-lg border border-slate-200 font-bold">
                            <div>الرصيد الافتتاحي: <span
                                    class="font-mono text-indigo-700">{{ number_format($shift_opening_cash, 2) }}</span>
                            </div>
                            <div>المبيعات النقدية: <span
                                    class="font-mono text-emerald-600">{{ number_format($shift_total_sales, 2) }}</span>
                            </div>
                            <div>المرتجعات النقدية: <span
                                    class="font-mono text-rose-600">{{ number_format($shift_total_returns, 2) }}</span>
                            </div>
                            <div class="col-span-2 border-t pt-2 text-sm text-slate-900">
                                المتوقع بالدرج: <span
                                    class="font-mono font-black text-amber-600">{{ number_format($shift_expected_cash, 2) }}</span>
                            </div>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">المبلغ الفعلي الموجود بالدرج بعد
                                العدّ:</label>
                            <input type="number" step="0.01" wire:model.live.debounce.300ms="actual_cash"
                                class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-lg font-black font-mono text-center focus:outline-indigo-600">
                        </div>

                        @php
                            $diff = (float) $actual_cash - $shift_expected_cash;
                        @endphp

                        <div
                            class="p-2.5 rounded-lg text-center font-bold text-xs {{ $diff == 0 ? 'bg-emerald-100 text-emerald-800' : ($diff < 0 ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800') }}">
                            @if ($diff == 0)
                                الصندوق مطابق تماماً 👌
                            @elseif($diff < 0)
                                يوجد عجز بمقدار: {{ number_format(abs($diff), 2) }} ⚠️
                            @else
                                يوجد زيادة بمقدار: {{ number_format($diff, 2) }} 💡
                            @endif
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">ملاحظات الإغلاق (اختياري):</label>
                            <textarea wire:model="shift_notes" rows="2"
                                class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-xs focus:outline-indigo-600"></textarea>
                        </div>

                        <button wire:click="closeShift"
                            class="w-full bg-rose-600 hover:bg-rose-700 text-white font-bold p-3 rounded-xl text-xs shadow transition-all active:scale-95">
                            تأكيد إغلاق الشيفت وتصفية الصندوق
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- مودال الفواتير المعلقة -->
        @if ($showHeldModal)
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-lg overflow-hidden border border-slate-300">
                    <div class="bg-slate-800 text-white p-3 flex justify-between items-center">
                        <h3 class="text-sm font-bold">الفواتير المعلقة (F1 لإغلاق)</h3>
                        <button wire:click="$set('showHeldModal', false)"
                            class="text-slate-400 hover:text-white font-bold text-sm">✕</button>
                    </div>
                    <div class="p-3 max-h-80 overflow-y-auto space-y-2">
                        @forelse($heldInvoices as $index => $held)
                            <div
                                class="bg-slate-50 border border-slate-200 p-2.5 rounded-lg flex items-center justify-between hover:bg-slate-100">
                                <div>
                                    <div class="text-xs font-bold text-slate-800">فاتورة #{{ $held['id'] }}</div>
                                    <div class="text-[10px] text-slate-500 font-mono">{{ $held['time'] }}</div>
                                    <div class="text-xs font-bold text-indigo-700 font-mono mt-0.5">المجموع:
                                        {{ number_format($held['total'], 2) }}</div>
                                    @if (!empty($held['notes']))
                                        <div class="text-[10px] text-slate-600">ملاحظة: {{ $held['notes'] }}</div>
                                    @endif
                                </div>
                                <div class="flex gap-2">
                                    <button wire:click="restoreHeldInvoice({{ $index }})"
                                        class="px-2.5 py-1 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded shadow active:scale-95 transition-all">
                                        استرجاع
                                    </button>
                                    <button wire:click="removeHeldInvoice({{ $index }})"
                                        class="px-2 py-1 bg-rose-500 hover:bg-rose-600 text-white text-xs font-bold rounded shadow active:scale-95 transition-all">
                                        حذف
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="text-center py-8 text-xs text-slate-400 font-semibold">
                                لا توجد فواتير معلقة حالياً.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif

        <!-- مودال استطلاع تكلفة وأرباح الأصناف -->
        @if ($showCostModal)
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl overflow-hidden border border-slate-300">
                    <div class="bg-indigo-900 text-white p-3 flex justify-between items-center">
                        <h3 class="text-sm font-bold flex items-center gap-1.5">
                            <span>🔍</span>
                            <span>معاينة تكلفة وأرباح الفاتورة</span>
                        </h3>
                        <button wire:click="$set('showCostModal', false)"
                            class="text-slate-300 hover:text-white font-bold text-sm">✕</button>
                    </div>
                    <div class="p-3 max-h-96 overflow-y-auto">
                        <table class="w-full text-right text-xs">
                            <thead class="bg-slate-100 font-bold border-b border-slate-200">
                                <tr>
                                    <th class="p-2">الصنف</th>
                                    <th class="p-2 text-center">الكمية</th>
                                    <th class="p-2 text-center">سعر البيع</th>
                                    <th class="p-2 text-center">تكلفة الوحدة</th>
                                    <th class="p-2 text-center">إجمالي التكلفة</th>
                                    <th class="p-2 text-center">الربح المتوقع</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($cart as $id => $item)
                                    @php
                                        $itemCost = (float) ($item['cost_price'] ?? 0);
                                        $totalItemCost = $itemCost * $item['quantity'];
                                        $itemProfit = $item['subtotal'] - $totalItemCost;
                                    @endphp
                                    <tr>
                                        <td class="p-2 font-bold text-slate-800">{{ $item['name'] }}</td>
                                        <td class="p-2 text-center font-mono">{{ $item['quantity'] }}</td>
                                        <td class="p-2 text-center font-mono text-slate-700">
                                            {{ number_format($item['price'], 2) }}</td>
                                        <td class="p-2 text-center font-mono text-rose-600 font-semibold">
                                            {{ number_format($itemCost, 2) }}</td>
                                        <td class="p-2 text-center font-mono font-bold text-rose-700">
                                            {{ number_format($totalItemCost, 2) }}</td>
                                        <td
                                            class="p-2 text-center font-mono font-bold {{ $itemProfit >= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
                                            {{ number_format($itemProfit, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="bg-slate-100 p-3 border-t border-slate-200 flex justify-between items-center text-xs">
                        <div class="flex gap-4 font-bold">
                            <div>
                                <span class="text-slate-600">إجمالي التكلفة:</span>
                                <span
                                    class="font-mono text-rose-700 font-black text-sm mr-1">{{ number_format($this->total_cost, 2) }}</span>
                            </div>
                            <div>
                                <span class="text-slate-600">إجمالي الربح:</span>
                                <span
                                    class="font-mono {{ $this->expected_profit >= 0 ? 'text-emerald-600' : 'text-rose-600' }} font-black text-sm mr-1">
                                    {{ number_format($this->expected_profit, 2) }}
                                </span>
                            </div>
                        </div>
                        <button wire:click="$set('showCostModal', false)"
                            class="px-4 py-1.5 bg-slate-700 hover:bg-slate-800 text-white font-bold rounded shadow transition-all">
                            إغلاق
                        </button>
                    </div>
                </div>
            </div>
        @endif

        <!-- مودال التنبيه عند البيع تحت التكلفة -->
        @if ($showBelowCostModal)
            <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden border border-rose-300">
                    <div class="bg-rose-600 text-white p-4 flex justify-between items-center font-bold text-sm">
                        <span class="flex items-center gap-2">
                            <span class="text-lg">⚠️</span>
                            <span>تنبيه: السعر أقل من التكلفة</span>
                        </span>
                        <button wire:click="$set('showBelowCostModal', false)"
                            class="text-rose-100 hover:text-white font-bold">✕</button>
                    </div>

                    <div class="p-4 space-y-3">
                        <p class="text-xs font-bold text-slate-700 leading-relaxed">
                            تحذير! تحتوي الفاتورة على منتجات يتم بيعها بأسعار أقل من التكلفة:
                        </p>

                        <div class="max-h-48 overflow-y-auto space-y-2">
                            @foreach ($cart as $item)
                                @if ($item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0))
                                    <div
                                        class="bg-rose-50 border border-rose-200 p-2 rounded-lg text-xs flex justify-between items-center font-bold">
                                        <div>
                                            <div class="text-slate-900">{{ $item['name'] }}</div>
                                            <div class="text-[10px] text-rose-700">التكلفة:
                                                {{ number_format($item['cost_price'], 2) }}</div>
                                        </div>
                                        <div class="text-rose-700 font-mono text-sm">
                                            سعر البيع: {{ number_format($item['price'], 2) }}
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <p class="text-xs text-slate-600 font-semibold">هل تريد الاستمرار وإتمام الفاتورة بهذا السعر؟
                        </p>

                        <div class="flex items-center gap-2 pt-2">
                            <button wire:click="confirmBelowCostCheckout"
                                class="flex-1 bg-rose-600 hover:bg-rose-700 text-white font-bold py-2.5 px-3 rounded-xl text-xs shadow transition-all active:scale-95">
                                نعم، استمرار
                            </button>
                            <button wire:click="$set('showBelowCostModal', false)"
                                class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold py-2.5 px-3 rounded-xl text-xs transition-all">
                                إلغاء لتعديل السعر
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif

    </div>
    <!-- ==================== قالب الفاتورة الحرارية للطباعة المباشرة ==================== -->
<div id="thermal-receipt" class="hidden print:block text-black bg-white p-2 font-mono text-xs w-[80mm] mx-auto">
    @if (!empty($receipt))
        <div class="text-center font-bold mb-2">
            <h2 class="text-base font-black">{{ $receipt['store_name'] }}</h2>
            <p class="text-[10px]">{{ $receipt['copy_type'] }}</p>
            <p class="text-[10px]">رقم الفاتورة: {{ $receipt['invoice_no'] }}</p>
            <p class="text-[10px]">{{ $receipt['date'] }} {{ $receipt['time'] }}</p>
        </div>

        <div class="border-b border-t border-black py-1 my-1 text-[11px]">
            <div class="flex justify-between">
                <span>الكاشير:</span>
                <span class="font-bold">{{ $this->invoiceCreator }}</span>
            </div>
        </div>

        <table class="w-full text-right my-2 text-[10px] border-collapse">
            <thead>
                <tr class="border-b border-black">
                    <th class="py-0.5">الصنف</th>
                    <th class="py-0.5 text-center">الكمية</th>
                    <th class="py-0.5 text-center">السعر</th>
                    <th class="py-0.5 text-left">الإجمالي</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($receipt['items'] as $item)
                    <tr>
                        <td class="py-0.5 font-bold">{{ $item['name'] }}</td>
                        <td class="py-0.5 text-center">{{ $item['qty'] }}</td>
                        <td class="py-0.5 text-center">{{ $item['price'] }}</td>
                        <td class="py-0.5 text-left font-bold">{{ $item['total'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="border-t border-black pt-1 mt-1 text-[11px] space-y-0.5">
            <div class="flex justify-between">
                <span>مجموع الكميات:</span>
                <span>{{ $receipt['total_qty'] }}</span>
            </div>
            <div class="flex justify-between">
                <span>المجموع:</span>
                <span>{{ $receipt['total_amount'] }}</span>
            </div>
            <div class="flex justify-between font-black text-sm border-t border-black pt-1">
                <span>الصافي المطلوب:</span>
                <span>{{ $receipt['net_amount'] }}</span>
            </div>
        </div>

        <div class="text-center mt-4 pt-2 border-t border-dashed border-black text-[9px]">
            <p>{{ $receipt['notice'] }}</p>
            <p>{{ $receipt['system_name'] }}</p>
        </div>
    @endif
</div>
<iframe id="silent-print-frame" style="display: none; position: absolute; width: 0; height: 0; border: 0;"></iframe>
</flux:main>
<style>
    @media print {
        /* إخفاء كل عناصر الواجهة والشاشة */
        body * {
            visibility: hidden !important;
        }

        /* إظهار الفاتورة الحرارية فقط */
        #thermal-receipt, #thermal-receipt * {
            visibility: visible !important;
        }

        #thermal-receipt {
            display: block !important;
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 80mm !important; /* عرض ورقة طابعة الفواتير الحرارية */
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
