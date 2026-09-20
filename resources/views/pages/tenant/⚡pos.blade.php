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

new class extends Component {
    // --- مصفوفة الفاتورة المخصصة للطباعة ---
    public array $receipt = [];

    // --- البيانات والحالة (State) ---
    public string $barcode = '';
    public array $cart = [];
    public $paid_amount = 0;
    public string $notes = '';

    // --- حقول البحث ---
    public string $inlineSearchQuery = '';
    public array $inlineSearchResults = [];
    public string $searchInvoiceQuery = '';
    public string $productSearchQuery = '';

    // --- الفرع والخصم ---
    public ?int $selectedBranchId = null;
    public $discount_amount = 0;
    public string $discount_type = 'fixed';
    public $custom_final_total = null;
    public $categories = [];
    public ?int $selectedCategoryId = null;
    public $quickProducts = [];
    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    // --- الحالة والمودالات ---
    public ?int $currentInvoiceId = null;
    public array $heldInvoices = [];
    public bool $showHeldModal = false;
    public bool $showCostModal = false;
    public bool $showBelowCostModal = false;
    public string $pendingCheckoutMode = 'checkout';
    public bool $showProductsModal = false;
    public bool $isReturnMode = false;

    // --- الشيفت والصندوق ---
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

    public function mount()
    {
        $user = Auth::user();
        $this->selectedBranchId = $user->branch_id ?? session('active_branch_id');

        $tenantId = session('active_tenant_id');
        $this->categories = Category::where('tenant_id', $tenantId)->get();
        $this->loadQuickProducts();
        $this->checkActiveShift();
    }

    // --- دالة تجهيز بيانات الطباعة الحرارية المباشرة ---
    public function printReceipt()
    {
        if (empty($this->cart) && !$this->currentInvoiceId) {
            $this->errorMessage = 'لا توجد فاتورة لطباعتها!';
            return;
        }

        $items = [];
        $counter = 1;
        foreach ($this->cart as $item) {
            $items[] = [
                'id' => $counter++,
                'name' => $item['name'],
                'qty' => $item['quantity'],
                'price' => number_format($item['price'], 2),
                'total' => number_format($item['subtotal'], 2),
            ];
        }

        $this->receipt = [
            'store_name' => 'فانوس',
            'notice' => 'راجع فاتورتك وتأكد من مشترياتك قبل مغادرة المعرض',
            'copy_type' => $this->currentInvoiceId ? 'نسخة فاتورة' : 'النسخة الأصلية',
            'invoice_no' => $this->currentInvoiceId ? (string) $this->currentInvoiceId : 'POS-' . time(),
            'date' => now()->format('Y/m/d'),
            'time' => now()->format('h:i أ'),
            'items' => $items,
            'total_qty' => array_sum(array_column($this->cart, 'quantity')),
            'total_amount' => number_format($this->subtotal, 2),
            'net_amount' => number_format($this->total, 2),
            'currency' => 'ش.ض',
            'system_name' => 'الشامل لايت للمحاسبة',
        ];

        // إطلاق الحدث المباشر المماثل لتجربتك
        $this->dispatch('trigger-print');
    }

    public function updatedSelectedBranchId($value)
    {
        session(['active_branch_id' => $value]);
        $this->loadQuickProducts();
        $this->checkActiveShift();
    }

    public function updatedProductSearchQuery()
    {
        $this->loadQuickProducts();
    }

    public function updatedInlineSearchQuery()
    {
        $search = trim($this->inlineSearchQuery);
        if ($search === '') {
            $this->inlineSearchResults = [];
            return;
        }

        $tenantId = session('active_tenant_id');
        $branchId = $this->getActiveBranchId();

        $this->inlineSearchResults = Product::where('tenant_id', $tenantId)
            ->where(function ($q) use ($search, $tenantId) {
                $q->where('name', 'like', '%' . $search . '%')->orWhereHas('barcodes', function ($bQuery) use ($search, $tenantId) {
                    $bQuery->where('tenant_id', $tenantId)->where('barcode', 'like', '%' . $search . '%');
                });
            })
            ->take(7)
            ->get()
            ->map(function ($product) use ($branchId) {
                $branchData = BranchProduct::where('branch_id', $branchId)->where('product_id', $product->id)->first();
                $product->retail_price = $branchData?->retail_price ?? ($product->retail_price ?? 0);
                return $product;
            })
            ->toArray();
    }

    public function selectInlineProduct(int $productId)
    {
        $product = Product::find($productId);
        if ($product) {
            $this->addToCart($product);
        }
        $this->inlineSearchQuery = '';
        $this->inlineSearchResults = [];
    }

    public function selectCategory(?int $categoryId = null)
    {
        $this->selectedCategoryId = $categoryId;
        $this->loadQuickProducts();
    }

    public function loadQuickProducts()
    {
        $tenantId = session('active_tenant_id');
        $branchId = $this->getActiveBranchId();

        $query = Product::where('tenant_id', $tenantId);

        if ($this->selectedCategoryId) {
            $query->where('category_id', $this->selectedCategoryId);
        }

        $search = trim($this->productSearchQuery);
        if ($search !== '') {
            $query->where(function ($q) use ($search, $tenantId) {
                $q->where('name', 'like', '%' . $search . '%')->orWhereHas('barcodes', function ($bQuery) use ($search, $tenantId) {
                    $bQuery->where('tenant_id', $tenantId)->where('barcode', 'like', '%' . $search . '%');
                });
            });
        }

        $products = $query->take(30)->get();

        $this->quickProducts = $products->map(function ($product) use ($branchId) {
            $branchData = BranchProduct::where('branch_id', $branchId)->where('product_id', $product->id)->first();
            $product->retail_price = $branchData?->retail_price ?? ($product->retail_price ?? 0);
            return $product;
        });
    }

    private function getActiveBranchId(): ?int
    {
        $user = Auth::user();
        return $user->branch_id ?? $this->selectedBranchId;
    }

    public function checkActiveShift(): void
    {
        $tenantId = session('active_tenant_id');
        $userId = Auth::id();

        $this->activeShift = Shift::where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'open')->first();
    }

    public function triggerOpenShiftModal(): void
    {
        $this->opening_cash = 0;
        $this->showOpenShiftModal = true;
    }

    public function openShift(): void
    {
        $tenantId = session('active_tenant_id');
        $user = Auth::user();

        if (!$tenantId) {
            $this->errorMessage = 'يرجى تحديد المتجر أولاً!';
            return;
        }

        $branchId = $this->getActiveBranchId();

        if (!$branchId) {
            $this->errorMessage = 'يرجى اختيار الفرع أولاً لفتح الشيفت!';
            return;
        }

        $this->activeShift = Shift::create([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'user_id' => $user->id,
            'opening_cash' => (float) $this->opening_cash,
            'status' => 'open',
            'opened_at' => now(),
        ]);

        $this->showOpenShiftModal = false;
        $this->successMessage = 'تم فتح الشيفت بنجاح، يمكنك بدء العمل الآن.';
    }

    public function prepareCloseShift(): void
    {
        if (!$this->activeShift) {
            $this->checkActiveShift();
            if (!$this->activeShift) {
                $this->errorMessage = 'لا يوجد شيفت مفتوح لإغلاقه!';
                return;
            }
        }

        $cashSales = (float) Order::where('shift_id', $this->activeShift->id)->where('type', 'pos')->sum('total');
        $cashReturns = (float) Order::where('shift_id', $this->activeShift->id)->where('type', 'return')->sum('total');

        $this->shift_opening_cash = (float) $this->activeShift->opening_cash;
        $this->shift_total_sales = $cashSales;
        $this->shift_total_returns = abs($cashReturns);
        $this->shift_expected_cash = $this->shift_opening_cash + $this->shift_total_sales - $this->shift_total_returns;

        $this->actual_cash = $this->shift_expected_cash;

        $this->showCloseShiftModal = true;
    }

    public function closeShift(): void
    {
        if (!$this->activeShift) {
            return;
        }

        $actual = (float) $this->actual_cash;
        $expected = (float) $this->activeShift->expected_cash;

        $this->activeShift->update([
            'actual_cash' => $actual,
            'difference' => $actual - $expected,
            'notes' => $this->shift_notes,
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->activeShift = null;
        $this->showCloseShiftModal = false;
        $this->showOpenShiftModal = false;
        $this->clearCartState();
        $this->successMessage = 'تم إغلاق الشيفت وتسوية الصندوق بنجاح.';
    }

    public function toggleReturnMode(): void
    {
        $this->isReturnMode = !$this->isReturnMode;
    }

    public function openCostModal(): void
    {
        if (empty($this->cart)) {
            $this->errorMessage = 'السلة فارغة، لا توجد أصناف للاستطلاع!';
            return;
        }

        $this->showCostModal = true;
    }

    public function holdInvoice(): void
    {
        if (empty($this->cart)) {
            $this->errorMessage = 'لا يمكن تعليق فاتورة فارغة!';
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
        $this->errorMessage = null;
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
    }

    public function removeHeldInvoice(int $index): void
    {
        unset($this->heldInvoices[$index]);
        $this->heldInvoices = array_values($this->heldInvoices);
    }

    public function searchInvoice()
    {
        $query = trim($this->searchInvoiceQuery);

        if ($query === '') {
            $this->errorMessage = 'يرجى إدخال رقم الفاتورة للبحث.';
            return;
        }

        $tenantId = session('active_tenant_id');
        $digitsOnly = preg_replace('/\D/', '', $query);

        $invoice = Order::where('tenant_id', $tenantId)
            ->where('type', 'pos')
            ->where(function ($q) use ($query, $digitsOnly) {
                $q->where('invoice_number', $query)
                    ->orWhere('invoice_number', 'like', '%' . $query . '%')
                    ->orWhere('id', $query);

                if (!empty($digitsOnly)) {
                    $q->orWhere('invoice_number', 'like', '%' . $digitsOnly . '%');
                }
            })
            ->first();

        if ($invoice) {
            $this->loadInvoice($invoice->id);
            $this->searchInvoiceQuery = '';
        } else {
            $this->errorMessage = "لم يتم العثور على أي فاتورة مطابقة للبحث: {$query}";
        }
    }

    public function loadInvoice(int $invoiceId)
    {
        $tenantId = session('active_tenant_id');
        $invoice = Order::where('tenant_id', $tenantId)->with('items.product')->find($invoiceId);

        if (!$invoice) {
            $this->errorMessage = 'لم يتم العثور على الفاتورة المطلوبة.';
            return;
        }

        $this->currentInvoiceId = $invoice->id;
        $this->cart = [];

        foreach ($invoice->items as $item) {
            $product = $item->product;
            $this->cart[$item->product_id] = [
                'id' => $item->product_id,
                'name' => $product->name ?? 'منتج غير محدد',
                'barcode' => '',
                'price' => (float) $item->unit_price,
                'cost_price' => (float) ($item->cost_price ?? ($product->cost_price ?? 0)),
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
        $this->errorMessage = null;
        $this->successMessage = "تم عرض الفاتورة رقم #{$invoice->id} ({$invoice->invoice_number})";
    }

    public function previousInvoice()
    {
        $tenantId = session('active_tenant_id');
        $previous = Order::where('tenant_id', $tenantId)
            ->when($this->currentInvoiceId, function ($q) {
                $q->where('id', '<', $this->currentInvoiceId);
            })
            ->orderBy('id', 'desc')
            ->first();

        if ($previous) {
            $this->loadInvoice($previous->id);
        } else {
            $this->errorMessage = 'وصلت إلى أول فاتورة، لا توجد فاتورة سابقة.';
        }
    }

    public function nextInvoice()
    {
        $tenantId = session('active_tenant_id');
        if (!$this->currentInvoiceId) {
            return;
        }

        $next = Order::where('tenant_id', $tenantId)->where('id', '>', $this->currentInvoiceId)->orderBy('id', 'asc')->first();

        if ($next) {
            $this->loadInvoice($next->id);
        } else {
            $this->clearCart();
            $this->successMessage = 'تم الانتقال إلى واجهة فاتورة جديدة.';
        }
    }

    public function scanBarcode()
    {
        $this->errorMessage = null;
        $this->successMessage = null;
        $trimmedBarcode = trim($this->barcode);

        if ($trimmedBarcode === '') {
            return;
        }

        if (!$this->activeShift) {
            $this->errorMessage = 'يرجى فتح شيفت أولاً قبل مسح المنتجات!';
            $this->showOpenShiftModal = true;
            $this->barcode = '';
            return;
        }

        $tenantId = session('active_tenant_id');
        $barcodeRecord = ProductBarcode::where('tenant_id', $tenantId)->where('barcode', $trimmedBarcode)->first();

        if ($barcodeRecord && $barcodeRecord->product) {
            $this->addToCart($barcodeRecord->product, $trimmedBarcode);
        } else {
            $this->errorMessage = "عذراً، لم يتم العثور على منتج بالباركود: {$trimmedBarcode}";
        }

        $this->barcode = '';
    }

    public function addToCart(Product $product, string $scannedBarcode = '')
    {
        if (!$this->activeShift) {
            $this->errorMessage = 'يرجى فتح شيفت أولاً لإضافة منتجات!';
            $this->showOpenShiftModal = true;
            return;
        }

        $branchId = $this->getActiveBranchId();
        if (!$branchId) {
            $this->errorMessage = 'يرجى اختيار الفرع أولاً!';
            return;
        }

        $this->showProductsModal = false;

        if ($this->currentInvoiceId) {
            $this->currentInvoiceId = null;
        }

        $branchProduct = BranchProduct::where('branch_id', $branchId)->where('product_id', $product->id)->first();
        $retailPrice = $branchProduct ? (float) $branchProduct->retail_price : (float) ($product->retail_price ?? 0);

        $productId = $product->id;
        $changeQty = $this->isReturnMode ? -1 : 1;

        if (isset($this->cart[$productId])) {
            $this->cart[$productId]['quantity'] += $changeQty;

            if ($this->cart[$productId]['quantity'] === 0) {
                unset($this->cart[$productId]);
                if (empty($this->cart)) {
                    $this->clearCartState();
                } else {
                    $this->recalculatePrices();
                }
                return;
            }
        } else {
            $this->cart[$productId] = [
                'id' => $product->id,
                'name' => $product->name,
                'barcode' => $scannedBarcode,
                'price' => $retailPrice,
                'cost_price' => (float) ($product->cost_price ?? 0),
                'quantity' => $changeQty,
                'subtotal' => $retailPrice * $changeQty,
                'has_offer' => false,
            ];
        }

        $this->recalculatePrices();
        $this->loadQuickProducts();
    }

    public function updateUnitPrice($productId, $newPrice)
    {
        $price = (float) $newPrice;
        if ($price >= 0 && isset($this->cart[$productId])) {
            $this->cart[$productId]['price'] = $price;
            $this->recalculatePrices();
        }
    }

    public function updateCostPrice($productId, $newCost)
    {
        $cost = (float) $newCost;
        if ($cost >= 0 && isset($this->cart[$productId])) {
            $this->cart[$productId]['cost_price'] = $cost;
            $this->recalculatePrices();
        }
    }

    public function updateQuantity($productId, $qty)
    {
        $qty = (int) $qty;
        if ($qty === 0) {
            $this->removeFromCart($productId);
        } else {
            if (isset($this->cart[$productId])) {
                $this->cart[$productId]['quantity'] = $qty;
                $this->recalculatePrices();
            }
        }
    }

    public function removeFromCart($productId)
    {
        unset($this->cart[$productId]);
        if (empty($this->cart)) {
            $this->clearCartState();
        } else {
            $this->recalculatePrices();
        }
    }

    public function clearCart()
    {
        $this->clearCartState();
        $this->errorMessage = null;
        $this->successMessage = 'تم تنظيف محتويات الفاتورة بنجاح.';
    }

    private function clearCartState()
    {
        $this->cart = [];
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
        $this->total = 0;
        $this->calculated_discount = 0;
        $this->subtotal = 0;
        $this->showBelowCostModal = false;

        $user = Auth::user();
        $this->selectedBranchId = $user->branch_id ?? session('active_branch_id');
        $this->loadQuickProducts();
    }

    public function appendNumpad(string $val)
    {
        if ($val === 'C') {
            $this->paid_amount = 0;
            return;
        }

        $current = (string) $this->paid_amount;
        if ($current === '0') {
            $current = '';
        }

        $newVal = $current . $val;
        $this->paid_amount = (float) $newVal;
    }

    private function roundUpToNearestHalf(float $amount): float
    {
        $sign = $amount < 0 ? -1 : 1;
        return $sign * (ceil(abs($amount) * 2) / 2);
    }

    private function recalculatePrices()
    {
        foreach ($this->cart as $id => $item) {
            $rawSubtotal = $item['quantity'] * $item['price'];
            $this->cart[$id]['subtotal'] = $this->roundUpToNearestHalf($rawSubtotal);
        }
    }

    public function updatedDiscountAmount()
    {
        $this->custom_final_total = null;
    }

    public function updatedDiscountType()
    {
        $this->custom_final_total = null;
    }

    public function toggleDiscountType()
    {
        $this->discount_type = $this->discount_type === 'fixed' ? 'percentage' : 'fixed';
        $this->custom_final_total = null;
    }

    public function updatedCustomFinalTotal($value)
    {
        if ($value === '' || $value === null) {
            $this->custom_final_total = null;
            return;
        }

        $targetTotal = (float) $value;
        $subtotal = $this->subtotal;

        if ($subtotal > 0 && $targetTotal <= $subtotal) {
            $this->discount_type = 'fixed';
            $this->discount_amount = $subtotal - $targetTotal;
        }
    }

    public function getSubtotalProperty(): float
    {
        if (empty($this->cart)) {
            return 0.0;
        }
        return array_sum(array_column($this->cart, 'subtotal'));
    }

    public function getTotalCostProperty(): float
    {
        if (empty($this->cart)) {
            return 0.0;
        }
        $totalCost = 0;
        foreach ($this->cart as $item) {
            $totalCost += ($item['cost_price'] ?? 0) * $item['quantity'];
        }
        return $totalCost;
    }

    public function getExpectedProfitProperty(): float
    {
        if (empty($this->cart)) {
            return 0.0;
        }
        return $this->total - $this->total_cost;
    }

    public function getCalculatedDiscountProperty(): float
    {
        if (empty($this->cart)) {
            return 0.0;
        }

        $subtotal = $this->subtotal;
        if ($subtotal <= 0) {
            return 0.0;
        }

        $discount = (float) ($this->discount_amount ?? 0);

        if ($this->discount_type === 'percentage') {
            $percentage = min(100, max(0, $discount));
            return ($subtotal * $percentage) / 100;
        }

        return min($subtotal, max(0, $discount));
    }

    public function getTotalProperty(): float
    {
        if (empty($this->cart)) {
            return 0.0;
        }

        if ($this->custom_final_total !== null && $this->custom_final_total !== '') {
            return (float) $this->custom_final_total;
        }

        return max(0, $this->subtotal - $this->calculated_discount);
    }

    public function getChangeProperty(): float
    {
        if (empty($this->cart)) {
            return 0.0;
        }
        $paid = (float) ($this->paid_amount ?? 0);
        return $paid - $this->total;
    }

    public function getHasBelowCostItemProperty(): bool
    {
        foreach ($this->cart as $item) {
            if ($item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0)) {
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

    public function checkoutAndPrint()
    {
        if ($this->has_below_cost_item && !$this->showBelowCostModal) {
            $this->pendingCheckoutMode = 'checkoutAndPrint';
            $this->showBelowCostModal = true;
            return;
        }

        $this->showBelowCostModal = false;

        // 1. تجهيز بيانات الفاتورة
        $this->printReceipt();

        // 2. إتمام العملية وتصفير السلة
        $this->processCheckout();
    }

    public function confirmBelowCostCheckout()
    {
        $this->showBelowCostModal = false;
        if ($this->pendingCheckoutMode === 'checkoutAndPrint') {
            $this->printReceipt();
            $this->processCheckout();
        } else {
            $this->processCheckout();
        }
    }

    private function processCheckout(): ?Order
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        if (!$this->activeShift) {
            $this->errorMessage = 'لا يمكنك إجراء عملية بيع بدون فتح شيفت أولاً!';
            $this->showOpenShiftModal = true;
            return null;
        }

        if (empty($this->cart)) {
            $this->errorMessage = 'الفاتورة فارغة حالياً!';
            return null;
        }

        $tenantId = session('active_tenant_id');
        if (!$tenantId) {
            $this->errorMessage = 'يرجى اختيار متجر أولاً لتتمكن من إتمام الفاتورة!';
            return null;
        }

        $user = Auth::user();
        $branchId = $this->getActiveBranchId();

        if (!$branchId) {
            $this->errorMessage = 'تعذر إتمام العملية: يرجى تحديد الفرع أولاً!';
            return null;
        }

        $subtotal = $this->subtotal;
        $discount = $this->calculated_discount;
        $total = $this->total;

        $invoiceType = $total < 0 ? 'return' : 'pos';
        $prefix = $invoiceType === 'return' ? 'RET-' : 'POS-';

        try {
            $order = null;
            DB::transaction(function () use ($user, $tenantId, $branchId, $subtotal, $discount, $total, $invoiceType, $prefix, &$order) {
                $order = Order::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'shift_id' => $this->activeShift->id,
                    'user_id' => $user->id,
                    'customer_id' => null,
                    'invoice_number' => $prefix . time(),
                    'type' => $invoiceType,
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'total' => $total,
                    'paid_amount' => (float) $this->paid_amount != 0 ? (float) $this->paid_amount : $total,
                    'payment_status' => 'paid',
                    'notes' => $this->notes,
                    'created_at' => now(),
                ]);

                foreach ($this->cart as $item) {
                    OrderItem::create([
                        'tenant_id' => $tenantId,
                        'order_id' => $order->id,
                        'product_id' => $item['id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['price'],
                        'cost_price' => $item['cost_price'] ?? 0,
                        'total_price' => $item['subtotal'],
                    ]);

                    BranchProduct::where('branch_id', $branchId)->where('product_id', $item['id'])->decrement('stock_quantity', $item['quantity']);
                }
            });

            $this->clearCartState();
            $this->loadQuickProducts();
            $this->checkActiveShift();

            $this->successMessage = $invoiceType === 'return' ? 'تمت عملية الإرجاع وحفظ الفاتورة بنجاح!' : 'تمت عملية البيع وحفظ الفاتورة بنجاح!';
            return $order;
        } catch (\Exception $e) {
            $this->errorMessage = 'خطأ في العملية: ' . $e->getMessage();
            return null;
        }
    }

    public function getInvoiceCreatorProperty(): string
    {
        if ($this->currentInvoiceId) {
            $invoice = Order::with('user')->find($this->currentInvoiceId);
            return $invoice?->user?->name ?? 'غير محدد';
        }

        return Auth::user()->name ?? 'الكاشير الحالي';
    }

    public function getInvoiceDateProperty(): string
    {
        if ($this->currentInvoiceId) {
            $invoice = Order::find($this->currentInvoiceId);
            if ($invoice && $invoice->created_at) {
                return $invoice->created_at->locale('ar')->isoFormat('dddd، YYYY-MM-DD - h:mm A');
            }
        }

        return now()->locale('ar')->isoFormat('dddd، YYYY-MM-DD');
    }

    public function render()
    {
        $tenantId = session('active_tenant_id');
        $branches = !Auth::user()->branch_id ? Branch::where('tenant_id', $tenantId)->get() : [];

        return $this->view([
            'branches' => $branches,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="h-[calc(100vh-4rem)] p-2 bg-slate-100 font-sans select-none overflow-hidden no-print">
    <div x-data x-on:keydown.window.f1.prevent="$wire.set('showHeldModal', !$wire.showHeldModal)"
        x-on:keydown.window.f2.prevent="$wire.holdInvoice()" x-on:keydown.window.f3.prevent="$wire.checkout()"
        x-on:keydown.window.f6.prevent="$wire.printReceipt()" x-on:keydown.window.f4.prevent="$wire.clearCart()"
        x-on:keydown.window.f10.prevent="$wire.set('showProductsModal', !$wire.showProductsModal)" class="h-full">
        <div class="grid grid-cols-12 gap-2 h-full">

            <!-- ==================== قسم الفاتورة والحسابات ==================== -->
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
                    <div class="p-2 bg-slate-50 border-b border-slate-200 relative shrink-0">
                        <div class="relative flex items-center gap-2">
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
                    <div class="relative">
                        <div class="flex justify-between items-center mb-0.5">
                            <label class="text-[10px] font-bold text-slate-600">المبلغ المدفوع (نقداً):</label>
                            <button type="button" @click="showNumpad = !showNumpad"
                                class="text-[10px] text-indigo-600 font-bold underline">[لوحة الأرقام]</button>
                        </div>
                        <input type="number" wire:model.live="paid_amount" @focus="showNumpad = true"
                            class="w-full text-lg font-black font-mono text-left bg-slate-50 border border-slate-300 rounded-lg p-1 focus:outline-none focus:border-indigo-600 text-indigo-900">

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

                    <div class="grid grid-cols-2 gap-1.5">
                        <button wire:click="printReceipt" @if (count($cart) === 0 && !$currentInvoiceId) disabled @endif
                            class="col-span-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-lg font-bold py-2 px-3 shadow active:scale-95 transition-all text-xs flex justify-between items-center">
                            <span>🖨️ طباعة الفاتورة</span>
                            <span class="text-[9px] bg-blue-800 text-white px-1.5 py-0.5 rounded font-mono">F6</span>
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
    </div>

    <!-- ==================== قالب الفاتورة المعتمد من تجربتك ==================== -->
    <!-- ==================== قالب الفاتورة الحرارية ==================== -->
    <div id="receipt-print-area" class="receipt-box">
        @if (!empty($receipt))
            <!-- الرأسية / Header -->
            <div class="header">
                <h2 class="store-title">{{ $receipt['store_name'] }}</h2>
                <p class="notice">{{ $receipt['notice'] }}</p>
                <div class="divider-line"></div>
                <p class="copy-type">{{ $receipt['copy_type'] }}</p>
            </div>

            <!-- تفاصيل الرقم والتاريخ -->
            <div class="meta-info">
                <span>رقم: <strong>{{ $receipt['invoice_no'] }}</strong></span>
                <span>التاريخ: <strong>{{ $receipt['date'] }}</strong></span>
                <span>الوقت: <strong>{{ $receipt['time'] }}</strong></span>
            </div>

            <!-- جدول المنتجات -->
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 8%;">#</th>
                        <th style="width: 48%;">البيان</th>
                        <th style="width: 12%;">كمية</th>
                        <th style="width: 16%;">سعر</th>
                        <th style="width: 16%;">مبلغ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($receipt['items'] as $item)
                        <tr>
                            <td>{{ $item['id'] }}</td>
                            <td class="item-name">{{ $item['name'] }}</td>
                            <td>{{ $item['qty'] }}</td>
                            <td>{{ $item['price'] }}</td>
                            <td>{{ $item['total'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <!-- مجموع الكميات والمبالغ -->
            <div class="info-box">
                <span>مجموع الكميات:</span>
                <strong class="font-bold-black">{{ $receipt['total_qty'] }}</strong>
            </div>

            <div class="info-box">
                <span>المجموع:</span>
                <strong class="font-bold-black">{{ $receipt['total_amount'] }}</strong>
            </div>

            <div class="net-box">
                <span>الصافي للدفع ({{ $receipt['currency'] }}):</span>
                <strong class="net-value">{{ $receipt['net_amount'] }}</strong>
            </div>

            <!-- الباركود والتذييل -->
            <div class="barcode-section">
                <svg id="barcode"></svg>
                <p class="system-name">{{ $receipt['system_name'] }}</p>
                <p class="print-time">تاريخ ووقت الطباعة: {{ $receipt['date'] }} {{ $receipt['time'] }}</p>
            </div>
        @endif
    </div>

    <style>
        /* خط مخصص وأداء ممتاز للطابعات الحرارية */
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@600;800;900&display=swap');

        /* الحاوية الرئيسية للفاتورة (على الشاشة) */
        .receipt-box {
            width: 80mm;
            max-width: 80mm;
            background: #fff;
            padding: 2mm 3mm;
            box-sizing: border-box;

            font-family: 'Cairo', 'Courier New', monospace, sans-serif;
            font-size: 13px;
            line-height: 1.3;
            color: #000;
            direction: rtl;
            text-align: center;
            margin: 0 auto;
        }

        /* تغميق النصوص والحدود بأقصى درجة */
        .receipt-box *,
        .receipt-box strong,
        .receipt-box td,
        .receipt-box th,
        .receipt-box span,
        .receipt-box p {
            color: #000 !important;
            font-weight: 800 !important;
        }

        .font-bold-black {
            font-weight: 900 !important;
            font-size: 14px;
        }

        .header .store-title {
            font-size: 22px;
            font-weight: 900 !important;
            margin: 0 0 2px 0;
            line-height: 1.2;
        }

        .header .notice {
            font-size: 11px;
            margin: 3px 0;
            font-weight: 800 !important;
            line-height: 1.2;
        }

        .header .copy-type {
            font-size: 13px;
            font-weight: 900 !important;
            margin: 4px 0 6px 0;
            background-color: #000;
            color: #fff !important;
            padding: 2px 0;
            border-radius: 2px;
        }

        .divider-line {
            border-bottom: 2px dashed #000;
            margin: 4px 0;
        }

        .meta-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 11px;
            font-weight: 800 !important;
            margin-bottom: 6px;
            padding: 0 1px;
            border-bottom: 1.5px solid #000;
            padding-bottom: 4px;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            table-layout: fixed;
        }

        .items-table th {
            border: 2px solid #000;
            padding: 4px 1px;
            text-align: center;
            font-size: 12px;
            font-weight: 900 !important;
            background-color: #f0f0f0 !important;
        }

        .items-table td {
            border: 1.5px solid #000;
            padding: 4px 2px;
            text-align: center;
            font-size: 12px;
            font-weight: 800 !important;
            word-break: break-word;
        }

        .items-table .item-name {
            text-align: right;
            font-size: 12px;
            line-height: 1.2;
            padding-right: 3px;
        }

        .info-box {
            border: 1.5px solid #000;
            padding: 3px 6px;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 13px;
            font-weight: 800 !important;
        }

        .net-box {
            border: 2.5px solid #000;
            padding: 4px 6px;
            margin: 6px 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: 900 !important;
            background-color: #f9f9f9 !important;
        }

        .net-value {
            font-size: 18px;
            font-weight: 900 !important;
        }

        .barcode-section {
            margin-top: 6px;
            text-align: center;
        }

        #barcode {
            width: 85%;
            max-height: 40px;
            margin: 0 auto;
        }

        .system-name {
            font-size: 11px;
            font-weight: 900 !important;
            margin: 4px 0 0 0;
        }

        .print-time {
            font-size: 9px;
            font-weight: 800 !important;
            margin: 2px 0;
        }

        /* ========================================================
       عزل الطباعة الآمن بنسبة 100% (حل مشكلة الفاتورة البيضاء)
       ======================================================== */
      @media print {

    @page {
        size: 80mm auto;
        margin: 0;
    }

    html,
    body {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    body {
        visibility: hidden !important;
    }

    #receipt-print-area,
    #receipt-print-area * {
        visibility: visible !important;
    }

    #receipt-print-area {
        position: absolute !important;

        /* التوسيط */
        left: 50% !important;
        top: 0 !important;
        transform: translateX(-50%) !important;

        width: 80mm !important;
        max-width: 80mm !important;

        margin: 0 !important;
        padding: 2mm 3mm !important;

        box-sizing: border-box !important;

        background: #fff !important;

        direction: rtl !important;
        text-align: center !important;

        font-family: 'Cairo', Arial, sans-serif !important;
        font-size: 12px !important;
        line-height: 1.25 !important;
    }

    .receipt-box {
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 auto !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }

    .items-table {
        width: 100% !important;
        table-layout: fixed !important;
    }

    .items-table th,
    .items-table td {
        padding: 3px 2px !important;
        font-size: 11px !important;
    }

    .items-table .item-name {
        font-size: 11px !important;
        word-break: break-word !important;
    }

    .header .store-title {
        font-size: 20px !important;
    }

    .header .notice {
        font-size: 10px !important;
    }

    .meta-info {
        font-size: 10px !important;
    }

    .info-box {
        font-size: 11px !important;
        padding: 3px 4px !important;
    }

    .net-box {
        font-size: 12px !important;
        padding: 4px !important;
    }

    .net-value {
        font-size: 16px !important;
    }

    .system-name {
        font-size: 10px !important;
    }

    .print-time {
        font-size: 8px !important;
    }
}
    </style>

    <!-- مكتبة الباركود والسكربت الخاص بتجربتك -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>



    <script>
        function generateBarcode() {
            const barcodeElem = document.getElementById("barcode");
            if (barcodeElem && "{{ $receipt['invoice_no'] ?? '' }}") {
                JsBarcode("#barcode", "{{ $receipt['invoice_no'] ?? '00000' }}", {
                    format: "CODE128",
                    displayValue: false,
                    height: 40,
                    margin: 0
                });
            }
        }

        document.addEventListener('livewire:initialized', () => {
            generateBarcode();

            Livewire.on('trigger-print', () => {
                setTimeout(() => {
                    generateBarcode();
                    window.print();
                }, 300);
            });
        });
    </script>
</flux:main>
