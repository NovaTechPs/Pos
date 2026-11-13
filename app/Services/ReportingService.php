<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * @phpstan-type ReportDefinition array{query: Builder, columns: array<string, string>, summary: array<string, mixed>, notes: list<string>}
 */
class ReportingService
{
    public readonly int $tenantId;

    public readonly ?int $branchId;

    /** @var array<string, mixed> */
    private array $filters = [];

    public const REPORTS = [
        'statement' => ['كشف حساب زبون / مورد', 'customers.view'],
        'balances' => ['الذمم المدينة والدائنة', 'customers.view'],
        'sales' => ['ملخص مبيعات نقطة البيع اليومي (زبون نقدي)', 'reports.sales'],
        'product-sales' => ['مبيعات الأصناف خلال فترة', 'reports.sales'],
        'product-profit' => ['أرباح الأصناف خلال فترة', 'reports.profit'],
        'product-history' => ['حركة صنف — مبيعات ومشتريات', 'reports.inventory'],
        'profit' => ['الأرباح والمصروفات', 'reports.profit'],
        'shifts' => ['تقرير الشيفتات: البيع والربح', 'reports.profit'],
        'purchases' => ['المشتريات ومردوداتها', 'reports.purchases'],
        'payroll' => ['مسيرات الرواتب والمستحقات', 'payroll.view'],
        'salary-payments' => ['الرواتب والسلف المصروفة', 'payroll.view'],
        'movements' => ['حركة صنف / فترة', 'reports.inventory'],
        'movement-summary' => ['ملخص حركة المخزون', 'reports.inventory'],
        'inventory' => ['تقييم المخزون والنواقص', 'reports.inventory'],
        'stagnant' => ['الأصناف الراكدة (بلا مبيعات خلال الفترة)', 'reports.inventory'],
        'expiring' => ['الصلاحية: أصناف منتهية أو تنتهي حتى تاريخ النهاية', 'reports.inventory'],
        'cashflow' => ['المقبوضات والمدفوعات', 'accounting.payments.view'],
        'daily-cash' => ['حركة الصندوق اليومية حسب طريقة الدفع', 'accounting.payments.view'],
        'expenses' => ['المصروفات التشغيلية', 'accounting.payments.view'],
        'aging' => ['أعمار الذمم المدينة والدائنة', 'customers.view'],
        'tax' => ['تقرير الضريبة: المبيعات والمشتريات', 'reports.sales'],
        'branches' => ['ملخص أداء الفروع', 'reports.profit'],
        'employees' => ['أفضل الموظفين: المبيعات والربح لكل موظف', 'reports.profit'],
        'settlements' => ['سجل التسويات اليومية', 'reports.daily_settlement'],
    ];

    /** @var array<string, list<string>> */
    public const REPORT_GROUPS = [
        'الحسابات والذمم' => ['statement', 'balances', 'aging', 'cashflow', 'daily-cash', 'expenses', 'settlements'],
        'المبيعات والأرباح' => ['sales', 'product-sales', 'product-profit', 'profit', 'shifts', 'branches', 'employees', 'tax'],
        'المشتريات والمخزون' => ['purchases', 'product-history', 'movements', 'movement-summary', 'inventory', 'stagnant', 'expiring'],
        'الموظفون' => ['payroll', 'salary-payments'],
    ];

    /** @var list<string> */
    public const FILTER_KEYS = ['from', 'to', 'party_id', 'product_id', 'search', 'party_type', 'balance_side', 'stock_filter', 'source', 'sale_kind', 'cost_method', 'voucher_type', 'payment_method'];

    /**
     * Amount a walk-in POS invoice put in the drawer. Walk-in POS invoices no longer create
     * receipt vouchers, and delivery fees are collected by the delivery company.
     */
    private const WALK_IN_COLLECTION_SQL = "CASE WHEN orders.source = 'api' THEN orders.paid_amount WHEN orders.paid_amount > orders.delivery_fee THEN orders.paid_amount - orders.delivery_fee ELSE 0 END";

    /** Purchase invoices are dated by their invoice date, like the supplier statement. */
    private const PURCHASE_DATE_SQL = 'COALESCE(purchases.invoice_date, purchases.created_at)';

    /** Net revenue of a sales document without tax and without the delivery fee collected by the courier. */
    public const ORDER_REVENUE_SQL = "CASE WHEN orders.type = 'return' THEN -(ABS(orders.total) - ABS(orders.tax_amount) - ABS(orders.delivery_fee)) ELSE orders.total - orders.tax_amount - orders.delivery_fee END";

    public function __construct(public readonly User $user, ?int $branchId = null, bool $useActiveBranchWhenUnspecified = true)
    {
        $tenant = $user->isSaaSAdmin() ? session('active_tenant_id') : $user->tenant_id;
        abort_unless($tenant && DB::table('tenants')->where('id', $tenant)->exists(), 403, 'حدد المتجر أولاً.');
        $this->tenantId = (int) $tenant;
        $activeBranchId = $user->activeBranchId();
        $this->branchId = $user->isTenantOwner() || $user->isSaaSAdmin()
            ? ($useActiveBranchWhenUnspecified && $activeBranchId ? (int) $activeBranchId : $branchId)
            : ($user->branch_id ? (int) $user->branch_id : $branchId);
        if ($this->branchId) {
            abort_unless(Branch::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->whereKey($this->branchId)->exists(), 404);
        }
    }

    /**
     * The balance a party's documents add up to across every branch, in the same sign as
     * parties.current_balance. This is the figure the statement report compares as «فرق المطابقة».
     */
    public function partyLedgerBalance(int $partyId): float
    {
        abort_unless($this->branchId === null, 422, 'Reconciliation covers every branch.');
        $party = $this->table('parties')->where('id', $partyId)->first(['id', 'type', 'opening_balance']);
        abort_unless($party !== null, 404);
        $this->filters = ['party_type' => 'all', 'party_id' => $partyId, 'search' => ''];
        $sign = $party->type === 'supplier' ? -1 : 1;
        $ledger = $sign * (float) $party->opening_balance + (float) $this->partyEvents()->sum('amount');

        return round($sign * $ledger, 2);
    }

    /**
     * Every party's statement balance across all branches in one query, keyed by party id,
     * in the same sign as parties.current_balance.
     *
     * @return array<int, float>
     */
    public function partyLedgerBalances(): array
    {
        abort_unless($this->branchId === null, 422, 'Reconciliation covers every branch.');
        $this->filters = ['party_type' => 'all', 'party_id' => null, 'search' => ''];
        $movements = $this->partyEvents()->select('party_id')
            ->selectRaw('SUM(amount) as movement')->groupBy('party_id')->pluck('movement', 'party_id');

        return $this->table('parties')->get(['id', 'type', 'opening_balance'])->mapWithKeys(function (object $party) use ($movements): array {
            $sign = $party->type === 'supplier' ? -1 : 1;

            return [(int) $party->id => round($sign * ($sign * (float) $party->opening_balance + (float) ($movements[$party->id] ?? 0)), 2)];
        })->all();
    }

    /** @return array<string, array{string, string}> */
    public function availableReports(): array
    {
        return array_filter(self::REPORTS, fn (array $report): bool => $this->user->can('reports.analytics') && $this->user->can($report[1]));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return ReportDefinition
     */
    public function prepare(string $report, array $filters): array
    {
        abort_unless(isset(self::REPORTS[$report]), 404);
        Gate::forUser($this->user)->authorize('reports.analytics');
        Gate::forUser($this->user)->authorize(self::REPORTS[$report][1]);
        $this->filters = Validator::make($filters, [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'party_id' => ['nullable', 'integer', 'min:1'],
            'product_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:200'],
            'party_type' => ['required', Rule::in(['all', 'customer', 'supplier', 'both'])],
            'balance_side' => ['required', Rule::in(['all', 'debit', 'credit', 'settled'])],
            'stock_filter' => ['required', Rule::in(['all', 'low', 'zero', 'negative'])],
            'source' => ['nullable', Rule::in(['', 'pos', 'web', 'api', 'online'])],
            'sale_kind' => ['nullable', Rule::in(['', 'retail', 'wholesale'])],
            'cost_method' => ['nullable', Rule::in(['recorded', 'latest_purchase', 'weighted_average'])],
            'voucher_type' => ['nullable', Rule::in(['', 'receipt', 'payment'])],
            'payment_method' => ['nullable', Rule::in(['', 'cash', 'card', 'bank_transfer', 'cheque'])],
        ])->validate();
        foreach (['party_id' => 'parties', 'product_id' => 'products'] as $key => $table) {
            if (! empty($this->filters[$key])) {
                abort_unless($this->table($table)->where('id', $this->filters[$key])->exists(), 404);
            }
        }

        return match ($report) {
            'statement' => $this->statement(), 'balances' => $this->balances(),
            'sales' => $this->sales(), 'product-sales' => $this->productSales(),
            'product-history' => $this->productHistory(), 'product-profit' => $this->productProfit(),
            'profit' => $this->profit(), 'shifts' => $this->shifts(), 'purchases' => $this->purchases(),
            'payroll' => $this->payroll(), 'salary-payments' => $this->salaryPayments(),
            'movements' => $this->movements(), 'movement-summary' => $this->movementSummary(),
            'inventory' => $this->inventory(), 'stagnant' => $this->stagnant(), 'expiring' => $this->expiring(), 'cashflow' => $this->cashflow(),
            'daily-cash' => $this->dailyCash(), 'expenses' => $this->expenses(), 'aging' => $this->aging(),
            'tax' => $this->tax(), 'branches' => $this->branches(), 'employees' => $this->employees(), 'settlements' => $this->settlements(),
        };
    }

    public static function formatCell(string $column, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        $quantity = ['quantity', 'quantity_before', 'quantity_after', 'sold', 'returned', 'net_quantity', 'stock_quantity', 'alert_quantity', 'incoming', 'outgoing', 'gift_quantity'];
        $money = ['debit', 'credit', 'balance', 'sales', 'returns', 'net', 'revenue', 'basic_salary', 'total_allowances', 'total_deductions', 'advance_deduction', 'net_salary', 'paid_amount', 'remaining', 'amount', 'unit_price', 'cost_price', 'retail_price', 'valuation', 'opening_cash', 'expected_cash', 'actual_cash', 'difference', 'cost', 'gross_profit', 'expenses', 'net_profit', 'average_invoice',
            'cash_in', 'cash_out', 'net_cash', 'card_in', 'transfer_in', 'cheque_in', 'total_in', 'total_out', 'net_total', 'taxable', 'output_tax', 'input_tax', 'total',
            'age_0_30', 'age_31_60', 'age_61_90', 'age_over_90', 'purchases_total', 'delivery_fees', 'tax_amount', 'total_sales', 'total_returns', 'total_cash', 'total_card'];
        if (in_array($column, $quantity, true)) {
            return number_format((float) $value, 3, '.', '');
        }
        if (in_array($column, $money, true)) {
            return number_format((float) $value, 2, '.', '');
        }
        if (! in_array($column, ['status', 'payment_method', 'source_type'], true)) {
            return (string) $value;
        }

        return match ((string) $value) {
            'approved' => 'معتمد', 'paid' => 'مدفوع', 'draft' => 'مسودة', 'processing' => 'قيد المعالجة',
            'cash' => 'نقدي', 'card' => 'بطاقة', 'bank_transfer' => 'تحويل', 'cheque', 'check' => 'شيك',
            'opening' => 'افتتاحي', 'manual' => 'تصحيح', 'order' => 'بيع', 'purchase' => 'شراء',
            'sales_return' => 'مردود بيع', 'purchase_return' => 'مردود شراء', 'stock_in' => 'سند إدخال', 'stock_out' => 'سند إخراج', 'stock_transfer' => 'تحويل مخزون', 'stock_count' => 'جرد',
            'transfer_reversal' => 'عكس تحويل', 'sale_reversal' => 'عكس بيع', 'sync' => 'مزامنة',
            'open' => 'مفتوح', 'closed' => 'مغلق',
            default => (string) $value,
        };
    }

    public static function csvValue(string $column, mixed $value): string
    {
        $text = self::formatCell($column, $value);

        return preg_match('/^[\s]*[=+\-@]/u', $text) ? "'".$text : $text;
    }

