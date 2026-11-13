<?php

namespace App\Services;

use App\Models\BranchProduct;
use App\Models\CustomerVisit;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeLeave;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The manager's home dashboard. Every figure comes from the same reports the reports centre shows,
 * so a card and the report it opens always agree.
 */
class HomeDashboard
{
    public const PERIODS = ['today' => 'اليوم', 'week' => 'هذا الأسبوع', 'month' => 'هذا الشهر', 'year' => 'هذه السنة'];

    private const BASE_FILTERS = ['party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all', 'search' => ''];

    public function __construct(private readonly User $user, private readonly ?int $branchId = null) {}

    /** @return array{0: Carbon, 1: Carbon} */
    public static function range(string $period, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return match ($period) {
            'today' => [$today->copy(), $today->copy()],
            'week' => [$today->copy()->subDays(6), $today->copy()],
            'year' => [$today->copy()->startOfYear(), $today->copy()],
            default => [$today->copy()->startOfMonth(), $today->copy()],
        };
    }

    /**
     * The period of the same length right before the given one, for the change percentages.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function previousRange(Carbon $from, Carbon $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;

        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }

    /** @return array<string, mixed> */
    public function build(string $period): array
    {
        [$from, $to] = self::range($period);
        [$previousFrom, $previousTo] = self::previousRange($from, $to);
        $current = $this->profit($from, $to);
        $previous = $this->profit($previousFrom, $previousTo);
        $branches = $this->report('branches', $from, $to)['query']->get();
        $invoices = (int) $branches->sum('invoices');
        $previousInvoices = (int) $this->report('branches', $previousFrom, $previousTo)['query']->get()->sum('invoices');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpis' => [
                'revenue' => $this->kpi($current['revenue'], $previous['revenue']),
                'gross' => $this->kpi($current['gross'], $previous['gross']),
                'after_expenses' => $this->kpi($current['after_expenses'], $previous['after_expenses']),
                'net' => $current['net'] === null ? null : $this->kpi($current['net'], $previous['net']),
                'invoices' => $this->kpi($invoices, $previousInvoices),
                'average' => $invoices > 0 ? round($current['revenue'] / $invoices, 2) : 0.0,
            ],
            'trend' => $this->trend($from, $to, $period === 'year'),
            'branches' => $branches->filter(fn (object $row): bool => abs((float) $row->revenue) > 0.004)
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => $row->branch_name, 'revenue' => round((float) $row->revenue, 2)])->values()->all(),
            'products' => $this->topProducts($from, $to),
            'aging' => $this->aging($from, $to),
            'expenses' => array_filter([
                'تكلفة البضاعة المباعة' => $current['cost'],
                'مصروفات تشغيلية' => $current['expenses'],
                'رواتب مصروفة' => $current['salaries'],
            ], fn (?float $amount): bool => $amount !== null && $amount > 0.004),
            'alerts' => $this->alerts(),
        ];
    }

    /** @return array<string, mixed> */
    private function report(string $name, Carbon $from, Carbon $to, array $filters = []): array
    {
        return (new ReportingService($this->user, $this->branchId, false))
            ->prepare($name, ['from' => $from->toDateString(), 'to' => $to->toDateString()] + $filters + self::BASE_FILTERS);
    }

    /** @return array{revenue: float, cost: float, gross: float, expenses: float, after_expenses: float, salaries: ?float, net: ?float} */
    private function profit(Carbon $from, Carbon $to): array
    {
        $rows = $this->report('profit', $from, $to)['query']->get()->keyBy('row_index')
            ->map(fn (object $row): float => round((float) $row->amount, 2));

        return [
            'revenue' => $rows[5] ?? 0.0, 'cost' => $rows[6] ?? 0.0, 'gross' => $rows[7] ?? 0.0,
            'expenses' => $rows[8] ?? 0.0, 'after_expenses' => $rows[9] ?? 0.0,
            'salaries' => $rows[10] ?? null, 'net' => $rows[11] ?? null,
        ];
    }

    /** @return array{value: float, change: ?float} */
    private function kpi(float|int $value, float|int|null $previous): array
    {
        $change = $previous !== null && abs((float) $previous) > 0.004
            ? round(((float) $value - (float) $previous) / abs((float) $previous) * 100, 1)
            : null;

        return ['value' => round((float) $value, 2), 'change' => $change];
    }

    /** @return list<array{label: string, revenue: float, invoices: int}> */
    private function trend(Carbon $from, Carbon $to, bool $monthly): array
    {
        $tenantId = (int) $this->user->tenant_id;
        $driver = DB::connection()->getDriverName();
        $bucket = $monthly
            ? match ($driver) {
                'sqlite' => "strftime('%Y-%m', orders.created_at)", 'pgsql' => "to_char(orders.created_at, 'YYYY-MM')", default => "DATE_FORMAT(orders.created_at, '%Y-%m')"
            }
        : 'DATE(orders.created_at)';
        $rows = DB::table('orders')->where('orders.tenant_id', $tenantId)->whereNull('orders.deleted_at')
            ->when($this->branchId, fn ($query) => $query->where('orders.branch_id', $this->branchId))
            ->where('orders.status', 'completed')->whereIn('orders.type', ['pos', 'wholesale', 'online', 'return'])
            ->where('orders.created_at', '>=', $from->toDateString().' 00:00:00')
            ->where('orders.created_at', '<', $to->copy()->addDay()->toDateString().' 00:00:00')
            ->selectRaw($bucket.' as bucket')
            ->selectRaw('SUM('.ReportingService::ORDER_REVENUE_SQL.') as revenue')
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' THEN 1 ELSE 0 END) as invoices")
            ->groupByRaw($bucket)->get()->keyBy(fn (object $row): string => substr((string) $row->bucket, 0, $monthly ? 7 : 10));

        $points = [];
        for ($day = $from->copy(); $day->lte($to); $monthly ? $day->addMonth() : $day->addDay()) {
            $key = $monthly ? $day->format('Y-m') : $day->toDateString();
            $points[] = ['label' => $monthly ? $day->format('m/Y') : $day->format('d/m'), 'revenue' => round((float) ($rows[$key]->revenue ?? 0), 2), 'invoices' => (int) ($rows[$key]->invoices ?? 0)];
        }

        return $points;
    }

    /** @return list<array{id: int, name: string, revenue: float}> */
    private function topProducts(Carbon $from, Carbon $to): array
    {
        if (! $this->user->can('reports.sales')) {
            return [];
        }

        return $this->report('product-sales', $from, $to)['query']->reorder()->orderByDesc('revenue')->limit(5)->get()
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => $row->name, 'revenue' => round((float) $row->revenue, 2)])->all();
    }

    /** @return array<string, float> customers' open balances by age, as of today */
    private function aging(Carbon $from, Carbon $to): array
    {
        if (! $this->user->can('customers.view')) {
            return [];
        }
        $totals = DB::query()->fromSub($this->report('aging', $from, $to, ['party_type' => 'customer', 'balance_side' => 'debit'])['query']->reorder(), 'aging')
            ->selectRaw('COALESCE(SUM(age_0_30),0) as a, COALESCE(SUM(age_31_60),0) as b, COALESCE(SUM(age_61_90),0) as c, COALESCE(SUM(age_over_90),0) as d')->first();

        return array_filter(['0–30 يوماً' => round((float) $totals->a, 2), '31–60 يوماً' => round((float) $totals->b, 2),
            '61–90 يوماً' => round((float) $totals->c, 2), 'أكثر من 90 يوماً' => round((float) $totals->d, 2)], fn (float $amount): bool => $amount > 0.004);
    }

    /** @return list<array{label: string, count: int, url: ?string, tone: string}> */
    private function alerts(): array
    {
        $tenantId = (int) $this->user->tenant_id;
        $lowStock = BranchProduct::query()->join('products', 'products.id', '=', 'branch_products.product_id')
            ->where('branch_products.tenant_id', $tenantId)->whereNull('products.deleted_at')
            ->whereColumn('branch_products.stock_quantity', '<=', 'branch_products.alert_quantity')
            ->when($this->branchId, fn ($query) => $query->where('branch_products.branch_id', $this->branchId))->count();
        $alerts = [
            ['label' => 'أصناف وصلت حد التنبيه أو نفدت', 'count' => $lowStock, 'url' => $this->user->can('products.view') ? route('tenant.product', ['stock' => 'low']) : null, 'tone' => 'rose'],
            ['label' => 'طلبيات من زيارات اليوم', 'count' => CustomerVisit::query()->where('tenant_id', $tenantId)->whereDate('visit_date', Carbon::today())->where('outcome', 'order')
                ->when($this->branchId, fn ($query) => $query->where('branch_id', $this->branchId))->count(),
                'url' => $this->user->can('customer_visits.manage') ? route('tenant.customer-visits') : null, 'tone' => 'amber'],
        ];
        if ($this->user->can('employees.advances.view') || $this->user->can('payroll.view')) {
            $alerts[] = ['label' => 'طلبات سلف وإجازات بانتظار الموافقة', 'tone' => 'indigo', 'url' => null,
                'count' => EmployeeAdvance::query()->where('status', 'pending')->count() + EmployeeLeave::query()->where('status', 'pending')->count()];
        }

        return array_values(array_filter($alerts, fn (array $alert): bool => $alert['count'] > 0));
    }
}
