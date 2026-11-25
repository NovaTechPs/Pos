<?php

namespace App\Services;

use App\Models\TelegramLink;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The owner's weekly or monthly report on Telegram: sales, returns, gross profit, expenses,
 * collections, purchases, customer debts, sales per branch and the best-selling items, as a PDF
 * with the key figures in the caption.
 */
class TelegramPeriodReport
{
    public function __construct(private TelegramAlert $telegram) {}

    /**
     * Sends the report for the last full week (the 7 days up to yesterday) or last month to every
     * owner chat that asked for it. Returns how many chats it reached.
     *
     * @param  'week'|'month'  $period
     */
    public function send(int $tenantId, string $period): int
    {
        $links = TelegramLink::query()->linked()->where('tenant_id', $tenantId)->whereNotNull('user_id')->with('user')->get()
            ->filter(fn (TelegramLink $link): bool => $link->user instanceof User && $link->user->canAccessApplication()
                && $link->wants($period === 'week' ? 'weekly_report' : 'monthly_report')
                && TelegramLink::allows($link->user, $period === 'week' ? 'weekly_report' : 'monthly_report'));
        if ($links->isEmpty()) {
            return 0;
        }
        [$from, $to] = $period === 'week'
            ? [today()->subDays(7), today()->subDay()]
            : [now()->subMonthNoOverflow()->startOfMonth()->startOfDay(), now()->subMonthNoOverflow()->endOfMonth()->startOfDay()];
        $report = $this->figures($tenantId, $from, $to);
        $title = $period === 'week' ? 'التقرير الأسبوعي' : 'التقرير الشهري';
        $storeName = DB::table('tenants')->where('id', $tenantId)->value('name') ?: 'المتجر';
        $filename = PdfFilename::forReport($title, null, $from->toDateString(), $to->toDateString());
        $pdf = app('dompdf.wrapper')->loadView('pages.tenant.reports.pdf', [
            'title' => $title,
            'definition' => [
                'summary' => $report['summary'],
                'columns' => ['name' => 'الصنف', 'quantity' => 'الكمية المباعة', 'sales' => 'المبيعات', 'gross_profit' => 'الربح'],
                'notes' => [],
            ],
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'tenantName' => $storeName, 'branchName' => 'كل الفروع',
            'filename' => $filename, 'rows' => $report['top'], 'truncated' => false,
        ])->setPaper('a4', 'landscape')->output();
        $caption = "📈 {$title} — {$storeName}\n{$from->toDateString()} إلى {$to->toDateString()}"
            .collect($report['summary'])->map(fn (float $value, string $label): string => "\n{$label}: ".number_format($value, 2))->implode('')
            .$report['branches']->map(fn (stdClass $branch): string => "\n🏬 {$branch->name}: ".number_format((float) $branch->sales, 2))->implode('');

        return $links->filter(fn (TelegramLink $link): bool => $this->telegram->sendDocument($link->chat_id, $filename, $pdf, $caption))->count();
    }

    /**
     * @return array{summary: array<string, float>, top: Collection<int, stdClass>, branches: Collection<int, stdClass>}
     */
    public function figures(int $tenantId, CarbonInterface $from, CarbonInterface $to): array
    {
        $range = [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
        $orders = DB::table('orders')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('status', 'completed')->whereBetween('created_at', $range);
        $totals = (clone $orders)->selectRaw("
            COALESCE(SUM(CASE WHEN type <> 'return' THEN ABS(total) ELSE 0 END), 0) AS sales,
            COALESCE(SUM(CASE WHEN type = 'return' THEN ABS(total) ELSE 0 END), 0) AS returns,
            COALESCE(SUM(CASE WHEN type <> 'return' THEN ABS(total_cost) ELSE 0 END), 0) AS sales_cost,
            COALESCE(SUM(CASE WHEN type = 'return' THEN ABS(total_cost) ELSE 0 END), 0) AS returns_cost,
            SUM(CASE WHEN type <> 'return' THEN 1 ELSE 0 END) AS invoices")->first();
        $payments = DB::table('payments')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereBetween('created_at', $range);
        $expenses = (float) (clone $payments)->where('type', 'payment')->whereNull('party_id')->whereNull('order_id')->whereNull('purchase_id')
            ->whereNull('purchase_return_id')->whereNull('employee_payment_id')->sum('amount');
        $collections = (float) (clone $payments)->where('type', 'receipt')->whereNotNull('party_id')->whereNull('order_id')->sum('amount');
        $purchases = (float) DB::table('purchases')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereBetween('created_at', $range)->sum('total');
        $receivables = (float) DB::table('parties')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('type', ['customer', 'both'])
            ->where('current_balance', '>', 0)->sum('current_balance');
        $grossProfit = ((float) $totals->sales - (float) $totals->sales_cost) - ((float) $totals->returns - (float) $totals->returns_cost);

        $top = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('orders.tenant_id', $tenantId)->whereNull('orders.deleted_at')->where('orders.status', 'completed')->where('orders.type', '<>', 'return')
            ->whereBetween('orders.created_at', $range)
            ->groupBy('order_items.product_id', 'products.name')
            ->selectRaw('products.name AS name, SUM(order_items.quantity) AS quantity, SUM(order_items.total_price) AS sales, SUM(order_items.total_price - order_items.total_cost) AS gross_profit')
            ->orderByDesc('sales')->limit(15)->get();
        $branches = DB::table('orders')->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->where('orders.tenant_id', $tenantId)->whereNull('orders.deleted_at')->where('orders.status', 'completed')
            ->whereBetween('orders.created_at', $range)->where('orders.type', '<>', 'return')
            ->groupBy('orders.branch_id', 'branches.name')->selectRaw('branches.name AS name, SUM(ABS(orders.total)) AS sales')->orderByDesc('sales')->get();

        return [
            'summary' => [
                'عدد الفواتير' => (float) $totals->invoices,
                'المبيعات' => (float) $totals->sales,
                'المردودات' => (float) $totals->returns,
                'مجمل الربح' => round($grossProfit, 2),
                'المصاريف' => $expenses,
                'صافي الربح التقريبي' => round($grossProfit - $expenses, 2),
                'المقبوضات من الزبائن' => $collections,
                'المشتريات' => $purchases,
                'ديون الزبائن الحالية' => $receivables,
            ],
            'top' => $top,
            'branches' => $branches,
        ];
    }
}
