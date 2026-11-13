<?php

use App\Models\Branch;
use App\Services\HomeDashboard;
use App\Services\ReportingService;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Url(as: 'period')]
    public string $period = 'month';

    public ?int $branchId = null;

    public static function canSee(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->tenant_id && $user->can('reports.analytics') && $user->can('reports.profit'));
    }

    public function mount(): void
    {
        abort_unless(self::canSee(), 403);
        $user = auth()->user();
        $this->period = array_key_exists($this->period, HomeDashboard::PERIODS) ? $this->period : 'month';
        $this->branchId = $user->isTenantOwner() || $user->isSaaSAdmin() ? null : $user->activeBranchId();
    }

    public function updatedBranchId(mixed $value): void
    {
        $user = auth()->user();
        if (! $user->isTenantOwner() && ! $user->isSaaSAdmin()) {
            $this->branchId = $user->activeBranchId();

            return;
        }
        $branchId = (int) $value;
        $this->branchId = $branchId > 0 && Branch::query()->where('tenant_id', $user->tenant_id)->whereKey($branchId)->exists() ? $branchId : null;
    }

    /** Opens a report on the dashboard's period and branch, for users who may see it. */
    public function reportUrl(string $report, string $from, string $to, array $extra = []): ?string
    {
        if (! auth()->user()->can(ReportingService::REPORTS[$report][1])) {
            return null;
        }

        return route('tenant.reports', ['report' => $report, 'from' => $from, 'to' => $to] + ($this->branchId ? ['branch_id' => $this->branchId] : []) + $extra);
    }

    public function render(): mixed
    {
        abort_unless(self::canSee(), 403);
        $user = auth()->user();
        // A short cache keeps the home page fast; figures are at most two minutes old.
        $data = Cache::remember(
            'home-dashboard:'.$user->id.':'.$this->period.':'.($this->branchId ?? 'all').':'.today()->toDateString(),
            now()->addMinutes(2),
            fn (): array => (new HomeDashboard($user, $this->branchId))->build($this->period),
        );

        return $this->view([
            'data' => $data,
            'periods' => HomeDashboard::PERIODS,
            'branches' => $user->isTenantOwner() || $user->isSaaSAdmin()
                ? Branch::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->get(['id', 'name'])
                : collect(),
        ]);
    }
};
?>

