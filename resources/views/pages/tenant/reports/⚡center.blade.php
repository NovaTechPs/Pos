<?php

use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Services\ReportingService;
use App\Services\PosProductSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $report = 'statement';
    public ?int $branchId = null;
    public string $from = '';
    public string $to = '';
    public ?int $partyId = null;
    public ?int $productId = null;
    public string $search = '';
    public string $partyType = 'all';
    public string $balanceSide = 'all';
    public string $stockFilter = 'all';
    public string $source = '';
    public string $saleKind = '';
    public string $costMethod = 'recorded';
    public string $voucherType = '';
    public string $paymentMethod = '';
    public string $partySearch = '';
    public string $statementPartyType = 'all';
    public string $productSearch = '';
    /** @var array{title: string, reference: string, date: string, party: string, amount: float, status: string, notes: string}|null */
    public ?array $statementDocument = null;
    public bool $showStatementDocument = false;

    public function mount(): void
    {
        $this->from = today()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
        $this->branchId = auth()->user()->isTenantOwner() || auth()->user()->isSaaSAdmin()
            ? null
            : auth()->user()->activeBranchId();
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
        if ($this->report === 'expiring' && ! request()->query('to')) {
            $this->to = today()->addDays(30)->toDateString();
        }
        if ($this->report === 'statement' && request()->integer('party_id') > 0) {
            $this->partyId = Party::query()->where('tenant_id', $this->service()->tenantId)
                ->whereKey(request()->integer('party_id'))->value('id');
            $this->statementPartyType = in_array(request('party_type'), ['customer', 'supplier'], true) ? request('party_type') : 'all';
        }
    }

    private function service(): ReportingService
    {
        return new ReportingService(auth()->user(), $this->branchId, false);
    }

    public function updatedBranchId($value): void
    {
        $user = auth()->user();
        if (! $user?->isTenantOwner() && ! $user?->isSaaSAdmin()) {
            $this->branchId = $user?->activeBranchId();
            return;
        }

        $branchId = $value === '' || $value === null ? null : (int) $value;
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
            'search' => $this->search, 'party_type' => $this->partyType, 'balance_side' => $this->balanceSide,
            'stock_filter' => $this->stockFilter, 'source' => $this->source, 'sale_kind' => $this->saleKind, 'cost_method' => $this->costMethod,
            'voucher_type' => $this->voucherType, 'payment_method' => $this->paymentMethod];
    }

    public function applyFilters(): void
    {
        $this->resetErrorBag();
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
        $this->partyId = $partyId;
        $this->resetPage();
    }

    public function openStatementDocument(int $sourceOrder, int $sourceId): void
    {
        $tenantId = $this->service()->tenantId;
        $document = match ($sourceOrder) {
            1 => Order::query()->where('tenant_id', $tenantId)->with('party')->findOrFail($sourceId),
            2 => Purchase::query()->where('tenant_id', $tenantId)->with('supplier')->findOrFail($sourceId),
            3 => PurchaseReturn::query()->where('tenant_id', $tenantId)->with('supplier')->findOrFail($sourceId),
            4 => Payment::query()->where('tenant_id', $tenantId)->with('party')->findOrFail($sourceId),
            default => abort(404),
        };

        $this->statementDocument = match ($sourceOrder) {
            1 => ['title' => $document->type === 'return' ? 'فاتورة مردود مبيعات' : 'فاتورة مبيعات', 'reference' => (string) $document->invoice_number, 'date' => (string) $document->created_at, 'party' => (string) ($document->party?->name ?? $document->customer_name ?? 'زبون عام'), 'amount' => (float) $document->total, 'status' => (string) $document->status, 'notes' => (string) ($document->notes ?? '')],
            2 => ['title' => 'فاتورة مشتريات', 'reference' => (string) $document->reference_number, 'date' => (string) $document->created_at, 'party' => (string) ($document->supplier?->name ?? 'مورد'), 'amount' => (float) $document->total, 'status' => (string) ($document->payment_status ?? ''), 'notes' => (string) ($document->notes ?? '')],
            3 => ['title' => 'مردود مشتريات', 'reference' => (string) $document->reference_number, 'date' => (string) $document->created_at, 'party' => (string) ($document->supplier?->name ?? 'مورد'), 'amount' => (float) $document->total, 'status' => '', 'notes' => (string) ($document->notes ?? '')],
            4 => ['title' => $document->type === 'receipt' ? 'سند قبض' : 'سند صرف', 'reference' => (string) $document->voucher_number, 'date' => (string) $document->payment_date, 'party' => (string) ($document->party?->name ?? 'بدون طرف'), 'amount' => (float) $document->amount, 'status' => (string) $document->payment_method, 'notes' => (string) ($document->notes ?? '')],
        };
        $this->showStatementDocument = true;
    }

    public function closeStatementDocument(): void
    {
        $this->showStatementDocument = false;
        $this->statementDocument = null;
    }

    public function updatedStatementPartySearch(): void
    {
        $this->resetPage('statementPartiesPage');
    }

    public function updatedStatementPartyType(): void
    {
        $this->resetPage('statementPartiesPage');
    }

    public function updatedReport(): void
    {
        $this->resetPage();
        $this->resetErrorBag();
        $this->search = '';
        $this->partyType = 'all';
        $this->balanceSide = 'all';
        $this->statementPartyType = 'all';
        $this->partyId = null;
        $this->productId = null;
        $this->voucherType = '';
        $this->paymentMethod = '';
        if ($this->report === 'expiring') {
            $this->to = today()->addDays(30)->toDateString();
        }
    }

    public function setPeriod(string $range): void
    {
        [$this->from, $this->to] = match ($range) {
            'today' => [today()->toDateString(), today()->toDateString()],
            'yesterday' => [today()->subDay()->toDateString(), today()->subDay()->toDateString()],
            'month' => [today()->startOfMonth()->toDateString(), today()->toDateString()],
            'last-month' => [today()->subMonthNoOverflow()->startOfMonth()->toDateString(), today()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default => [$this->from, $this->to],
        };
        $this->resetPage();
    }

    public function exportCsv(): mixed
    {
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
        $valid = true;
        try { $definition = $service->prepare($this->report, $this->filters()); }
        catch (ValidationException $error) {
            $this->setErrorBag($error->validator->errors());
            $valid = false;
            $definition = ['query' => DB::table('orders')->whereRaw('1 = 0'), 'columns' => [], 'summary' => [], 'notes' => []];
        }
        $parties = collect();
        $statementParties = null;
        $products = collect();
        if (in_array($this->report, ['statement', 'balances', 'aging', 'cashflow'], true)) {
            $parties = Party::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->whereNull('deleted_at')
                ->when($service->branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $service->branchId)->orWhereNull('branch_id')))
                ->when(trim($this->partySearch) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->partySearch.'%')->orWhere('code', 'like', '%'.$this->partySearch.'%')))
                ->orderBy('name')->limit(100)->get(['id', 'name', 'type']);
        }
        if ($this->report === 'statement') {
            $statementPartyTypes = $this->statementPartyType === 'supplier' ? ['supplier', 'both'] : ($this->statementPartyType === 'all' ? ['customer', 'supplier', 'both'] : ['customer', 'both']);
            $statementParties = Party::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->whereNull('deleted_at')
                ->when($service->branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $service->branchId)->orWhereNull('branch_id')))
                ->whereIn('type', $statementPartyTypes)
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
        $printUrl = $valid ? route('tenant.reports.print', ['report' => $this->report, 'branch_id' => $service->branchId ?? 0] + $this->filters()) : null;
        return $this->view(['definition' => $definition, 'rows' => $definition['query']->paginate(30), 'allowed' => $allowed, 'parties' => $parties, 'statementParties' => $statementParties, 'products' => $products, 'branches' => $branches, 'printUrl' => $printUrl, 'valid' => $valid])->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="space-y-6" data-keyboard-tables>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><flux:heading size="xl">مركز التقارير</flux:heading><flux:text>تقارير الحسابات والمبيعات والمخزون والموظفين حسب الصلاحيات والفروع المسموحة.</flux:text></div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('tenant.analytics')" wire:navigate>التحليلات السابقة</flux:button>
            <flux:button wire:click="exportCsv" wire:loading.attr="disabled" :disabled="!$valid">تصدير CSV</flux:button>
            @if ($printUrl)<flux:button :href="$printUrl" target="_blank" rel="noopener">طباعة التقرير كاملاً</flux:button>@endif
        </div>
    </div>
    <flux:select label="نوع التقرير" wire:model.live="report">
        @foreach (ReportingService::REPORT_GROUPS as $group => $keys) @php($groupReports = array_intersect_key($allowed, array_flip($keys))) @if ($groupReports)<optgroup wire:key="report-group-{{ $loop->index }}" label="{{ $group }}">@foreach ($groupReports as $key => $entry)<option wire:key="report-{{ $key }}" value="{{ $key }}">{{ $entry[0] }}</option>@endforeach</optgroup> @endif @endforeach
    </flux:select>
    <form wire:submit="applyFilters" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @if (auth()->user()->isTenantOwner() || auth()->user()->isSaaSAdmin())
                <flux:select label="الفرع" wire:model.live="branchId"><option value="">كل الفروع</option>@foreach ($branches as $branch)<option wire:key="report-branch-{{ $branch->id }}" value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>
            @else
                <div class="flex items-end"><div class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm"><span class="text-xs text-slate-500">الفرع</span><div class="font-bold">{{ \App\Models\Branch::query()->whereKey($branchId)->value('name') ?? 'فرع المستخدم' }}</div></div></div>
            @endif
            <flux:input type="date" label="من تاريخ" wire:model="from" />
            <flux:input type="date" label="إلى تاريخ" wire:model="to" />
            @if (in_array($report, ['balances', 'aging', 'cashflow', 'expenses', 'product-history', 'product-sales', 'product-profit', 'movements', 'movement-summary', 'inventory', 'stagnant', 'expiring']))<flux:input label="بحث في التقرير" wire:model="search" placeholder="اسم أو رقم الصنف / الطرف" />@endif
        </div>
        @if (in_array($report, ['statement', 'balances', 'aging', 'cashflow']))
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:input label="ابحث عن الطرف للاختيار" wire:model.live.debounce.300ms="partySearch" placeholder="الاسم أو الرمز أو الهاتف" />
                <flux:select label="الزبون / المورد" wire:model="partyId"><option value="">{{ $report === 'statement' ? 'اختر الطرف' : 'كل الأطراف' }}</option>@foreach ($parties as $party)<option wire:key="party-{{ $party->id }}" value="{{ $party->id }}">{{ $party->name }} — {{ ['customer' => 'زبون', 'supplier' => 'مورد', 'both' => 'زبون ومورد'][$party->type] }}</option>@endforeach</flux:select>
                @if (in_array($report, ['balances', 'aging']))<flux:select label="نوع الطرف" wire:model="partyType"><option value="all">الجميع</option><option value="customer">زبون</option><option value="supplier">مورد</option><option value="both">زبون ومورد</option></flux:select>@endif
                @if (in_array($report, ['balances', 'aging']))<flux:select label="اتجاه الذمم" wire:model="balanceSide"><option value="all">مدين ودائن معاً</option><option value="debit">مدين / لنا</option><option value="credit">دائن / علينا</option><option value="settled">مسدد</option></flux:select>@endif
            </div>
        @endif
        @if (in_array($report, ['product-history', 'product-sales', 'product-profit', 'movements', 'movement-summary', 'inventory', 'stagnant', 'expiring']))
            <div class="relative" x-data="{ open: false, active: 0, rows() { return [...this.$refs.rows.querySelectorAll('[data-report-product]')] }, move(direction) { const rows = this.rows(); if (!rows.length) return; this.active = Math.max(0, Math.min(rows.length - 1, this.active + direction)); rows[this.active]?.scrollIntoView({ block: 'nearest' }); }, choose() { this.rows()[this.active]?.click() } }" x-on:click.outside="open = false" x-on:keydown.arrow-down.prevent="open = true; move(1)" x-on:keydown.arrow-up.prevent="move(-1)" x-on:keydown.enter.prevent="if (open && rows().length) choose()" x-on:keydown.escape="open = false">
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
        @if (in_array($report, ['sales', 'product-sales', 'product-profit', 'profit', 'shifts', 'tax', 'branches']))
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select label="مصدر الفاتورة" wire:model="source"><option value="">الكل</option><option value="pos">نقطة البيع (زبون نقدي)</option><option value="web">فواتير المبيعات (تشمل فواتير نقطة البيع لزبون)</option><option value="api">التطبيق / API</option><option value="online">المتجر الإلكتروني</option></flux:select>
                <flux:select label="نوع البيع" wire:model="saleKind"><option value="">الكل</option><option value="retail">مفرق</option><option value="wholesale">جملة</option></flux:select>
            </div>
        @endif
        @if (in_array($report, ['profit', 'product-profit']))
            <flux:select label="طريقة احتساب تكلفة الصنف" wire:model="costMethod">
                <option value="recorded">التكلفة المسجلة وقت البيع</option>
                <option value="latest_purchase">آخر سعر شراء</option>
                <option value="weighted_average">متوسط أسعار الشراء المرجّح بالكميات</option>
            </flux:select>
        @endif
        @if ($report === 'inventory')<flux:select label="حالة المخزون" wire:model="stockFilter"><option value="all">الكل</option><option value="low">أقل من حد التنبيه</option><option value="zero">نافد</option><option value="negative">رصيد سالب</option></flux:select>@endif
        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">عرض التقرير</flux:button>
            @foreach (['today' => 'اليوم', 'yesterday' => 'أمس', 'month' => 'هذا الشهر', 'last-month' => 'الشهر السابق'] as $range => $label)<flux:button wire:key="range-{{ $range }}" wire:click="setPeriod('{{ $range }}')">{{ $label }}</flux:button>@endforeach
        </div>
        @if ($errors->any())<div role="alert" class="text-sm text-red-700 dark:text-red-300">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    </form>
    <div wire:loading role="status" class="text-sm text-blue-700 dark:text-blue-300">جارٍ تجهيز التقرير…</div>
    @if ($report === 'statement' && $statementParties)
        <div class="grid gap-4 xl:grid-cols-[minmax(280px,360px)_minmax(0,1fr)]" wire:loading.class="opacity-60" wire:target="selectStatementParty,partySearch,statementPartyType,applyFilters,setPeriod"
            x-data="{
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
                toParties() { const parties = this.parties(); (parties.find((party) => party.dataset.selected === 'true') ?? parties[0])?.focus() },
            }">
            <aside data-statement-directory class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
                x-on:keydown.arrow-down.prevent="move(parties(), 1)"
                x-on:keydown.arrow-up.prevent="move(parties(), -1)"
                x-on:keydown.arrow-left.prevent="toStatement()">
                <div class="border-b border-slate-200 p-4 dark:border-slate-700">
                    <div class="flex items-center justify-between gap-2"><div><h2 class="font-bold">دليل الحسابات</h2><p class="text-xs text-slate-500">اختر طرفاً لعرض كشفه بالتاريخ المحدد.</p></div><span class="rounded-full bg-indigo-50 px-2 py-1 text-xs font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-200">{{ $statementParties->count() }}</span></div>
                    <div class="mt-3 grid grid-cols-3 gap-1">
                        @foreach (['all' => 'الكل', 'customer' => 'الزبائن', 'supplier' => 'الموردون'] as $type => $label)
                            <button type="button" wire:key="statement-party-type-{{ $type }}" wire:click="$set('statementPartyType', '{{ $type }}')" class="rounded-lg px-2 py-2 text-xs font-bold {{ $statementPartyType === $type ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                    <p class="mt-2 text-[11px] text-slate-400">↑ ↓ للتنقل · Enter لعرض الكشف · ← للانتقال إلى الكشف</p>
                </div>
                <div class="max-h-[580px] overflow-y-auto p-2">
                    @forelse ($statementParties as $party)
                        <button type="button" data-statement-party data-selected="{{ (int) $partyId === (int) $party->id ? 'true' : 'false' }}" wire:click="selectStatementParty({{ $party->id }})" wire:key="statement-party-{{ $party->id }}" class="mb-1 w-full rounded-lg p-3 text-right transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-1 {{ (int) $partyId === (int) $party->id ? 'bg-indigo-600 text-white shadow-sm' : 'hover:bg-slate-50 focus:bg-indigo-50 dark:hover:bg-slate-800' }}">
                            <div class="flex items-center justify-between gap-3"><span class="truncate font-bold">{{ $party->name }}</span><span class="shrink-0 text-xs tabular-nums {{ (int) $partyId === (int) $party->id ? 'text-indigo-100' : 'text-slate-500' }}">{{ number_format((float) $party->current_balance, 2) }}</span></div>
                            <div class="mt-1 flex gap-2 text-xs {{ (int) $partyId === (int) $party->id ? 'text-indigo-100' : 'text-slate-500' }}"><span>{{ $party->code ?: 'بدون رمز' }}</span><span>{{ $party->phone ?: '' }}</span></div>
                        </button>
                    @empty
                        <p class="p-6 text-center text-sm text-slate-500">لا توجد حسابات مطابقة.</p>
                    @endforelse
                </div>
                @if ($statementParties->hasPages())<div class="border-t border-slate-200 px-3 py-2 dark:border-slate-700">{{ $statementParties->links() }}</div>@endif
            </aside>
            <section data-statement-ledger class="min-w-0 space-y-4"
                x-on:keydown.arrow-down.prevent="move(rows(), 1)"
                x-on:keydown.arrow-up.prevent="move(rows(), -1)"
                x-on:keydown.arrow-right.prevent="toParties()">
                @include('pages.tenant.reports.partials.statement-result', ['definition' => $definition, 'rows' => $rows, 'from' => $from, 'to' => $to])
            </section>
        </div>
    @else
    @if ($definition['summary'])<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach ($definition['summary'] as $label => $value)<div wire:key="summary-{{ $loop->index }}" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-bold tabular-nums" dir="ltr">{{ number_format((float) $value, 2) }}</p></div>@endforeach</div>@endif
    @if ($definition['notes'])<div class="space-y-1 rounded-xl bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">@foreach ($definition['notes'] as $note)<p wire:key="note-{{ $loop->index }}">{{ $note }}</p>@endforeach</div>@endif
    <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
        <table class="w-full whitespace-nowrap text-right text-sm">
            <thead class="bg-slate-100 dark:bg-slate-800"><tr>@foreach ($definition['columns'] as $label)<th class="p-3">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>@forelse ($rows as $row)<tr wire:key="row-{{ $report }}-{{ $rows->currentPage() }}-{{ $loop->index }}" class="border-t border-slate-100 dark:border-slate-800">@foreach ($definition['columns'] as $key => $label)<td class="p-3 tabular-nums">@if ($report === 'product-history' && $key === 'reference' && auth()->user()->can($row->invoice_type === 'purchase' ? 'purchases.view' : (in_array($row->invoice_type, ['pos', 'return']) ? 'pos.view' : 'sales_invoices.view')))<a class="font-bold text-indigo-600 dark:text-indigo-400" target="_blank" rel="noopener" href="{{ route($row->invoice_type === 'purchase' ? 'tenant.purchase-invoices' : (in_array($row->invoice_type, ['pos', 'return']) ? 'tenant.pos' : 'tenant.sale-invoices'), ['invoice' => $row->invoice_id]) }}">{{ $row->reference }} ↗</a>@else {{ ReportingService::formatCell($key, $row->{$key} ?? null) }} @endif</td>@endforeach</tr>@empty<tr><td colspan="{{ max(1, count($definition['columns'])) }}" class="p-8 text-center text-slate-500">لا توجد بيانات مطابقة. اختر الفلاتر ثم اعرض التقرير.</td></tr>@endforelse</tbody>
        </table>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3"><flux:text>عدد النتائج: {{ $rows->total() }} — {{ $from }} إلى {{ $to }}</flux:text>{{ $rows->links() }}</div>
    @endif
    @if ($showStatementDocument && $statementDocument)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" wire:click.self="closeStatementDocument" x-data x-on:keydown.escape.window="$wire.closeStatementDocument()">
            <section role="dialog" aria-modal="true" aria-label="تفاصيل المستند" class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-slate-900">
                <div class="flex items-start justify-between gap-4"><div><h2 class="text-lg font-black">{{ $statementDocument['title'] }}</h2><p class="mt-1 font-mono text-sm text-indigo-700" dir="ltr">{{ $statementDocument['reference'] }}</p></div><button type="button" wire:click="closeStatementDocument" class="rounded-lg px-3 py-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">إغلاق</button></div>
                <dl class="mt-5 grid gap-3 sm:grid-cols-2"><div><dt class="text-xs text-slate-500">التاريخ</dt><dd class="mt-1 font-bold">{{ $statementDocument['date'] }}</dd></div><div><dt class="text-xs text-slate-500">الطرف</dt><dd class="mt-1 font-bold">{{ $statementDocument['party'] }}</dd></div><div><dt class="text-xs text-slate-500">المبلغ</dt><dd class="mt-1 text-lg font-black tabular-nums" dir="ltr">{{ number_format($statementDocument['amount'], 2) }}</dd></div><div><dt class="text-xs text-slate-500">الحالة / الطريقة</dt><dd class="mt-1 font-bold">{{ $statementDocument['status'] ?: '—' }}</dd></div></dl>
                @if ($statementDocument['notes'])<div class="mt-4 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800"><p class="mb-1 text-xs text-slate-500">ملاحظات</p><p>{{ $statementDocument['notes'] }}</p></div>@endif
            </section>
        </div>
    @endif
</flux:main>
