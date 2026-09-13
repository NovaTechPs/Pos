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
    // --- البيانات والحالة (State) ---
    public string $barcode = '';
    public array $cart = [];
    public $paid_amount = 0;
    public string $notes = ''; // حقل الملاحظات

    // --- حقل البحث عن الفاتورة ---
    public string $searchInvoiceQuery = '';

    // --- حقل البحث عن الأصناف (بالاسم أو الباركود) ---
    public string $productSearchQuery = '';

    // --- تحديد الفرع الخاص بالأدمن والمستخدم ---
    public ?int $selectedBranchId = null;

    // --- إضافات الخصم والتعديل على السعر الإجمالي ---
    public $discount_amount = 0;
    public string $discount_type = 'fixed';
    public $custom_final_total = null;
    public $categories = [];
    public ?int $selectedCategoryId = null;
    public $quickProducts = [];
    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    // --- حالة التنقل بين الفواتير ---
    public ?int $currentInvoiceId = null;

    // --- ميزة تعليق الفواتير ---
    public array $heldInvoices = [];
    public bool $showHeldModal = false;

    // --- ميزة استطلاع تكلفة وأرباح الأصناف ---
    public bool $showCostModal = false;

    // --- ميزة التنبيه عند البيع تحت التكلفة ---
    public bool $showBelowCostModal = false;
    public string $pendingCheckoutMode = 'checkout';

    // --- ميزة وضع المرتجع المباشر ---
    public bool $isReturnMode = false;

    // --- إدارة الشيفت والصندوق ---
    public ?Shift $activeShift = null;
    public bool $showOpenShiftModal = false;
    public bool $showCloseShiftModal = false;
    public $opening_cash = 0;
    public $actual_cash = 0;
    public string $shift_notes = '';

    // --- الدورات والأحداث ---
    public function mount()
    {
        $user = Auth::user();
        $this->selectedBranchId = $user->branch_id ?? session('active_branch_id');

        $tenantId = session('active_tenant_id');
        $this->categories = Category::where('tenant_id', $tenantId)->get();
        $this->loadQuickProducts();
        $this->checkActiveShift();
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
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhereHas('barcodes', function ($bQuery) use ($search, $tenantId) {
                      $bQuery->where('tenant_id', $tenantId)
                             ->where('barcode', 'like', '%' . $search . '%');
                  });
            });
        }

        $products = $query->take(30)->get();

        $this->quickProducts = $products->map(function ($product) use ($branchId) {
            $branchData = BranchProduct::where('branch_id', $branchId)
                ->where('product_id', $product->id)
                ->first();

            $product->retail_price = $branchData?->retail_price ?? $product->retail_price ?? 0;
            return $product;
        });
    }

    private function getActiveBranchId(): ?int
    {
        $user = Auth::user();
        return $user->branch_id ?? $this->selectedBranchId;
    }

    // --- وظائف الشيفت والصندوق ---
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

        $cashSales = Order::where('shift_id', $this->activeShift->id)->where('type', 'pos')->sum('paid_amount');
        $cashReturns = Order::where('shift_id', $this->activeShift->id)->where('type', 'return')->sum('paid_amount');

        $this->activeShift->total_sales = $cashSales;
        $this->activeShift->total_returns = abs($cashReturns);
        $this->activeShift->expected_cash = $this->activeShift->opening_cash + $cashSales - abs($cashReturns);
        $this->actual_cash = $this->activeShift->expected_cash;

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
        $invoice = Order::where('tenant_id', $tenantId)
            ->where(function ($q) use ($query) {
                $q->where('invoice_number', $query)
                  ->orWhere('invoice_number', 'like', '%' . $query . '%')
                  ->orWhere('id', $query);
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
            return;
        }

        $tenantId = session('active_tenant_id');
        $barcodeRecord = ProductBarcode::where('tenant_id', $tenantId)->where('barcode', $trimmedBarcode)->first();

        if ($barcodeRecord && $barcodeRecord->product) {
            $this->addToCart($barcodeRecord->product, $trimmedBarcode);
            $this->barcode = '';
        } else {
            $this->errorMessage = 'عذراً، لم يتم العثور على منتج بهذا الباركود!';
            $this->barcode = '';
        }
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

    // --- الفحص الذاتي للبيع تحت التكلفة ---
    public function getHasBelowCostItemProperty(): bool
    {
        foreach ($this->cart as $item) {
            if ($item['quantity'] > 0 && (float)$item['price'] < (float)($item['cost_price'] ?? 0)) {
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
        $order = $this->processCheckout();
        if ($order) {
            $this->dispatch('print-receipt', ['orderId' => $order->id]);
        }
    }

    public function confirmBelowCostCheckout()
    {
        $this->showBelowCostModal = false;
        if ($this->pendingCheckoutMode === 'checkoutAndPrint') {
            $order = $this->processCheckout();
            if ($order) {
                $this->dispatch('print-receipt', ['orderId' => $order->id]);
            }
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

<flux:main class="h-[calc(100vh-4rem)] p-2 bg-slate-100 font-sans select-none overflow-hidden">
    <div x-data x-on:keydown.window.f1.prevent="$wire.set('showHeldModal', !$wire.showHeldModal)"
        x-on:keydown.window.f2.prevent="$wire.holdInvoice()" x-on:keydown.window.f3.prevent="$wire.checkout()"
        x-on:keydown.window.f6.prevent="$wire.checkoutAndPrint()" x-on:keydown.window.f4.prevent="$wire.clearCart()"
        class="h-full">
        <div class="grid grid-cols-12 gap-2 h-full">
            <div class="col-span-12 lg:col-span-7 flex flex-col h-full space-y-2 min-h-0">
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
                        <input type="text" wire:model="searchInvoiceQuery"
                            placeholder="رقم الفاتورة أو ID..."
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

                <!-- تنبيه مبكر في الواجهة عند البيع تحت التكلفة -->
                @if ($this->has_below_cost_item)
                    <div class="bg-rose-100 border-l-4 border-rose-600 text-rose-900 p-2 rounded-lg text-xs font-bold flex items-center justify-between shadow-sm animate-pulse">
                        <div class="flex items-center gap-2">
                            <span class="text-sm">⚠️</span>
                            <span>تنبيه: يوجد منتج (أو أكثر) بسعر بيع أقل من سعر التكلفة!</span>
                        </div>
                    </div>
                @endif

                <div
                    class="flex-1 bg-white border border-slate-300 rounded-xl overflow-hidden shadow-sm flex flex-col min-h-0">
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
                                        $isBelowCost = $item['quantity'] > 0 && (float)$item['price'] < (float)($item['cost_price'] ?? 0);
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
                                                    class="inline-block bg-rose-600 text-white text-[10px] px-1.5 py-0.5 rounded font-bold mr-1 animate-bounce">تحت التكلفة</span>
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
                                            <input type="number" step="0.01" value="{{ $item['cost_price'] ?? 0 }}"
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
                                            {{ number_format($item['subtotal'], 2) }}</td>
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

                <div class="grid grid-cols-12 gap-2 shrink-0">
                    <div
                        class="col-span-6 bg-white p-2.5 rounded-xl border border-slate-300 shadow-sm flex flex-col justify-between">
                        <div class="mb-1.5">
                            <label class="text-[11px] font-bold text-slate-600">المبلغ المدفوع (نقداً):</label>
                            <input type="number" wire:model.live="paid_amount"
                                class="w-full text-xl font-black font-mono text-left bg-slate-50 border border-slate-300 rounded-lg p-1.5 focus:outline-none focus:border-indigo-600 text-indigo-900">
                        </div>
                        <div class="grid grid-cols-3 gap-1 font-bold font-mono">
                            @foreach (['7', '8', '9', '4', '5', '6', '1', '2', '3', '0', '.', 'C'] as $num)
                                <button type="button" wire:click="appendNumpad('{{ $num }}')"
                                    class="py-1.5 bg-slate-100 hover:bg-slate-200 border border-slate-300 rounded text-sm text-slate-800 shadow-sm active:bg-slate-300">
                                    {{ $num }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                    <div class="col-span-6 flex flex-col justify-between gap-2">
                        <button wire:click="checkout" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif
                            class="flex-1 bg-slate-700 hover:bg-slate-800 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-xl font-extrabold flex flex-col items-center justify-center p-2 shadow active:scale-95 transition-all text-center">
                            <span class="text-sm">حفظ فقط (بدون طباعة)</span>
                            <span
                                class="text-[10px] bg-slate-900 text-white px-2 py-0.5 rounded font-mono mt-1">F3</span>
                        </button>

                        <button wire:click="checkoutAndPrint" @if (count($cart) === 0 || $currentInvoiceId) disabled @endif
                            class="flex-1 {{ $this->total < 0 ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700' }} disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-xl font-extrabold flex flex-col items-center justify-center p-2 shadow active:scale-95 transition-all text-center">
                            <span class="text-base">🖨️
                                {{ $this->total < 0 ? 'حفظ وطباعة المرتجع' : 'حفظ وطباعة' }}</span>
                            <span
                                class="text-[10px] {{ $this->total < 0 ? 'bg-rose-800' : 'bg-emerald-800' }} text-white px-2 py-0.5 rounded font-mono mt-1">F6</span>
                        </button>

                        <button wire:click="clearCart" @if (count($cart) === 0) disabled @endif
                            class="bg-slate-500 hover:bg-slate-600 disabled:bg-slate-200 disabled:text-slate-400 text-white rounded-xl font-bold p-2 shadow active:scale-95 transition-all text-xs text-center">
                            <span>تنظيف السلة (F4)</span>
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="col-span-12 lg:col-span-5 flex flex-col h-full bg-white border border-slate-300 rounded-xl p-2.5 shadow-sm min-h-0 space-y-2">

                <!-- حقل المباشرة لمسح الباركود بالأجهزة -->
                <form wire:submit.prevent="scanBarcode" class="shrink-0">
                    <input wire:model="barcode"
                        placeholder="{{ $isReturnMode ? 'امسح الباركود لإرجاعه...' : 'امسح الباركود هنا لإضافته المباشرة...' }}"
                        autofocus
                        class="w-full bg-slate-50 border {{ $isReturnMode ? 'border-rose-400 focus:outline-rose-600' : 'border-slate-300 focus:outline-indigo-600' }} rounded-lg p-2 text-xs font-semibold">
                </form>

                <!-- حقل البحث في الأصناف (بالاسم أو الباركود) -->
                <div class="relative shrink-0">
                    <input type="text"
                        wire:model.live.debounce.250ms="productSearchQuery"
                        placeholder="بحث في الأصناف (بالاسم أو الباركود)... 🔍"
                        class="w-full bg-indigo-50/50 border border-indigo-200 rounded-lg p-2 text-xs font-bold text-indigo-900 focus:outline-indigo-600 focus:bg-white placeholder-indigo-400">
                    @if(!empty($productSearchQuery))
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
                            <button wire:click="addToCart({{ $p->id }})"
                                class="{{ $isReturnMode ? 'bg-rose-700 hover:bg-rose-800 border-rose-900' : 'bg-indigo-700 hover:bg-indigo-800 border-indigo-900' }} text-white p-2 rounded-lg shadow-sm flex flex-col justify-between items-start text-right transition-all h-20 active:scale-95 border">
                                <span class="text-xs font-bold line-clamp-2 leading-tight">{{ $p->name }}</span>
                                <span
                                    class="text-xs font-mono font-black text-amber-300 mt-1">{{ number_format($p->retail_price, 2) }}</span>
                            </button>
                        @empty
                            <div class="col-span-full text-center py-16 text-slate-400 text-xs font-semibold">
                                لا توجد منتجات مطابقة لنتيجة البحث
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

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
                                    class="font-mono text-indigo-700">{{ number_format($activeShift->opening_cash ?? 0, 2) }}</span>
                            </div>
                            <div>المبيعات النقدية: <span
                                    class="font-mono text-emerald-600">{{ number_format($activeShift->total_sales ?? 0, 2) }}</span>
                            </div>
                            <div>المرتجعات النقدية: <span
                                    class="font-mono text-rose-600">{{ number_format($activeShift->total_returns ?? 0, 2) }}</span>
                            </div>
                            <div class="col-span-2 border-t pt-2 text-sm text-slate-900">
                                المتوقع بالدرج: <span
                                    class="font-mono font-black text-amber-600">{{ number_format($activeShift->expected_cash ?? 0, 2) }}</span>
                            </div>
                        </div>

                        <div>
                            <label class="block font-bold text-slate-700 mb-1">المبلغ الفعلي الموجود بالدرج بعد
                                العدّ:</label>
                            <input type="number" step="0.01" wire:model.live="actual_cash"
                                class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2 text-lg font-black font-mono text-center focus:outline-indigo-600">
                        </div>

                        @php
                            $diff = (float) $actual_cash - (float) ($activeShift->expected_cash ?? 0);
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
                        <button wire:click="$set('showBelowCostModal', false)" class="text-rose-100 hover:text-white font-bold">✕</button>
                    </div>

                    <div class="p-4 space-y-3">
                        <p class="text-xs font-bold text-slate-700 leading-relaxed">
                            تحذير! تحتوي الفاتورة على منتجات يتم بيعها بأسعار أقل من التكلفة:
                        </p>

                        <div class="max-h-48 overflow-y-auto space-y-2">
                            @foreach($cart as $item)
                                @if($item['quantity'] > 0 && (float)$item['price'] < (float)($item['cost_price'] ?? 0))
                                    <div class="bg-rose-50 border border-rose-200 p-2 rounded-lg text-xs flex justify-between items-center font-bold">
                                        <div>
                                            <div class="text-slate-900">{{ $item['name'] }}</div>
                                            <div class="text-[10px] text-rose-700">التكلفة: {{ number_format($item['cost_price'], 2) }}</div>
                                        </div>
                                        <div class="text-rose-700 font-mono text-sm">
                                            سعر البيع: {{ number_format($item['price'], 2) }}
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        <p class="text-xs text-slate-600 font-semibold">هل تريد الاستمرار وإتمام الفاتورة بهذا السعر؟</p>

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
</flux:main>

<script>
    document.addEventListener('livewire:initialized', () => {
        Livewire.on('print-receipt', (event) => {
            const orderId = event.orderId || (event[0] ? event[0].orderId : null);
            if (orderId) {
                window.open(`/orders/${orderId}/print`, '_blank', 'width=400,height=600');
            }
        });
    });
</script>