@php
    $money = fn (float $value): string => number_format($value, 2);
    $from = $data['from'];
    $to = $data['to'];
    $palette = ['#0d9488', '#2563eb', '#f59e0b', '#7c3aed', '#e11d48', '#0891b2', '#65a30d', '#db2777'];
    $cards = array_filter([
        ['key' => 'revenue', 'label' => 'صافي المبيعات', 'icon' => 'banknotes', 'report' => 'branches', 'hint' => 'دون الضريبة والتوصيل'],
        ['key' => 'gross', 'label' => 'مجمل الربح', 'icon' => 'arrow-trending-up', 'report' => 'profit', 'hint' => 'المبيعات ناقص التكلفة'],
        ['key' => 'after_expenses', 'label' => 'الربح بعد المصروفات', 'icon' => 'chart-pie', 'report' => 'profit', 'hint' => 'قبل الرواتب'],
        $data['kpis']['net'] ? ['key' => 'net', 'label' => 'صافي الربح', 'icon' => 'trophy', 'report' => 'profit', 'hint' => 'بعد الرواتب المصروفة'] : null,
        ['key' => 'invoices', 'label' => 'عدد الفواتير', 'icon' => 'document-text', 'report' => 'employees', 'hint' => 'متوسط الفاتورة '.$money($data['kpis']['average']), 'count' => true],
    ]);
    $trendConfig = [
        'type' => 'bar',
        'data' => [
            'labels' => array_column($data['trend'], 'label'),
            'datasets' => [
                ['type' => 'line', 'label' => 'صافي المبيعات', 'data' => array_column($data['trend'], 'revenue'), 'borderColor' => '#0d9488', 'backgroundColor' => 'rgba(13,148,136,0.15)', 'fill' => true, 'tension' => 0.35, 'yAxisID' => 'y'],
                ['type' => 'bar', 'label' => 'عدد الفواتير', 'data' => array_column($data['trend'], 'invoices'), 'backgroundColor' => 'rgba(37,99,235,0.35)', 'borderRadius' => 4, 'yAxisID' => 'invoices'],
            ],
        ],
        'options' => ['interaction' => ['mode' => 'index', 'intersect' => false], 'scales' => ['y' => ['position' => 'left', 'beginAtZero' => true], 'invoices' => ['position' => 'right', 'beginAtZero' => true, 'grid' => ['drawOnChartArea' => false]]]],
    ];
    $doughnut = fn (array $labels, array $values, array $links = []): array => [
        'type' => 'doughnut',
        'data' => ['labels' => $labels, 'datasets' => [['data' => $values, 'backgroundColor' => array_slice($palette, 0, max(1, count($values))), 'borderWidth' => 2]]],
        'options' => ['cutout' => '62%', 'plugins' => ['legend' => ['position' => 'bottom']]],
        'links' => $links,
    ];
    $branchConfig = $doughnut(array_column($data['branches'], 'name'), array_column($data['branches'], 'revenue'),
        array_map(fn (array $branch): ?string => $this->reportUrl('branches', $from, $to), $data['branches']));
    $productConfig = [
        'type' => 'bar',
        'data' => ['labels' => array_column($data['products'], 'name'), 'datasets' => [['label' => 'قيمة المبيعات', 'data' => array_column($data['products'], 'revenue'), 'backgroundColor' => '#0d9488', 'borderRadius' => 6]]],
        'options' => ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => ['beginAtZero' => true]]],
        'links' => array_map(fn (array $product): ?string => $this->reportUrl('product-history', $from, $to, ['product_id' => $product['id']]), $data['products']),
    ];
    $agingConfig = $doughnut(array_keys($data['aging']), array_values($data['aging']));
    $expenseConfig = $doughnut(array_keys($data['expenses']), array_values($data['expenses']));
    $charts = [
        ['id' => 'trend', 'title' => 'اتجاه المبيعات وعدد الفواتير', 'config' => $trendConfig, 'empty' => array_sum(array_column($data['trend'], 'invoices')) === 0, 'report' => 'branches', 'wide' => true],
        ['id' => 'branches', 'title' => 'المبيعات حسب الفرع', 'config' => $branchConfig, 'empty' => $data['branches'] === [], 'report' => 'branches'],
        ['id' => 'products', 'title' => 'أفضل 5 أصناف', 'config' => $productConfig, 'empty' => $data['products'] === [], 'report' => 'product-sales'],
        ['id' => 'aging', 'title' => 'أعمار ديون الزبائن', 'config' => $agingConfig, 'empty' => $data['aging'] === [], 'report' => 'aging'],
        ['id' => 'expenses', 'title' => 'التكاليف والمصروفات', 'config' => $expenseConfig, 'empty' => $data['expenses'] === [], 'report' => 'profit'],
    ];
@endphp