    private function table(string $table): Builder
    {
        $query = DB::table($table)->where("$table.tenant_id", $this->tenantId);
        if (in_array($table, ['orders', 'purchases', 'payments', 'parties', 'products'])) {
            $query->whereNull("$table.deleted_at");
        }

        return $query;
    }

    private function branch(Builder $query, string $column): Builder
    {
        return $query->when($this->branchId, fn (Builder $query): Builder => $query->where($column, $this->branchId));
    }

    private function period(Builder $query, string|Expression $column): Builder
    {
        return $query->where($column, '>=', $this->filters['from'].' 00:00:00')
            ->where($column, '<', Carbon::parse($this->filters['to'])->addDay()->toDateString().' 00:00:00');
    }

    /**
     * @param  array<string, string>  $columns
     * @param  array<string, mixed>  $summary
     * @param  list<string>  $notes
     * @return ReportDefinition
     */
    private function definition(Builder $query, array $columns, array $summary = [], array $notes = []): array
    {
        return compact('query', 'columns', 'summary', 'notes');
    }

    private function parties(): Builder
    {
        return $this->table('parties')->when($this->branchId, fn (Builder $q): Builder => $q->where(fn (Builder $q): Builder => $q->where('parties.branch_id', $this->branchId)->orWhereNull('parties.branch_id')))
            ->when($this->filters['party_type'] !== 'all', fn (Builder $q): Builder => $q->where('type', $this->filters['party_type']))
            ->when(! empty($this->filters['party_id']), fn (Builder $q): Builder => $q->where('id', $this->filters['party_id']))
            ->when(trim($this->filters['search'] ?? '') !== '', fn (Builder $q): Builder => $q->where(fn (Builder $q): Builder => $q->where('name', 'like', '%'.$this->filters['search'].'%')->orWhere('code', 'like', '%'.$this->filters['search'].'%')->orWhere('phone', 'like', '%'.$this->filters['search'].'%')));
    }

    private function partyEvents(): Builder
    {
        // Party balance is expressed uniformly: positive = receivable, negative = payable.
        $partyIds = $this->parties()->select('parties.id');
        // A sale paid on the invoice itself without a receipt voucher (older invoices) is settled by that
        // payment, like purchases below; vouchers linked to the invoice appear separately on their own date.
        $linkedOrderReceipts = $this->table('payments')->whereNotNull('order_id')->where('type', 'receipt')
            ->select('order_id')->selectRaw('SUM(amount) as linked_received')->groupBy('order_id');
        $unlinkedPaid = 'CASE WHEN orders.paid_amount - orders.delivery_fee - COALESCE(order_paid.linked_received, 0) > 0 THEN orders.paid_amount - orders.delivery_fee - COALESCE(order_paid.linked_received, 0) ELSE 0 END';
        $orders = $this->branch($this->table('orders'), 'orders.branch_id')->where('orders.status', 'completed')
            ->whereIn('orders.type', ['pos', 'wholesale', 'online', 'return'])->whereIn('orders.customer_id', clone $partyIds)
            ->leftJoinSub($linkedOrderReceipts, 'order_paid', 'order_paid.order_id', '=', 'orders.id')
            ->selectRaw("orders.customer_id as party_id, COALESCE(orders.invoice_date, orders.created_at) as event_date, orders.invoice_number as reference, 'فاتورة مبيعات / مردود' as description, CASE WHEN orders.type = 'return' THEN -(CASE WHEN ABS(orders.total) > orders.delivery_fee THEN ABS(orders.total) - orders.delivery_fee ELSE 0 END) ELSE (CASE WHEN orders.total > orders.delivery_fee THEN orders.total - orders.delivery_fee ELSE 0 END) - ({$unlinkedPaid}) END as amount, 1 as source_order, orders.id as source_id, orders.notes as notes");
        $deliveryOrders = $this->branch($this->table('orders'), 'orders.branch_id')->where('status', 'completed')
            ->whereNotNull('delivery_party_id')->whereIn('delivery_party_id', clone $partyIds)->where('delivery_fee', '>', 0)
            ->selectRaw("delivery_party_id as party_id, COALESCE(invoice_date, created_at) as event_date, invoice_number as reference, 'رسوم توصيل' as description, -ABS(delivery_fee) as amount, 2 as source_order, id as source_id, orders.notes as notes");
        $linkedPurchasePayments = $this->table('payments')->whereNotNull('purchase_id')->select('purchase_id')
            ->selectRaw("SUM(CASE WHEN type = 'payment' THEN amount ELSE -amount END) as linked_paid")->groupBy('purchase_id');
        $purchases = $this->branch($this->table('purchases'), 'purchases.branch_id')->whereIn('supplier_id', clone $partyIds)
            ->leftJoinSub($linkedPurchasePayments, 'purchase_paid', 'purchase_paid.purchase_id', '=', 'purchases.id')
            ->selectRaw("supplier_id as party_id, COALESCE(purchases.invoice_date, purchases.created_at) as event_date, reference_number as reference, 'فاتورة مشتريات (بعد المدفوع غير المربوط بسند)' as description, -total + CASE WHEN paid_amount > COALESCE(linked_paid,0) THEN paid_amount - COALESCE(linked_paid,0) ELSE 0 END as amount, 3 as source_order, purchases.id as source_id, purchases.notes as notes");
        $returns = $this->branch($this->table('purchase_returns'), 'purchase_returns.branch_id')->whereIn('supplier_id', clone $partyIds)
            ->selectRaw("supplier_id as party_id, created_at as event_date, reference_number as reference, 'مردود مشتريات' as description, ABS(total) as amount, 4 as source_order, id as source_id, purchase_returns.notes as notes");
        $payments = $this->branch($this->table('payments'), 'payments.branch_id')
            ->leftJoin('orders as paid_order', function ($join): void {
                $join->on('paid_order.id', '=', 'payments.order_id')->where('paid_order.tenant_id', $this->tenantId);
            })
            ->leftJoin('purchases as paid_purchase', function ($join): void {
                $join->on('paid_purchase.id', '=', 'payments.purchase_id')->where('paid_purchase.tenant_id', $this->tenantId);
            })
            ->whereIn(DB::raw('COALESCE(payments.party_id, paid_order.customer_id, paid_purchase.supplier_id)'), clone $partyIds)
            ->selectRaw("COALESCE(payments.party_id, paid_order.customer_id, paid_purchase.supplier_id) as party_id, COALESCE(payments.payment_date, payments.created_at) as event_date, voucher_number as reference, CASE WHEN payments.type = 'receipt' THEN 'سند قبض' ELSE 'سند صرف / رد مبلغ' END as description, CASE WHEN payments.type = 'receipt' THEN -ABS(amount) ELSE ABS(amount) END as amount, 5 as source_order, payments.id as source_id, payments.notes as notes");

        return DB::query()->fromSub($orders->unionAll($deliveryOrders)->unionAll($purchases)->unionAll($returns)->unionAll($payments), 'events');
    }

    /** @return ReportDefinition */
    private function statement(): array
    {
        $columns = ['event_date' => 'التاريخ', 'reference' => 'المستند', 'description' => 'البيان', 'notes' => 'ملاحظات', 'debit' => 'مدين', 'credit' => 'دائن', 'balance' => 'الرصيد'];
        if (empty($this->filters['party_id'])) {
            return $this->definition(DB::query()->fromSub($this->partyEvents(), 'ledger')->whereRaw('1 = 0'), $columns, [], ['اختر الطرف لعرض كشف الحساب.']);
        }
        $party = $this->parties()->first();
        abort_unless($party !== null, 404);
        $opening = $this->branchId && $party->branch_id === null ? 0.0 : ($party->type === 'supplier' ? -1 : 1) * (float) $party->opening_balance;
        $events = $this->partyEvents();
        $opening += (float) (clone $events)->where('event_date', '<', $this->filters['from'].' 00:00:00')->sum('amount');
        $period = $this->period(clone $events, 'event_date');
        $sums = (clone $period)->selectRaw('COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) as debit, COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) as credit')->first();
        $query = $period->select('event_date', 'reference', 'description', 'notes', 'source_order', 'source_id')
            ->selectRaw('CASE WHEN amount > 0 THEN amount ELSE 0 END as debit, CASE WHEN amount < 0 THEN -amount ELSE 0 END as credit')
            ->selectRaw('? + SUM(amount) OVER (ORDER BY event_date, source_order, source_id ROWS UNBOUNDED PRECEDING) as balance', [$opening])
            ->orderBy('event_date')->orderBy('source_order')->orderBy('source_id');
        $closing = $opening + (float) $sums->debit - (float) $sums->credit;
        $recorded = ($party->type === 'supplier' ? -1 : 1) * (float) $party->current_balance;
        $allBalance = ($this->branchId && $party->branch_id === null ? 0 : ($party->type === 'supplier' ? -1 : 1) * (float) $party->opening_balance) + (float) $this->partyEvents()->sum('amount');

        return $this->definition($query, $columns, ['رصيد أول الفترة' => $opening, 'إجمالي المدين' => $sums->debit, 'إجمالي الدائن' => $sums->credit, 'رصيد آخر الفترة' => $closing, ...(! $this->branchId ? ['الرصيد المسجل حالياً' => $recorded, 'فرق المطابقة الحالي' => round($allBalance - $recorded, 2)] : [])], [
            $party->name.' — موجب: مستحق لنا؛ سالب: مستحق علينا.',
            'المدفوع غير المربوط بسند يُحتسب داخل فاتورة الشراء؛ السندات المرتبطة والمستقلة في تاريخها دون تكرار. دقة توقيت الدفعات القديمة غير الموثقة بسند تعتمد على البيانات الأصلية. فرق المطابقة تنبيه للمراجعة ولا يعدّل أي رصيد.',
            'عند تحديد فرع، لا يوزع الرصيد الافتتاحي العام عليه؛ يُعرض فقط افتتاحي الطرف المرتبط بهذا الفرع. المطابقة مع الرصيد العام متاحة عبر كل الفروع.',
            'الأطراف المسجلة كزبون ومورد معاً تُعرض بحركاتها في الاتجاهين؛ الرصيد الافتتاحي الموجب يُعامل كمستحق لنا.',
        ]);
    }

