<?php

use App\Models\Branch;
use App\Models\Party;
use App\Models\Product;
use App\Services\ReportingService;
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
    public string $partySearch = '';
    public string $productSearch = '';

    public function mount(): void
    {
        $this->from = today()->startOfMonth()->toDateString();
        $this->to = today()->toDateString();
        $this->branchId = auth()->user()->branch_id;
        $allowed = $this->service()->availableReports();
        abort_if($allowed === [], 403);
        if (!isset($allowed[$this->report])) { $this->report = array_key_first($allowed); }
    }

    private function service(): ReportingService
    {
        return new ReportingService(auth()->user(), $this->branchId);
    }

    public function filters(): array
    {
        return ['from' => $this->from, 'to' => $this->to, 'party_id' => $this->partyId, 'product_id' => $this->productId,
            'search' => $this->search, 'party_type' => $this->partyType, 'balance_side' => $this->balanceSide,
            'stock_filter' => $this->stockFilter, 'source' => $this->source, 'sale_kind' => $this->saleKind];
    }

    public function applyFilters(): void
    {
        $this->resetErrorBag();
        $this->resetPage();
    }

    public function updatedReport(): void
    {
        $this->resetPage();
        $this->resetErrorBag();
        $this->search = '';
        $this->partyType = 'all';
        $this->balanceSide = 'all';
        $this->partyId = null;
        $this->productId = null;
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
        $branches = Branch::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->when(auth()->user()->branch_id, fn ($q) => $q->whereKey(auth()->user()->branch_id))->orderBy('name')->get(['id', 'name']);
        $parties = collect();
        $products = collect();
        if (in_array($this->report, ['statement', 'balances'], true)) {
            $parties = Party::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->whereNull('deleted_at')
                ->when($service->branchId, fn ($q) => $q->where(fn ($q) => $q->where('branch_id', $service->branchId)->orWhereNull('branch_id')))
                ->when(trim($this->partySearch) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->partySearch.'%')->orWhere('code', 'like', '%'.$this->partySearch.'%')))
                ->orderBy('name')->limit(100)->get(['id', 'name', 'type']);
        }
        if (in_array($this->report, ['product-sales', 'movements', 'movement-summary', 'inventory'], true)) {
            $products = Product::withoutGlobalScopes()->where('tenant_id', $service->tenantId)->whereNull('deleted_at')
                ->when(trim($this->productSearch) !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->productSearch.'%')->orWhere('product_number', 'like', '%'.$this->productSearch.'%')->orWhereHas('barcodes', fn ($codes) => $codes->where('tenant_id', $service->tenantId)->where('barcode', 'like', '%'.$this->productSearch.'%'))))
                ->orderBy('name')->limit(100)->get(['id', 'name', 'product_number']);
        }
        $printUrl = $valid ? route('tenant.reports.print', ['report' => $this->report, 'branch_id' => $service->branchId] + $this->filters()) : null;
        return $this->view(['definition' => $definition, 'rows' => $definition['query']->paginate(30), 'allowed' => $allowed, 'branches' => $branches, 'parties' => $parties, 'products' => $products, 'printUrl' => $printUrl, 'valid' => $valid])->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div><flux:heading size="xl">مركز التقارير</flux:heading><flux:text>تقارير الحسابات والمبيعات والمخزون والموظفين حسب الصلاحيات والفروع المسموحة.</flux:text></div>
        <div class="flex flex-wrap gap-2">
            <flux:button :href="route('tenant.analytics')" wire:navigate>التحليلات السابقة</flux:button>
            <flux:button wire:click="exportCsv" wire:loading.attr="disabled" :disabled="!$valid">تصدير CSV</flux:button>
            @if ($printUrl)<flux:button :href="$printUrl" target="_blank" rel="noopener">طباعة التقرير كاملاً</flux:button>@endif
        </div>
    </div>
    <flux:select label="نوع التقرير" wire:model.live="report">
        @foreach ($allowed as $key => $entry)<option wire:key="report-{{ $key }}" value="{{ $key }}">{{ $entry[0] }}</option>@endforeach
    </flux:select>
    <form wire:submit="applyFilters" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:select label="الفرع" wire:model="branchId" :disabled="(bool) auth()->user()->branch_id">
                <option value="">كل الفروع</option>@foreach ($branches as $branch)<option wire:key="branch-{{ $branch->id }}" value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach
            </flux:select>
            <flux:input type="date" label="من تاريخ" wire:model="from" />
            <flux:input type="date" label="إلى تاريخ" wire:model="to" />
            @if (in_array($report, ['balances', 'product-sales', 'movements', 'movement-summary', 'inventory']))<flux:input label="بحث في التقرير" wire:model="search" placeholder="اسم أو رقم الصنف / الطرف" />@endif
        </div>
        @if (in_array($report, ['statement', 'balances']))
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:input label="ابحث عن الطرف للاختيار" wire:model.live.debounce.300ms="partySearch" placeholder="الاسم أو الرمز؛ أول 100 نتيجة" />
                <flux:select label="الزبون / المورد" wire:model="partyId"><option value="">{{ $report === 'statement' ? 'اختر الطرف' : 'كل الأطراف' }}</option>@foreach ($parties as $party)<option wire:key="party-{{ $party->id }}" value="{{ $party->id }}">{{ $party->name }} — {{ ['customer' => 'زبون', 'supplier' => 'مورد', 'both' => 'زبون ومورد'][$party->type] }}</option>@endforeach</flux:select>
                @if ($report === 'balances')<flux:select label="نوع الطرف" wire:model="partyType"><option value="all">الجميع</option><option value="customer">زبون</option><option value="supplier">مورد</option><option value="both">زبون ومورد</option></flux:select>@endif
                @if ($report === 'balances')<flux:select label="اتجاه الذمم" wire:model="balanceSide"><option value="all">مدين ودائن معاً</option><option value="debit">مدين / لنا</option><option value="credit">دائن / علينا</option><option value="settled">مسدد</option></flux:select>@endif
            </div>
        @endif
        @if (in_array($report, ['product-sales', 'movements', 'movement-summary', 'inventory']))
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input label="ابحث عن الصنف للاختيار" wire:model.live.debounce.300ms="productSearch" placeholder="الاسم أو الرقم أو الباركود؛ أول 100 نتيجة" />
                <flux:select label="الصنف" wire:model="productId"><option value="">كل الأصناف</option>@foreach ($products as $product)<option wire:key="product-{{ $product->id }}" value="{{ $product->id }}">{{ $product->product_number }} — {{ $product->name }}</option>@endforeach</flux:select>
            </div>
        @endif
        @if (in_array($report, ['sales', 'product-sales', 'profit']))
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select label="مصدر الفاتورة" wire:model="source"><option value="">الكل</option><option value="pos">نقطة البيع</option><option value="web">الويب</option><option value="api">التطبيق / API</option><option value="online">المتجر الإلكتروني</option></flux:select>
                <flux:select label="نوع البيع" wire:model="saleKind"><option value="">الكل</option><option value="retail">مفرق</option><option value="wholesale">جملة</option></flux:select>
            </div>
        @endif
        @if ($report === 'inventory')<flux:select label="حالة المخزون" wire:model="stockFilter"><option value="all">الكل</option><option value="low">أقل من حد التنبيه</option><option value="zero">نافد</option><option value="negative">رصيد سالب</option></flux:select>@endif
        <div class="flex flex-wrap gap-2">
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">عرض التقرير</flux:button>
            @foreach (['today' => 'اليوم', 'yesterday' => 'أمس', 'month' => 'هذا الشهر', 'last-month' => 'الشهر السابق'] as $range => $label)<flux:button wire:key="range-{{ $range }}" wire:click="setPeriod('{{ $range }}')">{{ $label }}</flux:button>@endforeach
        </div>
        @if ($errors->any())<div role="alert" class="text-sm text-red-700 dark:text-red-300">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    </form>
    <div wire:loading role="status" class="text-sm text-blue-700 dark:text-blue-300">جارٍ تجهيز التقرير…</div>
    @if ($definition['summary'])<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach ($definition['summary'] as $label => $value)<div wire:key="summary-{{ $loop->index }}" class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-bold tabular-nums" dir="ltr">{{ number_format((float) $value, 2) }}</p></div>@endforeach</div>@endif
    @if ($definition['notes'])<div class="space-y-1 rounded-xl bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950 dark:text-amber-100">@foreach ($definition['notes'] as $note)<p wire:key="note-{{ $loop->index }}">{{ $note }}</p>@endforeach</div>@endif
    <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
        <table class="w-full whitespace-nowrap text-right text-sm">
            <thead class="bg-slate-100 dark:bg-slate-800"><tr>@foreach ($definition['columns'] as $label)<th class="p-3">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>@forelse ($rows as $row)<tr wire:key="row-{{ $report }}-{{ $rows->currentPage() }}-{{ $loop->index }}" class="border-t border-slate-100 dark:border-slate-800">@foreach ($definition['columns'] as $key => $label)<td class="p-3 tabular-nums">{{ ReportingService::formatCell($key, $row->{$key} ?? null) }}</td>@endforeach</tr>@empty<tr><td colspan="{{ max(1, count($definition['columns'])) }}" class="p-8 text-center text-slate-500">لا توجد بيانات مطابقة. اختر الفلاتر ثم اعرض التقرير.</td></tr>@endforelse</tbody>
        </table>
    </div>
    <div class="flex flex-wrap items-center justify-between gap-3"><flux:text>عدد النتائج: {{ $rows->total() }} — {{ $from }} إلى {{ $to }}</flux:text>{{ $rows->links() }}</div>
</flux:main>
