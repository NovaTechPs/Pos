<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncOperation;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\ProductOffer;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shift;
use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use App\Models\TenantSetting;
use App\Services\DesktopPosConnection;
use App\Services\PosProductSearch;

new class extends Component {
    #[Locked]
    public string $connectionState = 'offline';

    /** @var list<int> Products already checked against the server during this request. */
    private array $verifiedRemoteProductIds = [];

    private ?string $quickProductsRequestKey = null;

    /** @param array<string, mixed> $filters
     * @return list<int>|null
     */
    private function refreshRemoteCatalog(string $entity = 'products', array $filters = []): ?array
    {
        $connection = app(DesktopPosConnection::class);
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();
        if (!$connection->enabled() || !$tenantId || !$branchId) {
            return null;
        }
        $ids = $connection->read((int) $tenantId, (int) $branchId, $entity, $filters);
        $this->connectionState = $connection->state;
        if ($ids !== null) {
            if ($entity === 'products') {
                $this->verifiedRemoteProductIds = array_values(array_unique([...$this->verifiedRemoteProductIds, ...$ids]));
            }
            $this->allowNegativeStock = TenantSetting::getBool((int) $tenantId, 'allow_negative_stock');
            $this->unifiedStock = TenantSetting::getBool((int) $tenantId, 'unified_stock');
            $this->mergeSimilarProducts = TenantSetting::getBool((int) $tenantId, 'merge_similar_products');
            $this->categories = Category::query()->where('tenant_id', $tenantId)->orderBy('name')->get()->toArray();
        }

        return $ids;
    }

    /**
     * Checks the central server first, so an offline device pays for a single failed request.
     * Queued offline work is uploaded only once the server answers, then the catalog is read again
     * so local stock reflects the uploaded sales.
     */
    public function refreshLiveData(): void
    {
        $connection = app(DesktopPosConnection::class);
        $tenantId = (int) $this->tenantId();
        $branchId = (int) $this->getActiveBranchId();
        $desktop = $connection->enabled() && $tenantId && $branchId;
        if ($desktop) {
            $connection->retry($tenantId, $branchId);
        }
        $this->loadQuickProducts(force: true, refreshRemoteCatalog: true);
        if ($desktop && $this->connectionState === 'pending' && $connection->hasPendingUploads($tenantId, $branchId)) {
            $connection->flush($tenantId, $branchId);
            $this->loadQuickProducts(force: true, refreshRemoteCatalog: true);
        }
        $this->refreshBranchSyncStatus();
    }

    /** Lets the cashier work offline while the internet is slow, and return online to upload queued work. */
    public function setManualOffline(bool $offline): void
    {
        $connection = app(DesktopPosConnection::class);
        $tenantId = (int) $this->tenantId();
        $branchId = (int) $this->getActiveBranchId();
        if (! $connection->enabled() || ! $tenantId || ! $branchId) {
            return;
        }
        $connection->setManuallyOffline($tenantId, $branchId, $offline);
        $this->errorMessage = null;
        if ($offline) {
            $this->connectionState = 'manual_offline';
            $this->refreshBranchSyncStatus();
            $this->successMessage = 'تم التحويل إلى العمل دون اتصال. البيع مستمر من البيانات المحفوظة على الجهاز.';

            return;
        }
        $this->refreshLiveData();
        $this->successMessage = match ($this->connectionState) {
            'online' => 'تمت العودة للاتصال ومزامنة العمليات المحفوظة.',
            'pending' => 'تمت العودة للاتصال؛ بعض العمليات ما زالت بانتظار المزامنة أو المراجعة.',
            default => 'تعذر الوصول إلى السيرفر حالياً؛ سيستمر البيع محلياً وتُعاد المحاولة تلقائياً.',
        };
    }

    #[Locked]
    public ?string $draftContextKey = null;
    public array $receipt = [];
    public string $barcode = '';
    #[Locked]
    public string $missingBarcode = '';
    public bool $showMissingBarcodeModal = false;
    public bool $showNewProductModal = false;
    public ?int $editingProductId = null;
    public bool $linkMissingBarcode = false;
    public string $missingBarcodeSearch = '';
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
    /** @var array<int, int|float|string> */
    public array $quickProductQuantities = [];
    public ?int $selectedBranchId = null;
    public float $discount_amount = 0;
    public string $discount_type = 'fixed';
    public float $delivery_fee = 0;
    public bool $showDeliveryDetailsModal = false;
    public string $pendingDeliveryAction = 'checkout';
    public string $deliveryCustomerName = '';
    public ?int $deliveryCompanyId = null;
    public string $deliveryCustomerPhone = '';
    public string $deliveryCustomerAddress = '';
    public string $deliveryNotes = '';
    public bool $customerInvoiceCopy = false;
    public ?float $custom_final_total = null;
    public array $categories = [];
    public ?int $selectedCategoryId = null;
    public array $quickProducts = [];
    public ?string $errorMessage = null;
    public ?string $successMessage = null;
    public ?int $currentInvoiceId = null;
    public ?int $editingInvoiceId = null;
    public bool $invoiceEditMode = false;
    public array $heldInvoices = [];
    public bool $showHeldModal = false;
    public bool $showClearInvoiceModal = false;
    public bool $showHoldNotesModal = false;
    public string $holdNotes = '';
    public bool $showCostModal = false;
    public bool $showBelowCostModal = false;

    public bool $showCustomerPaymentModal = false;
    public float $customerPaymentAmount = 0;
    public string $pendingCustomerPaymentMode = 'checkout';
    public bool $customerPaymentConfirmed = false;

    public string $pendingCheckoutMode = 'checkout';
    public bool $showProductsModal = false;
    public bool $selectingGift = false;
    public bool $isReturnMode = false;

    public bool $showCashReturnModal = false;
    public string $cashReturnSearch = '';
    public ?int $cashReturnProductId = null;
    public ?float $cashReturnQuantity = null;
    public ?float $cashReturnPrice = null;
    public string $cashReturnCondition = 'resellable';
    public string $cashReturnReason = '';
    #[Locked]
    public string $cashReturnNumber = '';
    #[Locked]
    public ?int $cashReturnOrderId = null;
    #[Locked]
    public ?int $activeShiftId = null;
    #[Locked]
    public bool $showOpenShiftModal = false;
    #[Locked]
    public bool $showCloseShiftModal = false;
    public bool $showExpenseModal = false;
    public float $opening_cash = 0;
    public float $actual_cash = 0;
    public string $shift_notes = '';
    public float $shift_opening_cash = 0;
    #[Locked]
    public float $shift_total_sales = 0;
    #[Locked]
    public float $shift_total_returns = 0;
    #[Locked]
    public float $shift_cash_receipts = 0;
    #[Locked]
    public float $shift_cash_payments = 0;
    #[Locked]
    public float $shift_card_receipts = 0;
    #[Locked]
    public float $shift_bank_transfer_receipts = 0;
    #[Locked]
    public float $shift_bank_receipts = 0;
    public float $expense_amount = 0;
    public string $expense_category = 'كهرباء';
    public string $expense_notes = '';
    #[Locked]
    public float $shift_expected_cash = 0;
    public bool $allowNegativeStock = false;
    public bool $unifiedStock = false;
    public bool $mergeSimilarProducts = false;
    #[Locked]
    public int $pendingSyncOperations = 0;
    #[Locked]
    public int $conflictedSyncOperations = 0;

    private ?Shift $activeShiftCache = null;
    private bool $activeShiftLoaded = false;

    public function mount(): void
    {
        $user = Auth::user();
        $this->selectedBranchId = $user?->activeBranchId();
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
        $this->mergeSimilarProducts = TenantSetting::getBool(
            (int) $tenantId,
            'merge_similar_products',
            false
        );

        $this->loadHeldInvoices();
        $this->checkActiveShift();
        // On desktop, the first load also checks the central server so the connection status is known immediately.
        $this->loadQuickProducts(refreshRemoteCatalog: app(DesktopPosConnection::class)->enabled());
        $this->refreshBranchSyncStatus();
        $this->restoreCurrentDraft();

        $invoiceId = request()->integer('invoice');
        $this->customerInvoiceCopy = request()->boolean('customer_copy');
        if ($invoiceId > 0) {
            $this->loadInvoice($invoiceId);
        }
    }

    public function refreshBranchSyncStatus(): void
    {
        if (config('branch-sync.mode') !== 'edge') {
            $this->pendingSyncOperations = 0;
            $this->conflictedSyncOperations = 0;

            return;
        }

        $operations = BranchSyncOperation::query()
            ->where('tenant_id', $this->tenantId())
            ->where('branch_id', $this->getActiveBranchId());

        $this->pendingSyncOperations = (clone $operations)->where('status', 'pending')->count();
        $this->conflictedSyncOperations = (clone $operations)->whereIn('status', ['conflict', 'failed'])->count();
    }

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
        if (! empty($this->cart[$lineKey]['is_gift']) && in_array($field, ['price', 'subtotal'], true)) {
            return;
        }
        $value = str_replace(',', '.', trim((string) $value));

        switch ($field) {
            case 'quantity':
                $this->updateQuantity($lineKey, $value);
                break;

            case 'price':
                $this->authorize('pos.price.edit');
                $this->updateUnitPrice($lineKey, $value);
                break;

            case 'subtotal':
                $this->authorize('pos.price.edit');
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
        if (!$this->ensureInvoiceEditable() || $this->isReturnMode) {
            return;
        }
        $this->mergeSimilarProducts = !$this->mergeSimilarProducts;

        if (empty($this->cart)) {
            return;
        }

        if ($this->mergeSimilarProducts) {
            $this->mergeSimilarCartLines();
        } else {
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

            $groupKey = (string) $productId . (! empty($item['is_gift']) ? '_gift' : '');

            if (!isset($merged[$groupKey])) {
                $merged[$groupKey] = $item;
                continue;
            }

            $oldQuantity = (float) $merged[$groupKey]['quantity'];
            $merged[$groupKey]['quantity'] = $oldQuantity + $quantity;
            $totalQuantity = (float) $merged[$groupKey]['quantity'];
            if ($totalQuantity !== 0) {
                $merged[$groupKey]['subtotal'] = $this->roundMoney(
                    abs((float) $merged[$groupKey]['price'] * $totalQuantity)
                );
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

        $invoice = $this->invoiceNavigationQuery()->find($this->editingInvoiceId);
        if ($invoice?->type === 'return') {
            $this->editCashReturn((int) $invoice->id, true);
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

        $branchId = $user->activeBranchId() ?: $this->selectedBranchId;

        if (!$branchId) {
            return null;
        }

        // Checked once per request: this is called many times while handling a single scan or click.
        $cacheKey = $tenantId.':'.$branchId;
        $this->validBranches[$cacheKey] ??= Branch::query()->where('tenant_id', $tenantId)->whereKey((int) $branchId)->exists();

        return $this->validBranches[$cacheKey] ? (int) $branchId : null;
    }

    /** @var array<string, bool> Branch checks made during the current request. */
    private array $validBranches = [];

    private function draftFields(): array
    {
        return ['cart', 'paid_amount', 'payment_method', 'notes', 'selectedCustomerId', 'discount_amount',
            'discount_type', 'delivery_fee', 'deliveryCustomerName', 'deliveryCustomerPhone', 'deliveryCustomerAddress', 'deliveryCompanyId', 'deliveryNotes', 'custom_final_total', 'isReturnMode', 'currentInvoiceId',
            'editingInvoiceId', 'invoiceEditMode', 'mergeSimilarProducts', 'cashReturnNumber', 'cashReturnOrderId'];
    }

    private function currentDraftKey(): ?string
    {
        $key = $this->heldSessionKey();

        return $key ? str_replace('pos.held.', 'pos.draft.', $key) : null;
    }

    public function dehydrate(): void
    {
        $key = $this->currentDraftKey();
        if (!$key || $key !== $this->draftContextKey) {
            return;
        }
        if ($this->cart === [] && !$this->currentInvoiceId && $this->notes === '' && !$this->selectedCustomerId) {
            session()->forget($key);
            return;
        }
        $draft = [];
        foreach ($this->draftFields() as $field) {
            $draft[$field] = $this->{$field};
        }
        session()->put($key, $draft);
    }

    private function restoreCurrentDraft(): void
    {
        $this->draftContextKey = $this->currentDraftKey();
        $draft = $this->draftContextKey ? session($this->draftContextKey) : null;
        if (!is_array($draft) || !is_array($draft['cart'] ?? null)) {
            return;
        }
        if (!empty($draft['currentInvoiceId']) && !$this->invoiceNavigationQuery()->whereKey($draft['currentInvoiceId'])->exists()) {
            session()->forget($this->draftContextKey);
            return;
        }
        foreach ($this->draftFields() as $field) {
            if (array_key_exists($field, $draft)) {
                $this->{$field} = $draft[$field];
            }
        }
        if (!$this->currentInvoiceId || $this->invoiceEditMode) {
            $customTotal = $this->custom_final_total;
            $this->recalculatePrices();
            if ($customTotal !== null) {
                $this->custom_final_total = min(max(0, $customTotal), max(0, $this->subtotal + $this->delivery_fee));
            }
        }
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
        if ($this->activeShiftLoaded) {
            return $this->activeShiftCache;
        }

        $this->activeShiftLoaded = true;
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

        // Shift totals are not shown while selling; closing the shift computes them itself.
        $this->activeShiftCache = $shift;

        return $this->activeShiftCache;
    }

    /** @return array{sales: float, returns: float, cash_receipts: float, cash_payments: float, card_receipts: float, bank_transfer_receipts: float, bank_receipts: float, expected_cash: float} */
    private function shiftFinancialSummary(Shift $shift): array
    {
        $orders = Order::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('branch_id', $shift->branch_id)
            ->where('shift_id', $shift->id)
            ->where('status', 'completed')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'pos' THEN total ELSE 0 END), 0) as sales")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'return' THEN ABS(total) ELSE 0 END), 0) as returns")
            ->first();

        $payments = Payment::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('shift_id', $shift->id)
            ->where(function ($query) use ($shift): void {
                $query->where('branch_id', $shift->branch_id)->orWhereNull('branch_id');
            })
            ->where('payment_method', 'cash')
            ->where(function ($query): void {
                $query->whereNull('order_id')
                    ->orWhereDoesntHave('order', fn ($orderQuery) => $orderQuery->where('type', 'pos')->whereNull('customer_id'));
            })
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'receipt' THEN amount ELSE 0 END), 0) as cash_receipts")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'payment' THEN amount ELSE 0 END), 0) as cash_payments")
            ->first();

        $walkInOrders = Order::query()->where('tenant_id', $shift->tenant_id)
            ->where('branch_id', $shift->branch_id)->where('shift_id', $shift->id)
            ->where('status', 'completed')->where('type', 'pos')->whereNull('customer_id');
        $cashReceipts = (float) ($payments?->cash_receipts ?? 0) + (float) (clone $walkInOrders)
            ->where('payment_method', 'cash')->sum(DB::raw("CASE WHEN source = 'api' THEN paid_amount WHEN paid_amount > delivery_fee THEN paid_amount - delivery_fee ELSE 0 END"));
        $cashPayments = (float) ($payments?->cash_payments ?? 0);
        $bankReceipts = Payment::query()
            ->where('tenant_id', $shift->tenant_id)
            ->where('shift_id', $shift->id)
            ->where(function ($query) use ($shift): void {
                $query->where('branch_id', $shift->branch_id)->orWhereNull('branch_id');
            })
            ->where('type', 'receipt')
            ->where(function ($query): void {
                $query->whereNull('order_id')
                    ->orWhereDoesntHave('order', fn ($orderQuery) => $orderQuery->where('type', 'pos')->whereNull('customer_id'));
            })
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'card' THEN amount ELSE 0 END), 0) as card_receipts")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount ELSE 0 END), 0) as bank_transfer_receipts")
            ->toBase()
            ->first();
        $cardReceipts = (float) ($bankReceipts?->card_receipts ?? 0);
        $bankTransferReceipts = (float) ($bankReceipts?->bank_transfer_receipts ?? 0);
        $chequeReceipts = (float) (clone $walkInOrders)->where('payment_method', 'cheque')
            ->sum(DB::raw("CASE WHEN source = 'api' THEN paid_amount WHEN paid_amount > delivery_fee THEN paid_amount - delivery_fee ELSE 0 END"));
        $cardReceipts += (float) (clone $walkInOrders)->where('payment_method', 'card')
            ->sum(DB::raw("CASE WHEN source = 'api' THEN paid_amount WHEN paid_amount > delivery_fee THEN paid_amount - delivery_fee ELSE 0 END"));
        $bankTransferReceipts += (float) (clone $walkInOrders)->where('payment_method', 'bank_transfer')
            ->sum(DB::raw("CASE WHEN source = 'api' THEN paid_amount WHEN paid_amount > delivery_fee THEN paid_amount - delivery_fee ELSE 0 END"));

        return [
            'sales' => (float) ($orders?->sales ?? 0),
            'returns' => (float) ($orders?->returns ?? 0),
            'cash_receipts' => $cashReceipts,
            'cash_payments' => $cashPayments,
            'card_receipts' => $cardReceipts,
            'bank_transfer_receipts' => $bankTransferReceipts,
            'bank_receipts' => $cardReceipts + $bankTransferReceipts,
            'expected_cash' => (float) $shift->opening_cash + $cashReceipts - $cashPayments,
        ];
    }

    /** @param array{sales: float, returns: float, cash_receipts: float, cash_payments: float, expected_cash: float} $summary */
    private function shiftSummaryChanged(array $summary): bool
    {
        return round($this->shift_total_sales, 2) !== round($summary['sales'], 2)
            || round($this->shift_total_returns, 2) !== round($summary['returns'], 2)
            || round($this->shift_cash_receipts, 2) !== round($summary['cash_receipts'], 2)
            || round($this->shift_cash_payments, 2) !== round($summary['cash_payments'], 2)
            || round($this->shift_bank_receipts, 2) !== round($summary['bank_receipts'], 2)
            || round($this->shift_expected_cash, 2) !== round($summary['expected_cash'], 2);
    }

    private function invalidateActiveShiftCache(): void
    {
        $this->activeShiftCache = null;
        $this->activeShiftLoaded = false;
    }

    public function updatedSelectedBranchId($value): void
    {
        $user = Auth::user();

        if ($user?->branch_id && ! $user->isTenantOwner() && ! $user->isSaaSAdmin()) {
            $this->selectedBranchId = (int) $user->branch_id;
            return;
        }

        $branchId = $value ? (int) $value : null;
        $tenantId = $this->tenantId();

        if ($branchId && $tenantId && Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->exists()) {
            $this->clearCartState();
            session(['active_branch_id' => $branchId]);
            $this->selectedBranchId = $branchId;
            $this->invalidateActiveShiftCache();
            $this->activeShiftId = null;
            $this->loadHeldInvoices();
            $this->checkActiveShift();
            $this->loadQuickProducts();
            $this->restoreCurrentDraft();
            return;
        }

        $this->selectedBranchId = null;
        $this->invalidateActiveShiftCache();
        $this->activeShiftId = null;
        session()->forget('active_branch_id');
        $this->quickProducts = [];
        $this->heldInvoices = [];
        $this->errorMessage = 'الفرع المحدد غير صالح لهذا المتجر.';
    }

    public function checkActiveShift(): void
    {
        $this->invalidateActiveShiftCache();
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
        $this->errorMessage = null;
        $this->showOpenShiftModal = true;
    }

    public function openShift(): void
    {
        $this->authorize('pos.shift.open');
        if (!$this->showOpenShiftModal) {
            $this->errorMessage = 'افتح نافذة بدء الشيفت أولاً.';
            return;
        }

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

        $this->validate([
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'shift_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $shift = DB::transaction(function () use ($tenantId, $branchId, $user): Shift {
                $branch = Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->lockForUpdate()->first();

                if (!$branch) {
                    throw new \RuntimeException('الفرع غير موجود لهذا المتجر.');
                }

                $existing = Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('opened_by', $user->id)->where('status', 'open')->lockForUpdate()->latest('id')->first();

                if ($existing) {
                    return $existing;
                }

                $shift = Shift::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'opening_cash' => $this->opening_cash,
                    'status' => 'open',
                    'opened_at' => now(),
                    'opened_by' => $user->id,
                    'notes' => trim($this->shift_notes) ?: null,
                ]);

                $this->queueShiftOpenForSync($shift);

                return $shift;
            });

            $this->invalidateActiveShiftCache();
            $this->activeShiftId = $shift->id;
            $this->showOpenShiftModal = false;
            $this->errorMessage = null;
            $this->successMessage = $shift->wasRecentlyCreated
                ? 'تم فتح الشيفت بنجاح. رقم الشيفت: #' . $shift->id
                : 'يوجد شيفت مفتوح بالفعل برقم #' . $shift->id;
            $this->refreshBranchSyncStatus();

            if ($shift->wasRecentlyCreated) {
                $this->refreshLiveData();
                $this->dispatch('pos-sound', type: 'shift-open');
            }
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

    public function cancelOpenShift(): void
    {
        $this->showOpenShiftModal = false;
        $this->errorMessage = null;
    }

    public function prepareCloseShift(): void
    {
        if (!empty($this->cart)) {
            $this->errorMessage = 'احفظ الفاتورة أو ألغها قبل إغلاق الشيفت حتى لا تفقد البيانات.';
            return;
        }

        $this->errorMessage = null;

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

        $summary = $this->shiftFinancialSummary($shift);
        $this->activeShiftId = $shift->id;
        $this->shift_opening_cash = (float) $shift->opening_cash;
        $this->shift_total_sales = $summary['sales'];
        $this->shift_total_returns = $summary['returns'];
        $this->shift_cash_receipts = $summary['cash_receipts'];
        $this->shift_cash_payments = $summary['cash_payments'];
        $this->shift_card_receipts = $summary['card_receipts'];
        $this->shift_bank_transfer_receipts = $summary['bank_transfer_receipts'];
        $this->shift_bank_receipts = $summary['bank_receipts'];
        $this->shift_expected_cash = $summary['expected_cash'];
        $this->actual_cash = $this->shift_expected_cash;
        $this->shift_notes = '';
        $this->showCloseShiftModal = true;
    }

    public function closeShift(): void
    {
        $this->authorize('pos.shift.close');
        if (!$this->showCloseShiftModal) {
            $this->errorMessage = 'افتح نافذة التسوية وراجع الرصيد قبل تأكيد الإغلاق.';
            return;
        }

        if (!empty($this->cart)) {
            $this->errorMessage = 'احفظ الفاتورة أو ألغها قبل إغلاق الشيفت حتى لا تفقد البيانات.';
            return;
        }

        $this->validate([
            'actual_cash' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'shift_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();
        $userId = Auth::id();
        $shiftId = $this->activeShiftId;

        if (!$tenantId || !$branchId || !$userId || !$shiftId) {
            $this->errorMessage = 'لا يوجد شيفت مفتوح لإغلاقه.';
            return;
        }

        $actualCash = (float) $this->actual_cash;
        $closingNotes = trim($this->shift_notes);

        try {
            $result = DB::transaction(function () use ($tenantId, $branchId, $userId, $shiftId, $actualCash, $closingNotes): array {
                $lockedShift = Shift::query()
                    ->where('tenant_id', $tenantId)
                    ->where('branch_id', $branchId)
                    ->where('opened_by', $userId)
                    ->where('status', 'open')
                    ->whereKey($shiftId)
                    ->lockForUpdate()
                    ->first();

                if (!$lockedShift) {
                    throw new \RuntimeException('الشيفت مغلق بالفعل.');
                }

                $summary = $this->shiftFinancialSummary($lockedShift);

                if ($this->shiftSummaryChanged($summary)) {
                    return ['closed' => false, 'summary' => $summary];
                }

                $difference = round($actualCash - $summary['expected_cash'], 2);
                $openingNotes = trim((string) $lockedShift->notes);
                $notes = $openingNotes;

                if ($closingNotes !== '') {
                    $notes = trim(($openingNotes !== '' ? "ملاحظات الفتح:\n{$openingNotes}\n\n" : '') . "ملاحظات الإغلاق:\n{$closingNotes}");
                }

                $lockedShift->update([
                    'expected_cash' => $summary['expected_cash'],
                    'actual_cash' => $actualCash,
                    'difference' => $difference,
                    'notes' => $notes ?: null,
                    'status' => 'closed',
                    'closed_at' => now(),
                    'closed_by' => $userId,
                    'total_sales' => $summary['sales'],
                    'total_returns' => $summary['returns'],
                ]);

                $this->queueShiftCloseForSync($lockedShift->fresh());

                return ['closed' => true, 'summary' => $summary];
            });

            if (!$result['closed']) {
                $this->shift_total_sales = $result['summary']['sales'];
                $this->shift_total_returns = $result['summary']['returns'];
                $this->shift_cash_receipts = $result['summary']['cash_receipts'];
                $this->shift_cash_payments = $result['summary']['cash_payments'];
                $this->shift_card_receipts = $result['summary']['card_receipts'];
                $this->shift_bank_transfer_receipts = $result['summary']['bank_transfer_receipts'];
                $this->shift_bank_receipts = $result['summary']['bank_receipts'];
                $this->shift_expected_cash = $result['summary']['expected_cash'];
                $this->errorMessage = 'حدثت حركة جديدة أثناء التسوية. أعد عدّ النقد الموجود في الصندوق ثم أكد الإغلاق.';
                return;
            }

            $this->invalidateActiveShiftCache();
            $this->refreshBranchSyncStatus();
            $this->activeShiftId = null;
            $this->showCloseShiftModal = false;
            $this->clearCartState(false);
            $this->errorMessage = null;
            $this->successMessage = 'تم إغلاق الشيفت وتسوية الصندوق بنجاح.';
            $this->refreshLiveData();
            $this->dispatch('pos-sound', type: 'shift-close');
        } catch (\Throwable $e) {
            Log::error('POS shift closing failed', [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'shift_id' => $shiftId,
                'message' => $e->getMessage(),
            ]);
            $this->errorMessage = 'تعذر إغلاق الشيفت. حدّث البيانات وتأكد أن الشيفت ما زال مفتوحاً.';
        }
    }

    public function cancelCloseShift(): void
    {
        $this->showCloseShiftModal = false;
        $this->errorMessage = null;
    }

    public function openExpenseModal(): void
    {
        if (! $this->activeShift()) {
            $this->errorMessage = 'افتح شيفتاً أولاً لتسجيل المصروف من الصندوق.';
            $this->showOpenShiftModal = true;

            return;
        }

        $this->expense_amount = 0;
        $this->expense_category = 'كهرباء';
        $this->expense_notes = '';
        $this->errorMessage = null;
        $this->showExpenseModal = true;
    }

    public function saveExpense(): void
    {
        $this->authorize('accounting.voucher.create');
        $this->validate([
            'expense_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'expense_category' => ['required', 'string', 'max:80'],
            'expense_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();
        $user = Auth::user();
        if (! $tenantId || ! $branchId || ! $user) {
            $this->errorMessage = 'تعذر تحديد المتجر أو الفرع.';

            return;
        }

        try {
            DB::transaction(function () use ($tenantId, $branchId, $user): void {
                $shift = Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                    ->where('opened_by', $user->id)->where('status', 'open')->latest('id')->lockForUpdate()->first();
                if (! $shift) {
                    throw new \RuntimeException('لا يوجد شيفت مفتوح لتسجيل المصروف.');
                }

                $expense = Payment::create([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'shift_id' => $shift->id,
                    'created_by' => $user->id,
                    'type' => 'payment',
                    'voucher_number' => 'EXP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5)),
                    'amount' => $this->expense_amount,
                    'payment_method' => 'cash',
                    'notes' => trim($this->expense_category.($this->expense_notes !== '' ? ' — '.$this->expense_notes : '')),
                    'payment_date' => now(),
                ]);
                $this->queueExpenseForSync($expense, $shift);
            });
            if (app(DesktopPosConnection::class)->enabled()) {
                app(DesktopPosConnection::class)->flush((int) $tenantId, (int) $branchId);
                $this->refreshBranchSyncStatus();
            }

            $this->invalidateActiveShiftCache();
            $this->showExpenseModal = false;
            $this->successMessage = 'تم تسجيل المصروف وخصمه من الصندوق.';
        } catch (\Throwable $exception) {
            Log::warning('POS expense creation failed', ['message' => $exception->getMessage(), 'tenant_id' => $tenantId, 'branch_id' => $branchId]);
            $this->errorMessage = 'تعذر تسجيل المصروف. حاول مرة أخرى.';
        }
    }

    public function updatedProductSearchQuery(): void
    {
        $this->quickProductQuantities = [];
        $this->loadQuickProducts();
    }

    public function submitProductSearch(string $search): void
    {
        if ($this->invoiceIsLocked() || mb_strlen($search) > 120) {
            return;
        }
        $this->productSearchQuery = $search;
        $this->loadQuickProducts(force: true, refreshRemoteCatalog: true);
        if (count($this->quickProducts) === 1) {
            $this->selectInlineProduct((int) $this->quickProducts[0]['id']);
        } elseif ($this->quickProducts !== []) {
            $this->dispatch('pos-search-results-ready');
        }
    }

    /** Search by all name words in any order, with Arabic letter normalization. */
    private function applyPosCatalogSearch($query, string $search, int $tenantId): void
    {
        $normalized = app(PosProductSearch::class)->normalize($search);
        if ($normalized === '' || mb_strlen($normalized) > 120) {
            return;
        }

        $escape = static fn (string $value): string => str_replace(
            ['!', '%', '_'], ['!!', '!%', '!_'], $value
        );
        $words = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return;
        }

        // products.search_name holds the name already normalized (أ/إ/آ → ا, ة → ه, ى → ي, Arabic-Indic digits,
        // lower case), so the words are matched without normalizing every row on each search.
        $nameSql = 'products.search_name';

        $escaped = $escape($normalized);
        $query->where(function ($matches) use ($normalized, $tenantId, $escaped, $words, $escape, $nameSql): void {
            // Preserve existing exact/prefix search and numeric product ID support.
            $matches->where(function ($standard) use ($normalized, $tenantId): void {
                app(PosProductSearch::class)->apply($standard->getQuery(), $normalized, $tenantId);
            })->orWhereRaw("LOWER(products.product_number) LIKE ? ESCAPE '!'", ['%' . $escaped . '%']);

            // Any part of a barcode, e.g. its last 5 digits.
            app(PosProductSearch::class)->orWhereBarcodeContains($matches->getQuery(), $normalized, $tenantId);

            // Match all words, even with additional words between them.
            $matches->orWhere(function ($nameQuery) use ($words, $escape, $nameSql): void {
                foreach ($words as $word) {
                    if (ctype_digit($word)) {
                        $number = $escape($word);
                        $nameQuery->where(function ($numberQuery) use ($nameSql, $number): void {
                            $numberQuery->whereRaw("{$nameSql} = ?", [$number])
                                ->orWhereRaw("{$nameSql} LIKE ? ESCAPE '!'", [$number.' %'])
                                ->orWhereRaw("{$nameSql} LIKE ? ESCAPE '!'", ['% '.$number.' %'])
                                ->orWhereRaw("{$nameSql} LIKE ? ESCAPE '!'", ['% '.$number]);
                        });
                    } else {
                        $nameQuery->whereRaw(
                            "{$nameSql} LIKE ? ESCAPE '!'",
                            ['%' . $escape($word) . '%']
                        );
                    }
                }
            });
        });
    }

    public function updatedInlineSearchQuery(): void
    {
        $search = app(PosProductSearch::class)->normalize($this->inlineSearchQuery);

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

        $remoteIds = $this->refreshRemoteCatalog(filters: ['search' => $search]);
        $query = Product::query()
            ->select(['id', 'tenant_id', 'name', 'product_number'])
            ->where('tenant_id', $tenantId)
            ->when($remoteIds !== null, fn ($query) => $query->whereIn('id', $remoteIds))
            ->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $branchId))
            ->with([
                'barcodes' => fn ($query) => $query->select(['id', 'product_id', 'tenant_id', 'barcode'])->where('tenant_id', $tenantId),
                'branchProducts' => fn ($query) => $query->select(['id', 'product_id', 'branch_id', 'retail_price', 'stock_quantity'])->where('branch_id', $branchId),
            ]);
         $this->applyPosCatalogSearch($query, $search, (int) $tenantId);
        // Keep the usual relevance ranking; within each level, the items with the most stock come first.
        app(PosProductSearch::class)->applyRelevanceOrdering($query->getQuery(), $search, (int) $tenantId);
        app(PosProductSearch::class)->applyBranchStockOrdering($query->getQuery(), (int) $branchId);
        $this->inlineSearchResults = $query->orderBy('name')->orderBy('id')
            ->limit(12)
            ->get()
            ->map(function (Product $product): array {
                $branchProduct = $product->branchProducts->first();
                return [
                    'id' => $product->id,
                    'product_number' => $product->product_number,
                    'name' => $product->name,
                    'barcode' => $product->barcodes->first()?->barcode,
                    'price' => (float) ($branchProduct?->retail_price ?? 0),
                    'stock' => (float) ($branchProduct?->stock_quantity ?? 0),
                ];
            })
            ->all();
        $this->inlineSearchResults = $this->withSharedStock($this->inlineSearchResults);
    }

    /** @param array<int, array<string, mixed>> $products */
    private function withSharedStock(array $products): array
    {
        if (!$this->unifiedStock || $products === []) {
            return $products;
        }
        $totals = BranchProduct::query()->where('tenant_id', $this->tenantId())
            ->whereIn('product_id', array_column($products, 'id'))
            ->selectRaw('product_id, SUM(stock_quantity) as quantity')->groupBy('product_id')
            ->pluck('quantity', 'product_id')->all();
        if (app(DesktopPosConnection::class)->enabled()) {
            $shared = json_decode((string) TenantSetting::get($this->tenantId(), 'desktop_shared_stock_'.$this->getActiveBranchId(), '{}'), true) ?: [];
            $totals = array_replace($totals, $shared);
        }
        foreach ($products as &$product) {
            $product['shared_stock_quantity'] = isset($totals[$product['id']]) ? (float) $totals[$product['id']] : null;
        }
        unset($product);

        return $products;
    }

    public function startGiftSelection(): void
    {
        $this->authorize('pos.sale.checkout');
        if (! $this->ensureInvoiceEditable() || $this->isReturnMode) {
            return;
        }
        $this->selectingGift = true;
        $this->productSearchQuery = '';
        $this->loadQuickProducts();
        $this->showProductsModal = true;
    }

    public function selectInlineProduct(int $productId): void
    {
        if (!$this->addToCart($productId)) {
            return;
        }

        $this->finishInlineProductSelection();
    }

    public function addSearchedProduct(int $productId): void
    {
        $quantity = $this->quickProductQuantities[$productId] ?? 1;

        if (!is_numeric($quantity) || (float) $quantity <= 0 || (float) $quantity > 999999999.99) {
            $this->errorMessage = 'أدخل كمية صحيحة أكبر من صفر.';

            return;
        }

        if (!$this->addToCart($productId, '', (float) $quantity)) {
            return;
        }

        unset($this->quickProductQuantities[$productId]);
        $this->finishInlineProductSelection();
    }

    private function finishInlineProductSelection(): void
    {
        $this->inlineSearchQuery = '';
        $this->productSearchQuery = '';
        $this->quickProductQuantities = [];
        $this->inlineSearchResults = [];
        $this->selectedCategoryId = null;
        $this->loadQuickProducts();

        $this->dispatch('pos-search-cleared', componentId: $this->getId());
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
        $this->authorize('returns.create');
        if (! $this->ensureInvoiceEditable()) {
            return;
        }
        if ($this->editingInvoiceId || $this->currentInvoiceId) {
            $this->errorMessage = 'افتح فاتورة جديدة لتغيير نوع الفاتورة.';
            return;
        }
        $this->isReturnMode = ! $this->isReturnMode;
        $this->cashReturnNumber = $this->isReturnMode ? 'RET-CASH-'.Str::uuid() : '';
        $this->cashReturnOrderId = null;
        $this->showCashReturnModal = false;
        $this->selectedCustomerId = null;
        $this->payment_method = 'cash';
        $this->delivery_fee = 0;
        $this->discount_amount = 0;
        $this->custom_final_total = null;
        foreach ($this->cart as $key => $item) {
            $this->cart[$key]['quantity'] = ($this->isReturnMode ? -1 : 1) * abs((float) $item['quantity']);
            $this->cart[$key]['returned_to_stock'] = true;
        }
        $this->recalculatePrices();
        $this->errorMessage = null;
        $this->dispatch('pos-focus-barcode');
    }

    public function openCashReturnModal(): void
    {
        $this->authorize('returns.create');
        $this->resetValidation();
        $this->reset('cashReturnSearch', 'cashReturnProductId', 'cashReturnQuantity', 'cashReturnPrice', 'cashReturnCondition', 'cashReturnReason');
        $this->cashReturnNumber = 'RET-CASH-'.Str::uuid();
        $this->cashReturnOrderId = null;
        $this->cashReturnQuantity = 1;
        $this->errorMessage = null;
        $this->showCashReturnModal = true;
    }

    public function getCashReturnProductsProperty(): \Illuminate\Support\Collection
    {
        if (! $this->showCashReturnModal || trim($this->cashReturnSearch) === '') {
            return collect();
        }

        $search = trim($this->cashReturnSearch);
        $this->authorize('returns.create');

        return Product::query()->where('tenant_id', $this->tenantId())
            ->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $this->getActiveBranchId()))
            ->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('barcode', $search)))
            ->limit(10)->get();
    }

    public function selectCashReturnProduct(int $productId): void
    {
        $this->authorize('returns.create');
        $stock = BranchProduct::query()->where('tenant_id', $this->tenantId())
            ->where('branch_id', $this->getActiveBranchId())->where('product_id', $productId)->firstOrFail();
        $product = Product::query()->where('tenant_id', $this->tenantId())->findOrFail($productId);
        $this->cashReturnProductId = $productId;
        $this->cashReturnSearch = $product->name;
        $this->cashReturnPrice = (float) $stock->retail_price;
    }

    public function updatedCashReturnSearch(): void
    {
        $this->cashReturnProductId = null;
        $this->cashReturnPrice = null;
    }

    public function getRecentCashReturnsProperty(): \Illuminate\Support\Collection
    {
        if (! $this->showCashReturnModal) {
            return collect();
        }
        $this->authorize('returns.create');

        return $this->onlyOwnInvoices(Order::query())->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())
            ->where('type', 'return')->where('invoice_number', 'like', 'RET-CASH-%')
            ->latest('id')->limit(10)->get();
    }

    public function editCashReturn(int $orderId, bool $useCart = false): void
    {
        $this->authorize('returns.create');
        $this->authorize('sales_invoices.edit');
        if (config('branch-sync.mode') === 'edge') {
            $this->errorMessage = 'عدّل المرتجع من النظام المركزي بعد المزامنة، مثل الفواتير المحفوظة.';
            return;
        }
        $order = Order::query()->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())
            ->where('type', 'return')->whereNull('original_order_id')->whereNull('customer_id')
            ->where('invoice_number', 'like', 'RET-CASH-%')->with('items.product')->findOrFail($orderId);
        $shift = Shift::query()->where('tenant_id', $this->tenantId())->find($order->shift_id);
        if (! $shift || $shift->status !== 'open' || (int) $shift->opened_by !== (int) auth()->id()) {
            $this->errorMessage = 'يمكن تعديل المرتجع داخل شيفتك المفتوح فقط.';
            return;
        }
        if ($useCart) {
            $this->loadInvoice($orderId);
            $this->invoiceEditMode = true;
            $this->cashReturnOrderId = $order->id;
            $this->cashReturnNumber = $order->invoice_number;
            $this->showCashReturnModal = false;
            $this->errorMessage = null;
            return;
        }
        if ($order->items->count() !== 1) {
            $this->errorMessage = 'هذه النافذة مخصصة للمرتجع النقدي المباشر لصنف واحد.';
            return;
        }
        $line = $order->items->first();
        $this->resetValidation();
        $this->cashReturnOrderId = $order->id;
        $this->cashReturnNumber = $order->invoice_number;
        $this->cashReturnProductId = $line->product_id;
        $this->cashReturnSearch = $line->product?->name ?? '';
        $this->cashReturnQuantity = abs((float) $line->quantity);
        $this->cashReturnPrice = (float) $line->unit_price;
        $this->cashReturnCondition = $line->returned_to_stock ? 'resellable' : 'damaged';
        $this->cashReturnReason = (string) preg_replace('/^مرتجع نقدي .*?\. /u', '', (string) $order->notes);
        $this->errorMessage = null;
        $this->showCashReturnModal = true;
    }

    public function saveCashReturn(bool $fromCart = false): ?Order
    {
        $this->authorize('returns.create');
        $this->authorize('pos.sale.checkout');
        if ($this->cashReturnOrderId) {
            $this->authorize('sales_invoices.edit');
            abort_if(config('branch-sync.mode') === 'edge', 403);
        }
        if ($fromCart && (! $this->isReturnMode || $this->invoiceIsLocked())) {
            return null;
        }
        if ((! $fromCart && ! $this->showCashReturnModal) || $this->cashReturnNumber === '') {
            return null;
        }
        if ($fromCart) {
            $validated = $this->validate([
                'cart' => ['required', 'array', 'min:1'],
                'cart.*.id' => ['required', 'integer'],
                'cart.*.quantity' => ['required', 'numeric', 'min:-999999', 'max:-0.01'],
                'cart.*.price' => ['required', 'numeric', 'min:0.01', 'max:999999'],
                'cart.*.returned_to_stock' => ['sometimes', 'boolean'],
                'notes' => ['nullable', 'string', 'max:500'],
            ]);
            $returnItems = collect($validated['cart'])->map(fn (array $item): array => [
                'id' => $item['id'], 'quantity' => abs((float) $item['quantity']), 'price' => (float) $item['price'],
                'restock' => (bool) ($item['returned_to_stock'] ?? true),
            ])->all();
            $reason = $this->notes ?: 'مرتجع نقدي من شاشة POS';
        } else {
            $data = $this->validate([
            'cashReturnProductId' => ['required', 'integer'],
            'cashReturnQuantity' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'cashReturnPrice' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'cashReturnCondition' => ['required', 'in:resellable,damaged'],
            'cashReturnReason' => ['required', 'string', 'max:500'],
        ]);
            $returnItems = [['id' => $data['cashReturnProductId'], 'quantity' => $data['cashReturnQuantity'],
                'price' => $data['cashReturnPrice'], 'restock' => $data['cashReturnCondition'] === 'resellable']];
            $reason = $data['cashReturnReason'];
        }
        $tenantId = (int) $this->tenantId();
        $branchId = (int) $this->getActiveBranchId();

        $order = DB::transaction(function () use ($tenantId, $branchId, $returnItems, $reason): Order {
            $shift = \App\Services\ApiShiftLedger::activeForWrite($tenantId, $branchId, (int) auth()->id());
            if (! $shift) {
                throw \Illuminate\Validation\ValidationException::withMessages(['cashReturnQuantity' => 'افتح شيفت قبل تسجيل المرتجع النقدي.']);
            }
            $existing = Order::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                ->where('invoice_number', $this->cashReturnNumber)->first();
            if ($existing && ! $this->cashReturnOrderId) {
                return $existing;
            }
            $editingOrder = null;
            $stockChanges = [];
            $previousCosts = [];
            if ($this->cashReturnOrderId) {
                $editingOrder = Order::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                    ->where('shift_id', $shift->id)->where('type', 'return')->where('status', 'completed')
                    ->whereNull('customer_id')->whereNull('original_order_id')->where('invoice_number', $this->cashReturnNumber)
                    ->where('invoice_number', 'like', 'RET-CASH-%')->lockForUpdate()->findOrFail($this->cashReturnOrderId);
                foreach ($editingOrder->items()->get() as $oldItem) {
                    $previousCosts[$oldItem->product_id] = (float) $oldItem->cost_price;
                    if ($oldItem->returned_to_stock) {
                        $stockChanges[$oldItem->product_id] = ($stockChanges[$oldItem->product_id] ?? 0) - abs((float) $oldItem->quantity);
                    }
                }
                $editingOrder->items()->delete();
            }
            $lines = [];
            $total = 0.0;
            $totalCost = 0.0;
            foreach ($returnItems as $returnItem) {
            $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($returnItem['id']);
            $stock = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                ->where('product_id', $product->id)->lockForUpdate()->firstOrFail();
            $quantity = round((float) $returnItem['quantity'], 2);
            $price = round((float) $returnItem['price'], 2);
            $lineTotal = round($quantity * $price, 2);
            $restock = $returnItem['restock'];
            if ($lineTotal <= 0) {
                throw \Illuminate\Validation\ValidationException::withMessages(['cashReturnPrice' => 'أدخل مبلغ مرتجع أكبر من صفر.']);
            }
            $cost = $previousCosts[$product->id] ?? (float) $product->cost_price;
            $lineCost = $restock ? round($cost * $quantity, 2) : 0.0;
            $total += $lineTotal;
            $totalCost += $lineCost;
            $lines[] = ['tenant_id' => $tenantId, 'product_id' => $product->id, 'quantity' => -$quantity,
                'unit_price' => $price, 'total_price' => -$lineTotal, 'cost_price' => $cost, 'total_cost' => -$lineCost,
                'discount' => 0, 'returned_to_stock' => $restock];
            if ($restock) {
                $stockChanges[$product->id] = ($stockChanges[$product->id] ?? 0) + $quantity;
            }
            }
            $order = $editingOrder ?? new Order;
            $order->fill([
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'shift_id' => $shift->id,
                'created_by' => auth()->id(), 'customer_name' => 'زبون نقدي',
                'invoice_number' => $this->cashReturnNumber, 'invoice_date' => $editingOrder?->invoice_date ?? today(),
                'type' => 'return', 'source' => 'pos', 'sale_kind' => 'retail', 'status' => 'completed',
                'subtotal' => -$total, 'total' => -$total, 'tax_amount' => 0,
                'discount' => 0, 'discount_type' => 'fixed', 'discount_rate' => 0, 'delivery_fee' => 0,
                'total_cost' => -$totalCost, 'total_profit' => -$total + $totalCost,
                'paid_amount' => -$total, 'payment_status' => 'paid',
                'notes' => $reason,
            ]);
            $order->save();
            $order->items()->createMany($lines);
            foreach ($stockChanges as $productId => $change) {
                if (abs($change) < 0.005) {
                    continue;
                }
                $changedStock = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                    ->where('product_id', $productId)->lockForUpdate()->firstOrFail();
                if ($change < 0 && (float) $changedStock->stock_quantity + $change < 0 && ! TenantSetting::getBool($tenantId, 'allow_negative_stock')) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['cashReturnQuantity' => 'لا يمكن تخفيض المرتجع: الكمية المعادة استُخدمت ولم تعد متاحة في المخزون.']);
                }
                \App\Services\StockMovementContext::forDocument($order, fn () => $changedStock->increment('stock_quantity', $change));
            }
            $payment = Payment::firstOrNew(['tenant_id' => $tenantId, 'order_id' => $order->id, 'type' => 'payment']);
            $payment->fill([
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'shift_id' => $shift->id,
                'created_by' => $payment->created_by ?? auth()->id(), 'type' => 'payment', 'voucher_number' => $payment->voucher_number ?? 'REF-'.Str::uuid(),
                'amount' => $total, 'payment_method' => 'cash', 'payment_date' => $payment->payment_date ?? now(),
                'order_id' => $order->id, 'notes' => $order->notes,
            ]);
            $payment->save();
            $this->queueSaleForSync($order, $shift);

            return $order;
        });

        $this->showCashReturnModal = false;
        $this->invalidateActiveShiftCache();
        if ((int) $this->currentInvoiceId === (int) $order->id) {
            $this->loadInvoice((int) $order->id);
        }
        $this->loadQuickProducts(true);
        $this->successMessage = ($this->cashReturnOrderId ? 'تم تعديل المرتجع وتحديث المخزون والمصروف النقدي إلى ' : 'تم تسجيل المرتجع النقدي وصرف ').number_format(abs((float) $order->total), 2).' للزبون.';
        if (config('branch-sync.mode') === 'edge') {
            $this->successMessage .= ' محفوظ محليًا وبانتظار المزامنة.';
        }
        $this->refreshBranchSyncStatus();
        if ($fromCart) {
            $this->loadInvoice((int) $order->id);
        }
        return $order;
    }

    public function loadQuickProducts(bool $force = false, bool $refreshRemoteCatalog = false): void
    {
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        if (!$tenantId || !$branchId) {
            $this->quickProducts = [];
            return;
        }

        $requestKey = json_encode([$tenantId, $branchId, $this->selectedCustomerId, $this->selectedCategoryId,
            app(PosProductSearch::class)->normalize($this->productSearchQuery)], JSON_THROW_ON_ERROR);
        if (!$force && $this->quickProductsRequestKey === $requestKey) {
            return;
        }

        $remoteIds = $refreshRemoteCatalog
            ? $this->refreshRemoteCatalog(filters: array_filter([
                'search' => trim($this->productSearchQuery), 'category_id' => $this->selectedCategoryId,
            ], fn ($value) => $value !== null && $value !== ''))
            : null;
        $query = Product::query()
            ->select(['id', 'tenant_id', 'name', 'category_id', 'product_number'])
            ->where('tenant_id', $tenantId)
            ->when($remoteIds !== null, fn ($query) => $query->whereIn('id', $remoteIds))
            ->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $branchId))
            ->with([
                'barcodes' => fn ($q) => $q->select(['id', 'product_id', 'tenant_id', 'barcode'])->where('tenant_id', $tenantId),
                'branchProducts' => fn ($q) => $q->select(['id', 'product_id', 'branch_id', 'retail_price', 'stock_quantity', 'offer_quantity', 'offer_price'])->where('branch_id', $branchId),
            ]);

        if ($this->selectedCategoryId) {
            $query->where('category_id', $this->selectedCategoryId);
        }

        $search = app(PosProductSearch::class)->normalize($this->productSearchQuery);
        // An empty search means "All": do not apply the search service's no-results guard.
        if ($search !== '') {
             $this->applyPosCatalogSearch($query, $search, (int) $tenantId);
            // Keep the usual relevance ranking; within each level, the items with the most stock come first.
            app(PosProductSearch::class)->applyRelevanceOrdering($query->getQuery(), $search, (int) $tenantId);
            app(PosProductSearch::class)->applyBranchStockOrdering($query->getQuery(), (int) $branchId);
        }

        $resultLimit = 24;
        $matchedProducts = $query->orderBy('name')->orderBy('id')->limit($resultLimit)->get();
        if ($remoteIds !== null) {
            $remoteOrder = array_flip($remoteIds);
            $matchedProducts = $matchedProducts
                ->sortBy(fn (Product $product): int => $remoteOrder[$product->id] ?? PHP_INT_MAX)
                ->values();
        }
        if ($matchedProducts->isEmpty()) {
            $this->quickProducts = [];
            $this->quickProductsRequestKey = $requestKey;
            return;
        }

        $activeOffers = ProductOffer::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
            ->whereIn('product_id', $matchedProducts->modelKeys())
            ->where('is_active', true)->where('start_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>=', now()))
            ->orderByDesc('start_at')->get()->unique('product_id')->keyBy('product_id');

        $this->quickProducts = $matchedProducts
            ->map(function (Product $product) use ($activeOffers): array {
                $branchProduct = $product->branchProducts->first();
                $offer = $activeOffers->get($product->id);
                $offerQuantity = (float) ($offer?->offer_quantity ?? $branchProduct?->offer_quantity ?? 0);
                $offerPrice = $offer?->offer_price ?? $branchProduct?->offer_price;
                $hasOffer = $offerQuantity > 0 && $offerPrice !== null && (float) $offerPrice >= 0
                    && (float) $offerPrice < $offerQuantity * (float) ($branchProduct?->retail_price ?? 0);

                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'category_id' => $product->category_id,
                    'product_number' => $product->product_number,
                    'retail_price' => (float) ($branchProduct?->retail_price ?? 0),
                    'stock_quantity' => (float) ($branchProduct?->stock_quantity ?? 0),
                    'barcode_value' => $product->barcodes->first()?->barcode,
                    'offer_quantity_value' => $offerQuantity,
                    'offer_price_value' => $offerPrice !== null ? (float) $offerPrice : null,
                    'has_offer' => $hasOffer,
                ];
            })
            ->all();
        $this->quickProducts = $this->withSharedStock($this->quickProducts);
        $this->quickProductsRequestKey = $requestKey;
    }

    public function scanBarcode(string $scannedBarcode = ''): void
    {
        if ($this->showMissingBarcodeModal || $this->showNewProductModal) {
            return;
        }
        $invoiceBarcode = trim($scannedBarcode !== '' ? $scannedBarcode : $this->barcode);
        $invoice = Order::query()
            ->where('tenant_id', $this->tenantId())
            ->where('branch_id', $this->getActiveBranchId())
            ->whereIn('type', ['pos', 'return'])
            ->where('invoice_number', $invoiceBarcode)
            ->first();

        if ($invoice && ! $this->onlyOwnInvoices(Order::query())->whereKey($invoice->id)->exists()) {
            $this->errorMessage = 'هذه الفاتورة أنشأها مستخدم آخر؛ يمكنك فتح فواتيرك فقط.';
            $this->barcode = '';
            $this->dispatch('pos-barcode-handled', componentId: $this->getId(), barcode: $invoiceBarcode);
            $this->dispatch('pos-sound', type: 'error');

            return;
        }

        if ($invoice) {
            $this->loadInvoice($invoice->id);
            $this->barcode = '';
            $this->dispatch('pos-barcode-handled', componentId: $this->getId(), barcode: $invoiceBarcode);

            return;
        }
        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $this->errorMessage = null;
        $this->successMessage = null;
        $barcode = trim($scannedBarcode !== '' ? $scannedBarcode : $this->barcode);

        if ($barcode === '') {
            return;
        }

        $this->dispatch('pos-barcode-handled', componentId: $this->getId(), barcode: $barcode);

        if (!$this->activeShift()) {
            $this->errorMessage = 'افتح الشيفت أولاً قبل البيع أو الإرجاع.';
            $this->dispatch('pos-sound', type: 'error');
            $this->showOpenShiftModal = true;
            $this->barcode = '';
            return;
        }

        $record = ProductBarcode::query()
            ->where('tenant_id', $this->tenantId())
            ->where('barcode', $barcode)
            ->first(['id', 'product_id']);
        if (!$record) {
            $remoteIds = $this->refreshRemoteCatalog(filters: ['barcode' => $barcode]);
            $record = ProductBarcode::query()
                ->where('tenant_id', $this->tenantId())
                ->when($remoteIds !== null, fn ($query) => $query->whereIn('product_id', $remoteIds))
                ->where('barcode', $barcode)
                ->first(['id', 'product_id']);
        } else {
            // Use the cached barcode immediately; queued sync reconciles the sale afterward.
            $this->verifiedRemoteProductIds[] = (int) $record->product_id;
        }

        if (!$record) {
            $this->missingBarcode = $barcode;
            $this->showMissingBarcodeModal = true;
            $this->linkMissingBarcode = false;
            $this->missingBarcodeSearch = '';
            $this->resetValidation();
            $this->dispatch('pos-sound', type: 'error');
            $this->barcode = '';
            return;
        }

        $this->addToCart((int) $record->product_id, $barcode);
        $this->barcode = '';
    }

    public function cancelMissingBarcode(): void
    {
        $this->showMissingBarcodeModal = false;
        $this->missingBarcode = '';
        $this->linkMissingBarcode = false;
        $this->missingBarcodeSearch = '';
        $this->resetValidation();
        $this->dispatch('pos-focus-barcode');
    }

    public function createProductFromMissingBarcode(): void
    {
        $this->authorize('products.create');
        $this->authorize('products.view');
        if (!$this->showMissingBarcodeModal || $this->missingBarcode === '') {
            return;
        }
        if (config('branch-sync.mode') === 'edge') {
            // A product created only on this device would never reach the central catalog and could clash with central identifiers.
            $this->addError('missingBarcode', 'إضافة صنف جديد تتم من النظام المركزي (الموقع). أضف الصنف هناك ثم اضغط «مزامنة الآن»، أو اختر «لا» لربط الباركود بصنف موجود.');

            return;
        }
        $this->validate(['missingBarcode' => ['required', 'string', 'max:100']]);
        $this->showMissingBarcodeModal = false;
        $this->showNewProductModal = true;
    }

    #[On('pos-product-form-closed')]
    public function finishNewProductForm(): void
    {
        $this->showNewProductModal = false;
        $this->editingProductId = null;
        $this->cancelMissingBarcode();
        $this->loadQuickProducts();
    }

    public function chooseExistingProductForBarcode(): void
    {
        $this->authorize('products.barcode.manage');
        if ($this->missingBarcode !== '' && $this->showMissingBarcodeModal) {
            $this->linkMissingBarcode = true;
        }
    }

    public function attachMissingBarcode(int $productId): void
    {
        $this->authorize('products.barcode.manage');
        if (!$this->ensureInvoiceEditable() || !$this->showMissingBarcodeModal || !$this->linkMissingBarcode) {
            return;
        }
        $this->validate(['missingBarcode' => ['required', 'string', 'max:100']]);
        $product = Product::query()->where('tenant_id', $this->tenantId())->findOrFail($productId);
        if (config('branch-sync.mode') === 'edge') {
            if (!$this->attachMissingBarcodeOnBranch($product)) {
                return;
            }
        } else {
            $record = ProductBarcode::query()->firstOrCreate(
                ['tenant_id' => $this->tenantId(), 'barcode' => $this->missingBarcode],
                ['product_id' => $product->id],
            );
            if ((int) $record->product_id !== (int) $product->id) {
                $this->addError('missingBarcode', 'تم تسجيل هذا الباركود لصنف آخر. ألغِ العملية وأعد مسحه.');
                return;
            }
        }
        $this->cancelMissingBarcode();
        $this->loadQuickProducts();
        $this->successMessage = 'تم ربط الباركود بالصنف. امسحه مرة أخرى لإضافته إلى الفاتورة.';
    }

    /**
     * Links a scanned barcode to a central product from a branch device. The barcode works locally at once
     * and is queued for the central server; its local row uses an identifier far above central ones so it
     * can never clash with a barcode downloaded later, which replaces it once the server confirms it.
     */
    private function attachMissingBarcodeOnBranch(Product $product): bool
    {
        $tenantId = (int) $this->tenantId();
        $branchId = (int) $this->getActiveBranchId();
        $existing = ProductBarcode::query()->where('tenant_id', $tenantId)->where('barcode', $this->missingBarcode)->first();
        if ($existing && (int) $existing->product_id !== (int) $product->id) {
            $this->addError('missingBarcode', 'تم تسجيل هذا الباركود لصنف آخر. ألغِ العملية وأعد مسحه.');

            return false;
        }
        $operation = DB::transaction(function () use ($existing, $product, $tenantId, $branchId): BranchSyncOperation {
            if (!$existing) {
                (new ProductBarcode())->forceFill([
                    'id' => 9_000_000_000_000 + random_int(1, 999_999_999),
                    'tenant_id' => $tenantId, 'product_id' => $product->id, 'barcode' => $this->missingBarcode,
                ])->save();
            }

            return BranchSyncOperation::query()->create([
                'tenant_id' => $tenantId, 'branch_id' => $branchId, 'operation_uuid' => (string) Str::uuid(),
                'operation_type' => 'barcode.attached', 'status' => 'pending',
                'payload' => ['barcode' => ['product_id' => (int) $product->id, 'barcode' => $this->missingBarcode]],
            ]);
        });
        $connection = app(DesktopPosConnection::class);
        if ($connection->enabled()) {
            $connection->flush($tenantId, $branchId, $operation->operation_uuid);
            if ($operation->fresh()->status === 'synced') {
                $this->refreshRemoteCatalog(filters: ['ids' => [(int) $product->id]]);
            } elseif ($operation->fresh()->status === 'conflict') {
                $this->errorMessage = 'رفض السيرفر ربط الباركود: '.$operation->fresh()->last_error;
            }
        }
        $this->refreshBranchSyncStatus();

        return true;
    }

    public function addToCart(int $productId, string $scannedBarcode = '', float $quantity = 1): bool
    {
        if (!$this->ensureInvoiceEditable()) {
            return false;
        }
        if (!is_finite($quantity) || $quantity <= 0 || $quantity > 999999999.99) {
            $this->errorMessage = 'أدخل كمية صحيحة أكبر من صفر.';

            return false;
        }
        if (!$this->activeShift()) {
            $this->errorMessage = 'افتح الشيفت أولاً لإضافة المنتجات.';
            $this->showOpenShiftModal = true;
            return false;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();

        if (!$tenantId || !$branchId) {
            $this->errorMessage = 'تعذر تحديد المتجر أو الفرع.';
            $this->dispatch('pos-sound', type: 'error');
            return false;
        }

        $remoteIds = in_array($productId, $this->verifiedRemoteProductIds, true)
            ? [$productId]
            : $this->refreshRemoteCatalog(filters: ['ids' => [$productId]]);
        if ($remoteIds !== null && !in_array($productId, $remoteIds, true)) {
            $this->errorMessage = 'المنتج لم يعد متاحاً على السيرفر في الفرع الحالي.';
            return false;
        }

        $product = Product::query()
            ->where('tenant_id', $tenantId)
            ->with(['barcodes' => fn($q) => $q->where('tenant_id', $tenantId)])
            ->find($productId);
        $branchProduct = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('product_id', $productId)->first();

        if (!$product || !$branchProduct) {
            $this->errorMessage = 'المنتج غير مرتبط بالفرع الحالي أو لم يعد متاحاً.';
            $this->dispatch('pos-sound', type: 'error');
            return false;
        }

        $isGift = $this->selectingGift && $this->showProductsModal;
        if ($isGift) {
            $this->authorize('pos.sale.checkout');
        }
        $this->selectingGift = false;
        $this->showProductsModal = false;
        $changeQty = ($this->isReturnMode ? -1 : 1) * $quantity;

        $activeOffer = $this->getActiveProductOffer($productId, (int) $tenantId, (int) $branchId);
        $offerQuantity = $activeOffer?->offer_quantity !== null
            ? (float) $activeOffer->offer_quantity
            : (float) ($branchProduct->offer_quantity ?? 0);
        $offerPrice = $activeOffer?->offer_price !== null
            ? (float) $activeOffer->offer_price
            : ($branchProduct->offer_price !== null ? (float) $branchProduct->offer_price : null);

        $existingLineKey = null;

        if ($this->mergeSimilarProducts && ! $this->isReturnMode) {
            foreach ($this->cart as $lineKey => $item) {
                if ((bool) ($item['is_gift'] ?? false) === $isGift && (int) ($item['id'] ?? 0) === $productId && (float) ($item['quantity'] ?? 0) < 0 === $changeQty < 0) {
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
            $lineKey = $this->mergeSimilarProducts ? (string) $productId . ($isGift ? '_gift' : '') : 'line_' . str()->uuid()->toString();
            $customerPrice = ! $isGift && ! $this->isReturnMode && $this->selectedCustomerId
                ? ($this->lastCustomerPrices([$productId])[$productId] ?? null)
                : null;

            $this->cart[$lineKey] = [
                'id' => $productId,
                'product_number' => $product->product_number,
                'name' => $product->name,
                'barcode' => $scannedBarcode ?: $product->barcodes->first()?->barcode ?? '',
                'is_gift' => $isGift,
                'returned_to_stock' => true,
                'price' => $isGift ? 0.0 : ($customerPrice ?? (float) $branchProduct->retail_price),
                'cost_price' => (float) ($product->cost_price ?? 0),
                'quantity' => $changeQty,
                'subtotal' => $this->roundMoney(($customerPrice ?? (float) $branchProduct->retail_price) * $changeQty),
                // A customer's last price is kept like a manual price so offers and server refreshes don't replace it.
                'price_manual' => $customerPrice !== null,
                'customer_price' => $customerPrice !== null,
                'offer_quantity' => $offerQuantity,
                'offer_price' => $offerPrice,
            ];
        }

        if (empty($this->cart)) {
            $this->clearCartState();
            return true;
        }

        $this->recalculatePrices();

        $this->dispatch('pos-sound', type: 'add');
        $this->dispatch('pos-scroll-cart-bottom');

        return true;
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
        $this->authorize('pos.price.edit');

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

        $this->cart[$lineKey]['price'] = ! empty($this->cart[$lineKey]['is_gift']) ? 0.0 : max(0, (float) $newPrice);
        $this->cart[$lineKey]['price_manual'] = true;
        $this->cart[$lineKey]['customer_price'] = false;
        $this->cart[$lineKey]['subtotal'] = $this->roundMoney((float) $this->cart[$lineKey]['quantity'] * (float) $this->cart[$lineKey]['price']);
        $this->recalculatePrices();
    }

    public function updateLineTotal(string $lineKey, $newTotal): void
    {
        $this->authorize('pos.price.edit');

        if (!$this->ensureInvoiceEditable()) {
            return;
        }
        $resolvedKey = $this->resolveCartLineKey($lineKey);

        if ($resolvedKey === null || !isset($this->cart[$resolvedKey])) {
            return;
        }

        $lineKey = $resolvedKey;

        if (! empty($this->cart[$lineKey]['is_gift'])) {
            return;
        }
        $quantity = (float) ($this->cart[$lineKey]['quantity'] ?? 0);

        if (abs($quantity) < 0.000001) {
            return;
        }

        $targetTotal = (float) str_replace(',', '.', (string) $newTotal);

        if ($quantity < 0) {
            $targetTotal = -abs($targetTotal);
        } else {
            $targetTotal = max(0, $targetTotal);
        }

        $targetTotal = $this->roundMoney($targetTotal);

        $unitPrice = abs($targetTotal / $quantity);

        $this->cart[$lineKey]['price'] = round($unitPrice, 6);
        $this->cart[$lineKey]['price_manual'] = true;
        $this->cart[$lineKey]['customer_price'] = false;
        $this->cart[$lineKey]['subtotal'] = $targetTotal;
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

    public function editCartProduct(int $productId): void
    {
        $this->authorize('products.view');
        $this->authorize('products.edit');

        Product::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($productId);

        $this->editingProductId = $productId;
        $this->showNewProductModal = true;
    }

    public function clearCart(): void
    {
        if ($this->showHoldNotesModal) {
            return;
        }
        $this->showClearInvoiceModal = true;
    }

    public function confirmClearCart(): void
    {
        if (!$this->showClearInvoiceModal) {
            return;
        }
        $this->showClearInvoiceModal = false;
        $this->startNewInvoice();
        $this->dispatch('pos-focus-barcode');
    }

    public function cancelInvoiceDialogs(): void
    {
        $this->showClearInvoiceModal = false;
        $this->showHoldNotesModal = false;
        $this->holdNotes = '';
        $this->resetValidation('holdNotes');
        $this->dispatch('pos-focus-barcode');
    }

    private function clearCartState(bool $reloadProducts = true): void
    {
        $this->selectingGift = false;
        $this->cart = [];
        $this->receipt = [];
        $this->paid_amount = 0;
        $this->payment_method = 'cash';
        $this->discount_amount = 0;
        $this->discount_type = 'fixed';
        $this->delivery_fee = 0;
        $this->showDeliveryDetailsModal = false;
        $this->pendingDeliveryAction = 'checkout';
        $this->deliveryCustomerName = '';
        $this->deliveryCompanyId = null;
        $this->deliveryCustomerPhone = '';
        $this->deliveryCustomerAddress = '';
        $this->deliveryNotes = '';
        $this->custom_final_total = null;
        $this->currentInvoiceId = null;
        $this->editingInvoiceId = null;
        $this->invoiceEditMode = false;
        $this->isReturnMode = false;
        $this->cashReturnNumber = '';
        $this->cashReturnOrderId = null;
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

        unset($this->subtotal, $this->total_cost, $this->expected_profit, $this->calculated_discount, $this->total, $this->amountDue, $this->change, $this->remaining, $this->hasBelowCostItem);
    }

    public function holdInvoice(): void
    {
        $this->authorize('pos.invoice.hold');
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

        if ($this->showClearInvoiceModal) {
            return;
        }
        $this->holdNotes = $this->notes;
        $this->showHoldNotesModal = true;
    }

    public function confirmHoldInvoice(): void
    {
        $this->authorize('pos.invoice.hold');
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

        if (!$this->showHoldNotesModal) {
            return;
        }
        $this->validate(['holdNotes' => ['nullable', 'string', 'max:500']]);
        $this->notes = trim($this->holdNotes);
        $this->heldInvoices[] = [
            'id' => (string) str()->uuid(),
            'cart' => $this->cart,
            'paid_amount' => $this->paid_amount,
            'payment_method' => $this->payment_method,
            'discount_amount' => $this->discount_amount,
            'discount_type' => $this->discount_type,
            'delivery_fee' => $this->delivery_fee,
            'delivery_customer_name' => $this->deliveryCustomerName,
            'delivery_company_id' => $this->deliveryCompanyId,
            'delivery_customer_phone' => $this->deliveryCustomerPhone,
            'delivery_customer_address' => $this->deliveryCustomerAddress,
            'delivery_notes' => $this->deliveryNotes,
            'custom_final_total' => $this->custom_final_total,
            'is_return_mode' => $this->isReturnMode,
            'cash_return_number' => $this->cashReturnNumber,
            'notes' => $this->notes,
            'selected_customer_id' => $this->selectedCustomerId,
            'time' => now()->format('Y-m-d H:i:s'),
            'total' => $this->total,
        ];

        $this->saveHeldInvoices();
        $this->showHoldNotesModal = false;
        $this->holdNotes = '';
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
        $this->deliveryCustomerName = (string) ($held['delivery_customer_name'] ?? '');
        $this->deliveryCompanyId = isset($held['delivery_company_id']) ? (int) $held['delivery_company_id'] : null;
        $this->deliveryCustomerPhone = (string) ($held['delivery_customer_phone'] ?? '');
        $this->deliveryCustomerAddress = (string) ($held['delivery_customer_address'] ?? '');
        $this->deliveryNotes = (string) ($held['delivery_notes'] ?? '');
        $this->custom_final_total = isset($held['custom_final_total']) ? (float) $held['custom_final_total'] : null;
        $this->isReturnMode = (bool) ($held['is_return_mode'] ?? false);
        $this->cashReturnNumber = $this->isReturnMode ? ($held['cash_return_number'] ?? 'RET-CASH-'.Str::uuid()) : '';
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
        $query = trim(strtr(trim($this->searchInvoiceQuery), array_combine(mb_str_split('٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹'), str_split('01234567890123456789'))));
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
        $invoice = $this->onlyOwnInvoices(Order::query())
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['pos', 'return'])
            ->where(function ($q) use ($query, $digitsOnly): void {
                $q->where('invoice_number', $query)->orWhere('invoice_number', 'like', "%{$query}%");

                if (is_numeric($query)) {
                    $q->orWhere('id', (int) $query);
                }

                if ($digitsOnly !== '') {
                    $q->orWhere('invoice_number', 'like', "%{$digitsOnly}%");
                }
            })
            ->orderByRaw('CASE WHEN invoice_number = ? THEN 0 ELSE 1 END', [$query])
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
        $invoice = $this->onlyOwnInvoices(Order::query())
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
            $lineKey = $this->mergeSimilarProducts && $invoice->type !== 'return' ? (string) $item->product_id . ($item->is_gift ? '_gift' : '') : 'invoice_' . $item->id;

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
                'product_number' => $item->product?->product_number,
                'is_gift' => (bool) $item->is_gift,
                'returned_to_stock' => (bool) $item->returned_to_stock,
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
        $this->payment_method = $invoice->payment_method ?: Payment::query()->where('tenant_id', $this->tenantId())->where('order_id', $invoice->id)->latest('id')->value('payment_method') ?: 'cash';
        $this->discount_amount = (float) ($invoice->discount ?? 0);
        $this->discount_type = $invoice->discount_type ?? 'fixed';
        $this->delivery_fee = (float) ($invoice->delivery_fee ?? 0);
        $this->deliveryCustomerName = (string) ($invoice->customer_name ?? '');
        $this->deliveryCompanyId = $invoice->delivery_party_id ? (int) $invoice->delivery_party_id : null;
        $this->deliveryCustomerPhone = (string) ($invoice->customer_phone ?? '');
        $this->deliveryCustomerAddress = (string) ($invoice->customer_address ?? '');
        $invoiceNotes = trim((string) ($invoice->notes ?? ''));
        $deliveryNotesPrefix = 'ملاحظة التوصيل: ';
        $deliveryNotesPosition = mb_strpos($invoiceNotes, $deliveryNotesPrefix);
        $this->deliveryNotes = '';
        if ($deliveryNotesPosition !== false) {
            $this->deliveryNotes = trim(mb_substr($invoiceNotes, $deliveryNotesPosition + mb_strlen($deliveryNotesPrefix)));
            $invoiceNotes = trim(mb_substr($invoiceNotes, 0, $deliveryNotesPosition));
        }
        $this->custom_final_total = $this->roundMoney(max(0, $this->subtotal - $this->calculated_discount + $this->delivery_fee));
        $this->notes = $invoiceNotes;
        $this->selectedCustomerId = $invoice->customer_id ? (int) $invoice->customer_id : null;
        $this->customerSearch = '';
        $this->isReturnMode = $invoice->type === 'return';
        $this->errorMessage = null;
        $this->successMessage = null;
    }

    public function startNewInvoice(): void
    {
        $this->clearCartState();
        $this->errorMessage = null;
        $this->successMessage = null;
    }

    public function deleteInvoice(): void
    {
        $this->authorize('sales_invoices.delete');
        $this->errorMessage = null;
        $this->successMessage = null;

        if (config('branch-sync.mode') === 'edge') {
            $this->errorMessage = 'حذف الفواتير المحفوظة غير متاح أثناء وضع الفرع المحلي. استخدم النظام المركزي.';
            return;
        }

        $invoice = $this->invoiceNavigationQuery()->find($this->currentInvoiceId);
        if (! $invoice) {
            $this->errorMessage = 'اختر فاتورة محفوظة وغير محذوفة أولاً.';
            return;
        }

        try {
            DB::transaction(function () use ($invoice): void {
                $shift = $invoice->shift_id ? Shift::query()
                    ->where('tenant_id', $this->tenantId())
                    ->where('branch_id', $this->getActiveBranchId())
                    ->lockForUpdate()->find($invoice->shift_id) : null;
                if ($invoice->shift_id && (! $shift || $shift->status !== 'open')) {
                    throw new \RuntimeException('لا يمكن حذف فاتورة من شفت مغلق.');
                }

                $order = $this->invoiceNavigationQuery()->lockForUpdate()->find($invoice->id);
                if (! $order) {
                    throw new \RuntimeException('الفاتورة محذوفة أو غير متاحة.');
                }
                if ($order->type === 'return' || $order->returns()->withTrashed()->exists()) {
                    throw new \RuntimeException('لا يمكن حذف مرتجع أو فاتورة مرتبطة بمرتجعات.');
                }
                if (! in_array($order->status, ['completed', 'pending'], true)) {
                    throw new \RuntimeException('حالة الفاتورة لا تسمح بالحذف.');
                }

                if ($order->status === 'completed') {
                    app(\App\Services\BranchStockAllocator::class)->restore($order);
                }

                foreach (Payment::query()->where('tenant_id', $this->tenantId())
                    ->where('branch_id', $order->branch_id)
                    ->where(function ($query) use ($order): void {
                        $query->where('order_id', $order->id)
                            ->orWhere('voucher_number', 'PAY-' . $order->invoice_number);
                    })->lockForUpdate()->get() as $payment) {
                    $payment->delete();
                }
                app(\App\Services\PartyBalance::class)->moveSaleOutstanding((int) $this->tenantId(),
                    $order->customer_id ? (int) $order->customer_id : null, app(\App\Services\PartyBalance::class)->saleOutstanding($order), null, 0);
                $order->delete();
            });
        } catch (\RuntimeException $exception) {
            $this->errorMessage = $exception->getMessage();
            return;
        }

        $this->clearCartState();
        $this->invalidateActiveShiftCache();
        $this->loadHeldInvoices();
        $this->loadQuickProducts();
        $this->successMessage = "تم حذف الفاتورة {$invoice->invoice_number} وإعادة مخزونها. لن تظهر في قوائم الفواتير.";
    }

    /**
     * Employees reopen, search and browse only the invoices they created; the store owner sees every invoice.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Order>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Order>
     */
    private function onlyOwnInvoices($query)
    {
        $user = Auth::user();
        if ($user && ! $user->isTenantOwner() && ! $user->isSaaSAdmin()) {
            $query->where('created_by', $user->id);
        }

        return $query;
    }

    private function invoiceNavigationQuery()
    {
        return $this->onlyOwnInvoices(Order::query()
            ->where('tenant_id', $this->tenantId())
            ->where('branch_id', $this->getActiveBranchId())
            ->whereIn('type', ['pos', 'return']));
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
    }

    public function getCustomerResultsProperty()
    {
        $search = trim($this->customerSearch);
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return collect();
        }

        $remoteIds = $this->showCustomerModal ? $this->refreshRemoteCatalog('customers', ['search' => $search]) : null;
        return Party::query()
            ->where('tenant_id', $tenantId)
            ->when($remoteIds !== null, fn ($query) => $query->whereIn('id', $remoteIds))
            ->whereIn('type', ['customer', 'supplier', 'both'])
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

        $remoteIds = $this->refreshRemoteCatalog('customers', ['ids' => [$customerId]]);
        if ($remoteIds !== null && !in_array($customerId, $remoteIds, true)) {
            $this->errorMessage = 'العميل لم يعد متاحاً على السيرفر في الفرع الحالي.';
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

        $this->selectedCustomerId = (int) $customer->id;
        $this->customerSearch = '';
        $this->showCustomerModal = false;
        $this->errorMessage = null;
        $this->applyCustomerPricesToCart();
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
        $this->applyCustomerPricesToCart();
    }

    /**
     * Last unit price each product was sold at to the selected customer.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, float>
     */
    private function lastCustomerPrices(array $productIds): array
    {
        $tenantId = $this->tenantId();
        if (! $tenantId || ! $this->selectedCustomerId || $productIds === []) {
            return [];
        }

        return DB::table('order_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->where('items.tenant_id', $tenantId)
            ->where('orders.customer_id', $this->selectedCustomerId)
            ->whereIn('items.product_id', $productIds)
            ->whereNull('orders.deleted_at')
            ->where('orders.status', 'completed')
            ->where('orders.type', '!=', 'return')
            ->where('items.quantity', '>', 0)
            ->where('items.is_gift', false)
            ->when($this->currentInvoiceId, fn ($query) => $query->where('orders.id', '!=', $this->currentInvoiceId))
            ->orderByDesc('orders.created_at')
            ->orderByDesc('items.id')
            ->get(['items.product_id', 'items.unit_price'])
            ->unique('product_id')
            ->mapWithKeys(fn (object $row): array => [(int) $row->product_id => (float) $row->unit_price])
            ->all();
    }

    /**
     * Uses the selected customer's last price on cart lines whose price the cashier did not
     * type, and returns lines priced for a previous customer to the branch price.
     */
    private function applyCustomerPricesToCart(): void
    {
        if ($this->cart === [] || $this->isReturnMode) {
            return;
        }

        $lines = collect($this->cart)->filter(fn (array $item): bool => empty($item['is_gift'])
            && (float) ($item['quantity'] ?? 0) > 0
            && (empty($item['price_manual']) || ! empty($item['customer_price'])));
        if ($lines->isEmpty()) {
            return;
        }

        $productIds = $lines->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $customerPrices = $this->lastCustomerPrices($productIds);
        $branchPrices = BranchProduct::query()->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())
            ->whereIn('product_id', $productIds)->pluck('retail_price', 'product_id');

        foreach ($lines as $lineKey => $item) {
            $productId = (int) $item['id'];
            if (array_key_exists($productId, $customerPrices)) {
                $this->cart[$lineKey]['price'] = $customerPrices[$productId];
                $this->cart[$lineKey]['price_manual'] = true;
                $this->cart[$lineKey]['customer_price'] = true;
            } elseif (! empty($item['customer_price'])) {
                $this->cart[$lineKey]['price'] = (float) ($branchPrices[$productId] ?? $item['price']);
                $this->cart[$lineKey]['price_manual'] = false;
                $this->cart[$lineKey]['customer_price'] = false;
            }
        }

        $this->recalculatePrices();
    }

    public function openCustomerPhoneModal(): void
    {
        if (!$this->selectedCustomerId) {
            $this->errorMessage = 'يرجى اختيار الزبون أولاً.';
            return;
        }

        $customer = Party::query()
            ->where('tenant_id', $this->tenantId())
            ->whereIn('type', ['customer', 'supplier', 'both'])
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
            ->whereIn('type', ['customer', 'supplier', 'both'])
            ->where('is_active', true)
            ->findOrFail($this->selectedCustomerId);

        $customer->phone = trim($validated['customerPhoneInput']);
        $customer->save();

        $this->customerPhoneInput = $customer->phone;
        $this->showCustomerPhoneModal = false;
        $this->errorMessage = null;

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

        $this->custom_final_total = $this->roundMoney(max(0, $this->subtotal - $this->calculated_discount + $this->delivery_fee));
    }

    public function updatedDiscountType(): void
    {
        if ($this->subtotal <= 0) {
            $this->custom_final_total = null;
            return;
        }

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

        $maxTotal = $this->roundMoney($this->subtotal + max(0, $this->delivery_fee));
        $minTotal = $this->roundMoney(max(0, $this->delivery_fee));
        $target = max($minTotal, min($maxTotal, (float) $value));

        $target = $this->roundMoney($target);

        $this->discount_type = 'fixed';
        $this->discount_amount = $this->roundMoney(max(0, min($this->subtotal, $this->subtotal + $this->delivery_fee - $target)));

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

    public string $deliveryCompanySearch = '';

    public function updatedDeliveryCompanySearch(): void
    {
        $this->deliveryCompanyId = null;
        $this->resetValidation('deliveryCompanyId');
    }

    public function getDeliveryCompaniesProperty(): \Illuminate\Support\Collection
    {
        $search = trim($this->deliveryCompanySearch);

        if ($search === '') {
            return collect();
        }

        return Party::query()
            ->where('tenant_id', $this->tenantId())
            ->where('is_active', true)
            ->whereIn('type', ['customer', 'supplier', 'both'])
            ->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->limit(30)
            ->get(['id', 'name', 'phone']);
    }

    public function selectDeliveryCompany(int $companyId): void
    {
        $company = Party::query()
            ->where('tenant_id', $this->tenantId())
            ->where('is_active', true)
            ->whereIn('type', ['customer', 'supplier', 'both'])
            ->findOrFail($companyId);

        $this->deliveryCompanyId = $company->id;
        $this->deliveryCompanySearch = $company->name;
        $this->resetValidation('deliveryCompanyId');
    }

    private function deliveryCompanyIsValid(): bool
    {
        return $this->deliveryCompanyId !== null && Party::query()
            ->where('tenant_id', $this->tenantId())
            ->where('is_active', true)
            ->whereIn('type', ['customer', 'supplier', 'both'])
            ->whereKey($this->deliveryCompanyId)
            ->exists();
    }

    public function openDeliveryDetails(string $action = 'checkout'): void
    {
        $this->pendingDeliveryAction = $action;

        if ($this->selectedCustomerId && ($this->deliveryCustomerName === '' || $this->deliveryCustomerPhone === '' || $this->deliveryCustomerAddress === '')) {
            $customer = Party::query()
                ->where('tenant_id', $this->tenantId())
                ->whereKey($this->selectedCustomerId)
                ->first();

            if ($customer) {
                $this->deliveryCustomerName = $this->deliveryCustomerName ?: (string) $customer->name;
                $this->deliveryCustomerPhone = $this->deliveryCustomerPhone ?: (string) ($customer->phone ?? '');
                $this->deliveryCustomerAddress = $this->deliveryCustomerAddress ?: (string) ($customer->address ?? '');
            }
        }

        $this->showDeliveryDetailsModal = true;
    }

    private function needsDeliveryDetails(string $action): bool
    {
        if ($this->delivery_fee <= 0) {
            return false;
        }

        if ($this->deliveryCustomerName !== '' && $this->deliveryCustomerPhone !== '' && $this->deliveryCustomerAddress !== '') {
            if ($this->deliveryCompanyIsValid()) {
                return false;
            }
        }

        $this->openDeliveryDetails($action);

        return true;
    }

    public function confirmDeliveryDetails(): void
    {
        $this->validate([
            'deliveryCustomerName' => ['required', 'string', 'max:255'],
            'deliveryCustomerPhone' => ['required', 'string', 'max:40'],
            'deliveryCustomerAddress' => ['required', 'string', 'max:2000'],
            'deliveryCompanyId' => ['required', 'integer'],
            'deliveryNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $this->deliveryCompanyIsValid()) {
            $this->addError('deliveryCompanyId', 'اختر شركة توصيل من قائمة الموردين أو الزبائن.');

            return;
        }

        $this->deliveryCustomerName = trim($this->deliveryCustomerName);
        $this->deliveryCustomerPhone = trim($this->deliveryCustomerPhone);
        $this->deliveryCustomerAddress = trim($this->deliveryCustomerAddress);
        $this->deliveryNotes = trim($this->deliveryNotes);
        $this->showDeliveryDetailsModal = false;

        if ($this->pendingDeliveryAction === 'checkoutAndPrint') {
            $this->checkoutAndPrint();

            return;
        }

        $this->checkout();
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
        $deliveryFee = ! empty($this->cart) && ! $this->isReturnMode
            ? max(0, $this->delivery_fee)
            : 0;

        return $this->roundMoney($this->total - $deliveryFee - $this->total_cost);
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
            if (empty($item['is_gift']) && (int) $item['quantity'] > 0 && (float) $item['price'] < (float) ($item['cost_price'] ?? 0)) {
                return true;
            }
        }
        return false;
    }

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
        if ($this->preventUnauthorizedBelowCostSale()) {
            return null;
        }

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
        if ($this->isReturnMode && ! $this->invoiceIsLocked()) {
            return $this->saveCashReturn(true);
        }
        if ($this->invoiceIsLocked()) {
            $this->errorMessage = 'هذه فاتورة محفوظة للعرض فقط. اضغط «تعديل» أولاً.';
            return null;
        }
        if ($this->needsDeliveryDetails('checkout')) {
            return null;
        }
        if ($this->preventUnauthorizedBelowCostSale()) {
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
        if ($this->isReturnMode && ! $this->invoiceIsLocked()) {
            $order = $this->saveCashReturn(true);
            if ($order) {
                $this->prepareReceiptFromOrder($order);
                $this->dispatch('print-receipt');
            }
            return;
        }
        if ($this->invoiceIsLocked()) {
            $this->printReceipt();
            return;
        }
        if ($this->needsDeliveryDetails('checkoutAndPrint')) {
            return;
        }
        if ($this->preventUnauthorizedBelowCostSale()) {
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
        $this->authorize('pos.sell_below_cost');

        $mode = $this->pendingCheckoutMode;
        $this->showBelowCostModal = false;
        $order = $this->processCheckout();

        if ($order && $mode === 'checkoutAndPrint') {
            $this->prepareReceiptFromOrder($order);
            $this->dispatch('print-receipt');
        }
    }

    private function preventUnauthorizedBelowCostSale(): bool
    {
        if (! $this->has_below_cost_item || auth()->user()?->can('pos.sell_below_cost')) {
            return false;
        }

        $this->showBelowCostModal = false;
        $this->errorMessage = 'لا تملك صلاحية البيع بأقل من التكلفة.';

        return true;
    }

    private function refreshCheckoutData(): bool
    {
        $ids = array_values(array_unique(array_map('intval', array_column($this->cart, 'id'))));
        $remoteIds = [];
        foreach (array_chunk($ids, 100) as $chunk) {
            $result = $this->refreshRemoteCatalog(filters: ['ids' => $chunk]);
            if ($result === null) {
                return true;
            }
            $remoteIds = [...$remoteIds, ...$result];
        }
        if (array_diff($ids, $remoteIds) !== []) {
            $this->errorMessage = 'أحد الأصناف لم يعد متاحاً على السيرفر. راجع الفاتورة قبل الحفظ.';
            return false;
        }
        if ($this->selectedCustomerId) {
            $customers = $this->refreshRemoteCatalog('customers', ['ids' => [$this->selectedCustomerId]]);
            if ($customers !== null && !in_array($this->selectedCustomerId, $customers, true)) {
                $this->errorMessage = 'العميل لم يعد متاحاً على السيرفر. اختر عميلاً آخر.';
                return false;
            }
        }
        $before = array_map(fn ($amount): float => $this->roundMoney((float) $amount), array_column($this->cart, 'subtotal'));
        $prices = BranchProduct::query()->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())
            ->whereIn('product_id', $ids)->get()->keyBy('product_id');
        foreach ($this->cart as $key => $item) {
            if (empty($item['is_gift']) && empty($item['price_manual']) && $prices->has($item['id'])) {
                $this->cart[$key]['price'] = (float) $prices->get($item['id'])->retail_price;
            }
        }
        $this->recalculatePrices();
        $after = array_map(fn ($amount): float => $this->roundMoney((float) $amount), array_column($this->cart, 'subtotal'));
        if ($before !== $after) {
            $this->customerPaymentConfirmed = false;
            $this->errorMessage = 'تغير سعر أو عرض على السيرفر. تم تحديث الفاتورة؛ راجع المبلغ ثم احفظ مجدداً.';
            return false;
        }

        return true;
    }

    private function processCheckout(): ?Order
    {
        $this->authorize('pos.sale.checkout');
        $this->errorMessage = null;
        $this->successMessage = null;

        $editingInvoiceId = $this->editingInvoiceId ?: ($this->currentInvoiceId ? (int) $this->currentInvoiceId : null);

        if (config('branch-sync.mode') === 'edge' && $editingInvoiceId) {
            $this->errorMessage = 'لا يمكن تعديل فاتورة محفوظة أثناء وضع الفرع المحلي. سجّل مرتجعًا ثم فاتورة جديدة. / Saved invoices cannot be edited in local mode; record a return and a new sale.';

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

        if (!$this->refreshCheckoutData()) {
            return null;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();
        $user = Auth::user();

        if (!$tenantId || !$branchId || !$user) {
            $this->errorMessage = 'تعذر تحديد المتجر أو الفرع أو المستخدم.';
            return null;
        }

        if ($this->has_below_cost_item && ! $user->can('pos.sell_below_cost')) {
            $this->showBelowCostModal = false;
            $this->errorMessage = 'لا تملك صلاحية البيع بأقل من التكلفة.';

            return null;
        }

        if (!in_array($this->payment_method, ['cash', 'card', 'bank_transfer', 'cheque'], true)) {
            $this->errorMessage = 'طريقة الدفع غير صالحة.';
            return null;
        }

        $invoiceType = $this->isReturnMode ? 'return' : 'pos';
        if ($invoiceType === 'return') {
            $this->errorMessage = 'استخدم صفحة مردودات المبيعات لربط المرتجع بفاتورته الأصلية.';
            return null;
        }
        $paidInput = max(0, (float) ($this->paid_amount ?: 0));

        $customer = null;
        if ($this->selectedCustomerId) {
            $customer = Party::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('type', ['customer', 'supplier', 'both'])
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
                $previousCustomerId = null;
                $previousOutstanding = 0.0;

                if ($editingInvoiceId) {
                    $order = $this->onlyOwnInvoices(Order::query())
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $branchId)
                        ->whereIn('type', ['pos', 'return'])
                        ->whereKey($editingInvoiceId)
                        ->lockForUpdate()
                        ->first();

                    if (!$order) {
                        throw new \RuntimeException('الفاتورة الأصلية لم تعد موجودة. رقمها الداخلي: ' . $editingInvoiceId);
                    }

                    if ($order->type === 'return' || Order::query()->where('tenant_id', $tenantId)
                        ->where('original_order_id', $order->id)->exists()) {
                        throw new \DomainException('لا يمكن تعديل فاتورة مرتبطة بمرتجع؛ سجّل مرتجعاً جديداً أو أنشئ فاتورة جديدة.');
                    }

                    $previousCustomerId = $order->customer_id ? (int) $order->customer_id : null;
                    $previousOutstanding = app(\App\Services\PartyBalance::class)->saleOutstanding($order);

                    app(\App\Services\BranchStockAllocator::class)->restore($order);

                    OrderItem::query()
                        ->where('tenant_id', $tenantId)
                        ->where('order_id', $order->id)
                        ->delete();
                }

                $checkoutIds = array_values(array_unique(array_map(fn ($item) => (int) $item['id'], $this->cart)));
                $checkoutProducts = Product::query()->where('tenant_id', $tenantId)->whereIn('id', $checkoutIds)->get()->keyBy('id');
                $checkoutStocks = BranchProduct::query()->where('tenant_id', $tenantId)->whereIn('product_id', $checkoutIds)
                    ->when(!$this->unifiedStock, fn ($query) => $query->where('branch_id', $branchId))
                    ->orderBy('product_id')->orderBy('id')->lockForUpdate()->get()->groupBy('product_id');

                if (! $user->can('pos.price.edit')) {
                    foreach ($this->cart as $lineKey => $cartItem) {
                        if (! empty($cartItem['is_gift'])) {
                            continue;
                        }

                        $branchProduct = $checkoutStocks->get((int) $cartItem['id'], collect())->firstWhere('branch_id', $branchId);
                        if (! $branchProduct) {
                            throw new \RuntimeException('أحد الأصناف غير مرتبط بالفرع الحالي.');
                        }

                        $this->cart[$lineKey]['price'] = (float) $branchProduct->retail_price;
                        $this->cart[$lineKey]['price_manual'] = false;
                    }

                    $this->recalculatePrices();
                }

                $validatedItems = [];
                $saleQuantities = [];
                $subtotal = 0;
                $totalCost = 0;

                foreach ($this->cart as $rawItem) {
                    $productId = (int) ($rawItem['id'] ?? 0);
                    $quantity = (float) ($rawItem['quantity'] ?? 0);
                    $isGift = (bool) ($rawItem['is_gift'] ?? false);
                    $price = $isGift ? 0.0 : max(0, (float) ($rawItem['price'] ?? 0));

                    if (!$productId || $quantity === 0) {
                        throw new \RuntimeException('يوجد صنف أو كمية غير صالحة في الفاتورة.');
                    }

                    if ($invoiceType === 'return' && $quantity > 0) {
                        throw new \RuntimeException('لا يمكن إضافة صنف بيع إلى فاتورة مرتجع. افتح فاتورة جديدة إذا أردت البيع.');
                    }

                    if ($invoiceType === 'pos' && $quantity < 0) {
                        throw new \RuntimeException('لا يمكن إضافة صنف مرتجع إلى فاتورة بيع. افتح فاتورة جديدة إذا أردت الإرجاع.');
                    }

                    $product = $checkoutProducts->get($productId);

                    if (!$product) {
                        throw new \RuntimeException('أحد المنتجات لم يعد متاحاً.');
                    }

                    $activeBranchProduct = $checkoutStocks->get($productId, collect())->firstWhere('branch_id', $branchId);
                    if (! $activeBranchProduct) {
                        throw new \RuntimeException("المنتج {$product->name} غير مرتبط بالفرع الحالي.");
                    }

                    $price = $isGift ? 0.0 : max(0, (float) ($rawItem['price'] ?? $activeBranchProduct->retail_price));

                    $costPrice = max(0, (float) ($rawItem['cost_price'] ?? ($product->cost_price ?? 0)));

                    $lineTotal = array_key_exists('subtotal', $rawItem)
                        ? $this->roundMoney((float) $rawItem['subtotal'])
                        : $this->roundMoney($price * $quantity);

                    if ($isGift) {
                        $lineTotal = 0.0;
                    }

                    if ($quantity < 0) {
                        $lineTotal = -abs($lineTotal);
                    } else {
                        $lineTotal = max(0, $lineTotal);
                    }

                    if ($quantity > 0) {
                        $saleQuantities[$productId] = ($saleQuantities[$productId] ?? 0) + $quantity;
                        $this->validateSaleStockAvailability(
                            $tenantId,
                            $branchId,
                            $productId,
                            $saleQuantities[$productId],
                            $product->name,
                            $checkoutStocks->get($productId, collect())
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
                        'is_gift' => $isGift,
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
                    $discount = $this->roundMoney(
                        max(0, min($subtotal, $subtotal + $deliveryFee - $customTotal))
                    );
                }

                $requiredPayment = abs($total);
                $paid = min($requiredPayment, $customer ? $paidInput : ($paidInput > 0 ? $paidInput : $requiredPayment));
                // A POS sale remains a POS sale even when it is assigned to a customer.
                // The customer only changes the outstanding balance; cash movement is based
                // exclusively on the actual paid amount recorded below.
                $storedInvoiceType = $invoiceType;

                $paymentStatus = $requiredPayment <= 0 || $paid >= $requiredPayment ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
                $isDelivery = $deliveryFee > 0;
                if ($isDelivery && ! $this->deliveryCompanyIsValid()) {
                    throw new \DomainException('اختر شركة توصيل صحيحة من الموردين.');
                }
                $invoiceCustomerName = $isDelivery ? trim($this->deliveryCustomerName) : ($customer?->name ?? 'زبون عام');
                $invoiceCustomerPhone = $isDelivery ? trim($this->deliveryCustomerPhone) : ($customer?->phone ?? null);
                $invoiceCustomerAddress = $isDelivery ? trim($this->deliveryCustomerAddress) : ($customer?->address ?? null);
                $invoiceNotes = trim($this->notes);

                if (trim($this->deliveryNotes) !== '') {
                    $invoiceNotes = trim($invoiceNotes . ($invoiceNotes !== '' ? "\n\n" : '') . 'ملاحظة التوصيل: ' . trim($this->deliveryNotes));
                }

                if (!$order) {
                    $order = Order::create([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'shift_id' => $lockedShift->id,
                        'created_by' => $user->id,
                        'customer_id' => $customer?->id,
                        'customer_name' => $invoiceCustomerName,
                        'customer_phone' => $invoiceCustomerPhone,
                        'customer_address' => $invoiceCustomerAddress,
                        'invoice_number' => $this->makeInvoiceNumber($storedInvoiceType, $tenantId, $branchId),
                        'type' => $storedInvoiceType,
                        'source' => 'pos',
                        'sale_kind' => 'retail',
                        'status' => 'completed',
                        'subtotal' => $subtotal,
                        'tax_amount' => 0,
                        'discount_type' => $this->discount_type,
                        'discount_rate' => $this->discount_type === 'percentage' ? min(100, max(0, $this->discount_amount)) : 0,
                        'discount' => $discount,
                        'delivery_fee' => $deliveryFee,
                        'delivery_party_id' => $isDelivery ? $this->deliveryCompanyId : null,
                        'total' => $total,
                        'total_cost' => $totalCost,
                        'total_profit' => $this->roundMoney(($total - $deliveryFee) - $totalCost),
                        'paid_amount' => $paid,
                        'payment_method' => $this->payment_method,
                        'payment_status' => $paymentStatus,
                        'notes' => $invoiceNotes ?: null,
                    ]);
                } else {
                    $updateData = [
                        'shift_id' => $lockedShift->id,
                        'customer_id' => $customer?->id,
                        'customer_name' => $invoiceCustomerName,
                        'customer_phone' => $invoiceCustomerPhone,
                        'customer_address' => $invoiceCustomerAddress,
                        'type' => $storedInvoiceType,
                        'source' => 'pos',
                        'sale_kind' => 'retail',
                        'status' => 'completed',
                        'subtotal' => $subtotal,
                        'tax_amount' => 0,
                        'discount_type' => $this->discount_type,
                        'discount_rate' => $this->discount_type === 'percentage' ? min(100, max(0, $this->discount_amount)) : 0,
                        'discount' => $discount,
                        'delivery_fee' => $deliveryFee,
                        'delivery_party_id' => $isDelivery ? $this->deliveryCompanyId : null,
                        'total' => $total,
                        'total_cost' => $totalCost,
                        'total_profit' => $this->roundMoney(($total - $deliveryFee) - $totalCost),
                        'paid_amount' => $paid,
                        'payment_method' => $this->payment_method,
                        'payment_status' => $paymentStatus,
                        'notes' => $invoiceNotes ?: null,
                        'updated_at' => now(),
                    ];

                    $affected = Order::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)->whereKey($order->id)->update($updateData);

                    if ($affected === 0) {
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

                foreach ($validatedItems as $item) {
                    $orderItem = OrderItem::create([
                        'is_gift' => $item['is_gift'],
                        'tenant_id' => $tenantId,
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'cost_price' => $item['cost_price'],
                        'total_price' => $item['total_price'],
                        'total_cost' => $this->roundMoney($item['cost_price'] * $item['quantity']),
                        'discount' => 0,
                    ]);

                    $branchProduct = $checkoutStocks->get($item['product_id'], collect())->firstWhere('branch_id', $branchId);

                    if (!$branchProduct) {
                        throw new \RuntimeException(
                            "المنتج {$item['product_id']} غير مرتبط بالفرع الحالي."
                        );
                    }

                    $itemQuantity = (float) $item['quantity'];

                    if ($itemQuantity > 0) {
                        if (app(DesktopPosConnection::class)->enabled()) {
                            \App\Services\StockMovementContext::forDocument($order, fn () => $branchProduct->decrement('stock_quantity', $itemQuantity));
                        }
                    } elseif ($itemQuantity < 0) {
                        \App\Services\StockMovementContext::forDocument($order, fn () => $branchProduct->increment('stock_quantity', abs($itemQuantity)));
                    }
                }

                if (!app(DesktopPosConnection::class)->enabled()) {
                    app(\App\Services\BranchStockAllocator::class)->deductMany($order, $saleQuantities, $this->unifiedStock, $this->allowNegativeStock);
                }

                $voucherNumber = 'PAY-' . $order->invoice_number;

                $existingPayment = Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where(function ($query) use ($voucherNumber, $order): void {
                        $query->where('voucher_number', $voucherNumber)->orWhere('order_id', $order->id);
                    })
                    ->lockForUpdate()
                    ->first();

                // Delivery fees remain part of the invoice total and the customer's paid
                // balance, but are collected by the delivery company.  Therefore they
                // must not create a cash/bank movement in the cashier's shift.
                $shiftReceiptAmount = $this->roundMoney(max(0, $paid - $deliveryFee));

                if ($shiftReceiptAmount > 0 && ($customer || $invoiceType === 'return')) {
                    $paymentNotes = 'سند قبض للفاتورة #' . $order->invoice_number;

                    if ($deliveryFee > 0) {
                        $paymentNotes .= ' (لا يشمل رسوم التوصيل: ' . number_format($deliveryFee, 2, '.', '') . ')';
                    }

                    $paymentData = [
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'shift_id' => (int) ($order->shift_id ?: $lockedShift->id),
                        'created_by' => $user->id,
                        'party_id' => $customer?->id,
                        'type' => $invoiceType === 'return' ? 'payment' : 'receipt',
                        'voucher_number' => $voucherNumber,
                        'amount' => $shiftReceiptAmount,
                        'payment_method' => $this->payment_method,
                        'order_id' => $order->id,
                        'notes' => $paymentNotes,
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

                // The unpaid part of a customer sale is owed by the customer, like on every other sales screen.
                $order->refresh();
                app(\App\Services\PartyBalance::class)->moveSaleOutstanding((int) $tenantId, $previousCustomerId, $previousOutstanding,
                    $order->customer_id ? (int) $order->customer_id : null, app(\App\Services\PartyBalance::class)->saleOutstanding($order));

                $this->queueSaleForSync($order, $lockedShift->fresh());

                return Order::query()
                    ->whereKey($order->id)
                    ->with(['items.product', 'user', 'branch'])
                    ->firstOrFail();
            });

            $savedInvoiceNumber = (string) $order->invoice_number;
            $wasEditing = (bool) $editingInvoiceId;

            $this->invalidateActiveShiftCache();
            $this->activeShiftId = $shift->id;

            $this->clearCartState();
            $operation = null;
            if (app(DesktopPosConnection::class)->enabled()) {
                $operation = BranchSyncOperation::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                    ->where('operation_type', 'sale.upsert')->where('payload->order->sync_uuid', $order->sync_uuid)->first();
                if ($operation) {
                    app(DesktopPosConnection::class)->flush((int) $tenantId, (int) $branchId, $operation->operation_uuid);
                }
            }
            $this->refreshBranchSyncStatus();
            $this->activeShiftId = $shift->id;

            $this->successMessage = $customer
                ? ($wasEditing
                    ? "تم تعديل فاتورة الزبون {$savedInvoiceNumber} وفتح فاتورة جديدة."
                    : "تم حفظ فاتورة الزبون {$savedInvoiceNumber} وفتح فاتورة جديدة.")
                : ($wasEditing ? ($invoiceType === 'return' ? "تم تعديل المرتجع {$savedInvoiceNumber} بنجاح وتم فتح فاتورة جديدة." : "تم تعديل الفاتورة {$savedInvoiceNumber} بنجاح وتم فتح فاتورة جديدة.") : ($invoiceType === 'return' ? "تم حفظ المرتجع {$savedInvoiceNumber} وتم فتح فاتورة جديدة." : "تم حفظ الفاتورة {$savedInvoiceNumber} وتم فتح فاتورة جديدة."));

            if (app(DesktopPosConnection::class)->enabled()) {
                $operation?->refresh();
                $this->successMessage = $operation?->status === 'synced'
                    ? "تم حفظ الفاتورة {$savedInvoiceNumber} على السيرفر."
                    : "تم حفظ الفاتورة {$savedInvoiceNumber} محلياً، وهي بانتظار تأكيد السيرفر. لا تعد إدخالها.";
                if (in_array($operation?->status, ['conflict', 'failed'], true)) {
                    $this->errorMessage = 'حُفظت الفاتورة محلياً، لكن السيرفر رفض مزامنتها. يلزم مراجعة تعارض المزامنة.';
                }
            }

            $this->dispatch('pos-sound', type: $wasEditing ? 'save-edit' : 'save');

            return $order;
        } catch (\DomainException $exception) {
            $this->errorMessage = $exception->getMessage();

            return null;
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
        string $productName,
        ?\Illuminate\Support\Collection $lockedStockRows = null
    ): void {
        $branchProducts = $lockedStockRows ?? BranchProduct::query()
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
                throw new \DomainException(
                    "المنتج {$productName} غير مرتبط بالفرع الحالي."
                );
            }

            if (!$this->allowNegativeStock && (float) $current->stock_quantity < $quantity) {
                throw new \DomainException(
                    "المخزون غير كافٍ للمنتج {$productName}. المتوفر: {$current->stock_quantity}."
                );
            }

            return;
        }

        if ($branchProducts->isEmpty()) {
            throw new \DomainException(
                "المنتج {$productName} غير مرتبط بأي فرع في هذا المتجر."
            );
        }

        $available = 0.0;
        foreach ($branchProducts as $branchProduct) {
            $available += max(0, (float) $branchProduct->stock_quantity);
        }

        if (app(DesktopPosConnection::class)->enabled()) {
            $shared = json_decode((string) TenantSetting::get($tenantId, 'desktop_shared_stock_'.$currentBranchId, '{}'), true) ?: [];
            $available = (float) ($shared[$productId] ?? $available);
        }

        if (!$this->allowNegativeStock && $available + 0.000001 < $quantity) {
            throw new \DomainException(
                "المخزون الموحد غير كافٍ للمنتج {$productName}. المتوفر في جميع الفروع: {$available}."
            );
        }
    }

    private function queueSaleForSync(Order $order, Shift $shift): void
    {
        if (config('branch-sync.mode') !== 'edge') {
            return;
        }

        $payment = Payment::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('order_id', $order->id)
            ->first();

        $items = OrderItem::query()
            ->where('tenant_id', $order->tenant_id)
            ->where('order_id', $order->id)
            ->get()
            ->map(fn (OrderItem $item): array => [
                'product_id' => (int) $item->product_id,
                'is_gift' => (bool) $item->is_gift,
                'restock' => (bool) $item->returned_to_stock,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'total_price' => (float) $item->total_price,
                'cost_price' => (float) $item->cost_price,
                'total_cost' => (float) $item->total_cost,
                'discount' => (float) $item->discount,
            ])->all();

        $paymentData = $payment ? [
            'amount' => (float) $payment->amount,
            'type' => $payment->type,
            'voucher_number' => $payment->voucher_number,
            'payment_method' => $payment->payment_method,
            'payment_date' => $payment->payment_date->toISOString(),
            'notes' => $payment->notes,
        ] : null;

        if (app(DesktopPosConnection::class)->enabled()) {
            $shared = json_decode((string) TenantSetting::get((int) $order->tenant_id, 'desktop_shared_stock_'.$order->branch_id, '{}'), true) ?: [];
            foreach ($items as $item) {
                if (array_key_exists($item['product_id'], $shared) && ($item['quantity'] > 0 || $item['restock'])) {
                    $shared[$item['product_id']] -= $item['quantity'];
                }
            }
            TenantSetting::set((int) $order->tenant_id, 'desktop_shared_stock_'.$order->branch_id, json_encode($shared, JSON_THROW_ON_ERROR));
        }

        BranchSyncOperation::query()->create([
            'tenant_id' => $order->tenant_id,
            'branch_id' => $order->branch_id,
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'sale.upsert',
            'status' => 'pending',
            'payload' => [
                'order' => [
                    'sync_uuid' => $order->sync_uuid,
                    'invoice_number' => $order->invoice_number,
                    'created_at' => $order->created_at->toISOString(),
                    'created_by' => $order->created_by,
                    'customer_id' => $order->customer_id,
                    'customer_name' => $order->customer_name ?: 'زبون عام',
                    'customer_phone' => $order->customer_phone,
                    'customer_address' => $order->customer_address,
                    'type' => $order->type,
                    'source' => $order->source,
                    'sale_kind' => $order->sale_kind,
                    'subtotal' => (float) $order->subtotal,
                    'tax_amount' => (float) $order->tax_amount,
                    'discount_type' => $order->discount_type,
                    'discount_rate' => (float) $order->discount_rate,
                    'discount' => (float) $order->discount,
                    'delivery_fee' => (float) $order->delivery_fee,
                    'delivery_party_id' => $order->delivery_party_id,
                    'total' => (float) $order->total,
                    'total_cost' => (float) $order->total_cost,
                    'total_profit' => (float) $order->total_profit,
                    'paid_amount' => (float) $order->paid_amount,
                    'payment_method' => $order->payment_method,
                    'payment_status' => $order->payment_status,
                    'notes' => $order->notes,
                ],
                'shift' => [
                    'sync_uuid' => $shift->sync_uuid,
                    'status' => 'open',
                    'opening_cash' => (float) $shift->opening_cash,
                    'opened_at' => $shift->opened_at->toISOString(),
                    'opened_by' => $shift->opened_by,
                    'notes' => $shift->notes,
                ],
                'items' => $items,
                'payment' => $paymentData,
            ],
        ]);
    }

    /** Queues a cash expense paid from the drawer so the central accounts receive it too. */
    private function queueExpenseForSync(Payment $expense, Shift $shift): void
    {
        if (config('branch-sync.mode') !== 'edge') {
            return;
        }

        BranchSyncOperation::query()->create([
            'tenant_id' => $expense->tenant_id,
            'branch_id' => $expense->branch_id,
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'expense.created',
            'status' => 'pending',
            'payload' => [
                'payment' => [
                    'voucher_number' => $expense->voucher_number,
                    'amount' => (float) $expense->amount,
                    'payment_method' => $expense->payment_method,
                    'payment_date' => $expense->payment_date->toISOString(),
                    'created_by' => $expense->created_by,
                    'notes' => $expense->notes,
                ],
                'shift' => [
                    'sync_uuid' => $shift->sync_uuid,
                    'opening_cash' => (float) $shift->opening_cash,
                    'opened_at' => $shift->opened_at->toISOString(),
                    'opened_by' => $shift->opened_by,
                    'notes' => $shift->notes,
                ],
            ],
        ]);
    }

    private function queueShiftOpenForSync(Shift $shift): void
    {
        if (config('branch-sync.mode') !== 'edge') {
            return;
        }

        BranchSyncOperation::query()->create([
            'tenant_id' => $shift->tenant_id,
            'branch_id' => $shift->branch_id,
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'shift.opened',
            'status' => 'pending',
            'payload' => [
                'shift' => [
                    'sync_uuid' => $shift->sync_uuid,
                    'opening_cash' => (float) $shift->opening_cash,
                    'opened_at' => $shift->opened_at->toISOString(),
                    'opened_by' => $shift->opened_by,
                    'notes' => $shift->notes,
                ],
            ],
        ]);
    }

    private function queueShiftCloseForSync(?Shift $shift): void
    {
        if (config('branch-sync.mode') !== 'edge' || ! $shift) {
            return;
        }

        BranchSyncOperation::query()->create([
            'tenant_id' => $shift->tenant_id,
            'branch_id' => $shift->branch_id,
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'shift.closed',
            'status' => 'pending',
            'payload' => [
                'shift' => [
                    'sync_uuid' => $shift->sync_uuid,
                    'status' => 'closed',
                    'opening_cash' => (float) $shift->opening_cash,
                    'expected_cash' => (float) $shift->expected_cash,
                    'actual_cash' => (float) $shift->actual_cash,
                    'difference' => (float) $shift->difference,
                    'total_sales' => (float) $shift->total_sales,
                    'total_returns' => (float) $shift->total_returns,
                    'opened_at' => $shift->opened_at->toISOString(),
                    'opened_by' => $shift->opened_by,
                    'closed_at' => $shift->closed_at->toISOString(),
                    'closed_by' => $shift->closed_by,
                    'notes' => $shift->notes,
                ],
            ],
        ]);
    }

    private function makeInvoiceNumber(string $type, int $tenantId, int $branchId): string
    {
        $prefix = $type === 'return' ? 'R' : 'A';

        do {
            $number = $prefix . $branchId . '-' . random_int(100000000, 999999999);
        } while (Order::query()->where('tenant_id', $tenantId)->where('invoice_number', $number)->exists());

        return $number;
    }

    private function roundMoney(float $amount): float
    {
        return round($amount, 2);
    }

    private function calculateOfferTotal(float $quantity, float $unitPrice, ?float $offerQuantity, ?float $offerPrice): float
    {
        $sign = $quantity < 0 ? -1 : 1;
        $absoluteQuantity = abs($quantity);
        $offerQuantity = (float) ($offerQuantity ?? 0);
        $offerPrice = $offerPrice !== null ? (float) $offerPrice : null;

        if ($absoluteQuantity <= 0 || $offerQuantity <= 0 || $offerPrice === null || $offerPrice < 0) {
            return $this->roundMoney($quantity * $unitPrice);
        }

        if ($offerPrice >= ($offerQuantity * $unitPrice)) {
            return $this->roundMoney($quantity * $unitPrice);
        }

        $fullOffers = (int) floor($absoluteQuantity / $offerQuantity);
        $remainder = round($absoluteQuantity - ($fullOffers * $offerQuantity), 6);

        $total = ($fullOffers * $offerPrice) + ($remainder * $unitPrice);

        return $this->roundMoney($total * $sign);
    }

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

        $productIds = collect($this->cart)
            ->filter(fn (array $item): bool => empty($item['is_gift']) && empty($item['price_manual']))
            ->pluck('id')->filter()->unique()->values();
        $branchPrices = collect();
        $activeOffers = collect();
        if ($tenantId && $branchId && $productIds->isNotEmpty()) {
            $branchPrices = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                ->whereIn('product_id', $productIds)->get(['product_id', 'offer_quantity', 'offer_price'])->keyBy('product_id');
            $now = now();
            $activeOffers = ProductOffer::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                ->whereIn('product_id', $productIds)->where('is_active', true)->where('start_at', '<=', $now)
                ->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>=', $now))
                ->orderByDesc('start_at')->get(['product_id', 'offer_quantity', 'offer_price'])->unique('product_id')->keyBy('product_id');
        }

        foreach ($this->cart as $id => $item) {
            if (! empty($item['is_gift'])) {
                $this->cart[$id]['price'] = 0.0;
                $this->cart[$id]['subtotal'] = 0.0;
                $this->cart[$id]['normal_total'] = 0.0;
                $this->cart[$id]['promotion_savings'] = 0.0;
                continue;
            }
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['price'] ?? 0);

            $normalTotal = $this->roundMoney($quantity * $unitPrice);

            if (($item['price_manual'] ?? false) === true || $this->isReturnMode) {
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

            if ($tenantId && $branchId && (int) ($item['id'] ?? 0) > 0) {
                $productId = (int) $item['id'];
                $activeOffer = $activeOffers->get($productId);

                if ($activeOffer) {
                    $offerQuantity = (float) ($activeOffer->offer_quantity ?? 0);
                    $offerPrice = $activeOffer->offer_price !== null
                        ? (float) $activeOffer->offer_price
                        : null;
                } else {
                    $branchProduct = $branchPrices->get($productId);

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

            $savings = 0;
            if ($quantity > 0 && abs($finalTotal) < abs($normalTotal)) {
                $savings = abs($normalTotal) - abs($finalTotal);
            }

            $this->cart[$id]['promotion_savings'] = $this->roundMoney($savings);
        }

        $offerGroups = [];
        foreach ($this->cart as $lineKey => $item) {
            if (!empty($item['is_gift']) || !empty($item['price_manual']) || (float) $item['quantity'] == 0) {
                continue;
            }
            $groupKey = json_encode([$item['id'], (float) $item['price'], (float) ($item['offer_quantity'] ?? 0), $item['offer_price'] ?? null, (float) $item['quantity'] < 0]);
            $offerGroups[$groupKey][] = $lineKey;
        }
        foreach ($offerGroups as $lineKeys) {
            if (count($lineKeys) < 2) {
                continue;
            }
            $first = $this->cart[$lineKeys[0]];
            $quantity = array_sum(array_map(fn ($key) => (float) $this->cart[$key]['quantity'], $lineKeys));
            $groupTotal = $this->calculateOfferTotal($quantity, (float) $first['price'], (float) ($first['offer_quantity'] ?? 0), $first['offer_price'] ?? null);
            $allocatedTotal = 0.0;
            $allocatedQuantity = 0.0;
            foreach ($lineKeys as $lineKey) {
                $allocatedQuantity += (float) $this->cart[$lineKey]['quantity'];
                $cumulativeTotal = $this->roundMoney($groupTotal * $allocatedQuantity / $quantity);
                $lineTotal = $this->roundMoney($cumulativeTotal - $allocatedTotal);
                $allocatedTotal = $cumulativeTotal;
                $this->cart[$lineKey]['subtotal'] = $lineTotal;
                $this->cart[$lineKey]['promotion_savings'] = (float) $this->cart[$lineKey]['quantity'] > 0
                    ? $this->roundMoney(max(0, (float) $this->cart[$lineKey]['normal_total'] - $lineTotal)) : 0.0;
            }
        }

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
                $groupKey = 'product_' . $productId . '_price_' . number_format($unitPrice, 2, '.', '') . ($item->is_gift ? '_gift' : '');
            }

            if (!isset($groupedItems[$groupKey])) {
                $groupedItems[$groupKey] = [
                    'product_id' => $productId,
                    'is_gift' => (bool) $item->is_gift,
                    'name' => ($item->product?->name ?? 'منتج غير محدد') . ($item->is_gift ? ' — هدية' : ''),
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

        foreach ($groupedItems as $groupedItem) {
            $quantity = (float) $groupedItem['qty'];
            $total = (float) $groupedItem['total'];
            $normalTotal = $this->roundMoney((float) $groupedItem['normal_total']);

            if (abs($quantity) < 0.000001) {
                continue;
            }

            $totalQty += abs($quantity);

            $productId = $groupedItem['product_id'];

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
        $tenant = Tenant::query()->find($this->tenantId());

        // Delivery details are printed with the invoice; the delivery company is never printed.
        $receiptNotes = trim((string) ($order->notes ?? ''));
        $deliveryNote = '';
        $deliveryNotePosition = mb_strpos($receiptNotes, 'ملاحظة التوصيل: ');
        if ($deliveryNotePosition !== false) {
            $deliveryNote = trim(mb_substr($receiptNotes, $deliveryNotePosition + mb_strlen('ملاحظة التوصيل: ')));
            $receiptNotes = trim(mb_substr($receiptNotes, 0, $deliveryNotePosition));
        }
        $isDeliveryOrder = (float) ($order->delivery_fee ?? 0) > 0 || $order->delivery_party_id || $deliveryNote !== '';
        $receiptDelivery = $isDeliveryOrder ? array_filter([
            'name' => trim((string) $order->customer_name),
            'phone' => trim((string) $order->customer_phone),
            'address' => trim((string) $order->customer_address),
            'notes' => $deliveryNote,
        ], fn (string $value): bool => $value !== '') : [];
        $currency = (string) TenantSetting::get((int) $this->tenantId(), 'currency', 'ILS');
        $currencySymbols = ['ILS' => '₪', 'USD' => '$', 'JOD' => 'د.أ'];
        $this->receipt = [
            'store_name' => $order->branch?->name ?: ($tenant?->name ?: 'نقطة البيع'),
            'store_phone' => $tenant?->phone,
            'store_address' => TenantSetting::get((int) $this->tenantId(), 'store_address', ''),
            'copy_type' => $order->type === 'return' ? 'فاتورة مرتجع' : 'فاتورة بيع',
            'invoice_no' => (string) $order->invoice_number,
            'date' => $createdAt->format('Y/m/d'),
            'time' => $createdAt->format('h:i A'),
            'cashier' => $order->user?->name ?? 'الكاشير',
            'notes' => $receiptNotes,
            'delivery' => $receiptDelivery,
            'items' => $items,
            'total_qty' => $totalQty,
            'total_promotion_savings' => number_format($totalPromotionSavings, 2),
            'subtotal' => number_format((float) $order->subtotal, 2),
            'discount' => number_format((float) $order->discount, 2),
            'delivery_fee' => number_format($this->customerInvoiceCopy ? 0 : (float) ($order->delivery_fee ?? 0), 2),
            'total_amount' => number_format(max(0, abs((float) $order->total) - ($this->customerInvoiceCopy ? (float) $order->delivery_fee : 0)), 2),
            'paid' => number_format(max(0, (float) $order->paid_amount - ($this->customerInvoiceCopy ? (float) $order->delivery_fee : 0)), 2),
            'account_balance' => $order->customer_id ? Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->value('current_balance') : null,
            'change' => number_format(max(0, (float) $order->paid_amount - abs((float) $order->total)), 2),
            'customer_name' => $order->customer_id ? ($order->customer_name ?: Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->value('name')) : null,
            'summary' => \App\Services\InvoiceBalanceSummary::forOrder($order),
            'currency' => $currencySymbols[$currency] ?? '₪',
            'notice' => TenantSetting::get((int) $this->tenantId(), 'receipt_footer', 'شكراً لتعاملكم معنا'),
        ];
    }

    public function printReceipt(): void
    {
        if ($this->currentInvoiceId) {
            $order = $this->onlyOwnInvoices(Order::query())->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())->whereKey($this->currentInvoiceId)->with('items.product', 'user', 'branch')->first();

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

    public function copyLoadedInvoice(): void
    {
        if (!$this->currentInvoiceId) {
            $this->errorMessage = 'لا توجد فاتورة محفوظة لنسخها.';
            return;
        }

        $invoice = $this->onlyOwnInvoices(Order::query())
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
                ? (string) $item->product_id . ($item->is_gift ? '_gift' : '')
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
                'is_gift' => (bool) $item->is_gift,
                'name' => $item->product?->name ?? 'منتج غير محدد',
                'barcode' => '',
                'price' => (float) $item->unit_price,
                'cost_price' => (float) ($item->cost_price ?? ($item->product?->cost_price ?? 0)),
                'quantity' => $quantity,
                'subtotal' => $subtotal,
            ];
        }

        $this->currentInvoiceId = null;
        $this->editingInvoiceId = null;
        $this->invoiceEditMode = false;

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

    private function buildWhatsappInvoiceMessage(Order $order): string
    {
        $order->loadMissing('items.product');

        $lines = [];
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

            $productName = trim((string) ($item->product?->name ?? 'منتج غير محدد'));
            $nameWords = preg_split('/\s+/u', $productName, -1, PREG_SPLIT_NO_EMPTY);
            $shortName = implode(' ', array_slice($nameWords ?: ['منتج'], 0, 2)) . ($item->is_gift ? ' (هدية)' : '');

            $unitPrice = number_format(abs((float) $item->unit_price), 2);
            $lineTotal = number_format(abs((float) $item->total_price), 2);
            $prefix = $quantity < 0 ? 'مرتجع: ' : '• ';

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
        $lines[] = 'جميع الحقوق محفوظة © '.now()->year.' — WaslaPOS';

        return implode("\n", $lines);
    }

    private function saveInvoiceBeforeAction(): ?Order
    {
        if ($this->currentInvoiceId && !$this->invoiceEditMode) {
            return $this->onlyOwnInvoices(Order::query())
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

        return $this->processCheckout();
    }

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
            return $this->onlyOwnInvoices(Order::query())->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())->with('user')->find($this->currentInvoiceId)?->user?->name ?? 'غير محدد';
        }

        return Auth::user()?->name ?? 'الكاشير الحالي';
    }

    public function getInvoiceDateProperty(): string
    {
        if ($this->currentInvoiceId) {
            $date = $this->onlyOwnInvoices(Order::query())->where('tenant_id', $this->tenantId())->where('branch_id', $this->getActiveBranchId())->find($this->currentInvoiceId)?->created_at;

            if ($date) {
                return $date->locale('ar')->isoFormat('dddd، YYYY-MM-DD - h:mm A');
            }
        }

        return now()->locale('ar')->isoFormat('dddd، YYYY-MM-DD');
    }

    public function downloadInvoicePdf(): mixed
    {
        $order = $this->saveInvoiceBeforeAction();

        if (!$order) {
            if (!$this->errorMessage) {
                $this->errorMessage = 'تعذر حفظ الفاتورة قبل إنشاء PDF.';
            }
            return null;
        }

        $order->loadSum('payments as collected_amount', 'amount');

        return response()->streamDownload(function () use ($order) {
            $pdf = app('dompdf.wrapper');

            $pdf->loadView('pages.tenant.pos.partials.invoice-pdf', [
                'order' => $order,
                'accountBalance' => $order->customer_id ? Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->value('current_balance') : null,
                'storeName' => $order->branch?->name ?: Tenant::query()->whereKey($order->tenant_id)->value('name') ?: 'المتجر',
            ]);

            echo $pdf->output();
        }, \App\Services\PdfFilename::forDocument($order->customer_name ?: ($order->customer_id ? Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->value('name') : null), $order->invoice_number, 'زبون عام'));
    }

    /** @return array{local: array<int, float|null>, shared: array<int, float|null>} */
    private function cartStockPreviews(): array
    {
        $tenantId = $this->tenantId();
        $branchId = $this->getActiveBranchId();
        $result = ['local' => [], 'shared' => []];
        if (!$tenantId || !$branchId || $this->cart === []) {
            return $result;
        }
        $quantities = [];
        foreach ($this->cart as $item) {
            $productId = (int) $item['id'];
            $quantity = (float) $item['quantity'];
            $quantities[$productId] = ($quantities[$productId] ?? 0) + ($quantity < 0 && ! ($item['returned_to_stock'] ?? true) ? 0 : $quantity);
        }
        $stocks = BranchProduct::query()->where('tenant_id', $tenantId)
            ->whereIn('product_id', array_keys($quantities))
            ->when(!$this->unifiedStock, fn ($query) => $query->where('branch_id', $branchId))
            ->selectRaw('product_id, SUM(stock_quantity) as total_quantity, SUM(CASE WHEN branch_id = ? THEN stock_quantity ELSE 0 END) as local_quantity, SUM(CASE WHEN branch_id = ? THEN 1 ELSE 0 END) as local_records', [$branchId, $branchId])
            ->groupBy('product_id')->get()->keyBy('product_id');
        $remote = [];
        if ($this->unifiedStock && app(DesktopPosConnection::class)->enabled()) {
            $remote = json_decode((string) TenantSetting::get($tenantId, 'desktop_shared_stock_'.$branchId, '{}'), true) ?: [];
        }
        $original = [];
        $localOriginal = [];
        if ($this->editingInvoiceId && $this->invoiceEditMode && $this->onlyOwnInvoices(Order::query())->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)->whereKey($this->editingInvoiceId)->where('status', 'completed')->exists()) {
            $original = OrderItem::query()->where('tenant_id', $tenantId)->where('order_id', $this->editingInvoiceId)
                ->where(fn ($query) => $query->where('quantity', '>', 0)->orWhere('returned_to_stock', true))
                ->selectRaw('product_id, SUM(quantity) as quantity')->groupBy('product_id')->pluck('quantity', 'product_id')->all();
            $localOriginal = $this->unifiedStock
                ? DB::table('stock_movements')->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                    ->where('source_id', $this->editingInvoiceId)->whereIn('source_type', ['order', 'sale_reversal'])
                    ->selectRaw('product_id, -SUM(quantity) as quantity')->groupBy('product_id')->pluck('quantity', 'product_id')->all()
                : $original;
        }
        $locked = $this->invoiceIsLocked();
        foreach ($quantities as $productId => $quantity) {
            $stock = $stocks->get($productId);
            $deduction = $locked ? 0 : $quantity;
            $base = (float) ($stock?->local_quantity ?? 0) + (float) ($localOriginal[$productId] ?? 0);
            $localDeduction = $this->unifiedStock && $deduction > 0 ? min(max(0, $base), $deduction) : $deduction;
            $result['local'][$productId] = $stock && $stock->local_records > 0 ? round($base - $localDeduction, 3) : null;
            $total = isset($remote[$productId]) && is_numeric($remote[$productId]) ? (float) $remote[$productId] : $stock?->total_quantity;
            $result['shared'][$productId] = $total !== null ? round((float) $total + (float) ($original[$productId] ?? 0) - $deduction, 3) : null;
        }

        return $result;
    }

    public function render()
    {
        $tenantId = $this->tenantId();
        $user = Auth::user();
        $branches = [];

        if ($tenantId && !$user?->branch_id) {
            $branches = Branch::query()->where('tenant_id', $tenantId)->orderBy('name')->get();
        }

        $missingBarcodeProducts = collect();
        if ($this->linkMissingBarcode && Auth::user()?->can('products.barcode.manage')) {
            $search = mb_substr(trim($this->missingBarcodeSearch), 0, 100);
            if ($search !== '') {
                $normalizedSearch = app(PosProductSearch::class)->normalize($search);
                $searchWords = array_slice(explode(' ', $normalizedSearch), 0, 8);
                $missingBarcodeProducts = Product::query()->where('tenant_id', $tenantId)
                    ->where(function ($query) use ($search, $normalizedSearch, $searchWords): void {
                        $query->where(function ($nameQuery) use ($searchWords): void {
                            foreach ($searchWords as $word) {
                                $nameQuery->where(function ($wordQuery) use ($word): void {
                                    $wordQuery->where('search_name', 'like', '%'.$word.'%')
                                        ->orWhere('name', 'like', '%'.$word.'%');
                                });
                            }
                        })->orWhere('product_number', 'like', '%'.$search.'%')
                            ->orWhereHas('barcodes', fn ($query) => $query->where('barcode', 'like', '%'.$normalizedSearch.'%'));
                    })
                    ->with(['branchProducts' => fn ($query) => $query->where('tenant_id', $tenantId)->where('branch_id', $this->getActiveBranchId())
                        ->select(['id', 'product_id', 'branch_id', 'retail_price', 'stock_quantity'])])
                    ->orderBy('name')->limit(15)->get(['id', 'name', 'product_number']);
            }
        }
        $stockPreviews = $this->cartStockPreviews();
        return $this->view(['branches' => $branches, 'missingBarcodeProducts' => $missingBarcodeProducts,
            'remainingStock' => $stockPreviews['shared'],
            'localRemainingStock' => $stockPreviews['local'],
        ])->layout('layouts::pos');
    }
};
?>
<div class="pos-page" data-pos-page>
    <div wire:ignore data-pos-save-overlay style="display: none" class="fixed inset-0 z-[9999] items-center justify-center bg-slate-950/50 p-6 backdrop-blur-sm" role="status" aria-live="polite" aria-label="جارٍ حفظ الفاتورة">
        <div class="w-full max-w-sm rounded-3xl bg-white p-8 text-center shadow-2xl" dir="rtl">
            <img src="{{ asset('wasla-logo.svg') }}" alt="وصلة" class="mx-auto mb-6 h-20 max-w-full object-contain">
            <span aria-hidden="true" class="mx-auto mb-5 block size-10 animate-spin rounded-full border-4 border-blue-100 border-t-blue-600 motion-reduce:animate-none"></span>
            <p class="text-lg font-bold text-slate-900">جارٍ حفظ الفاتورة…</p>
            <p class="mt-3 text-sm leading-7 text-slate-500">يرجى الانتظار حتى تظهر نتيجة الحفظ. لا حاجة للضغط مرة أخرى.</p>
        </div>
    </div>
    <div dir="rtl" class="pos-workspace bg-slate-100 font-sans select-none">
        <livewire:product-history />
        <livewire:product-price-history-modal :key="'pos-price-history-singleton'" />

        <div x-data="{
                printInProgress: false,
                notesOpen: false,
                async saveAndPrint() {
                    if (this.printInProgress || this.$wire.showNewProductModal || this.$wire.showHoldNotesModal || this.$wire.showClearInvoiceModal) return;
                    this.printInProgress = true;
                    try {
                        const field = document.activeElement?.closest?.('[data-pos-field]');
                        if (field && !field.disabled && field.dataset.lineKey && field.dataset.posField) {
                            await this.$wire.updateCartField(field.dataset.lineKey, field.dataset.posField, field.value);
                        }
                        await this.$wire.checkoutAndPrint();
                    } finally {
                        this.printInProgress = false;
                    }
                }
            }" x-cloak x-init="$nextTick(() => { if (window.matchMedia('(pointer: coarse)').matches) return; const input = $el.querySelector('[data-pos-barcode-input]'); input?.focus({ preventScroll: true }); input?.select(); })"
            x-on:keydown.window.escape="$wire.set('showProductsModal', false); $wire.set('showHeldModal', false); $wire.set('showCostModal', false); $wire.cancelInvoiceDialogs();"
            x-on:keydown.window.f1.prevent="if (!$wire.showNewProductModal && !$wire.showHoldNotesModal && !$wire.showClearInvoiceModal) $wire.set('showHeldModal', !$wire.showHeldModal)"
            x-on:keydown.window.f2.prevent="if (!$wire.showNewProductModal && !$wire.showHoldNotesModal && !$wire.showClearInvoiceModal) $wire.holdInvoice()"
            x-on:keydown.window.f3.prevent="
                (async () => {
                    if ($wire.showNewProductModal || $wire.showHoldNotesModal || $wire.showClearInvoiceModal) return;
                    const field = document.activeElement?.closest?.('[data-pos-field]');
                    if (field && !field.disabled && field.dataset.lineKey && field.dataset.posField) {
                        await $wire.updateCartField(field.dataset.lineKey, field.dataset.posField, field.value);
                    }
                    await $wire.saveOrEditInvoice();
                })()
            "
            x-on:keydown.window.f4.prevent="if (!$wire.showNewProductModal && !$wire.showHoldNotesModal && !$wire.showClearInvoiceModal) $wire.clearCart()"
            x-on:keydown.window.f6.prevent="if (!$event.repeat) saveAndPrint()"
            x-on:keydown.window.f10.prevent="if (!$wire.showNewProductModal && !$wire.showHoldNotesModal && !$wire.showClearInvoiceModal) { const panel = $el.querySelector('[data-pos-products-panel]'); if (panel) panel.dataset.selectedProductIndex = '0'; $nextTick(() => $el.querySelector('[data-pos-product-panel-search]')?.focus()) }"
            class="pos-layout flex min-w-0 flex-col gap-2">
            @include('pages.tenant.pos.partials.toolbar')

            @if ($errorMessage || $successMessage || $this->has_below_cost_item)
                <div data-pos-notice-strip class="pos-notice-strip flex shrink-0 flex-wrap items-start gap-1.5" dir="rtl">
                    @if ($errorMessage)
                        <div data-pos-notice data-message="{{ $errorMessage }}" wire:key="pos-error-notice-{{ md5($errorMessage) }}" x-init="const message = $el.dataset.message; window.setTimeout(() => { if ($wire.errorMessage === message) $wire.set('errorMessage', null) }, 5000)" role="alert" class="pos-notice flex items-start gap-1 bg-rose-50 text-rose-800">
                            <details class="min-w-0">
                                <summary class="cursor-pointer">⚠ {{ \Illuminate\Support\Str::limit($errorMessage, 65) }}</summary>
                                <p class="mt-1 max-w-lg whitespace-normal">{{ $errorMessage }}</p>
                            </details>
                            <button type="button" wire:click="$set('errorMessage', null)" aria-label="إغلاق التنبيه">✕</button>
                        </div>
                    @endif
                    @if ($successMessage)
                        <div data-pos-notice data-message="{{ $successMessage }}" wire:key="pos-success-notice-{{ md5($successMessage) }}" x-init="const message = $el.dataset.message; window.setTimeout(() => { if ($wire.successMessage === message) $wire.set('successMessage', null) }, 5000)" role="status" class="pos-notice flex items-start gap-1 bg-emerald-50 text-emerald-800">
                            <details class="min-w-0">
                                <summary class="cursor-pointer">✓ {{ \Illuminate\Support\Str::limit($successMessage, 65) }}</summary>
                                <p class="mt-1 max-w-lg whitespace-normal">{{ $successMessage }}</p>
                            </details>
                            <button type="button" wire:click="$set('successMessage', null)" aria-label="إغلاق رسالة النجاح">✕</button>
                        </div>
                    @endif
                    @if ($this->has_below_cost_item)
                        <div data-pos-notice role="status" class="pos-notice flex items-center gap-2 bg-amber-50 text-amber-900">
                            <span>⚠ بيع أقل من التكلفة</span>
                            <button type="button" wire:click="openCostModal" class="underline">التفاصيل</button>
                        </div>
                    @endif
                </div>
            @endif
            <div class="pos-content min-w-0">
                <section class="pos-content-section min-w-0">
                    <div class="pos-content-grid grid min-w-0 grid-cols-1 gap-2 xl:grid-cols-[minmax(0,1fr)_minmax(320px,32%)]"
                        dir="ltr">
                        <aside id="pos-products-panel"
                            class="pos-products min-w-0 min-h-0 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm {{ $this->invoiceIsLocked() ? 'opacity-70' : '' }}"
                            dir="rtl">
                            <div class="flex h-full min-h-0 flex-col">

                                <div data-pos-product-search-header class="sticky top-0 z-10 shrink-0 border-b border-slate-200 bg-slate-50 p-2.5">
                                    <div class="flex items-center justify-between gap-2">
                                        <div>
                                            <div class="text-sm font-black text-slate-800">الأصناف</div>
                                            <div class="text-[9px] font-bold text-slate-400">
                                                {{ count($quickProducts) }} {{ trim($productSearchQuery) !== '' ? 'نتيجة · الأقرب أولاً' : 'صنف' }}
                                            </div>
                                        </div>

                                        <button type="button" wire:click="loadQuickProducts(true, true)"
                                            @disabled($this->invoiceIsLocked())
                                            class="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-[10px] font-black text-slate-600 hover:bg-slate-100">
                                            ↻ تحديث
                                        </button>
                                    </div>

                                    <label data-pos-search-field class="block w-full min-w-0">
                                        <span class="sr-only">بحث الأصناف</span>
                                        <input data-pos-product-panel-search
                                        x-on:pos-search-cleared.window="if ($event.detail.componentId === $wire.$id) $el.value = ''"
                                        x-on:keydown.f2.window.prevent="$el.focus(); $el.select()"
                                        x-on:keydown.escape.prevent="$wire.set('productSearchQuery', ''); $el.focus()"
                                        x-on:input="$el.closest('aside')?.querySelector('[data-pos-products-panel]') && ($el.closest('aside').querySelector('[data-pos-products-panel]').dataset.selectedProductIndex = '0')"
                                        x-on:keydown.arrow-down.prevent="$nextTick(() => $el.closest('aside')?.querySelector('[data-pos-product]')?.focus())"
                                        x-on:keydown.enter.prevent="$wire.submitProductSearch($el.value)"
                                        x-on:pos-search-results-ready.window="$nextTick(() => $el.closest('aside')?.querySelector('[data-pos-product]')?.focus())"
                                        wire:model.live.debounce.200ms="productSearchQuery" @disabled($this->invoiceIsLocked())
                                        type="search" maxlength="120" aria-describedby="pos-search-help" aria-label="البحث عن صنف بالاسم أو الرقم أو الباركود" autocomplete="off" placeholder="اسم الصنف، أي كلمات منه، رقمه أو باركوده…"
                                        class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-800 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                                    </label>
                                    <div id="pos-search-help" class="mt-1.5 flex flex-wrap items-center justify-between gap-1 text-[9px] font-semibold text-slate-500">
                                        <span>الكلمات بأي ترتيب · يدعم اختلافات الألف والياء والتشكيل</span>
                                        <span dir="ltr">F2 بحث · ↓ اختيار · Enter إضافة · Esc مسح</span>
                                    </div>
                                    <div wire:loading.delay wire:target="productSearchQuery,loadQuickProducts" role="status" class="mt-1 text-[10px] font-bold text-indigo-600">جارٍ البحث عن الأصناف…</div>
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
                                    @include('pages.tenant.pos.partials.quick-product-results')
                                </div>

                            </div>
                        </aside>

                        @include('pages.tenant.pos.partials.cart')
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
                            'use strict';

                            const PANEL_SELECTOR = '[data-pos-products-panel]';
                            const PRODUCT_SELECTOR = '[data-pos-product]';
                            const SEARCH_SELECTOR = '[data-pos-product-panel-search]';

                            window.addEventListener('pos-focus-barcode', function() {
                                setTimeout(function() {
                                    const barcode = document.querySelector(
                                        '[data-pos-barcode-input]'
                                    );

                                    if (!barcode || window.matchMedia('(pointer: coarse)').matches) {
                                        return;
                                    }

                                    barcode.focus({ preventScroll: true });
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

                                if (event.target?.closest?.('[data-pos-field]')) {
                                    return;
                                }

                                const panelTarget = event.target?.closest?.(PANEL_SELECTOR);

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
                                        .filter(({ rect }) =>
                                            Math.abs(
                                                rect.top - currentRect.top
                                            ) <= 2
                                        )
                                        .sort((a, b) => a.rect.left - b.rect.left);

                                    const rowPosition = sameRow.findIndex(
                                        ({ index }) => index === currentIndex
                                    );

                                    if (rowPosition < 0) {
                                        return;
                                    }

                                    const step = key === 'ArrowLeft' ? -1 : 1;
                                    const targetPosition = rowPosition + step;

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

                </section>
            </div>

            <div class="shrink-0">
                @include('pages.tenant.pos.partials.payment')
            </div>

            @if ($showCustomerPaymentModal)
                <div class="app-modal-overlay fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/60 p-4"
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

            @include('pages.tenant.pos.partials.products-modal')
            @include('pages.tenant.pos.partials.shift-open-modal')
            @include('pages.tenant.pos.partials.shift-close-modal')
            @include('pages.tenant.pos.partials.expense-modal')
            @include('pages.tenant.pos.partials.held-invoices-modal')
            @include('pages.tenant.pos.partials.invoice-confirmation-modals')
            @include('pages.tenant.pos.partials.cost-modal')
            @include('pages.tenant.pos.partials.below-cost-modal')
            @include('pages.tenant.pos.partials.missing-barcode-modal')
            @if ($showNewProductModal)
                <div data-pos-missing-barcode-dialog>
                    <livewire:pages::tenant.products.index :pos-embedded="true" :new-barcode="$missingBarcode" :edit-product-id="$editingProductId" :key="'pos-product-form-'.($editingProductId ? 'edit-'.$editingProductId : 'create-'.$missingBarcode)" />
                </div>
            @endif
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




</style>

<script>
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
                    beep(880, 0.08, 0.11);
                    beep(1175, 0.10, 0.09, 'sine', 0.09);
                    break;
                case 'add':
                    beep(1046, 0.060, 0.16, 'sine');
                    beep(1318, 0.085, 0.14, 'sine', 0.065);
                    break;
                case 'error':
                    beep(220, 0.18, 0.18, 'square');
                    beep(165, 0.22, 0.15, 'square', 0.13);
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

        if (window.Livewire && !window.__posRequestErrorSoundRegistered) {
            window.__posRequestErrorSoundRegistered = true;
            Livewire.hook('request', ({ fail }) => {
                fail(() => {
                    playPosSound('error');
                });
            });
        }
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

                window.location.href = target;
            });
        }

        document.addEventListener('livewire:init', registerPosInvoiceActions);
        if (window.Livewire) {
            registerPosInvoiceActions();
        }
    })();
</script>