    /** @return ReportDefinition */
    private function balances(): array
    {
        $events = $this->partyEvents()->where('event_date', '<', Carbon::parse($this->filters['to'])->addDay()->toDateString().' 00:00:00')
            ->select('party_id')->selectRaw('SUM(amount) as movement')->groupBy('party_id');
        $base = $this->parties()->leftJoinSub($events, 'totals', 'totals.party_id', '=', 'parties.id')
            ->where(fn (Builder $q): Builder => $q->where('parties.created_at', '<', Carbon::parse($this->filters['to'])->addDay()->toDateString().' 00:00:00')->orWhereNotNull('totals.movement'))
            ->select('parties.id', 'parties.code', 'parties.name', 'parties.phone')
            ->selectRaw("CASE WHEN parties.type = 'supplier' THEN 'مورد' WHEN parties.type = 'both' THEN 'زبون ومورد' ELSE 'زبون' END as party_kind")
            ->selectRaw("(CASE WHEN ? = 1 AND parties.branch_id IS NULL THEN 0 WHEN parties.type = 'supplier' THEN -parties.opening_balance ELSE parties.opening_balance END) + COALESCE(totals.movement, 0) as balance", [$this->branchId ? 1 : 0]);
        $query = DB::query()->fromSub($base, 'balances');
        match ($this->filters['balance_side']) {
            'debit' => $query->whereRaw('balance > 0.005'),
            'credit' => $query->whereRaw('balance < -0.005'),
            'settled' => $query->whereRaw('ABS(balance) <= 0.005'),
            default => null,
        };
        $sums = (clone $query)->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END),0) as debit, COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END),0) as credit')->first();

        return $this->definition($query->select('*')->selectRaw('CASE WHEN balance > 0 THEN balance ELSE 0 END as debit, CASE WHEN balance < 0 THEN -balance ELSE 0 END as credit')->orderBy('name')->orderBy('id'),
            ['code' => 'الرمز', 'name' => 'الطرف', 'party_kind' => 'النوع', 'phone' => 'الهاتف', 'debit' => 'مدين / لنا', 'credit' => 'دائن / علينا', 'balance' => 'الصافي'],
            ['الذمم المدينة' => $sums->debit, 'الذمم الدائنة' => $sums->credit, 'الصافي' => (float) $sums->debit - (float) $sums->credit],
            ['أرصدة معاد بناؤها من المستندات حتى نهاية '.$this->filters['to'].'؛ تاريخ البداية لا يغيّر رصيد الذمم.', 'اختيار الفرع يشمل حركاته للأطراف المرتبطة به والعامة، دون توزيع الرصيد الافتتاحي العام على الفروع.']);
    }

    private function orders(): Builder
    {
        return $this->period($this->branch($this->table('orders'), 'orders.branch_id'), 'orders.created_at')
            ->where('orders.status', 'completed')->whereIn('orders.type', ['pos', 'wholesale', 'online', 'return'])
            ->when(! empty($this->filters['source']), fn (Builder $q): Builder => $this->sourceFilter($q))
            ->when(! empty($this->filters['sale_kind']), fn (Builder $q): Builder => $q->where('orders.sale_kind', $this->filters['sale_kind']));
    }

    /**
     * POS sales are walk-in invoices only. A POS invoice issued to a registered customer stays
     * editable from the POS screen but is reported with the sales invoices.
     */
    private function sourceFilter(Builder $query): Builder
    {
        return match ($this->filters['source']) {
            'pos' => $query->where('orders.source', 'pos')->whereNull('orders.customer_id'),
            'web' => $query->where(fn (Builder $q): Builder => $q->where('orders.source', 'web')
                ->orWhere(fn (Builder $q): Builder => $q->where('orders.source', 'pos')->whereNotNull('orders.customer_id'))),
            default => $query->where('orders.source', $this->filters['source']),
        };
    }

    private function shopSalesOrders(): Builder
    {
        return $this->orders()->where('orders.source', 'pos')->whereNull('orders.customer_id');
    }

    /** @return ReportDefinition */
    private function sales(): array
    {
        $query = $this->shopSalesOrders()->selectRaw("DATE(orders.created_at) as day, SUM(CASE WHEN type != 'return' THEN 1 ELSE 0 END) as invoices, SUM(CASE WHEN type != 'return' THEN ABS(total) ELSE 0 END) as sales, SUM(CASE WHEN type = 'return' THEN ABS(total) ELSE 0 END) as returns, SUM(CASE WHEN type = 'return' THEN -ABS(total) ELSE total END) as net")
            ->groupByRaw('DATE(orders.created_at)')->orderBy('day');
        $totals = DB::query()->fromSub(clone $query, 'daily_totals')->selectRaw('COALESCE(SUM(sales),0) as sales, COALESCE(SUM(returns),0) as returns, COALESCE(SUM(net),0) as net')->first();

        return $this->definition($query, ['day' => 'التاريخ', 'invoices' => 'عدد فواتير البيع', 'sales' => 'المبيعات', 'returns' => 'المردودات', 'net' => 'صافي المبيعات'], ['إجمالي المبيعات' => $totals->sales, 'المردودات' => $totals->returns, 'صافي المبيعات' => $totals->net], ['فواتير البيع المكتملة غير المرتبطة بعميل أو مورد مسجل فقط؛ المرتجعات غير المرتبطة بطرف تُخصم من الصافي. صافي المبيعات يشمل الضريبة ورسوم التوصيل كما في الفاتورة.']);
    }

    /** @return ReportDefinition */
    private function productHistory(): array
    {
        $sales = $this->orders()->where('orders.type', '!=', 'return')
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')->where('order_items.tenant_id', $this->tenantId)
            ->join('products', 'products.id', '=', 'order_items.product_id')->where('products.tenant_id', $this->tenantId)
            ->leftJoin('parties', fn ($join) => $join->on('parties.id', '=', 'orders.customer_id')->where('parties.tenant_id', $this->tenantId));
        $this->productFilters($sales, 'products.id');
        $sales->select('orders.id as invoice_id', 'orders.type as invoice_type', 'orders.created_at as event_date', 'orders.invoice_number as reference', 'products.product_number', 'products.name', 'order_items.id as line_id', 'order_items.quantity', 'order_items.unit_price', 'order_items.total_price as amount')
            ->selectRaw("'بيع' as kind, COALESCE(NULLIF(orders.customer_name, ''), parties.name, 'زبون عام') as party_name, CASE WHEN order_items.is_gift = true THEN 0 ELSE order_items.quantity END as sold, 0 as incoming, CASE WHEN order_items.is_gift = true THEN order_items.quantity ELSE 0 END as gift_quantity");
        $purchases = $this->period($this->branch($this->table('purchases'), 'purchases.branch_id'), 'purchases.created_at')
            ->join('purchase_items', 'purchase_items.purchase_id', '=', 'purchases.id')
            ->join('products', 'products.id', '=', 'purchase_items.product_id')->where('products.tenant_id', $this->tenantId)
            ->leftJoin('parties', fn ($join) => $join->on('parties.id', '=', 'purchases.supplier_id')->where('parties.tenant_id', $this->tenantId));
        $this->productFilters($purchases, 'products.id');
        $purchases->select('purchases.id as invoice_id')->selectRaw("'purchase' as invoice_type")
            ->addSelect('purchases.created_at as event_date', 'purchases.reference_number as reference', 'products.product_number', 'products.name', 'purchase_items.id as line_id', 'purchase_items.quantity', 'purchase_items.unit_cost as unit_price', 'purchase_items.subtotal as amount')
            ->selectRaw("'شراء' as kind, COALESCE(parties.name, 'بدون مورد') as party_name, 0 as sold, purchase_items.quantity as incoming, 0 as gift_quantity");
        $query = DB::query()->fromSub($sales->unionAll($purchases), 'product_history');
        $summary = (clone $query)->selectRaw('COALESCE(SUM(sold), 0) as sold, COALESCE(SUM(incoming), 0) as incoming, COALESCE(SUM(gift_quantity), 0) as gift_quantity')->first();

        return $this->definition($query->orderBy('event_date')->orderBy('invoice_type')->orderBy('invoice_id')->orderBy('line_id'),
            ['event_date' => 'التاريخ', 'kind' => 'الحركة', 'reference' => 'الفاتورة', 'product_number' => 'رقم الصنف', 'name' => 'الصنف', 'party_name' => 'الزبون / المورد', 'quantity' => 'الكمية', 'gift_quantity' => 'الهدايا', 'unit_price' => 'سعر الوحدة', 'amount' => 'قيمة السطر'],
            ['الكمية المباعة' => $summary->sold, 'الكمية المشتراة' => $summary->incoming, 'كمية الهدايا' => $summary->gift_quantity],
            ['فواتير المبيعات المكتملة والمشتريات خلال الفترة المحددة، دون المرتجعات أو الفواتير الملغاة والمحذوفة. قيمة السطر قبل توزيع خصم الفاتورة العام والضريبة والتوصيل.']);
    }

    /** @return ReportDefinition */
    private function productSales(): array
    {
        $query = $this->orders()->join('order_items', 'order_items.order_id', '=', 'orders.id')->where('order_items.tenant_id', $this->tenantId)
            ->join('products', 'products.id', '=', 'order_items.product_id')->where('products.tenant_id', $this->tenantId);
        $this->productFilters($query, 'products.id');
        $query->select('products.id', 'products.product_number', 'products.name')
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' AND COALESCE(order_items.is_gift, false) = false THEN ABS(order_items.quantity) ELSE 0 END) as sold, SUM(CASE WHEN orders.type != 'return' AND order_items.is_gift = true THEN ABS(order_items.quantity) ELSE 0 END) as gift_quantity, SUM(CASE WHEN orders.type = 'return' THEN ABS(order_items.quantity) ELSE 0 END) as returned, SUM(CASE WHEN orders.type = 'return' AND order_items.returned_to_stock = true THEN ABS(order_items.quantity) ELSE 0 END) as returned_usable, SUM(CASE WHEN orders.type = 'return' AND order_items.returned_to_stock = false THEN ABS(order_items.quantity) ELSE 0 END) as returned_damaged, SUM(CASE WHEN orders.type = 'return' THEN -ABS(order_items.quantity) ELSE order_items.quantity END) as net_quantity, SUM(CASE WHEN orders.type = 'return' THEN -ABS(order_items.total_price) ELSE order_items.total_price END) as revenue")
            ->groupBy('products.id', 'products.product_number', 'products.name')->orderByDesc('sold')->orderBy('products.id');
        $totals = DB::query()->fromSub(clone $query, 'product_totals')->selectRaw('COALESCE(SUM(sold),0) as sold, COALESCE(SUM(gift_quantity),0) as gift_quantity, COALESCE(SUM(returned),0) as returned, COALESCE(SUM(returned_usable),0) as returned_usable, COALESCE(SUM(returned_damaged),0) as returned_damaged, COALESCE(SUM(net_quantity),0) as net_quantity, COALESCE(SUM(revenue),0) as revenue')->first();

        return $this->definition($query, ['product_number' => 'رقم الصنف', 'name' => 'الصنف', 'sold' => 'الكمية المباعة', 'gift_quantity' => 'كمية الهدايا', 'returned' => 'الكمية المرتجعة', 'returned_usable' => 'مرتجع سليم', 'returned_damaged' => 'مرتجع تالف', 'net_quantity' => 'صافي الكمية', 'revenue' => 'قيمة السطور الصافية'], ['الكمية المباعة' => $totals->sold, 'الهدايا' => $totals->gift_quantity, 'الكمية المرتجعة' => $totals->returned, 'مرتجع سليم' => $totals->returned_usable, 'مرتجع تالف' => $totals->returned_damaged, 'صافي الكمية' => $totals->net_quantity, 'قيمة السطور' => $totals->revenue], ['اختر التاريخ نفسه للبداية والنهاية لتقرير يوم واحد. قيمة السطور قبل توزيع خصم الفاتورة العام والضريبة والتوصيل؛ الهدايا معروضة في عمود مستقل؛ صافي الكمية يشمل الهدايا والمردودات.']);
    }

    /** @return ReportDefinition */
    private function productProfit(): array
    {
        $sales = $this->productSales();
        $costs = DB::query()->fromSub($this->itemCosts(), 'cost_lines')->select('product_id')->selectRaw('SUM(signed_cost) as cost')->groupBy('product_id');
        $query = DB::query()->fromSub($sales['query']->reorder(), 'product_sales')
            ->leftJoinSub($costs, 'product_costs', 'product_costs.product_id', '=', 'product_sales.id')
            ->select('product_sales.*')->selectRaw('COALESCE(product_costs.cost, 0) as cost, revenue - COALESCE(product_costs.cost, 0) as gross_profit')
            ->orderByDesc('gross_profit')->orderBy('product_sales.id');
        $totals = DB::query()->fromSub(clone $query, 'product_profit_totals')
            ->selectRaw('COALESCE(SUM(cost), 0) as cost, COALESCE(SUM(gross_profit), 0) as gross_profit')->first();

        return $this->definition($query, $sales['columns'] + ['cost' => 'التكلفة', 'gross_profit' => 'ربح السطور'],
            $sales['summary'] + ['التكلفة' => $totals->cost, 'ربح السطور' => $totals->gross_profit],
            [...$sales['notes'], ...$this->costNotes(), 'ربح السطور قبل توزيع خصم الفاتورة العام والمصروفات.']);
    }

    private function itemCosts(): Builder
    {
        $items = $this->orders()->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.tenant_id', $this->tenantId)
            ->select('order_items.product_id', 'orders.type', 'order_items.quantity', 'order_items.total_cost');
        $method = $this->filters['cost_method'] ?? 'recorded';
        if ($method === 'recorded') {
            return DB::query()->fromSub($items, 'cost_items')->select('product_id')
                ->selectRaw("CASE WHEN type = 'return' THEN -ABS(total_cost) ELSE ABS(total_cost) END as signed_cost");
        }

        $purchaseCost = $this->branch($this->table('purchases'), 'purchases.branch_id')
            ->join('purchase_items', 'purchase_items.purchase_id', '=', 'purchases.id')
            ->whereColumn('purchase_items.product_id', 'order_items.product_id')
            ->where('purchase_items.quantity', '>', 0)
            ->whereRaw('COALESCE(purchases.invoice_date, purchases.created_at) < ?', [Carbon::parse($this->filters['to'])->addDay()->toDateString()]);
        if ($method === 'latest_purchase') {
            $purchaseCost->select('purchase_items.unit_cost')->orderByRaw('COALESCE(purchases.invoice_date, purchases.created_at) DESC')
                ->orderByDesc('purchases.id')->orderByDesc('purchase_items.id')->limit(1);
        } else {
            $purchaseCost->selectRaw('1.0 * SUM(purchase_items.quantity * purchase_items.unit_cost) / NULLIF(SUM(purchase_items.quantity), 0)');
        }
        $items->selectSub($purchaseCost, 'purchase_cost');

        return DB::query()->fromSub($items, 'cost_items')->select('product_id')
            ->selectRaw("(CASE WHEN type = 'return' THEN -1 ELSE 1 END) * COALESCE(ABS(quantity) * purchase_cost, ABS(total_cost)) as signed_cost");
    }

    /** @return list<string> */
    private function costNotes(): array
    {
        $method = $this->filters['cost_method'] ?? 'recorded';
        if ($method === 'recorded') {
            return ['طريقة التكلفة: التكلفة المسجلة وقت البيع.'];
        }

        return [
            $method === 'latest_purchase' ? 'طريقة التكلفة: آخر سعر شراء.' : 'طريقة التكلفة: متوسط أسعار الشراء المرجّح بالكميات = مجموع (سعر الوحدة × الكمية المشتراة) ÷ مجموع الكميات المشتراة.',
            'تُستخدم فواتير الشراء غير المحذوفة للفرع المختار حتى نهاية الفترة، بما فيها المشتريات السابقة لبداية الفترة. عند اختيار كل الفروع تُجمع مشترياتها. لا تُطرح مردودات الشراء من أوزان المتوسط.',
            'عند عدم وجود مشتريات للصنف تُستخدم تكلفته المسجلة وقت البيع. تشمل التكلفة الهدايا وتُعكس تكلفة مردودات البيع. هذا تقييم تقديري ولا يغيّر تكاليف الفواتير المحفوظة.',
        ];
    }

    private function productFilters(Builder $query, string $idColumn): void
    {
        $query->when(! empty($this->filters['product_id']), fn (Builder $q): Builder => $q->where($idColumn, $this->filters['product_id']))
            ->when(trim($this->filters['search'] ?? '') !== '', fn (Builder $q): Builder => $q->where(fn (Builder $q): Builder => $q->where('products.name', 'like', '%'.$this->filters['search'].'%')->orWhere('products.product_number', 'like', '%'.$this->filters['search'].'%')->orWhereExists(fn (Builder $codes): Builder => $codes->selectRaw('1')->from('product_barcodes')->whereColumn('product_barcodes.product_id', 'products.id')->where('product_barcodes.tenant_id', $this->tenantId)->where('product_barcodes.barcode', 'like', '%'.$this->filters['search'].'%'))));
    }

    private function employeeQuery(string $table): Builder
    {
        return DB::table($table)->join('employees', 'employees.id', '=', "$table.employee_id")
            ->where('employees.tenant_id', $this->tenantId)
            ->when($this->branchId, fn (Builder $q): Builder => $q->where('employees.branch_id', $this->branchId));
    }

    /** @return ReportDefinition */
    private function payroll(): array
    {
        $query = $this->employeeQuery('employee_payrolls')->join('payroll_periods', 'payroll_periods.id', '=', 'employee_payrolls.payroll_period_id')
            ->where('payroll_periods.tenant_id', $this->tenantId)->whereBetween('payroll_periods.end_date', [$this->filters['from'], $this->filters['to']]);
        $summary = (clone $query)->selectRaw('COALESCE(SUM(employee_payrolls.net_salary),0) as due, COALESCE(SUM(employee_payrolls.paid_amount),0) as paid')->first();

        return $this->definition($query->select('employee_payrolls.id', 'employees.first_name', 'employees.last_name', 'payroll_periods.title', 'payroll_periods.status', 'employee_payrolls.basic_salary', 'employee_payrolls.total_allowances', 'employee_payrolls.total_deductions', 'employee_payrolls.advance_deduction', 'employee_payrolls.net_salary', 'employee_payrolls.paid_amount')
            ->selectRaw('employee_payrolls.net_salary - employee_payrolls.paid_amount as remaining')->orderBy('payroll_periods.end_date')->orderBy('employee_payrolls.id'),
            ['first_name' => 'اسم الموظف', 'last_name' => 'العائلة', 'title' => 'مسير الراتب', 'status' => 'حالة المسير', 'basic_salary' => 'الأساسي', 'total_allowances' => 'البدلات', 'total_deductions' => 'الخصومات', 'advance_deduction' => 'قسط السلفة', 'net_salary' => 'صافي المستحق', 'paid_amount' => 'المدفوع حالياً', 'remaining' => 'المتبقي حالياً'],
            ['صافي الرواتب' => $summary->due, 'المدفوع حالياً' => $summary->paid, 'المتبقي حالياً' => (float) $summary->due - (float) $summary->paid],
            ['الفترة تُحدَّد بتاريخ نهاية المسير، وتشمل المسودات مع عرض حالتها. المدفوع والمتبقي يعكسان الوضع الحالي؛ استخدم تقرير الصرف للدفعات خلال الفترة.']);
    }

    /** @return ReportDefinition */
    private function salaryPayments(): array
    {
        $query = $this->period($this->employeeQuery('employee_payments'), 'employee_payments.payment_date');
        $summary = (clone $query)->selectRaw("COALESCE(SUM(CASE WHEN payment_type = 'payroll' THEN amount ELSE 0 END),0) as salaries, COALESCE(SUM(CASE WHEN payment_type = 'advance' THEN amount ELSE 0 END),0) as advances")->first();

        return $this->definition($query->select('employee_payments.id', 'employees.first_name', 'employees.last_name', 'payment_date', 'reference_number', 'payment_method', 'amount')
            ->selectRaw("CASE WHEN payment_type = 'payroll' THEN 'راتب' ELSE 'سلفة' END as kind")->orderBy('payment_date')->orderBy('employee_payments.id'),
            ['payment_date' => 'التاريخ', 'first_name' => 'اسم الموظف', 'last_name' => 'العائلة', 'kind' => 'النوع', 'reference_number' => 'المرجع', 'payment_method' => 'الطريقة', 'amount' => 'المبلغ'],
            ['الرواتب المصروفة' => $summary->salaries, 'السلف المصروفة' => $summary->advances]);
    }

    /** @return ReportDefinition */
    private function profit(): array
    {
        $orders = $this->orders();
        $sales = (clone $orders)->selectRaw('COALESCE(SUM('.self::ORDER_REVENUE_SQL.'),0) as revenue')
            ->selectRaw("COALESCE(SUM(CASE WHEN type != 'return' THEN ABS(total) ELSE 0 END),0) as gross_sales, COALESCE(SUM(CASE WHEN type = 'return' THEN ABS(total) ELSE 0 END),0) as returns")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'return' THEN -ABS(tax_amount) ELSE tax_amount END),0) as tax, COALESCE(SUM(CASE WHEN type = 'return' THEN -ABS(delivery_fee) ELSE delivery_fee END),0) as delivery")
            ->selectRaw("COALESCE(SUM(CASE WHEN type != 'return' THEN discount ELSE 0 END),0) as discount, COALESCE(SUM(CASE WHEN type != 'return' THEN total_cost ELSE 0 END),0) as cost")->first();
        $returnCost = (clone $orders)->where('orders.type', 'return')->join('order_items', 'order_items.order_id', '=', 'orders.id')->where('order_items.tenant_id', $this->tenantId)->sum(DB::raw('ABS(order_items.total_cost)'));
        $expenses = $this->expenseVouchers()->sum('payments.amount');
        $cost = ($this->filters['cost_method'] ?? 'recorded') === 'recorded'
            ? (float) $sales->cost - (float) $returnCost
            : (float) DB::query()->fromSub($this->itemCosts(), 'cost_lines')->sum('signed_cost');
        $gross = (float) $sales->revenue - $cost;
        $rows = [
            ['label' => 'إجمالي فواتير البيع (شامل الضريبة والتوصيل)', 'amount' => $sales->gross_sales],
            ['label' => 'مردودات المبيعات', 'amount' => $sales->returns],
            ['label' => 'الخصومات الممنوحة على الفواتير (للعرض؛ مخصومة من الفواتير)', 'amount' => $sales->discount],
            ['label' => 'رسوم التوصيل المحصلة لشركة التوصيل (تُستبعد)', 'amount' => $sales->delivery],
            ['label' => 'ضريبة المبيعات الصافية (تُستبعد)', 'amount' => $sales->tax],
            ['label' => 'صافي الإيرادات دون ضريبة وتوصيل', 'amount' => $sales->revenue],
            ['label' => 'تكلفة البضاعة بعد عكس تكلفة المردود', 'amount' => $cost],
            ['label' => 'مجمل الربح', 'amount' => $gross],
            ['label' => 'المصروفات التشغيلية المسجلة', 'amount' => $expenses],
            ['label' => 'الربح بعد المصروفات وقبل الرواتب', 'amount' => $gross - (float) $expenses],
        ];
        if ($this->user->can('payroll.view')) {
            $salaries = $this->employeeQuery('employee_payments')->where('payment_type', 'payroll')
                ->whereDate('employee_payments.payment_date', '>=', $this->filters['from'])
                ->whereDate('employee_payments.payment_date', '<=', $this->filters['to'])->sum('amount');
            $rows[] = ['label' => 'رواتب مصروفة خلال الفترة (دون السلف)', 'amount' => $salaries];
            $rows[] = ['label' => 'صافي تقديري بعد الرواتب المصروفة', 'amount' => $gross - (float) $expenses - (float) $salaries];
        }
        $query = null;
        foreach ($rows as $index => $row) {
            $part = DB::query()->selectRaw('? as label, CAST(? AS DECIMAL(18,2)) as amount, ? as row_index', [$row['label'], $row['amount'], $index]);
            $query = $query ? $query->unionAll($part) : $part;
        }

        return $this->definition(DB::query()->fromSub($query, 'profit')->orderBy('row_index'), ['label' => 'البند', 'amount' => 'القيمة'], [], [
            ...$this->costNotes(),
            'تقرير إداري تقديري؛ لا يعتبر شراء المخزون مصروفاً فورياً.',
            'رسوم التوصيل تحصّلها شركة التوصيل ولا تدخل في الإيراد أو الربح؛ الضريبة أمانة مستحقة ولا تُعدّ إيراداً.',
            'الرواتب هنا على أساس الصرف، وليست تسوية استحقاق محاسبية. السلف وسداد الموردين ليست مصروفات تشغيلية. لا تسجّل الراتب نفسه مرة ثانية كمصروف عام.',
            'التكاليف التاريخية الناقصة أو المصروفات غير المسجلة تؤثر على الدقة. لا يُحسب هذا التقرير من المقبوضات بدلاً من المبيعات.',
        ]);
    }

    /** @return ReportDefinition */
    private function shifts(): array
    {
        $shiftIds = $this->period($this->branch($this->table('shifts'), 'shifts.branch_id'), 'shifts.opened_at')->select('shifts.id');
        $orders = $this->branch($this->table('orders'), 'orders.branch_id')
            ->where('orders.status', 'completed')
            ->whereIn('orders.shift_id', clone $shiftIds)
            ->whereIn('orders.type', ['pos', 'wholesale', 'online', 'return'])
            ->when(! empty($this->filters['source']), fn (Builder $query): Builder => $this->sourceFilter($query))
            ->when(! empty($this->filters['sale_kind']), fn (Builder $query): Builder => $query->where('orders.sale_kind', $this->filters['sale_kind']))
            ->select('orders.shift_id')
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' THEN 1 ELSE 0 END) as invoices")
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' THEN ABS(orders.total) ELSE 0 END) as sales")
            ->selectRaw("SUM(CASE WHEN orders.type = 'return' THEN ABS(orders.total) ELSE 0 END) as returns")
            ->selectRaw("SUM(CASE WHEN orders.type = 'return' THEN -ABS(orders.total) ELSE orders.total END) as net")
            ->selectRaw('SUM('.self::ORDER_REVENUE_SQL.') as revenue')
            ->selectRaw("SUM(CASE WHEN orders.type = 'return' THEN -ABS(orders.total_cost) ELSE orders.total_cost END) as cost")
            ->groupBy('orders.shift_id');

        $expenses = $this->branch($this->table('payments'), 'payments.branch_id')
            ->where('payments.type', 'payment')
            ->whereIn('payments.shift_id', clone $shiftIds)
            ->whereNull('payments.party_id')
            ->whereNull('payments.order_id')
            ->whereNull('payments.purchase_id')
            ->whereNull('payments.purchase_return_id')
            ->select('payments.shift_id')
            ->selectRaw('SUM(payments.amount) as expenses')
            ->groupBy('payments.shift_id');

        $query = $this->period($this->branch($this->table('shifts'), 'shifts.branch_id'), 'shifts.opened_at')
            ->join('branches', function ($join): void {
                $join->on('branches.id', '=', 'shifts.branch_id')->where('branches.tenant_id', $this->tenantId);
            })
            ->leftJoin('users as shift_user', 'shift_user.id', '=', 'shifts.opened_by')
            ->leftJoinSub($orders, 'shift_orders', 'shift_orders.shift_id', '=', 'shifts.id')
            ->leftJoinSub($expenses, 'shift_expenses', 'shift_expenses.shift_id', '=', 'shifts.id')
            ->select('shifts.id', 'shifts.opened_at', 'shifts.closed_at', 'shifts.status', 'branches.name as branch_name', 'shift_user.name as cashier_name', 'shifts.opening_cash', 'shifts.expected_cash', 'shifts.actual_cash', 'shifts.difference')
            ->selectRaw('COALESCE(shift_orders.invoices, 0) as invoices')
            ->selectRaw('COALESCE(shift_orders.sales, 0) as sales')
            ->selectRaw('COALESCE(shift_orders.returns, 0) as returns')
            ->selectRaw('COALESCE(shift_orders.net, 0) as net')
            ->selectRaw('COALESCE(shift_orders.cost, 0) as cost')
            ->selectRaw('COALESCE(shift_orders.revenue, 0) - COALESCE(shift_orders.cost, 0) as gross_profit')
            ->selectRaw('COALESCE(shift_expenses.expenses, 0) as expenses')
            ->selectRaw('COALESCE(shift_orders.revenue, 0) - COALESCE(shift_orders.cost, 0) - COALESCE(shift_expenses.expenses, 0) as net_profit');

        $totals = DB::query()->fromSub(clone $query, 'shift_totals')
            ->selectRaw('COALESCE(SUM(sales), 0) as sales, COALESCE(SUM(returns), 0) as returns, COALESCE(SUM(net), 0) as net, COALESCE(SUM(gross_profit), 0) as gross_profit, COALESCE(SUM(expenses), 0) as expenses, COALESCE(SUM(net_profit), 0) as net_profit')
            ->first();

        return $this->definition($query->orderByDesc('shifts.opened_at')->orderByDesc('shifts.id'), [
            'id' => 'رقم الشيفت', 'opened_at' => 'وقت الفتح', 'closed_at' => 'وقت الإغلاق', 'branch_name' => 'الفرع', 'cashier_name' => 'الكاشير', 'status' => 'الحالة', 'invoices' => 'فواتير البيع', 'sales' => 'المبيعات', 'returns' => 'المردودات', 'net' => 'صافي المبيعات', 'cost' => 'التكلفة', 'gross_profit' => 'مجمل الربح', 'expenses' => 'مصروفات الشيفت', 'net_profit' => 'الربح بعد المصروفات', 'opening_cash' => 'افتتاحي الصندوق', 'expected_cash' => 'النقد المتوقع', 'actual_cash' => 'النقد الفعلي', 'difference' => 'فرق الصندوق',
        ], [
            'إجمالي المبيعات' => $totals->sales, 'المردودات' => $totals->returns, 'صافي المبيعات' => $totals->net, 'مجمل الربح' => $totals->gross_profit, 'مصروفات الشيفت' => $totals->expenses, 'الربح بعد المصروفات' => $totals->net_profit,
        ], [
            'الفترة تُحدَّد بوقت فتح الشيفت، وتُحتسب كل فواتير الشيفت ومصروفاته حتى لو تجاوزت منتصف الليل. مجمل الربح دون الضريبة ورسوم التوصيل.',
            'يعتمد التقرير على الفواتير المكتملة المرتبطة بالشيفت. تقرير اليوم متاح أيضاً من تقريري «ملخص مبيعات نقطة البيع اليومي» و«الأرباح والمصروفات» باختيار زر اليوم.',
            'التكلفة هي تكلفة البيع المحفوظة وقت الفاتورة. مصروفات الشيفت تشمل سندات الصرف التشغيلية غير المرتبطة بطرف أو فاتورة، ولا تشمل الرواتب أو سداد الموردين.',
        ]);
    }

    /** @return ReportDefinition */
    private function purchases(): array
    {
        $purchases = $this->period($this->branch($this->table('purchases'), 'purchases.branch_id'), DB::raw(self::PURCHASE_DATE_SQL))
            ->leftJoin('parties', function ($join): void {
                $join->on('parties.id', '=', 'purchases.supplier_id')->where('parties.tenant_id', $this->tenantId);
            })
            ->selectRaw('purchases.id, '.self::PURCHASE_DATE_SQL." as event_date, reference_number as reference, parties.name as name, 'شراء' as kind, total as amount, paid_amount, total - paid_amount as remaining");
        $returns = $this->period($this->branch($this->table('purchase_returns'), 'purchase_returns.branch_id'), 'purchase_returns.created_at')
            ->leftJoin('parties', function ($join): void {
                $join->on('parties.id', '=', 'purchase_returns.supplier_id')->where('parties.tenant_id', $this->tenantId);
            })
            ->selectRaw("purchase_returns.id, purchase_returns.created_at as event_date, reference_number as reference, parties.name as name, 'مردود شراء' as kind, -ABS(total) as amount, -ABS(paid_amount) as paid_amount, -ABS(total) + ABS(paid_amount) as remaining");
        $query = DB::query()->fromSub($purchases->unionAll($returns), 'purchase_report');
        $sum = (float) (clone $query)->sum('amount');

        return $this->definition($query->orderBy('event_date')->orderBy('kind')->orderBy('id'), ['event_date' => 'التاريخ', 'reference' => 'المرجع', 'name' => 'المورد', 'kind' => 'النوع', 'amount' => 'القيمة', 'paid_amount' => 'المدفوع بالفاتورة', 'remaining' => 'المتبقي بالفاتورة'], ['صافي المشتريات' => $sum], ['السندات المستقلة في كشف المورد؛ المردود يخفض صافي المشتريات ولا يُفترض أنه قُبض نقداً.']);
    }

    private function movementBase(): Builder
    {
        $query = $this->period($this->branch($this->table('stock_movements'), 'stock_movements.branch_id'), 'stock_movements.created_at')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')->where('products.tenant_id', $this->tenantId)
            ->join('branches', 'branches.id', '=', 'stock_movements.branch_id')->where('branches.tenant_id', $this->tenantId);
        $this->productFilters($query, 'products.id');

        return $query;
    }

    /** @return ReportDefinition */
    private function movements(): array
    {
        $query = $this->movementBase();
        $sums = (clone $query)->selectRaw('COALESCE(SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END),0) as incoming, COALESCE(SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END),0) as outgoing')->first();

        return $this->definition($query->select('stock_movements.id', 'stock_movements.created_at as event_date', 'products.product_number', 'products.name', 'branches.name as branch_name', 'source_type', 'source_reference', 'quantity_before', 'quantity', 'quantity_after')->orderBy('stock_movements.created_at')->orderBy('stock_movements.id'),
            ['event_date' => 'التاريخ', 'product_number' => 'رقم الصنف', 'name' => 'الصنف', 'branch_name' => 'الفرع', 'source_type' => 'مصدر الحركة', 'source_reference' => 'المستند', 'quantity_before' => 'قبل', 'quantity' => 'الحركة', 'quantity_after' => 'بعد'],
            ['إجمالي الوارد' => $sums->incoming, 'إجمالي الصادر' => $sums->outgoing], ['الحركة الموجبة وارد والسالبة صادر، بما يشمل التحويلات والتصحيحات؛ الصادر لا يعني المبيعات فقط. الأرصدة قبل وبعد مأخوذة من السجل التاريخي الثابت.']);
    }

    /** @return ReportDefinition */
    private function movementSummary(): array
    {
        $query = $this->movementBase()->select('products.id', 'products.product_number', 'products.name', 'branches.name as branch_name', 'stock_movements.branch_id')
            ->selectRaw('SUM(CASE WHEN quantity > 0 THEN quantity ELSE 0 END) as incoming, SUM(CASE WHEN quantity < 0 THEN -quantity ELSE 0 END) as outgoing, SUM(quantity) as net_quantity, COUNT(*) as movements')
            ->groupBy('products.id', 'products.product_number', 'products.name', 'branches.name', 'stock_movements.branch_id')->orderBy('products.name')->orderBy('stock_movements.branch_id');

        return $this->definition($query, ['product_number' => 'رقم الصنف', 'name' => 'الصنف', 'branch_name' => 'الفرع', 'incoming' => 'الوارد', 'outgoing' => 'الصادر', 'net_quantity' => 'صافي الحركة', 'movements' => 'عدد الحركات'], [], ['ملخص الحركات المسجلة خلال الفترة فقط. اعرض حركة الصنف لمعرفة الرصيد قبل وبعد كل مستند.']);
    }

    /** @return ReportDefinition */
    private function inventory(): array
    {
        $query = $this->branch($this->table('branch_products'), 'branch_products.branch_id')
            ->join('products', 'products.id', '=', 'branch_products.product_id')->where('products.tenant_id', $this->tenantId)
            ->join('branches', 'branches.id', '=', 'branch_products.branch_id')->where('branches.tenant_id', $this->tenantId);
        $this->productFilters($query, 'products.id');
        match ($this->filters['stock_filter']) {
            'low' => $query->whereColumn('stock_quantity', '<=', 'alert_quantity'),
            'zero' => $query->where('stock_quantity', 0), 'negative' => $query->where('stock_quantity', '<', 0), default => null,
        };
        $sum = (float) (clone $query)->sum(DB::raw('branch_products.stock_quantity * products.cost_price'));

        return $this->definition($query->select('branch_products.id', 'products.product_number', 'products.name', 'branches.name as branch_name', 'stock_quantity', 'alert_quantity', 'products.cost_price', 'retail_price')->selectRaw('stock_quantity * products.cost_price as valuation')->orderBy('stock_quantity')->orderBy('branch_products.id'),
            ['product_number' => 'رقم الصنف', 'name' => 'الصنف', 'branch_name' => 'الفرع', 'stock_quantity' => 'الرصيد الحالي', 'alert_quantity' => 'حد التنبيه', 'cost_price' => 'التكلفة الحالية', 'retail_price' => 'سعر البيع', 'valuation' => 'قيمة المخزون'], ['قيمة المخزون الحالي' => $sum], ['هذا رصيد حالي وتقييم بالتكلفة الحالية، وليس تقييم مخزون تاريخياً في نهاية الفترة. استخدم حركة الصنف للأرصدة التاريخية.']);
    }

    /**
     * Stock that did not sell during the period, with the last sale ever recorded for it, so idle
     * capital can be cleared with offers or returned to the supplier.
     *
     * @return ReportDefinition
     */
    private function stagnant(): array
    {
        $sales = fn (Builder $query): Builder => $query->from('stock_movements as sold')
            ->whereColumn('sold.branch_product_id', 'branch_products.id')
            ->where('sold.source_type', 'order')->where('sold.quantity', '<', 0);
        $query = $this->branch($this->table('branch_products'), 'branch_products.branch_id')
            ->join('products', 'products.id', '=', 'branch_products.product_id')->where('products.tenant_id', $this->tenantId)->whereNull('products.deleted_at')
            ->join('branches', 'branches.id', '=', 'branch_products.branch_id')->where('branches.tenant_id', $this->tenantId)
            ->where('branch_products.stock_quantity', '>', 0)
            ->whereNotExists(fn (Builder $sold): Builder => $this->period($sales($sold)->selectRaw('1'), 'sold.created_at'));
        $this->productFilters($query, 'products.id');
        $totals = (clone $query)->selectRaw('COUNT(*) as items, COALESCE(SUM(branch_products.stock_quantity * products.cost_price), 0) as valuation')->first();

        return $this->definition($query->select('branch_products.id', 'products.product_number', 'products.name', 'branches.name as branch_name', 'branch_products.stock_quantity', 'products.cost_price')
            ->selectRaw('branch_products.stock_quantity * products.cost_price as valuation')
            ->selectSub(fn (Builder $last): Builder => $sales($last)->selectRaw('MAX(sold.created_at)'), 'last_sale_at')
            ->orderByDesc('valuation')->orderBy('branch_products.id'),
            ['product_number' => 'رقم الصنف', 'name' => 'الصنف', 'branch_name' => 'الفرع', 'stock_quantity' => 'الرصيد الحالي', 'cost_price' => 'التكلفة الحالية', 'valuation' => 'قيمة المخزون الراكد', 'last_sale_at' => 'آخر بيع'],
            ['عدد الأصناف الراكدة' => (int) $totals->items, 'قيمة المخزون الراكد' => (float) $totals->valuation],
            ['أصناف لها رصيد في الفرع ولم يُبع منها شيء خلال الفترة المختارة، مرتبة من الأعلى قيمة. «آخر بيع» فارغ يعني أن الصنف لم يُبع أبداً في هذا الفرع.']);
    }

    /**
     * Purchased batches whose expiry date falls on or before the end of the period while the branch
     * still holds the product. The stock is the product's whole branch balance, not the batch alone.
     *
     * @return ReportDefinition
     */
    private function expiring(): array
    {
        $today = Carbon::today()->toDateString();
        $query = $this->branch(DB::table('purchase_items'), 'purchases.branch_id')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchases.tenant_id', $this->tenantId)->whereNull('purchases.deleted_at')
            ->join('products', 'products.id', '=', 'purchase_items.product_id')->where('products.tenant_id', $this->tenantId)->whereNull('products.deleted_at')
            ->join('branches', 'branches.id', '=', 'purchases.branch_id')
            ->join('branch_products', fn ($join) => $join->on('branch_products.branch_id', '=', 'purchases.branch_id')->on('branch_products.product_id', '=', 'purchase_items.product_id'))
            ->where('branch_products.tenant_id', $this->tenantId)->where('branch_products.stock_quantity', '>', 0)
            ->whereNotNull('purchase_items.expiry_date')->whereDate('purchase_items.expiry_date', '<=', $this->filters['to']);
        $this->productFilters($query, 'products.id');
        $expired = (clone $query)->whereDate('purchase_items.expiry_date', '<', $today)->count();
        $total = (clone $query)->count();

        return $this->definition($query->select('purchase_items.id', 'products.product_number', 'products.name', 'branches.name as branch_name', 'purchase_items.expiry_date', 'purchases.reference_number as reference')
            ->selectRaw("CASE WHEN purchase_items.expiry_date < ? THEN 'منتهية' ELSE 'تنتهي قريباً' END as expiry_status", [$today])
            ->selectRaw('purchase_items.quantity as quantity, branch_products.stock_quantity as stock_quantity')
            ->orderBy('purchase_items.expiry_date')->orderBy('purchase_items.id'),
            ['product_number' => 'رقم الصنف', 'name' => 'الصنف', 'branch_name' => 'الفرع', 'expiry_date' => 'تاريخ الصلاحية', 'expiry_status' => 'الحالة', 'quantity' => 'الكمية المشتراة', 'stock_quantity' => 'رصيد الصنف الحالي', 'reference' => 'فاتورة الشراء'],
            ['دفعات منتهية' => $expired, 'دفعات تنتهي حتى تاريخ النهاية' => $total - $expired],
            ['يعرض دفعات الشراء التي لها تاريخ صلاحية حتى «تاريخ النهاية»، بما فيها المنتهية، ما دام للصنف رصيد في الفرع. الرصيد هو رصيد الصنف كله في الفرع وليس الدفعة وحدها؛ تأكد على الرف.']);
    }

    /** @return ReportDefinition */
    private function cashflow(): array
    {
        $query = $this->cashEvents();
        $sums = (clone $query)->selectRaw("COALESCE(SUM(CASE WHEN voucher_type = 'receipt' THEN amount ELSE 0 END),0) as incoming, COALESCE(SUM(CASE WHEN voucher_type = 'payment' THEN amount ELSE 0 END),0) as outgoing")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN (CASE WHEN voucher_type = 'receipt' THEN amount ELSE -amount END) ELSE 0 END),0) as net_cash")->first();

        return $this->definition($query->select('event_date', 'reference', 'party_name', 'source_label', 'payment_method', 'notes', 'source_order', 'row_id')
            ->selectRaw("CASE WHEN voucher_type = 'receipt' THEN 'قبض' ELSE 'صرف' END as kind, CASE WHEN voucher_type = 'receipt' THEN amount ELSE -amount END as amount")
            ->orderBy('event_date')->orderBy('source_order')->orderBy('row_id'),
            ['event_date' => 'التاريخ', 'reference' => 'السند / الفاتورة', 'kind' => 'النوع', 'source_label' => 'المصدر', 'party_name' => 'الطرف', 'payment_method' => 'الطريقة', 'notes' => 'البيان', 'amount' => 'الحركة'],
            ['المقبوضات' => $sums->incoming, 'المدفوعات' => $sums->outgoing, 'صافي الحركة' => (float) $sums->incoming - (float) $sums->outgoing, 'صافي النقد' => $sums->net_cash],
            ['يشمل سندات القبض والصرف وتحصيل فواتير نقطة البيع للزبون النقدي (لم تعد تُنشئ سند قبض)، دون رسوم التوصيل التي تحصّلها شركة التوصيل.', 'ليس رصيد الصندوق أو الربح. دفعات الموظفين لها تقرير مستقل؛ مدفوع فواتير الشراء القديمة غير الموثق بسند لا يظهر هنا.']);
    }

    /**
     * Money that entered or left the business in the period: vouchers plus walk-in POS collections.
     * Vouchers that belong to a walk-in POS invoice are skipped because the invoice itself carries the collection.
     */
    private function cashEvents(): Builder
    {
        $payments = $this->period($this->branch($this->table('payments'), 'payments.branch_id'), 'payments.payment_date')
            ->leftJoin('orders as linked_order', function ($join): void {
                $join->on('linked_order.id', '=', 'payments.order_id')->where('linked_order.tenant_id', $this->tenantId)->whereNull('linked_order.deleted_at');
            })
            ->leftJoin('parties', function ($join): void {
                $join->on('parties.id', '=', 'payments.party_id')->where('parties.tenant_id', $this->tenantId);
            })
            ->where(fn (Builder $q): Builder => $q->whereNull('linked_order.id')->orWhere('linked_order.type', '!=', 'pos')->orWhereNotNull('linked_order.customer_id'))
            ->selectRaw("payments.branch_id, payments.payment_date as event_date, payments.voucher_number as reference, payments.type as voucher_type, payments.payment_method, COALESCE(parties.name, '') as party_name, payments.party_id, payments.notes, ABS(payments.amount) as amount")
            ->selectRaw("CASE WHEN payments.purchase_return_id IS NOT NULL THEN 'مردود مشتريات' WHEN payments.order_id IS NOT NULL THEN 'سند فاتورة بيع / مردود' WHEN payments.purchase_id IS NOT NULL THEN 'سند فاتورة شراء' WHEN payments.party_id IS NULL AND payments.type = 'payment' THEN 'مصروف' WHEN payments.type = 'receipt' THEN 'سند قبض' ELSE 'سند صرف' END as source_label")
            ->selectRaw('payments.id as row_id, 1 as source_order');
        $walkIn = $this->period($this->branch($this->table('orders'), 'orders.branch_id'), 'orders.created_at')
            ->where('orders.status', 'completed')->where('orders.type', 'pos')->whereNull('orders.customer_id')
            ->whereRaw('('.self::WALK_IN_COLLECTION_SQL.') > 0')
            ->selectRaw("orders.branch_id, orders.created_at as event_date, orders.invoice_number as reference, 'receipt' as voucher_type, COALESCE(orders.payment_method, 'cash') as payment_method, COALESCE(NULLIF(orders.customer_name, ''), 'زبون نقدي') as party_name, NULL as party_id, orders.notes")
            ->selectRaw('('.self::WALK_IN_COLLECTION_SQL.") as amount, 'تحصيل فاتورة نقطة بيع' as source_label, orders.id as row_id, 2 as source_order");
        $search = trim($this->filters['search'] ?? '');

        return DB::query()->fromSub($payments->unionAll($walkIn), 'cash_events')
            ->when(! empty($this->filters['voucher_type']), fn (Builder $q): Builder => $q->where('voucher_type', $this->filters['voucher_type']))
            ->when(! empty($this->filters['payment_method']), fn (Builder $q): Builder => $q->where('payment_method', $this->filters['payment_method']))
            ->when(! empty($this->filters['party_id']), fn (Builder $q): Builder => $q->where('party_id', $this->filters['party_id']))
            ->when($search !== '', fn (Builder $q): Builder => $q->where(fn (Builder $q): Builder => $q->where('reference', 'like', '%'.$search.'%')->orWhere('notes', 'like', '%'.$search.'%')->orWhere('party_name', 'like', '%'.$search.'%')));
    }

    /** @return ReportDefinition */
    private function dailyCash(): array
    {
        $signed = "CASE WHEN voucher_type = 'receipt' THEN amount ELSE -amount END";
        $query = $this->cashEvents()->selectRaw('DATE(event_date) as day')
            ->selectRaw("SUM(CASE WHEN payment_method = 'cash' AND voucher_type = 'receipt' THEN amount ELSE 0 END) as cash_in")
            ->selectRaw("SUM(CASE WHEN payment_method = 'cash' AND voucher_type = 'payment' THEN amount ELSE 0 END) as cash_out")
            ->selectRaw("SUM(CASE WHEN payment_method = 'cash' THEN $signed ELSE 0 END) as net_cash")
            ->selectRaw("SUM(CASE WHEN payment_method = 'card' THEN $signed ELSE 0 END) as card_in")
            ->selectRaw("SUM(CASE WHEN payment_method = 'bank_transfer' THEN $signed ELSE 0 END) as transfer_in")
            ->selectRaw("SUM(CASE WHEN payment_method IN ('cheque', 'check') THEN $signed ELSE 0 END) as cheque_in")
            ->selectRaw("SUM(CASE WHEN voucher_type = 'receipt' THEN amount ELSE 0 END) as total_in")
            ->selectRaw("SUM(CASE WHEN voucher_type = 'payment' THEN amount ELSE 0 END) as total_out")
            ->selectRaw("SUM($signed) as net_total")
            ->groupByRaw('DATE(event_date)')->orderBy('day');
        $totals = DB::query()->fromSub(clone $query, 'daily_cash')
            ->selectRaw('COALESCE(SUM(cash_in),0) as cash_in, COALESCE(SUM(cash_out),0) as cash_out, COALESCE(SUM(net_cash),0) as net_cash, COALESCE(SUM(card_in),0) as card_in, COALESCE(SUM(transfer_in),0) as transfer_in, COALESCE(SUM(cheque_in),0) as cheque_in, COALESCE(SUM(net_total),0) as net_total')->first();

        return $this->definition($query,
            ['day' => 'اليوم', 'cash_in' => 'مقبوضات نقدية', 'cash_out' => 'مدفوعات نقدية', 'net_cash' => 'صافي النقد', 'card_in' => 'صافي البطاقة', 'transfer_in' => 'صافي التحويل', 'cheque_in' => 'صافي الشيكات', 'total_in' => 'إجمالي المقبوض', 'total_out' => 'إجمالي المدفوع', 'net_total' => 'صافي الحركة'],
            ['مقبوضات نقدية' => $totals->cash_in, 'مدفوعات نقدية' => $totals->cash_out, 'صافي النقد' => $totals->net_cash, 'صافي البطاقة' => $totals->card_in, 'صافي التحويل' => $totals->transfer_in, 'صافي الشيكات' => $totals->cheque_in, 'صافي الحركة لكل الطرق' => $totals->net_total],
            ['يجمع سندات القبض والصرف وتحصيلات نقطة البيع لكل يوم حسب طريقة الدفع. صافي النقد = المقبوض نقداً − المدفوع نقداً، ولا يشمل رصيد افتتاح الصندوق.', 'لمطابقة الصندوق مع الجرد الفعلي استخدم تقرير الشيفتات أو شاشة التسوية اليومية.']);
    }

    private function expenseVouchers(): Builder
    {
        return $this->period($this->branch($this->table('payments'), 'payments.branch_id'), 'payments.payment_date')
            ->where('payments.type', 'payment')->whereNull('payments.party_id')->whereNull('payments.order_id')
            ->whereNull('payments.purchase_id')->whereNull('payments.purchase_return_id')
            // Salaries have their own line in the profit report and advances are loans, not expenses.
            ->whereNull('payments.employee_payment_id');
    }

    /** @return ReportDefinition */
    private function expenses(): array
    {
        $search = trim($this->filters['search'] ?? '');
        $query = $this->expenseVouchers()
            ->when(! empty($this->filters['payment_method']), fn (Builder $q): Builder => $q->where('payments.payment_method', $this->filters['payment_method']))
            ->when($search !== '', fn (Builder $q): Builder => $q->where(fn (Builder $q): Builder => $q->where('payments.notes', 'like', '%'.$search.'%')->orWhere('payments.voucher_number', 'like', '%'.$search.'%')))
            ->leftJoin('branches', function ($join): void {
                $join->on('branches.id', '=', 'payments.branch_id')->where('branches.tenant_id', $this->tenantId);
            })
            ->leftJoin('users as expense_user', 'expense_user.id', '=', 'payments.created_by');
        $sums = (clone $query)->selectRaw("COALESCE(SUM(payments.amount),0) as total, COALESCE(SUM(CASE WHEN payments.payment_method = 'cash' THEN payments.amount ELSE 0 END),0) as cash, COUNT(*) as vouchers")->first();

        return $this->definition($query->select('payments.id', 'payments.payment_date', 'payments.voucher_number', 'payments.notes', 'payments.payment_method', 'payments.shift_id', 'payments.amount', 'branches.name as branch_name', 'expense_user.name as user_name')
            ->orderBy('payments.payment_date')->orderBy('payments.id'),
            ['payment_date' => 'التاريخ', 'voucher_number' => 'السند', 'notes' => 'البيان', 'branch_name' => 'الفرع', 'shift_id' => 'الشيفت', 'user_name' => 'المستخدم', 'payment_method' => 'الطريقة', 'amount' => 'المبلغ'],
            ['إجمالي المصروفات' => $sums->total, 'منها نقداً' => $sums->cash, 'عدد السندات' => $sums->vouchers],
            ['سندات الصرف غير المرتبطة بطرف أو فاتورة أو مردود فقط. سداد الموردين والرواتب والسلف ليست مصروفات تشغيلية ولها تقاريرها.']);
    }

    /** @return ReportDefinition */
    private function aging(): array
    {
        $end = Carbon::parse($this->filters['to'])->addDay();
        $cutoffs = [$end->copy()->subDays(30)->toDateString().' 00:00:00', $end->copy()->subDays(60)->toDateString().' 00:00:00', $end->copy()->subDays(90)->toDateString().' 00:00:00'];
        $events = $this->partyEvents()->where('event_date', '<', $end->toDateString().' 00:00:00')->select('party_id')
            ->selectRaw('SUM(amount) as movement')
            ->selectRaw('SUM(CASE WHEN amount > 0 AND event_date >= ? THEN amount ELSE 0 END) as debit_0', [$cutoffs[0]])
            ->selectRaw('SUM(CASE WHEN amount > 0 AND event_date >= ? AND event_date < ? THEN amount ELSE 0 END) as debit_1', [$cutoffs[1], $cutoffs[0]])
            ->selectRaw('SUM(CASE WHEN amount > 0 AND event_date >= ? AND event_date < ? THEN amount ELSE 0 END) as debit_2', [$cutoffs[2], $cutoffs[1]])
            ->selectRaw('SUM(CASE WHEN amount < 0 AND event_date >= ? THEN -amount ELSE 0 END) as credit_0', [$cutoffs[0]])
            ->selectRaw('SUM(CASE WHEN amount < 0 AND event_date >= ? AND event_date < ? THEN -amount ELSE 0 END) as credit_1', [$cutoffs[1], $cutoffs[0]])
            ->selectRaw('SUM(CASE WHEN amount < 0 AND event_date >= ? AND event_date < ? THEN -amount ELSE 0 END) as credit_2', [$cutoffs[2], $cutoffs[1]])
            ->groupBy('party_id');
        $balances = $this->parties()->leftJoinSub($events, 'totals', 'totals.party_id', '=', 'parties.id')
            ->select('parties.id', 'parties.code', 'parties.name', 'parties.phone')
            ->selectRaw("CASE WHEN parties.type = 'supplier' THEN 'مورد' WHEN parties.type = 'both' THEN 'زبون ومورد' ELSE 'زبون' END as party_kind")
            ->selectRaw("(CASE WHEN ? = 1 AND parties.branch_id IS NULL THEN 0 WHEN parties.type = 'supplier' THEN -parties.opening_balance ELSE parties.opening_balance END) + COALESCE(totals.movement, 0) as balance", [$this->branchId ? 1 : 0])
            ->selectRaw('COALESCE(totals.debit_0,0) as debit_0, COALESCE(totals.debit_1,0) as debit_1, COALESCE(totals.debit_2,0) as debit_2, COALESCE(totals.credit_0,0) as credit_0, COALESCE(totals.credit_1,0) as credit_1, COALESCE(totals.credit_2,0) as credit_2');
        // Open balance is matched against the newest documents first (oldest documents are assumed settled first).
        $level1 = DB::query()->fromSub($balances, 'aging_balances')->whereRaw('ABS(balance) > 0.005')
            ->select('id', 'code', 'name', 'phone', 'party_kind', 'balance')
            ->selectRaw('CASE WHEN balance < 0 THEN -balance ELSE balance END as open_amount')
            ->selectRaw('CASE WHEN balance < 0 THEN credit_0 ELSE debit_0 END as bucket_0, CASE WHEN balance < 0 THEN credit_1 ELSE debit_1 END as bucket_1, CASE WHEN balance < 0 THEN credit_2 ELSE debit_2 END as bucket_2');
        $level2 = DB::query()->fromSub($level1, 'aging_1')->select('*')->selectRaw('CASE WHEN open_amount < bucket_0 THEN open_amount ELSE bucket_0 END as part_0');
        $level3 = DB::query()->fromSub($level2, 'aging_2')->select('*')->selectRaw('CASE WHEN open_amount - part_0 < bucket_1 THEN open_amount - part_0 ELSE bucket_1 END as part_1');
        $level4 = DB::query()->fromSub($level3, 'aging_3')->select('*')->selectRaw('CASE WHEN open_amount - part_0 - part_1 < bucket_2 THEN open_amount - part_0 - part_1 ELSE bucket_2 END as part_2');
        $query = DB::query()->fromSub($level4, 'aging_4')->select('id', 'code', 'name', 'phone', 'party_kind', 'balance')
            ->selectRaw('CASE WHEN balance < 0 THEN -1 ELSE 1 END * part_0 as age_0_30, CASE WHEN balance < 0 THEN -1 ELSE 1 END * part_1 as age_31_60, CASE WHEN balance < 0 THEN -1 ELSE 1 END * part_2 as age_61_90')
            ->selectRaw('CASE WHEN balance < 0 THEN -1 ELSE 1 END * (open_amount - part_0 - part_1 - part_2) as age_over_90');
        $query = DB::query()->fromSub($query, 'aging');
        match ($this->filters['balance_side']) {
            'debit' => $query->whereRaw('balance > 0.005'),
            'credit' => $query->whereRaw('balance < -0.005'),
            'settled' => $query->whereRaw('1 = 0'),
            default => null,
        };
        $sums = (clone $query)->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END),0) as debit, COALESCE(SUM(CASE WHEN balance < 0 THEN -balance ELSE 0 END),0) as credit')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 THEN age_over_90 ELSE 0 END),0) as overdue_debit, COALESCE(SUM(CASE WHEN balance < 0 THEN -age_over_90 ELSE 0 END),0) as overdue_credit')->first();

        return $this->definition($query->select('*')->orderByDesc(DB::raw('ABS(balance)'))->orderBy('id'),
            ['code' => 'الرمز', 'name' => 'الطرف', 'party_kind' => 'النوع', 'phone' => 'الهاتف', 'balance' => 'الرصيد', 'age_0_30' => 'حتى 30 يوم', 'age_31_60' => '31 - 60 يوم', 'age_61_90' => '61 - 90 يوم', 'age_over_90' => 'أكثر من 90 يوم'],
            ['إجمالي الذمم المدينة (لنا)' => $sums->debit, 'ذمم مدينة أقدم من 90 يوماً' => $sums->overdue_debit, 'إجمالي الذمم الدائنة (علينا)' => $sums->credit, 'ذمم دائنة أقدم من 90 يوماً' => $sums->overdue_credit],
            ['الأعمار محسوبة حتى '.$this->filters['to'].': يُطابق الرصيد المفتوح مع أحدث المستندات، باعتبار أن السداد يغطي الأقدم أولاً. الرصيد الافتتاحي يُعدّ أقدم من 90 يوماً.', 'موجب: مستحق لنا؛ سالب: مستحق علينا. الأطراف المسددة لا تظهر.']);
    }

    /** @return ReportDefinition */
    private function tax(): array
    {
        $sales = $this->orders()->leftJoin('parties', function ($join): void {
            $join->on('parties.id', '=', 'orders.customer_id')->where('parties.tenant_id', $this->tenantId);
        })->selectRaw("orders.created_at as event_date, orders.invoice_number as reference, CASE WHEN orders.type = 'return' THEN 'مردود مبيعات' ELSE 'مبيعات' END as kind, COALESCE(parties.name, NULLIF(orders.customer_name, ''), 'زبون نقدي') as name, parties.tax_number")
            ->selectRaw('('.self::ORDER_REVENUE_SQL.') as taxable')
            ->selectRaw("CASE WHEN orders.type = 'return' THEN -ABS(orders.tax_amount) ELSE orders.tax_amount END as output_tax, 0 as input_tax, 1 as source_order, orders.id as row_id");
        $purchases = $this->period($this->branch($this->table('purchases'), 'purchases.branch_id'), DB::raw(self::PURCHASE_DATE_SQL))
            ->leftJoin('parties', function ($join): void {
                $join->on('parties.id', '=', 'purchases.supplier_id')->where('parties.tenant_id', $this->tenantId);
            })->selectRaw(self::PURCHASE_DATE_SQL." as event_date, purchases.reference_number as reference, 'مشتريات' as kind, COALESCE(parties.name, 'بدون مورد') as name, parties.tax_number")
            ->selectRaw('purchases.total - COALESCE(purchases.tax_amount, 0) as taxable, 0 as output_tax, COALESCE(purchases.tax_amount, 0) as input_tax, 2 as source_order, purchases.id as row_id');
        $returns = $this->period($this->branch($this->table('purchase_returns'), 'purchase_returns.branch_id'), 'purchase_returns.created_at')
            ->leftJoin('parties', function ($join): void {
                $join->on('parties.id', '=', 'purchase_returns.supplier_id')->where('parties.tenant_id', $this->tenantId);
            })->selectRaw("purchase_returns.created_at as event_date, purchase_returns.reference_number as reference, 'مردود مشتريات' as kind, COALESCE(parties.name, 'بدون مورد') as name, parties.tax_number")
            ->selectRaw('-(ABS(purchase_returns.total) - ABS(COALESCE(purchase_returns.tax_amount, 0))) as taxable, 0 as output_tax, -ABS(COALESCE(purchase_returns.tax_amount, 0)) as input_tax, 3 as source_order, purchase_returns.id as row_id');
        $query = DB::query()->fromSub($sales->unionAll($purchases)->unionAll($returns), 'tax_documents');
        $sums = (clone $query)->selectRaw('COALESCE(SUM(CASE WHEN source_order = 1 THEN taxable ELSE 0 END),0) as sales_taxable, COALESCE(SUM(output_tax),0) as output_tax, COALESCE(SUM(CASE WHEN source_order > 1 THEN taxable ELSE 0 END),0) as purchase_taxable, COALESCE(SUM(input_tax),0) as input_tax')->first();

        return $this->definition($query->select('*')->orderBy('event_date')->orderBy('source_order')->orderBy('row_id'),
            ['event_date' => 'التاريخ', 'kind' => 'المستند', 'reference' => 'الرقم', 'name' => 'الطرف', 'tax_number' => 'الرقم الضريبي', 'taxable' => 'المبلغ قبل الضريبة', 'output_tax' => 'ضريبة المبيعات', 'input_tax' => 'ضريبة المشتريات'],
            ['المبيعات الخاضعة قبل الضريبة' => $sums->sales_taxable, 'ضريبة المبيعات (المخرجات)' => $sums->output_tax, 'المشتريات قبل الضريبة' => $sums->purchase_taxable, 'ضريبة المشتريات (المدخلات)' => $sums->input_tax, 'صافي الضريبة المستحقة' => (float) $sums->output_tax - (float) $sums->input_tax],
            ['المبيعات المكتملة ومردوداتها حسب تاريخ الإنشاء، والمشتريات حسب تاريخ الفاتورة، ومردودات المشتريات. المبلغ قبل الضريبة لا يشمل رسوم التوصيل.', 'صافي الضريبة الموجب مستحق للدفع، والسالب رصيد قابل للخصم. راجع المحاسب قبل تقديم الإقرار الضريبي.']);
    }

    /** @return ReportDefinition */
    private function branches(): array
    {
        $orders = $this->orders()->select('orders.branch_id')
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' THEN 1 ELSE 0 END) as invoices")
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' THEN ABS(orders.total) ELSE 0 END) as sales")
            ->selectRaw("SUM(CASE WHEN orders.type = 'return' THEN ABS(orders.total) ELSE 0 END) as returns")
            ->selectRaw('SUM('.self::ORDER_REVENUE_SQL.') as revenue')
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' THEN orders.total_cost ELSE 0 END) as cost")
            ->groupBy('orders.branch_id');
        $returnCosts = $this->orders()->where('orders.type', 'return')->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.tenant_id', $this->tenantId)->select('orders.branch_id')->selectRaw('SUM(ABS(order_items.total_cost)) as return_cost')->groupBy('orders.branch_id');
        $expenses = $this->expenseVouchers()->select('payments.branch_id')->selectRaw('SUM(payments.amount) as expenses')->groupBy('payments.branch_id');
        $purchases = DB::query()->fromSub(
            $this->period($this->branch($this->table('purchases'), 'purchases.branch_id'), DB::raw(self::PURCHASE_DATE_SQL))->selectRaw('purchases.branch_id, purchases.total as amount')
                ->unionAll($this->period($this->branch($this->table('purchase_returns'), 'purchase_returns.branch_id'), 'purchase_returns.created_at')->selectRaw('purchase_returns.branch_id, -ABS(purchase_returns.total) as amount')),
            'branch_purchases')->select('branch_id')->selectRaw('SUM(amount) as purchases_total')->groupBy('branch_id');
        $cash = $this->cashEvents()->select('branch_id')
            ->selectRaw("SUM(CASE WHEN voucher_type = 'receipt' THEN amount ELSE 0 END) as total_in, SUM(CASE WHEN voucher_type = 'payment' THEN amount ELSE 0 END) as total_out")->groupBy('branch_id');
        $query = $this->table('branches')->when($this->branchId, fn (Builder $q): Builder => $q->where('branches.id', $this->branchId))
            ->leftJoinSub($orders, 'branch_orders', 'branch_orders.branch_id', '=', 'branches.id')
            ->leftJoinSub($returnCosts, 'branch_return_costs', 'branch_return_costs.branch_id', '=', 'branches.id')
            ->leftJoinSub($expenses, 'branch_expenses', 'branch_expenses.branch_id', '=', 'branches.id')
            ->leftJoinSub($purchases, 'branch_purchase_totals', 'branch_purchase_totals.branch_id', '=', 'branches.id')
            ->leftJoinSub($cash, 'branch_cash', 'branch_cash.branch_id', '=', 'branches.id')
            ->where(fn (Builder $q): Builder => $q->whereNull('branches.deleted_at')->orWhereNotNull('branch_orders.branch_id')->orWhereNotNull('branch_expenses.branch_id')
                ->orWhereNotNull('branch_purchase_totals.branch_id')->orWhereNotNull('branch_cash.branch_id'))
            ->select('branches.id', 'branches.name as branch_name')
            ->selectRaw('COALESCE(branch_orders.invoices, 0) as invoices, COALESCE(branch_orders.sales, 0) as sales, COALESCE(branch_orders.returns, 0) as returns, COALESCE(branch_orders.revenue, 0) as revenue')
            ->selectRaw('COALESCE(branch_orders.cost, 0) - COALESCE(branch_return_costs.return_cost, 0) as cost')
            ->selectRaw('COALESCE(branch_orders.revenue, 0) - COALESCE(branch_orders.cost, 0) + COALESCE(branch_return_costs.return_cost, 0) as gross_profit')
            ->selectRaw('COALESCE(branch_expenses.expenses, 0) as expenses')
            ->selectRaw('COALESCE(branch_orders.revenue, 0) - COALESCE(branch_orders.cost, 0) + COALESCE(branch_return_costs.return_cost, 0) - COALESCE(branch_expenses.expenses, 0) as net_profit')
            ->selectRaw('COALESCE(branch_purchase_totals.purchases_total, 0) as purchases_total, COALESCE(branch_cash.total_in, 0) as total_in, COALESCE(branch_cash.total_out, 0) as total_out');
        $totals = DB::query()->fromSub(clone $query, 'branch_totals')
            ->selectRaw('COALESCE(SUM(sales),0) as sales, COALESCE(SUM(returns),0) as returns, COALESCE(SUM(revenue),0) as revenue, COALESCE(SUM(gross_profit),0) as gross_profit, COALESCE(SUM(expenses),0) as expenses, COALESCE(SUM(net_profit),0) as net_profit, COALESCE(SUM(purchases_total),0) as purchases_total')->first();

        return $this->definition($query->orderBy('branches.name')->orderBy('branches.id'),
            ['branch_name' => 'الفرع', 'invoices' => 'فواتير البيع', 'sales' => 'المبيعات', 'returns' => 'المردودات', 'revenue' => 'صافي الإيراد', 'cost' => 'التكلفة', 'gross_profit' => 'مجمل الربح', 'expenses' => 'المصروفات', 'net_profit' => 'الربح بعد المصروفات', 'purchases_total' => 'صافي المشتريات', 'total_in' => 'المقبوضات', 'total_out' => 'المدفوعات'],
            ['إجمالي المبيعات' => $totals->sales, 'المردودات' => $totals->returns, 'صافي الإيراد' => $totals->revenue, 'مجمل الربح' => $totals->gross_profit, 'المصروفات' => $totals->expenses, 'الربح بعد المصروفات' => $totals->net_profit, 'صافي المشتريات' => $totals->purchases_total],
            ['صافي الإيراد دون الضريبة ورسوم التوصيل؛ التكلفة هي المسجلة وقت البيع بعد عكس تكلفة المردود. الرواتب غير موزعة على الفروع.', 'المقبوضات والمدفوعات تشمل السندات وتحصيل فواتير نقطة البيع، ولا تعني الربح.']);
    }

    /**
     * Sales and profit by the employee who created each invoice, best first. Returns count against the
     * employee who recorded them.
     *
     * @return ReportDefinition
     */
    private function employees(): array
    {
        $orders = $this->orders()->select('orders.created_by')
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' THEN 1 ELSE 0 END) as invoices")
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' THEN ABS(orders.total) ELSE 0 END) as sales")
            ->selectRaw("SUM(CASE WHEN orders.type = 'return' THEN ABS(orders.total) ELSE 0 END) as returns")
            ->selectRaw('SUM('.self::ORDER_REVENUE_SQL.') as revenue')
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' THEN orders.total_cost ELSE 0 END) as cost")
            ->groupBy('orders.created_by');
        $returnCosts = $this->orders()->where('orders.type', 'return')->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.tenant_id', $this->tenantId)->select('orders.created_by')->selectRaw('SUM(ABS(order_items.total_cost)) as return_cost')->groupBy('orders.created_by');
        $query = DB::query()->fromSub($orders, 'employee_orders')
            ->leftJoinSub($returnCosts, 'employee_return_costs', fn ($join) => $join->whereColumn('employee_return_costs.created_by', 'employee_orders.created_by')
                ->orWhere(fn ($both) => $both->whereNull('employee_return_costs.created_by')->whereNull('employee_orders.created_by')))
            ->leftJoin('users', 'users.id', '=', 'employee_orders.created_by')
            ->select('employee_orders.created_by as id')
            ->selectRaw("COALESCE(users.name, 'غير محدد') as employee_name")
            ->selectRaw('employee_orders.invoices, employee_orders.sales, employee_orders.returns, employee_orders.revenue')
            ->selectRaw('employee_orders.cost - COALESCE(employee_return_costs.return_cost, 0) as cost')
            ->selectRaw('employee_orders.revenue - employee_orders.cost + COALESCE(employee_return_costs.return_cost, 0) as gross_profit')
            ->selectRaw('CASE WHEN employee_orders.invoices > 0 THEN employee_orders.sales / employee_orders.invoices ELSE 0 END as average_invoice');
        $totals = DB::query()->fromSub(clone $query, 'employee_totals')
            ->selectRaw('COUNT(*) as employees, COALESCE(SUM(revenue),0) as revenue, COALESCE(SUM(gross_profit),0) as gross_profit')->first();

        return $this->definition($query->orderByDesc('revenue')->orderBy('employee_orders.created_by'),
            ['employee_name' => 'الموظف', 'invoices' => 'فواتير البيع', 'sales' => 'المبيعات', 'returns' => 'المردودات', 'revenue' => 'صافي الإيراد', 'cost' => 'التكلفة', 'gross_profit' => 'مجمل الربح', 'average_invoice' => 'متوسط الفاتورة'],
            ['عدد الموظفين' => (int) $totals->employees, 'صافي الإيراد' => $totals->revenue, 'مجمل الربح' => $totals->gross_profit],
            ['مرتبة من الأعلى صافي إيراد. كل فاتورة تُحسب لمن أنشأها، وكل مردود لمن سجّله. صافي الإيراد دون الضريبة ورسوم التوصيل، والتكلفة هي المسجلة وقت البيع.']);
    }

    /** @return ReportDefinition */
    private function settlements(): array
    {
        $query = $this->branch($this->table('daily_settlements'), 'daily_settlements.branch_id')
            ->whereDate('daily_settlements.settlement_date', '>=', $this->filters['from'])
            ->whereDate('daily_settlements.settlement_date', '<=', $this->filters['to'])
            ->leftJoin('branches', function ($join): void {
                $join->on('branches.id', '=', 'daily_settlements.branch_id')->where('branches.tenant_id', $this->tenantId);
            })
            ->leftJoin('users as settlement_user', 'settlement_user.id', '=', 'daily_settlements.closed_by');
        $sums = (clone $query)->selectRaw('COALESCE(SUM(daily_settlements.total_sales),0) as sales, COALESCE(SUM(daily_settlements.total_returns),0) as returns, COALESCE(SUM(daily_settlements.total_cash),0) as cash, COALESCE(SUM(daily_settlements.total_card),0) as card')->first();

        return $this->definition($query->select('daily_settlements.id', 'daily_settlements.settlement_date', 'branches.name as branch_name', 'daily_settlements.total_shifts_count', 'daily_settlements.total_sales', 'daily_settlements.total_returns', 'daily_settlements.total_cash', 'daily_settlements.total_card', 'daily_settlements.closed_at', 'settlement_user.name as user_name', 'daily_settlements.notes')
            ->orderBy('daily_settlements.settlement_date')->orderBy('daily_settlements.id'),
            ['settlement_date' => 'تاريخ التسوية', 'branch_name' => 'الفرع', 'total_shifts_count' => 'عدد الشيفتات', 'total_sales' => 'المبيعات', 'total_returns' => 'المردودات', 'total_cash' => 'النقد المعتمد', 'total_card' => 'البطاقات', 'closed_at' => 'وقت الاعتماد', 'user_name' => 'اعتمد بواسطة', 'notes' => 'ملاحظات'],
            ['المبيعات' => $sums->sales, 'المردودات' => $sums->returns, 'النقد المعتمد' => $sums->cash, 'البطاقات' => $sums->card],
            ['التسويات اليومية المعتمدة كما حُفظت وقت الإغلاق. لتفاصيل كل شيفت استخدم تقرير الشيفتات.']);
    }
}