<div class="space-y-5" dir="rtl" data-home-dashboard>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-black text-slate-900 dark:text-white">أهلاً {{ auth()->user()->name }} 👋</h1>
            <p class="text-sm text-slate-500">ملخص نشاط متجرك من {{ $from }} إلى {{ $to }}. اضغط أي رقم أو رسم لفتح تفاصيله.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($branches->isNotEmpty())
                <select wire:model.live="branchId" aria-label="الفرع" class="h-9 rounded-lg border border-slate-200 bg-white px-2 text-sm dark:border-slate-700 dark:bg-slate-900">
                    <option value="">كل الفروع</option>
                    @foreach ($branches as $branch)
                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                    @endforeach
                </select>
            @endif
            <div class="inline-flex rounded-lg border border-slate-200 bg-white p-0.5 dark:border-slate-700 dark:bg-slate-900" role="group" aria-label="الفترة">
                @foreach ($periods as $key => $label)
                    <button type="button" wire:click="$set('period', '{{ $key }}')" @class(['rounded-md px-3 py-1.5 text-xs font-bold', 'bg-teal-600 text-white' => $period === $key, 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' => $period !== $key])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    <section class="grid grid-cols-2 gap-3 lg:grid-cols-5" data-home-kpis>
        @foreach ($cards as $card)
            @php($kpi = $data['kpis'][$card['key']])
            @php($url = $this->reportUrl($card['report'], $from, $to))
            <div class="relative rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition hover:shadow-md dark:border-slate-700 dark:bg-slate-900" data-home-kpi="{{ $card['key'] }}">
                @if ($url)
                    <a href="{{ $url }}" wire:navigate class="absolute inset-0 rounded-2xl focus:outline-none focus:ring-2 focus:ring-teal-500" aria-label="تفاصيل: {{ $card['label'] }}"></a>
                @endif
                <div class="flex items-center gap-2 text-xs font-bold text-slate-500">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-teal-50 text-teal-700 dark:bg-teal-950/40"><flux:icon :name="$card['icon']" class="size-4" /></span>
                    {{ $card['label'] }}
                </div>
                <div class="mt-3 text-2xl font-black tabular-nums text-slate-900 dark:text-white" dir="ltr">{{ ! empty($card['count']) ? number_format($kpi['value']) : $money($kpi['value']) }}</div>
                <div class="mt-1 flex items-center justify-between gap-2 text-[11px]">
                    @if ($kpi['change'] !== null)
                        <span @class(['font-bold', 'text-emerald-600' => $kpi['change'] >= 0, 'text-rose-600' => $kpi['change'] < 0]) dir="ltr">{{ $kpi['change'] >= 0 ? '▲' : '▼' }} {{ abs($kpi['change']) }}%</span>
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                    <span class="truncate text-slate-400">{{ $card['hint'] }}</span>
                </div>
            </div>
        @endforeach
    </section>

    @if ($data['alerts'] !== [])
        <section class="flex flex-wrap gap-2" data-home-alerts>
            @foreach ($data['alerts'] as $alert)
                <a @if ($alert['url']) href="{{ $alert['url'] }}" wire:navigate @endif
                    @class(['inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-bold',
                        'border-rose-200 bg-rose-50 text-rose-700' => $alert['tone'] === 'rose',
                        'border-amber-200 bg-amber-50 text-amber-800' => $alert['tone'] === 'amber',
                        'border-indigo-200 bg-indigo-50 text-indigo-700' => $alert['tone'] === 'indigo'])>
                    <span class="rounded-full bg-white px-1.5 tabular-nums">{{ $alert['count'] }}</span> {{ $alert['label'] }}
                </a>
            @endforeach
        </section>
    @endif

    <section class="grid gap-4 lg:grid-cols-3">
        @foreach ($charts as $chart)
            @php($chartUrl = $this->reportUrl($chart['report'], $from, $to))
            <div @class(['rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900', 'lg:col-span-2' => ! empty($chart['wide'])]) data-home-chart="{{ $chart['id'] }}">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h2 class="text-sm font-black text-slate-800 dark:text-slate-100">{{ $chart['title'] }}</h2>
                    @if ($chartUrl)
                        <a href="{{ $chartUrl }}" wire:navigate class="text-xs font-bold text-teal-700 hover:underline">التفاصيل ←</a>
                    @endif
                </div>
                @if ($chart['empty'])
                    <div class="flex h-56 items-center justify-center rounded-xl border border-dashed border-slate-200 text-xs text-slate-400 dark:border-slate-700">لا توجد بيانات في هذه الفترة.</div>
                @else
                    <div class="relative h-56" wire:key="chart-{{ $chart['id'] }}-{{ $period }}-{{ $branchId ?? 'all' }}" wire:ignore x-data="dashboardChart(@js($chart['config']))">
                        <canvas x-ref="canvas" aria-label="{{ $chart['title'] }}" role="img"></canvas>
                    </div>
                @endif
            </div>
        @endforeach
    </section>

</div>
