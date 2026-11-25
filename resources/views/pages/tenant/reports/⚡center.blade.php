<?php

use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Services\BackfillLegacySalesReceipts;
use App\Services\ReportingService;
use App\Services\PosProductSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    private const NAVIGATION_GROUPS = [
        'الحسابات' => ['statement', 'balances', 'aging', 'cashflow', 'daily-cash', 'expenses', 'settlements'],
        'المبيعات' => ['sales', 'pos-invoices', 'product-sales', 'product-profit', 'customer-invoice-profit', 'customer-profit', 'profit', 'shifts'],
        'المشتريات' => ['purchases'],
        'المخزون' => ['product-history', 'movements', 'movement-summary', 'inventory', 'stagnant', 'expiring'],
        'الموظفون' => ['payroll', 'salary-payments', 'employees'],
        'المالية' => ['branches', 'tax'],
    ];

    public function selectReport(string $key): void
    {
        abort_unless(isset($this->service()->availableReports()[$key]), 403);
        $this->report = $key;
        $this->updatedReport();
    }

    public function selectReportGroup(string $group): void
    {
        $keys = self::NAVIGATION_GROUPS[$group] ?? [];
        $reports = array_intersect_key($this->service()->availableReports(), array_flip($keys));
        abort_if($reports === [], 403);
        $this->selectReport(array_key_first($reports));
    }

    /**
     * The paid amount of every sales (source 1) and purchase (source 3) invoice among the statement rows.
     *
     * @param  array<int, object>  $rows
     * @return array<string, float>
     */
    /** Reports whose rows are invoices and can show their delivery details. */
    private const DELIVERY_DETAIL_REPORTS = ['statement', 'pos-invoices', 'product-history'];

    /**
     * Delivery details of the sales invoices among the rows, keyed by order id.
     *
     * @param  array<int, object>  $rows
     * @return array<int, string>
     */
    private function invoiceDeliveryDetails(array $rows): array
    {
        $orderIds = collect($rows)->map(fn (object $row): ?int => match (true) {
            isset($row->source_order) => (int) $row->source_order === 1 ? (int) $row->source_id : null,
            isset($row->invoice_id) => ($row->invoice_type ?? '') !== 'purchase' ? (int) $row->invoice_id : null,
            default => null,
        })->filter()->unique()->values()->all();
        if ($orderIds === []) {
            return [];
        }

        $tenantId = $this->service()->tenantId;
        $orders = Order::query()->where('tenant_id', $tenantId)->whereKey($orderIds)->where('delivery_fee', '>', 0)
            ->get(['id', 'customer_name', 'customer_phone', 'customer_address', 'delivery_party_id', 'delivery_fee']);
        $companies = Party::query()->where('tenant_id', $tenantId)->whereKey($orders->pluck('delivery_party_id')->filter()->unique())->pluck('name', 'id');

        return $orders->mapWithKeys(fn (Order $order): array => [$order->id => collect([
            $order->customer_name, $order->customer_phone, $order->customer_address,
            $order->delivery_party_id ? $companies[$order->delivery_party_id] ?? null : null,
            'رسوم '.number_format((float) $order->delivery_fee, 2),
        ])->filter(fn ($part): bool => filled($part))->implode(' · ')])->all();
    }

    private function invoicePaidAmounts(array $rows): array
    {
        $tenantId = $this->service()->tenantId;
        $ids = fn (int $source): array => collect($rows)->filter(fn (object $row): bool => (int) ($row->source_order ?? 0) === $source && ($row->description ?? '') !== 'مردود مبيعات')->pluck('source_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $paid = [];
        foreach ([1 => Order::query(), 3 => Purchase::query()] as $source => $query) {
            if (($sourceIds = $ids($source)) !== []) {
                foreach ($query->where('tenant_id', $tenantId)->whereKey($sourceIds)->pluck('paid_amount', 'id') as $id => $amount) {
                    $paid[$source.':'.$id] = (float) $amount;
                }
            }
        }

        return $paid;
    }

    public function resetFilters(): void
    {
        $this->reset('columnFilters', 'partyId', 'productId', 'search', 'partyType', 'balanceSide', 'stockFilter', 'source', 'saleKind', 'invoiceScope', 'costMethod', 'productView', 'voucherType', 'paymentMethod', 'partySearch', 'statementPartyType', 'statementBalanceFilter', 'showInvoicePaid', 'showDeliveryDetails', 'appliedStatementSignature', 'productSearch');
        $this->setPeriod('month');
        $this->resetPage('statementPartiesPage');
        $this->resetErrorBag();
    }

    #[Url]
    public string $report = 'statement';
    public ?int $branchId = null;
    public string $from = '';
    public string $to = '';
    public ?int $partyId = null;
    public ?int $productId = null;
    public string $search = '';
    /** @var array<string, string> */
    public array $columnFilters = [];
    public string $partyType = 'all';
    public string $balanceSide = 'all';
    public string $stockFilter = 'all';
    public string $source = '';
    public string $saleKind = '';

    /** Daily sales summary: walk-in POS invoices (default), invoices linked to a customer, or all. */
    public string $invoiceScope = '';
    public string $costMethod = 'recorded';
    #[Url(as: 'product_view')]
    public string $productView = 'summary';
    public string $voucherType = '';
    public string $paymentMethod = '';
    public string $partySearch = '';
    public string $statementPartyType = 'all';
    public string $statementBalanceFilter = 'all';
    /** Shows how much of each invoice in the statement has been paid. */
    public bool $showInvoicePaid = false;
    /** Shows the recipient, phone, address, company and fee of delivery invoices in the statement and invoice reports. */
    public bool $showDeliveryDetails = false;
    #[Locked]
    public ?string $appliedStatementSignature = null;
    public string $productSearch = '';
    /** @var array{title: string, reference: string, date: string, party: string, amount: float, status: string, notes: string}|null */

    public function mount(): void
    {
        $this->from = today()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
        $this->branchId = $this->defaultBranchId();
        // Links from the analytics dashboard open the report on the same period and branch.
        foreach (['from', 'to'] as $key) {
            $value = request()->query($key);
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $this->{$key} = $value;
            }
        }
        if (request()->integer('branch_id') > 0) {
            $this->updatedBranchId(request()->integer('branch_id'));
        }
        if (request()->integer('product_id') > 0) {
            $this->productId = Product::query()->where('tenant_id', auth()->user()->tenant_id)->whereKey(request()->integer('product_id'))->value('id');
        }
        $allowed = $this->service()->availableReports();
        abort_if($allowed === [], 403);
        if (!isset($allowed[$this->report])) { $this->report = array_key_first($allowed); }
        // The branch follows the scope of the report actually opened (an employee may see different branches per report).
        if (! \App\Services\DataScope::unrestricted(auth()->user()) && request()->integer('branch_id') <= 0) {
            $this->branchId = $this->defaultBranchId();
        }
        if ($this->report === 'expiring' && ! request()->query('to')) {
            $this->to = today()->addDays(30)->toDateString();
        }
        if (in_array($this->report, ['statement', 'customer-invoice-profit'], true) && request()->integer('party_id') > 0) {
            $this->partyId = Party::query()->where('tenant_id', $this->service()->tenantId)
                ->whereKey(request()->integer('party_id'))->value('id');
            $this->statementPartyType = in_array(request('party_type'), ['customer', 'supplier'], true) ? request('party_type') : 'all';
            if ($this->report !== 'statement' && $this->partyId) {
                $this->partySearch = (string) Party::query()->whereKey($this->partyId)->value('name');
            }
        }
    }

    private function service(): ReportingService
    {
        return new ReportingService(auth()->user(), $this->branchId, false, $this->report);
    }

    /** «كل الفروع» when every branch is allowed, otherwise the employee's own branch or their first allowed one. */
    private function defaultBranchId(): ?int
    {
        $user = auth()->user();
        $allowed = $user ? ReportingService::allowedBranchIds($user, $this->report) : null;
        if ($allowed === null) {
            return null;
        }
        $own = $user->branch_id ? (int) $user->branch_id : null;

        return $own !== null && in_array($own, $allowed, true) ? $own : ($allowed[0] ?? null);
    }

    public function updatedBranchId($value): void
    {
        $user = auth()->user();
        if (! $user || ! ReportingService::choosesBranch($user, $this->report)) {
            $this->branchId = $this->defaultBranchId();
            return;
        }

        $branchId = $value === '' || $value === null ? null : (int) $value;
        $allowed = ReportingService::allowedBranchIds($user, $this->report);
        // Only some branches allowed: one of them, never «all».
        if ($allowed !== null && ($branchId === null || ! in_array($branchId, $allowed, true))) {
            $this->branchId = $this->defaultBranchId();
            $this->resetPage();

            return;
        }
        if ($branchId && ! \App\Models\Branch::query()->where('tenant_id', $this->service()->tenantId)->whereKey($branchId)->exists()) {
            $this->branchId = null;
            return;
        }

        $this->branchId = $branchId;
        $this->resetPage();
    }

    public function filters(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'party_id' => $this->partyId, 'product_id' => $this->productId,
            'column_filters' => $this->columnFilters, 'search' => $this->search, 'party_type' => $this->partyType, 'balance_side' => $this->balanceSide,
            'stock_filter' => $this->stockFilter, 'source' => $this->source, 'sale_kind' => $this->saleKind, 'invoice_scope' => $this->invoiceScope, 'cost_method' => $this->costMethod,
            'product_view' => $this->productView, 'voucher_type' => $this->voucherType, 'payment_method' => $this->paymentMethod];
    }

    public function updatedProductView(): void
    {
        $this->columnFilters = [];
        $this->resetPage();
    }

    public function applyFilters(): void
    {
        $this->resetErrorBag();
        $this->resetPage();
        if ($this->report === 'statement') {
            $this->appliedStatementSignature = null;
            $this->validate(['partyId' => ['required', 'integer', 'min:1']], ['partyId.required' => 'اختر زبوناً أو مورداً أولاً.']);
            $this->service()->prepare($this->report, $this->filters());
            $this->appliedStatementSignature = $this->statementSignature();
            $this->dispatch('statement-shown');
        }
    }

    private function statementSignature(): string
    {
        $filters = $this->filters();
        unset($filters['column_filters']);

        return hash('sha256', json_encode([$this->branchId, $filters], JSON_THROW_ON_ERROR));
    }

    private function statementIsReady(): bool
    {
        return $this->partyId !== null && $this->appliedStatementSignature === $this->statementSignature();
    }

    public function updatedColumnFilters(): void
    {
        $this->resetPage();
    }

    public function clearColumnFilters(): void
    {
        $this->columnFilters = [];
        $this->resetPage();
    }

    public function updatedProductSearch(): void
    {
        $this->productId = null;
    }

    public function selectReportProduct(int $productId): void
    {
        $product = Product::withoutGlobalScopes()->where('tenant_id', $this->service()->tenantId)->whereNull('deleted_at')->findOrFail($productId);
        $this->productId = $product->id;
        $this->productSearch = trim(($product->product_number ?? '').' — '.$product->name, ' —');
    }

    public function clearReportProduct(): void
    {
        $this->productId = null;
        $this->productSearch = '';
    }

    public function selectStatementParty(int $partyId): void
    {
        $service = $this->service();
        $party = Party::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->whereNull('deleted_at')
            ->when($service->branchId, fn ($query) => $query->where(fn ($query) => $query->where('branch_id', $service->branchId)->orWhereNull('branch_id')))
            ->findOrFail($partyId);
        $this->columnFilters = [];
        $this->partyId = $party->id;
        $this->appliedStatementSignature = null;
        $this->resetErrorBag();
        $this->resetPage();
    }

    public function showStatementParty(int $partyId): void
    {
        $this->selectStatementParty($partyId);
        $this->applyFilters();
    }

    /**
     * Opens the invoice, return or voucher behind a statement row in its own workspace tab, loaded and ready to view, edit or delete.
     */
    public function openStatementDocument(int $sourceOrder, int $sourceId): void
    {
        $tenantId = $this->service()->tenantId;

        if ($sourceOrder === 6) {
            $this->authorize('accounting.receipt.create');
            $order = Order::query()->where('tenant_id', $tenantId)->findOrFail($sourceId);
            $payment = app(BackfillLegacySalesReceipts::class)->createFor($order->id, $tenantId)
                ?? Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('order_id', $order->id)
                    ->where('type', 'receipt')
                    ->latest('id')
                    ->first();
            abort_unless($payment !== null, 409, 'تعذر إنشاء سند القبض التاريخي لهذه الفاتورة.');
            $url = route('tenant.receipts', ['voucher' => $payment->id]);
            $title = 'سند قبض '.$payment->voucher_number;
        } elseif (in_array($sourceOrder, [1, 2], true)) {
            $order = Order::query()->where('tenant_id', $tenantId)->findOrFail($sourceId);
            $route = $order->type === 'return' || ($order->type === 'pos' && ! $order->customer_id) ? 'tenant.pos' : 'tenant.sale-invoices';
            $url = route($route, ['invoice' => $order->id]);
            $title = ($order->type === 'return' ? 'مردود مبيعات ' : 'فاتورة مبيعات ').$order->invoice_number;
        } elseif (in_array($sourceOrder, [3, 7], true)) {
            $purchase = Purchase::query()->where('tenant_id', $tenantId)->findOrFail($sourceId);
            $url = route('tenant.purchase-invoices', ['purchase' => $purchase->id]);
            $title = 'فاتورة مشتريات '.$purchase->reference_number;
        } elseif ($sourceOrder === 4) {
            $return = PurchaseReturn::query()->where('tenant_id', $tenantId)->findOrFail($sourceId);
            $url = route('tenant.purchase-returns', ['return' => $return->id]);
            $title = 'مردود مشتريات '.$return->reference_number;
        } elseif ($sourceOrder === 5) {
            $payment = Payment::query()->where('tenant_id', $tenantId)->findOrFail($sourceId);
            $isReceipt = $payment->type === 'receipt';
            $url = route($isReceipt ? 'tenant.receipts' : 'tenant.payment', ['voucher' => $payment->id]);
            $title = ($isReceipt ? 'سند قبض ' : 'سند صرف ').$payment->voucher_number;
        } else {
            abort(404);
        }

        $this->dispatch('open-statement-document', url: $url, title: trim($title));
    }

    public function updatedPartySearch(): void
    {
        $this->resetPage('statementPartiesPage');
    }

    /** Picks a party from the report's single party search field and shows its name there. */
    public function selectReportParty(int $partyId): void
    {
        $party = Party::withoutGlobalScopes()->where('tenant_id', $this->service()->tenantId)->whereNull('deleted_at')->findOrFail($partyId);
        $this->partyId = $party->id;
        $this->partySearch = $party->name;
    }

    public function clearReportParty(): void
    {
        $this->partyId = null;
        $this->partySearch = '';
    }

    public function updatedStatementBalanceFilter(): void
    {
        $this->resetPage('statementPartiesPage');
    }

    public function updatedStatementPartyType(): void
    {
        if ($this->report !== 'statement') {
            $this->partyId = null;
        }
        $this->resetPage('statementPartiesPage');
    }

    public function updatedReport(): void
    {
        // Each report follows the employee's data scope: the account statement and the other reports have their own.
        if (! \App\Services\DataScope::unrestricted(auth()->user())) {
            $this->branchId = $this->defaultBranchId();
        }
        $this->columnFilters = [];
        $this->resetPage();
        $this->resetErrorBag();
        $this->search = '';
        $this->partyType = 'all';
        $this->balanceSide = 'all';
        $this->statementPartyType = 'all';
        $this->statementBalanceFilter = 'all';
        $this->appliedStatementSignature = null;
        $this->partyId = null;
        $this->productId = null;
        $this->voucherType = '';
        $this->paymentMethod = '';
        if ($this->report === 'expiring') {
            $this->to = today()->addDays(30)->toDateString();
        }
    }

    /**
     * Quick date ranges offered above the report, keyed by their button.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function periodRanges(): array
    {
        return [
            'today' => [today()->toDateString(), today()->toDateString(), 'اليوم'],
            'yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString(), 'أمس'],
            'month' => [today()->startOfMonth()->toDateString(), today()->toDateString(), 'هذا الشهر'],
            'last-month' => [today()->subMonthNoOverflow()->startOfMonth()->toDateString(), today()->subMonthNoOverflow()->endOfMonth()->toDateString(), 'الشهر السابق'],
            'last-30' => [today()->subDays(29)->toDateString(), today()->toDateString(), 'آخر 30 يوم'],
        ];
    }

    public function setPeriod(string $range): void
    {
        [$this->from, $this->to] = $this->periodRanges()[$range] ?? [$this->from, $this->to];
        $this->resetPage();
    }

    public function exportCsv(): mixed
    {
        abort_if($this->report === 'statement' && ! $this->statementIsReady(), 422, 'اعرض التقرير أولاً.');
        $service = $this->service();
        $definition = $service->prepare($this->report, $this->filters());
        $title = ReportingService::REPORTS[$this->report][0];
        return response()->streamDownload(function () use ($definition, $title, $service): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [$title, $this->from, $this->to, 'الفرع', $service->branchId ?? 'كل الفروع'], ',', '"', '');
            foreach ($definition['summary'] as $label => $value) { fputcsv($output, [$label, $value], ',', '"', ''); }
            foreach ($definition['notes'] as $note) { fputcsv($output, [ReportingService::csvValue('note', $note)], ',', '"', ''); }
            fputcsv($output, array_values($definition['columns']), ',', '"', '');
            foreach ($definition['query']->cursor() as $row) {
                $values = [];
                foreach ($definition['columns'] as $key => $label) { $values[] = ReportingService::csvValue($key, $row->{$key} ?? null); }
                fputcsv($output, $values, ',', '"', '');
            }
            fclose($output);
        }, 'report-'.$this->report.'-'.$this->from.'-'.$this->to.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function render(): mixed
    {
        $service = $this->service();
        $allowed = $service->availableReports();
        abort_unless(isset($allowed[$this->report]), 403);
        $statementReady = $this->report === 'statement' && $this->statementIsReady();
        $valid = $this->report !== 'statement' || $statementReady;
        $definition = ['query' => DB::table('orders')->whereRaw('1 = 0'), 'columns' => [], 'summary' => [], 'notes' => []];
        try { if ($valid) { $definition = $service->prepare($this->report, $this->filters()); } }
        catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());
            $valid = false;
            $definition = ['query' => DB::table('orders')->whereRaw('1 = 0'), 'columns' => [], 'summary' => [], 'notes' => []];
        }
        $parties = collect();
        $statementParties = null;
        $products = collect();
        if (in_array($this->report, ['statement', 'balances', 'aging', 'cashflow', 'customer-invoice-profit'], true)) {
            $parties = Party::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->whereNull('deleted_at')
                ->when($service->branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $service->branchId)->orWhereNull('branch_id')))
                ->when(trim($this->partySearch) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->partySearch.'%')->orWhere('code', 'like', '%'.$this->partySearch.'%')->orWhere('phone', 'like', '%'.$this->partySearch.'%')))
                ->when($this->report === 'customer-invoice-profit', fn ($q) => $q->whereIn('type', ['customer', 'both']))
                ->orderBy('name')->limit(100)->get(['id', 'name', 'type']);
            if ($this->partyId && ! $parties->contains('id', $this->partyId)) {
                $parties->prepend(Party::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->whereKey($this->partyId)->first(['id', 'name', 'type']));
                $parties = $parties->filter()->values();
            }
        }
        if ($this->report === 'statement') {
            $statementPartyTypes = $this->statementPartyType === 'supplier' ? ['supplier', 'both'] : ($this->statementPartyType === 'all' ? ['customer', 'supplier', 'both'] : ['customer', 'both']);
            $statementParties = Party::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->whereNull('deleted_at')
                ->when($service->branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $service->branchId)->orWhereNull('branch_id')))
                ->whereIn('type', $statementPartyTypes)
                ->when($this->statementBalanceFilter === 'nonzero', fn ($query) => $query->where('current_balance', '<>', 0))
                ->when(trim($this->partySearch) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->partySearch.'%')->orWhere('code', 'like', '%'.$this->partySearch.'%')->orWhere('phone', 'like', '%'.$this->partySearch.'%')))
                ->orderBy('name')->orderBy('id')->simplePaginate(40, ['id', 'name', 'code', 'phone', 'type', 'current_balance'], 'statementPartiesPage');
        }
        if (in_array($this->report, ['product-history', 'product-sales', 'product-profit', 'movements', 'movement-summary', 'inventory', 'stagnant', 'expiring'], true) && trim($this->productSearch) !== '') {
            $products = Product::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->whereNull('deleted_at')
                ->tap(function ($query) use ($service): void {
                    app(PosProductSearch::class)->apply($query->getQuery(), trim($this->productSearch), $service->tenantId);
                })
                ->with(['barcodes' => fn ($query) => $query->where('tenant_id', $service->tenantId)])
                ->orderBy('name')->limit(30)->get(['id', 'name', 'product_number']);
        }
        $branches = \App\Models\Branch::query()->where('tenant_id', $service->tenantId)->orderBy('name')->get(['id', 'name']);
        // Only the branches this report is allowed for; «كل الفروع» only when all of them are.
        $allowedBranchIds = ReportingService::allowedBranchIds(auth()->user(), $this->report);
        if ($allowedBranchIds !== null) {
            $branches = $branches->whereIn('id', $allowedBranchIds)->values();
        }
        $statementParty = $statementReady ? Party::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->find($this->partyId) : null;
        $printUrl = $valid ? route('tenant.reports.print', ['report' => $this->report, 'branch_id' => $service->branchId ?? 0] + $this->filters()) : null;
        $navigationGroups = [];
        $activeGroup = '';
        foreach (self::NAVIGATION_GROUPS as $group => $keys) {
            $entries = array_intersect_key($allowed, array_flip($keys));
            if ($entries !== []) {
                $navigationGroups[$group] = $entries;
                if (isset($entries[$this->report])) { $activeGroup = $group; }
            }
        }
        $periodRanges = $this->periodRanges();
        $activeRange = collect($periodRanges)->search(fn (array $range): bool => $range[0] === $this->from && $range[1] === $this->to);
        $rows = $definition['query']->paginate(30);
        $invoicePaid = $this->report === 'statement' && $this->showInvoicePaid ? $this->invoicePaidAmounts($rows->items()) : [];
        $invoiceDelivery = $this->showDeliveryDetails && in_array($this->report, self::DELIVERY_DETAIL_REPORTS, true) ? $this->invoiceDeliveryDetails($rows->items()) : [];
        if ($this->showDeliveryDetails && $this->report !== 'statement' && in_array($this->report, self::DELIVERY_DETAIL_REPORTS, true)) {
            $definition['columns']['delivery_details'] = 'بيانات التوصيل';
            foreach ($rows->items() as $row) {
                $row->delivery_details = ($row->invoice_type ?? '') !== 'purchase' ? ($invoiceDelivery[(int) ($row->invoice_id ?? 0)] ?? null) : null;
            }
        }
        return $this->view(['invoiceDelivery' => $invoiceDelivery, 'invoicePaid' => $invoicePaid, 'statementReady' => $statementReady, 'statementParty' => $statementParty, 'periodRanges' => $periodRanges, 'activeRange' => $activeRange, 'navigationGroups' => $navigationGroups, 'activeGroup' => $activeGroup,'definition' => $definition, 'rows' => $rows, 'allowed' => $allowed, 'parties' => $parties, 'statementParties' => $statementParties, 'products' => $products, 'branches' => $branches, 'allBranches' => $allowedBranchIds === null, 'choosesBranch' => $allowedBranchIds === null || count($allowedBranchIds) > 1, 'printUrl' => $printUrl, 'valid' => $valid])->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="reports-center reports-redesign" data-keyboard-tables>
    @php($groupIcons = ['الحسابات' => 'circle-stack', 'المبيعات' => 'shopping-bag', 'المشتريات' => 'shopping-cart', 'المخزون' => 'cube', 'الموظفون' => 'user-group', 'المالية' => 'chart-bar'])
    <header class="reports-header">
        <div class="reports-title">
            <span class="reports-title-icon" aria-hidden="true"><flux:icon.share variant="outline" /></span>
            <flux:heading size="lg">مركز التقارير</flux:heading>
        </div>
        <flux:button :href="route('tenant.analytics')" icon="clock" wire:navigate class="reports-legacy-link">التحليلات السابقة</flux:button>
    </header>
    <div class="reports-workspace {{ $report === 'statement' ? 'is-statement' : '' }}" x-data="{
                parties() { return [...this.$root.querySelectorAll('[data-statement-party]')] },
                rows() { return [...this.$root.querySelectorAll('[data-statement-row]')] },
                move(items, step) {
                    if (!items.length) return;
                    const index = items.indexOf(document.activeElement);
                    const next = items[index < 0 ? 0 : Math.max(0, Math.min(items.length - 1, index + step))];
                    next.focus();
                    next.scrollIntoView({ block: 'nearest' });
                },
                toStatement() { this.rows()[0]?.focus() },
                toParties() { const parties = this.parties(); const party = parties.find((party) => party.dataset.selected === 'true') ?? parties[0]; party?.focus() },
            }" x-on:statement-shown.window="$nextTick(() => requestAnimationFrame(() => { const row = rows()[0]; if (row) { row.focus({ preventScroll: true }); row.scrollIntoView({ block: 'nearest' }) } }))">
    <nav class="reports-category-tabs" aria-label="تصنيفات التقارير">
        @foreach ($navigationGroups as $group => $entries)
            <button type="button" wire:key="report-category-{{ $loop->index }}" wire:click="selectReportGroup('{{ $group }}')" class="reports-category-tab {{ $activeGroup === $group ? 'is-active' : '' }}" aria-pressed="{{ $activeGroup === $group ? 'true' : 'false' }}">
                <flux:icon :name="$groupIcons[$group]" variant="outline" /><span>{{ $group }}</span>
            </button>
        @endforeach
    </nav>
    <details class="reports-subnavigation" open wire:key="report-subnavigation-{{ $activeGroup }}">
        <summary><span>تقارير {{ $activeGroup }}</span><flux:icon.chevron-down variant="outline" /></summary>
        <nav class="reports-report-tabs" aria-label="اختيار التقرير">
            @foreach ($navigationGroups[$activeGroup] ?? [] as $key => $entry)
                <button type="button" wire:key="report-menu-{{ $key }}" wire:click="selectReport('{{ $key }}')" class="reports-report-tab {{ $report === $key ? 'is-active' : '' }}" aria-current="{{ $report === $key ? 'page' : 'false' }}">
                    <flux:icon :name="match ($key) { 'statement', 'balances', 'aging' => 'users', 'cashflow', 'daily-cash', 'expenses' => 'banknotes', 'inventory', 'movements', 'movement-summary', 'stagnant', 'expiring', 'product-history' => 'cube', default => 'document-chart-bar' }" variant="outline" />
                    <span>{{ $entry[0] }}</span>
                </button>
            @endforeach
        </nav>
    </details>
    @if ($report === 'statement')
    <aside class="reports-account-directory reports-account-sidebar" data-statement-directory>
        <div id="report-account-picker" class="reports-account-panel">
        <h2><flux:icon.user-group variant="outline" />قائمة الزباين</h2>
        <flux:input icon="magnifying-glass" aria-label="بحث في الحسابات" data-statement-party-search wire:model.live.debounce.300ms="partySearch" x-on:keydown.arrow-down.prevent="($wire.partySearch === $event.target.value ? Promise.resolve() : ($wire.partySearch = $event.target.value, $wire.$commit())).then(() => $nextTick(() => { const party = parties()[0]; if (party) { party.focus(); party.scrollIntoView({ block: 'nearest' }) } }))" placeholder="بحث بالاسم أو رقم الحساب أو الهاتف…" />
        <div class="reports-directory-tools"><div class="reports-account-types" role="group" aria-label="نوع الحساب">
            @foreach (['all' => 'الكل', 'customer' => 'الزبائن', 'supplier' => 'الموردون'] as $type => $label)
                <button type="button" wire:key="statement-party-type-{{ $type }}" wire:click="$set('statementPartyType', '{{ $type }}')" aria-pressed="{{ $statementPartyType === $type ? 'true' : 'false' }}" class="{{ $statementPartyType === $type ? 'is-active' : '' }}">{{ $label }}</button>
            @endforeach
        </div>
        <flux:select aria-label="فلتر رصيد الحساب" wire:model.live="statementBalanceFilter"><option value="all">كل الأرصدة</option><option value="nonzero">رصيد غير صفري</option></flux:select>
        </div><p class="reports-directory-hint">دليل الحسابات · ↑ ↓ للتنقل · Enter لعرض الكشف</p>
        <div class="reports-account-labels"><span>الاسم / رقم الحساب</span><span>الرصيد الحالي</span></div>
        <div class="reports-account-list" x-on:keydown.arrow-down.prevent="move(parties(), 1)" x-on:keydown.arrow-up.prevent="parties().indexOf(document.activeElement) === 0 ? $root.querySelector('input[data-statement-party-search], [data-statement-party-search] input')?.focus() : move(parties(), -1)" x-on:keydown.arrow-left.prevent="toStatement()">
            @forelse ($statementParties as $party)
                <button type="button" data-statement-party data-search-option data-selected="{{ (int) $partyId === (int) $party->id ? 'true' : 'false' }}" aria-pressed="{{ (int) $partyId === (int) $party->id ? 'true' : 'false' }}" wire:key="statement-party-{{ $party->id }}" wire:click="showStatementParty({{ $party->id }})" wire:keydown.enter.prevent.stop="showStatementParty({{ $party->id }})" class="reports-account-row {{ (int) $partyId === (int) $party->id ? 'is-selected' : '' }}">
                    <span><strong>{{ $party->name }}</strong><small>{{ $party->code ?: 'بدون رمز' }} · {{ ['customer' => 'زبون', 'supplier' => 'مورد', 'both' => 'زبون ومورد'][$party->type] ?? $party->type }}</small></span><b class="reports-party-balance {{ (float) $party->current_balance < 0 ? 'is-negative' : 'is-positive' }}" dir="ltr">{{ number_format((float) $party->current_balance, 2) }}</b>
                </button>
            @empty
                <p class="reports-empty">لا توجد حسابات مطابقة.</p>
            @endforelse
        </div>
        @if ($statementParties->hasPages())<div class="reports-account-pagination">{{ $statementParties->links() }}</div>@endif
        <footer class="reports-account-end" data-statement-directory-end>نهاية قائمة الحسابات</footer>
        </div>
    </aside>
    @endif
    <div class="reports-content">
    @if ($report === 'statement')
    <form wire:submit="applyFilters" class="reports-filter reports-statement-filter space-y-4" data-statement-filter-panel>
        <details class="reports-filter-details" open data-report-filter-toggle><summary class="reports-section-title"><flux:icon.adjustments-horizontal variant="outline" />فلاتر كشف الحساب<span class="reports-filter-hint">طي / عرض الفلاتر</span></summary><div class="reports-filter-fields">
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="reports-selected-account">
                <span>الحساب</span>
                @if ($statementParty)
                    <strong>{{ $statementParty->name }}</strong>
                    <small>{{ $statementParty->code ?: 'بدون رقم حساب' }} · {{ ['customer' => 'زبون', 'supplier' => 'مورد', 'both' => 'زبون ومورد'][$statementParty->type] ?? $statementParty->type }}</small>
                @else
                    <strong class="is-empty">اختر حساباً من القائمة</strong>
                @endif
                @error('partyId')<p role="alert">{{ $message }}</p>@enderror
            </div>
            <flux:input type="date" label="من تاريخ" wire:model="from" />
            <flux:input type="date" label="إلى تاريخ" wire:model="to" />
            @if ($choosesBranch)
                <flux:select label="الفرع" wire:model.live="branchId">@if ($allBranches)<option value="">كل الفروع</option>@endif @foreach ($branches as $branch)<option wire:key="statement-branch-{{ $branch->id }}" value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>
            @else
                <div class="reports-locked-branch"><span>الفرع</span><strong>{{ \App\Models\Branch::query()->whereKey($branchId)->value('name') ?? 'فرع المستخدم' }}</strong></div>
            @endif
            <label class="reports-invoice-paid-toggle" data-statement-invoice-paid-toggle><input type="checkbox" wire:model.live="showInvoicePaid" /><span>المدفوع من الفاتورة</span></label>
            <label class="reports-invoice-paid-toggle" data-report-delivery-toggle><input type="checkbox" wire:model.live="showDeliveryDetails" /><span>بيانات التوصيل</span></label>
        </div>
        <div class="reports-filter-actions flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary" icon="magnifying-glass" wire:loading.attr="disabled">عرض التقرير</flux:button>
            <flux:button type="button" icon="arrow-path" wire:click="resetFilters">إعادة التعيين</flux:button>
            <div class="reports-ranges" role="group" aria-label="فترات سريعة">
                @foreach ($periodRanges as $range => [, , $label])<flux:button type="button" wire:key="statement-range-{{ $range }}" wire:click="setPeriod('{{ $range }}')" :variant="$activeRange === $range ? 'primary' : 'outline'" aria-pressed="{{ $activeRange === $range ? 'true' : 'false' }}">{{ $label }}</flux:button>@endforeach
            </div>
        </div>
        </div></details>
        @if ($errors->any())<div role="alert" class="text-sm text-red-700 dark:text-red-300">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    </form>
    @else
    <form wire:submit="applyFilters" class="reports-filter space-y-4">
        <details class="reports-filter-details" open data-report-filter-toggle><summary class="reports-section-title"><flux:icon.adjustments-horizontal variant="outline" />فلاتر التقرير<span class="reports-filter-hint">طي / عرض الفلاتر</span></summary><div class="reports-filter-fields">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:input type="date" label="من تاريخ" wire:model="from" />
            <flux:input type="date" label="إلى تاريخ" wire:model="to" />
            @if ($choosesBranch)
                <flux:select label="الفرع" wire:model.live="branchId">@if ($allBranches)<option value="">كل الفروع</option>@endif @foreach ($branches as $branch)<option wire:key="report-branch-{{ $branch->id }}" value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>
            @else
                <div class="flex items-end"><div class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800"><span class="text-xs text-slate-500">الفرع</span><div class="font-bold">{{ \App\Models\Branch::query()->whereKey($branchId)->value('name') ?? 'فرع المستخدم' }}</div></div></div>
            @endif
            @if (in_array($report, ['balances', 'aging', 'cashflow', 'expenses', 'product-history', 'product-sales', 'product-profit', 'movements', 'movement-summary', 'inventory', 'stagnant', 'expiring']))<flux:input label="بحث في التقرير" wire:model="search" placeholder="اسم أو رقم الصنف / الطرف" />@endif
        </div>
        @if (in_array($report, ['balances', 'aging', 'cashflow', 'customer-invoice-profit']))
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="reports-filter-wide relative" data-report-party-picker x-data="{ open: false, active: 0, rows() { return [...this.$refs.rows.querySelectorAll('[data-report-party]')] }, move(direction) { const rows = this.rows(); if (!rows.length) return; this.active = Math.max(0, Math.min(rows.length - 1, this.active + direction)); rows[this.active]?.scrollIntoView({ block: 'nearest' }); }, choose() { this.rows()[this.active]?.click() } }" x-on:click.outside="open = false" x-on:keydown.arrow-down.prevent="open = true; move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="if (open && rows().length) choose()" x-on:keydown.escape="open = false">
                    <div class="flex items-end gap-2">
                        <flux:input label="الزبون / المورد" icon="magnifying-glass" wire:model.live.debounce.300ms="partySearch" x-on:focus="open = true" x-on:input="open = true; active = 0" autocomplete="off" placeholder="{{ $report === 'customer-invoice-profit' ? 'كل الزبائن' : 'كل الأطراف' }} — ابحث بالاسم أو الهاتف أو الرمز" />
                        @if ($partyId || $partySearch !== '')<flux:button type="button" wire:click="clearReportParty">{{ $report === 'customer-invoice-profit' ? 'كل الزبائن' : 'كل الأطراف' }}</flux:button>@endif
                    </div>
                    <div x-cloak x-show="open" class="absolute right-0 top-full z-40 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
                        <div class="max-h-72 overflow-auto" x-ref="rows">
                            @forelse ($parties as $party)
                                <button type="button" data-report-party wire:key="report-party-{{ $party->id }}" x-bind:class="active === {{ $loop->index }} ? 'search-result-active' : ''" wire:click="selectReportParty({{ $party->id }})" x-on:click="open = false" @class(['flex w-full items-center justify-between gap-3 border-t px-3 py-2 text-right text-sm hover:bg-indigo-50 dark:hover:bg-slate-800', 'font-bold' => (int) $partyId === (int) $party->id])><span>{{ $party->name }}</span><span class="text-xs text-slate-500">{{ ['customer' => 'زبون', 'supplier' => 'مورد', 'both' => 'زبون ومورد'][$party->type] ?? '' }}</span></button>
                            @empty<p class="p-4 text-center text-sm text-slate-500">لا توجد أطراف مطابقة.</p>@endforelse
                        </div>
                    </div>
                </div>
                @if (in_array($report, ['balances', 'aging']))<flux:select label="نوع الطرف" wire:model="partyType"><option value="all">الجميع</option><option value="customer">زبون</option><option value="supplier">مورد</option><option value="both">زبون ومورد</option></flux:select>@endif
                @if (in_array($report, ['balances', 'aging']))<flux:select label="اتجاه الذمم" wire:model="balanceSide"><option value="all">مدين ودائن معاً</option><option value="debit">مدين / لنا</option><option value="credit">دائن / علينا</option><option value="settled">مسدد</option></flux:select>@endif
            </div>
        @endif
        @if (in_array($report, ['product-history', 'product-sales', 'product-profit', 'movements', 'movement-summary', 'inventory', 'stagnant', 'expiring']))
            <div class="reports-filter-wide relative" x-data="{ open: false, active: 0, rows() { return [...this.$refs.rows.querySelectorAll('[data-report-product]')] }, move(direction) { const rows = this.rows(); if (!rows.length) return; this.active = Math.max(0, Math.min(rows.length - 1, this.active + direction)); rows[this.active]?.scrollIntoView({ block: 'nearest' }); }, choose() { this.rows()[this.active]?.click() } }" x-on:click.outside="open = false" x-on:keydown.arrow-down.prevent="open = true; move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="if (open && rows().length) choose()" x-on:keydown.escape="open = false">
                <div class="flex items-end gap-2">
                    <flux:input label="الصنف" wire:model.live.debounce.250ms="productSearch" x-on:focus="open = true" x-on:input="open = true; active = 0" autocomplete="off" placeholder="ابحث بالاسم أو رقم الصنف أو الباركود" />
                    <flux:button type="button" wire:click="clearReportProduct">كل الأصناف</flux:button>
                </div>
                @if ($productSearch !== '')
                    <div x-cloak x-show="open" class="absolute right-0 top-full z-40 mt-1 w-full overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
                        <div class="max-h-72 overflow-auto" x-ref="rows">
                            <table class="w-full text-right text-sm"><thead class="sticky top-0 bg-slate-100 text-xs dark:bg-slate-800"><tr><th class="p-2">رقم الصنف</th><th class="p-2">اسم الصنف</th><th class="p-2">الباركود</th></tr></thead><tbody>
                                @forelse ($products as $product)
                                    <tr data-report-product wire:key="report-product-{{ $product->id }}" x-bind:class="active === {{ $loop->index }} ? 'search-result-active' : ''" wire:click="selectReportProduct({{ $product->id }})" x-on:click="open = false" class="cursor-pointer border-t hover:bg-indigo-50 dark:hover:bg-slate-800"><td class="p-2 font-mono">{{ $product->product_number ?? $product->id }}</td><td class="p-2">{{ $product->name }}</td><td class="p-2 font-mono">{{ $product->barcodes->first()?->barcode ?? '—' }}</td></tr>
                                @empty<tr><td colspan="3" class="p-4 text-center text-slate-500">لا توجد أصناف مطابقة.</td></tr>@endforelse
                            </tbody></table>
                        </div>
                    </div>
                @endif
            </div>
        @endif
        @if (in_array($report, ['cashflow', 'daily-cash', 'expenses']))
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($report !== 'expenses')<flux:select label="نوع الحركة" wire:model="voucherType"><option value="">قبض وصرف</option><option value="receipt">قبض فقط</option><option value="payment">صرف فقط</option></flux:select>@endif
                <flux:select label="طريقة الدفع" wire:model="paymentMethod"><option value="">كل الطرق</option><option value="cash">نقدي</option><option value="card">بطاقة</option><option value="bank_transfer">تحويل</option><option value="cheque">شيك</option></flux:select>
            </div>
        @endif
        @if (in_array($report, ['sales', 'product-sales', 'product-profit', 'customer-invoice-profit', 'customer-profit', 'profit', 'shifts', 'tax', 'branches']))
            <div @class(['grid gap-4', 'sm:grid-cols-3' => $report === 'sales', 'sm:grid-cols-2' => $report !== 'sales'])>
                @if ($report === 'sales')
                    <flux:select label="الفواتير" wire:model="invoiceScope" data-report-invoice-scope><option value="">فواتير الزبون العام</option><option value="customer">فواتير مربوطة بزبون</option><option value="all">كل الفواتير</option></flux:select>
                @endif
                <flux:select label="مصدر الفاتورة" wire:model="source"><option value="">الكل</option><option value="pos">نقطة البيع (زبون نقدي)</option><option value="web">فواتير المبيعات (تشمل فواتير نقطة البيع لزبون)</option><option value="api">التطبيق / API</option><option value="online">المتجر الإلكتروني</option></flux:select>
                <flux:select label="نوع البيع" wire:model="saleKind"><option value="">الكل</option><option value="retail">مفرق</option><option value="wholesale">جملة</option></flux:select>
            </div>
        @endif
        @if (in_array($report, ['product-sales', 'product-profit']))
            <flux:select label="طريقة العرض" wire:model="productView">
                <option value="summary">إجمالي</option>
                <option value="detailed">تفصيلي</option>
            </flux:select>
        @endif
        @if (in_array($report, ['profit', 'product-profit', 'customer-invoice-profit', 'customer-profit']) || ($report === 'product-sales' && auth()->user()->can('reports.profit')))
            <flux:select label="طريقة احتساب تكلفة الصنف" wire:model="costMethod">
                <option value="recorded">التكلفة المسجلة وقت البيع</option>
                <option value="latest_purchase">آخر سعر شراء</option>
                <option value="weighted_average">متوسط أسعار الشراء المرجّح بالكميات</option>
            </flux:select>
        @endif
        @if ($report === 'pos-invoices')
            <flux:select label="الفواتير" wire:model="invoiceScope" data-report-invoice-scope><option value="">كل الفواتير</option><option value="walk_in">فواتير الزبون العام</option><option value="customer">فواتير مربوطة بزبون</option></flux:select>
        @endif
        @if (in_array($report, ['pos-invoices', 'product-history'], true))<label class="reports-invoice-paid-toggle" data-report-delivery-toggle><input type="checkbox" wire:model.live="showDeliveryDetails" /><span>بيانات التوصيل</span></label>@endif
        @if ($report === 'inventory')<flux:select label="حالة المخزون" wire:model="stockFilter"><option value="all">الكل</option><option value="low">أقل من حد التنبيه</option><option value="zero">نافد</option><option value="negative">رصيد سالب</option></flux:select>@endif
        <div class="reports-filter-actions flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary" icon="magnifying-glass" wire:loading.attr="disabled">عرض التقرير</flux:button>
            <flux:button type="button" icon="arrow-path" wire:click="resetFilters">إعادة التعيين</flux:button>
            <div class="reports-ranges" role="group" aria-label="فترات سريعة">
                @foreach ($periodRanges as $range => [, , $label])<flux:button type="button" wire:key="range-{{ $range }}" wire:click="setPeriod('{{ $range }}')" :variant="$activeRange === $range ? 'primary' : 'outline'" aria-pressed="{{ $activeRange === $range ? 'true' : 'false' }}">{{ $label }}</flux:button>@endforeach
            </div>
        </div>
        </div></details>
        @if ($errors->any())<div role="alert" class="text-sm text-red-700 dark:text-red-300">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    </form>
    @endif
    <div wire:loading role="status" class="text-sm text-blue-700 dark:text-blue-300">جارٍ تجهيز التقرير…</div>
    <section class="reports-result">
    @if ($report !== 'statement')
    <header class="reports-result-heading">
        <h2><flux:icon.document-text variant="outline" />{{ $allowed[$report][0] }}</h2>
        <p>الفترة: من {{ $from }} إلى {{ $to }} <span aria-hidden="true">|</span> الفرع: {{ $branches->firstWhere('id', $branchId)?->name ?? 'كل الفروع' }}</p>
        @if ($definition['summary'])
            <dl class="reports-summary-chips">@foreach ($definition['summary'] as $label => $value)<div wire:key="summary-chip-{{ $loop->index }}"><dt>{{ $label }}</dt><dd dir="ltr">{{ number_format((float) $value, 2) }}</dd></div>@endforeach</dl>
        @endif
    </header>
    @endif
    @if ($report === 'statement')
        @if ($statementReady)
            <section data-statement-ledger class="min-w-0 space-y-4" x-on:keydown.arrow-down.prevent="move(rows(), 1)" x-on:keydown.arrow-up.prevent="move(rows(), -1)" x-on:keydown.arrow-right.prevent="toParties()">
                @include('pages.tenant.reports.partials.statement-result')
            </section>
        @else
            <div class="reports-statement-placeholder" data-statement-pending><flux:icon.document-text variant="outline" /><h3>اختر الحساب ثم اعرض الكشف</h3><p>اضغط على الحساب أو Enter أو «عرض التقرير» لعرض حركاته.</p></div>
        @endif
    @else
    @if ($definition['notes'])<details class="reports-notes"><summary>ملاحظات التقرير</summary><div>@foreach ($definition['notes'] as $note)<p wire:key="note-{{ $loop->index }}">{{ $note }}</p>@endforeach</div></details>@endif
    <div class="reports-data-scroll overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700" tabindex="0" role="region" aria-label="نتائج التقرير">
        <table class="w-full whitespace-nowrap text-right text-sm">
            <thead class="bg-slate-100 dark:bg-slate-800">@include('pages.tenant.reports.partials.column-filters')</thead>
            <tbody>@forelse ($rows as $row)<tr wire:key="row-{{ $report }}-{{ $rows->currentPage() }}-{{ $loop->index }}" class="border-t border-slate-100 dark:border-slate-800">@foreach ($definition['columns'] as $key => $label)<td class="p-3 tabular-nums">@if (in_array($report, ['product-history', 'pos-invoices', 'customer-invoice-profit'], true) && $key === 'reference' && auth()->user()->can($row->invoice_type === 'purchase' ? 'purchases.view' : (in_array($row->invoice_type, ['pos', 'return']) ? 'pos.view' : 'sales_invoices.view')))<a class="font-bold text-indigo-600 dark:text-indigo-400" @if ($report !== 'pos-invoices') target="_blank" rel="noopener" @endif href="{{ route($row->invoice_type === 'purchase' ? 'tenant.purchase-invoices' : (in_array($row->invoice_type, ['pos', 'return']) ? 'tenant.pos' : 'tenant.sale-invoices'), ['invoice' => $row->invoice_id]) }}">{{ $row->reference }}@if ($report !== 'pos-invoices') ↗@endif</a>@elseif ($report === 'customer-profit' && $key === 'party_name')<a class="font-bold text-indigo-600 dark:text-indigo-400" title="فواتير هذا الزبون وربح كل فاتورة" data-customer-invoices-link href="{{ route('tenant.reports', ['report' => 'customer-invoice-profit', 'party_id' => $row->id, 'from' => $from, 'to' => $to] + ($branchId ? ['branch_id' => $branchId] : [])) }}">{{ $row->party_name }}</a>@elseif ($report === 'sales' && $key === 'day')<a class="font-bold text-indigo-600 dark:text-indigo-400" title="فواتير هذا اليوم" href="{{ route('tenant.reports', ['report' => 'pos-invoices', 'from' => $row->day, 'to' => $row->day] + ($branchId ? ['branch_id' => $branchId] : [])) }}">{{ $row->day }}</a>@else {{ ReportingService::formatCell($key, $row->{$key} ?? null) }} @endif</td>@endforeach</tr>@empty<tr><td colspan="{{ max(1, count($definition['columns'])) }}" class="p-8 text-center text-slate-500">لا توجد بيانات مطابقة. اختر الفلاتر ثم اعرض التقرير.</td></tr>@endforelse</tbody>
        </table>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3"><flux:text>عدد النتائج: {{ $rows->total() }} — {{ $from }} إلى {{ $to }}</flux:text>{{ $rows->links() }}</div>
    @endif
    <footer class="reports-result-footer">
        <span>{{ $report === 'statement' ? 'عدد الحركات' : 'عدد النتائج' }}: {{ $rows->total() }}</span>
        <div class="reports-actions">
            <flux:button wire:click="exportCsv" icon="table-cells" class="reports-excel" wire:loading.attr="disabled" :disabled="!$valid">تصدير Excel</flux:button>
            @if ($printUrl)
                <flux:button :href="$printUrl.'&format=pdf'" icon="document-arrow-down" class="reports-pdf">تصدير PDF</flux:button>
                <flux:button :href="$printUrl" icon="printer" target="_blank" rel="noopener" variant="primary">طباعة التقرير</flux:button>
            @endif
        </div>
    </footer>
    </section>
    </div>
    </div>
</flux:main>

@script
<script>
    $wire.on('open-statement-document', ({ url, title }) => {
        if (!url) return;
        let shell = window;
        try {
            if (window.top !== window && window.top.document.querySelector('.workspace-shell')) shell = window.top;
        } catch (_) {}
        if (!shell.document.querySelector('.workspace-shell')) {
            window.location.assign(url);
            return;
        }
        shell.dispatchEvent(new shell.CustomEvent('workspace:open-page', { detail: { url, title: title || 'المستند' } }));
    });
</script>
@endscript
