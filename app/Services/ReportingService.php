<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Query\Builder;
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
        'sales' => ['ملخص المبيعات اليومي', 'reports.sales'],
        'product-sales' => ['مبيعات الأصناف خلال فترة', 'reports.sales'],
        'profit' => ['الأرباح والمصروفات', 'reports.profit'],
        'purchases' => ['المشتريات ومردوداتها', 'reports.purchases'],
        'payroll' => ['مسيرات الرواتب والمستحقات', 'payroll.view'],
        'salary-payments' => ['الرواتب والسلف المصروفة', 'payroll.view'],
        'movements' => ['حركة صنف / فترة', 'reports.inventory'],
        'movement-summary' => ['ملخص حركة المخزون', 'reports.inventory'],
        'inventory' => ['تقييم المخزون والنواقص', 'reports.inventory'],
        'cashflow' => ['المقبوضات والمدفوعات', 'accounting.payments.view'],
    ];

    public function __construct(public readonly User $user, ?int $branchId = null)
    {
        $tenant = $user->isSaaSAdmin() ? session('active_tenant_id') : $user->tenant_id;
        abort_unless($tenant && DB::table('tenants')->where('id', $tenant)->exists(), 403, 'حدد المتجر أولاً.');
        $this->tenantId = (int) $tenant;
        $this->branchId = $user->branch_id ? (int) $user->branch_id : $branchId;
        if ($this->branchId) {
            abort_unless(Branch::withoutGlobalScopes()->where('tenant_id', $this->tenantId)->whereKey($this->branchId)->exists(), 404);
        }
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
        ])->validate();
        foreach (['party_id' => 'parties', 'product_id' => 'products'] as $key => $table) {
            if (! empty($this->filters[$key])) {
                abort_unless($this->table($table)->where('id', $this->filters[$key])->exists(), 404);
            }
        }

        return match ($report) {
            'statement' => $this->statement(), 'balances' => $this->balances(),
            'sales' => $this->sales(), 'product-sales' => $this->productSales(),
            'profit' => $this->profit(), 'purchases' => $this->purchases(),
            'payroll' => $this->payroll(), 'salary-payments' => $this->salaryPayments(),
            'movements' => $this->movements(), 'movement-summary' => $this->movementSummary(),
            'inventory' => $this->inventory(), 'cashflow' => $this->cashflow(),
        };
    }

    public static function formatCell(string $column, mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        $quantity = ['quantity', 'quantity_before', 'quantity_after', 'sold', 'returned', 'net_quantity', 'stock_quantity', 'alert_quantity', 'incoming', 'outgoing', 'gift_quantity'];
        $money = ['debit', 'credit', 'balance', 'sales', 'returns', 'net', 'revenue', 'basic_salary', 'total_allowances', 'total_deductions', 'advance_deduction', 'net_salary', 'paid_amount', 'remaining', 'amount', 'cost_price', 'retail_price', 'valuation'];
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
            'sales_return' => 'مردود بيع', 'purchase_return' => 'مردود شراء', 'stock_transfer' => 'تحويل مخزون',
            'transfer_reversal' => 'عكس تحويل', 'sale_reversal' => 'عكس بيع', 'sync' => 'مزامنة',
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

    private function period(Builder $query, string $column): Builder
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
        $orders = $this->branch($this->table('orders'), 'orders.branch_id')->where('status', 'completed')
            ->whereIn('type', ['pos', 'wholesale', 'online', 'return'])->whereIn('customer_id', clone $partyIds)
            ->selectRaw("customer_id as party_id, created_at as event_date, invoice_number as reference, 'فاتورة مبيعات / مردود' as description, CASE WHEN type = 'return' THEN -ABS(total) ELSE ABS(total) END as amount, 1 as source_order, id as source_id");
        $linkedPurchasePayments = $this->table('payments')->whereNotNull('purchase_id')->select('purchase_id')
            ->selectRaw("SUM(CASE WHEN type = 'payment' THEN amount ELSE -amount END) as linked_paid")->groupBy('purchase_id');
        $purchases = $this->branch($this->table('purchases'), 'purchases.branch_id')->whereIn('supplier_id', clone $partyIds)
            ->leftJoinSub($linkedPurchasePayments, 'purchase_paid', 'purchase_paid.purchase_id', '=', 'purchases.id')
            ->selectRaw("supplier_id as party_id, purchases.created_at as event_date, reference_number as reference, 'فاتورة مشتريات (بعد المدفوع غير المربوط بسند)' as description, -total + CASE WHEN paid_amount > COALESCE(linked_paid,0) THEN paid_amount - COALESCE(linked_paid,0) ELSE 0 END as amount, 2 as source_order, purchases.id as source_id");
        $returns = $this->branch($this->table('purchase_returns'), 'purchase_returns.branch_id')->whereIn('supplier_id', clone $partyIds)
            ->selectRaw("supplier_id as party_id, created_at as event_date, reference_number as reference, 'مردود مشتريات' as description, ABS(total) as amount, 3 as source_order, id as source_id");
        $payments = $this->branch($this->table('payments'), 'payments.branch_id')
            ->leftJoin('orders as paid_order', function ($join): void {
                $join->on('paid_order.id', '=', 'payments.order_id')->where('paid_order.tenant_id', $this->tenantId);
            })
            ->leftJoin('purchases as paid_purchase', function ($join): void {
                $join->on('paid_purchase.id', '=', 'payments.purchase_id')->where('paid_purchase.tenant_id', $this->tenantId);
            })
            ->whereIn(DB::raw('COALESCE(payments.party_id, paid_order.customer_id, paid_purchase.supplier_id)'), clone $partyIds)
            ->selectRaw("COALESCE(payments.party_id, paid_order.customer_id, paid_purchase.supplier_id) as party_id, COALESCE(payments.payment_date, payments.created_at) as event_date, voucher_number as reference, CASE WHEN payments.type = 'receipt' THEN 'سند قبض' ELSE 'سند صرف / رد مبلغ' END as description, CASE WHEN payments.type = 'receipt' THEN -ABS(amount) ELSE ABS(amount) END as amount, 4 as source_order, payments.id as source_id");

        return DB::query()->fromSub($orders->unionAll($purchases)->unionAll($returns)->unionAll($payments), 'events');
    }

    /** @return ReportDefinition */
    private function statement(): array
    {
        $columns = ['event_date' => 'التاريخ', 'reference' => 'المستند', 'description' => 'البيان', 'debit' => 'مدين', 'credit' => 'دائن', 'balance' => 'الرصيد'];
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
        $query = $period->select('event_date', 'reference', 'description', 'source_order', 'source_id')
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
            'debit' => $query->where('balance', '>', .005),
            'credit' => $query->where('balance', '<', -.005),
            'settled' => $query->whereBetween('balance', [-.005, .005]),
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
            ->when(! empty($this->filters['source']), fn (Builder $q): Builder => $q->where('orders.source', $this->filters['source']))
            ->when(! empty($this->filters['sale_kind']), fn (Builder $q): Builder => $q->where('orders.sale_kind', $this->filters['sale_kind']));
    }

    /** @return ReportDefinition */
    private function sales(): array
    {
        $query = $this->orders()->selectRaw("DATE(orders.created_at) as day, SUM(CASE WHEN type != 'return' THEN 1 ELSE 0 END) as invoices, SUM(CASE WHEN type != 'return' THEN ABS(total) ELSE 0 END) as sales, SUM(CASE WHEN type = 'return' THEN ABS(total) ELSE 0 END) as returns, SUM(CASE WHEN type = 'return' THEN -ABS(total) ELSE total END) as net")
            ->groupByRaw('DATE(orders.created_at)')->orderBy('day');
        $totals = DB::query()->fromSub(clone $query, 'daily_totals')->selectRaw('COALESCE(SUM(sales),0) as sales, COALESCE(SUM(returns),0) as returns, COALESCE(SUM(net),0) as net')->first();

        return $this->definition($query, ['day' => 'التاريخ', 'invoices' => 'عدد فواتير البيع', 'sales' => 'المبيعات', 'returns' => 'المردودات', 'net' => 'صافي المبيعات'], ['إجمالي المبيعات' => $totals->sales, 'المردودات' => $totals->returns, 'صافي المبيعات' => $totals->net], ['الفواتير المكتملة فقط؛ صافي المبيعات يشمل الضريبة ورسوم التوصيل كما في الفاتورة.']);
    }

    /** @return ReportDefinition */
    private function productSales(): array
    {
        $query = $this->orders()->join('order_items', 'order_items.order_id', '=', 'orders.id')->where('order_items.tenant_id', $this->tenantId)
            ->join('products', 'products.id', '=', 'order_items.product_id')->where('products.tenant_id', $this->tenantId);
        $this->productFilters($query, 'products.id');
        $query->select('products.id', 'products.product_number', 'products.name')
            ->selectRaw("SUM(CASE WHEN orders.type != 'return' AND COALESCE(order_items.is_gift, false) = false THEN ABS(order_items.quantity) ELSE 0 END) as sold, SUM(CASE WHEN orders.type != 'return' AND order_items.is_gift = true THEN ABS(order_items.quantity) ELSE 0 END) as gift_quantity, SUM(CASE WHEN orders.type = 'return' THEN ABS(order_items.quantity) ELSE 0 END) as returned, SUM(CASE WHEN orders.type = 'return' THEN -ABS(order_items.quantity) ELSE order_items.quantity END) as net_quantity, SUM(CASE WHEN orders.type = 'return' THEN -ABS(order_items.total_price) ELSE order_items.total_price END) as revenue")
            ->groupBy('products.id', 'products.product_number', 'products.name')->orderByDesc('sold')->orderBy('products.id');
        $totals = DB::query()->fromSub(clone $query, 'product_totals')->selectRaw('COALESCE(SUM(sold),0) as sold, COALESCE(SUM(gift_quantity),0) as gift_quantity, COALESCE(SUM(returned),0) as returned, COALESCE(SUM(net_quantity),0) as net_quantity, COALESCE(SUM(revenue),0) as revenue')->first();

        return $this->definition($query, ['product_number' => 'رقم الصنف', 'name' => 'الصنف', 'sold' => 'الكمية المباعة', 'gift_quantity' => 'كمية الهدايا', 'returned' => 'الكمية المرتجعة', 'net_quantity' => 'صافي الكمية', 'revenue' => 'قيمة السطور الصافية'], ['الكمية المباعة' => $totals->sold, 'الهدايا' => $totals->gift_quantity, 'الكمية المرتجعة' => $totals->returned, 'صافي الكمية' => $totals->net_quantity, 'قيمة السطور' => $totals->revenue], ['اختر التاريخ نفسه للبداية والنهاية لتقرير يوم واحد. قيمة السطور قبل توزيع خصم الفاتورة العام والضريبة والتوصيل؛ الهدايا معروضة في عمود مستقل؛ صافي الكمية يشمل الهدايا والمردودات.']);
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
        $query = $this->employeeQuery('employee_payments')->whereBetween('employee_payments.payment_date', [$this->filters['from'], $this->filters['to']]);
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
        $sales = (clone $orders)->selectRaw("COALESCE(SUM(CASE WHEN type = 'return' THEN -(ABS(total) - ABS(tax_amount)) ELSE total - tax_amount END),0) as revenue, COALESCE(SUM(CASE WHEN type != 'return' THEN total_cost ELSE 0 END),0) as cost, COALESCE(SUM(CASE WHEN type = 'return' THEN ABS(total) ELSE 0 END),0) as returns")->first();
        $returnCost = (clone $orders)->where('orders.type', 'return')->join('order_items', 'order_items.order_id', '=', 'orders.id')->where('order_items.tenant_id', $this->tenantId)->sum(DB::raw('ABS(order_items.total_cost)'));
        $expenses = $this->period($this->branch($this->table('payments'), 'payments.branch_id'), 'payment_date')->where('type', 'payment')->whereNull('party_id')->whereNull('order_id')->whereNull('purchase_id')->sum('amount');
        $cost = (float) $sales->cost - (float) $returnCost;
        $gross = (float) $sales->revenue - $cost;
        $rows = [
            ['label' => 'صافي المبيعات دون ضريبة (يشمل التوصيل)', 'amount' => $sales->revenue],
            ['label' => 'مردود المبيعات ضمن الفترة (للعرض فقط)', 'amount' => $sales->returns],
            ['label' => 'تكلفة البضاعة بعد عكس تكلفة المردود', 'amount' => $cost],
            ['label' => 'مجمل الربح', 'amount' => $gross],
            ['label' => 'المصروفات التشغيلية المسجلة', 'amount' => $expenses],
            ['label' => 'الربح بعد المصروفات وقبل الرواتب', 'amount' => $gross - (float) $expenses],
        ];
        if ($this->user->can('payroll.view')) {
            $salaries = $this->employeeQuery('employee_payments')->where('payment_type', 'payroll')->whereBetween('payment_date', [$this->filters['from'], $this->filters['to']])->sum('amount');
            $rows[] = ['label' => 'رواتب مصروفة خلال الفترة (دون السلف)', 'amount' => $salaries];
            $rows[] = ['label' => 'صافي تقديري بعد الرواتب المصروفة', 'amount' => $gross - (float) $expenses - (float) $salaries];
        }
        $query = null;
        foreach ($rows as $index => $row) {
            $part = DB::query()->selectRaw('? as label, CAST(? AS DECIMAL(18,2)) as amount, ? as row_index', [$row['label'], $row['amount'], $index]);
            $query = $query ? $query->unionAll($part) : $part;
        }

        return $this->definition(DB::query()->fromSub($query, 'profit')->orderBy('row_index'), ['label' => 'البند', 'amount' => 'القيمة'], [], [
            'تقرير إداري تقديري بحسب التكاليف المحفوظة وقت البيع. لا يعيد تسعير الفواتير بتكلفة الصنف الحالية، ولا يعتبر شراء المخزون مصروفاً فورياً.',
            'الرواتب هنا على أساس الصرف، وليست تسوية استحقاق محاسبية. السلف وسداد الموردين ليست مصروفات تشغيلية. لا تسجّل الراتب نفسه مرة ثانية كمصروف عام.',
            'التكاليف التاريخية الناقصة أو المصروفات غير المسجلة تؤثر على الدقة. لا يُحسب هذا التقرير من المقبوضات بدلاً من المبيعات.',
        ]);
    }

    /** @return ReportDefinition */
    private function purchases(): array
    {
        $purchases = $this->period($this->branch($this->table('purchases'), 'purchases.branch_id'), 'purchases.created_at')
            ->leftJoin('parties', function ($join): void {
                $join->on('parties.id', '=', 'purchases.supplier_id')->where('parties.tenant_id', $this->tenantId);
            })
            ->selectRaw("purchases.id, purchases.created_at as event_date, reference_number as reference, parties.name as name, 'شراء' as kind, total as amount, paid_amount, total - paid_amount as remaining");
        $returns = $this->period($this->branch($this->table('purchase_returns'), 'purchase_returns.branch_id'), 'purchase_returns.created_at')
            ->leftJoin('parties', function ($join): void {
                $join->on('parties.id', '=', 'purchase_returns.supplier_id')->where('parties.tenant_id', $this->tenantId);
            })
            ->selectRaw("purchase_returns.id, purchase_returns.created_at as event_date, reference_number as reference, parties.name as name, 'مردود شراء' as kind, -ABS(total) as amount, 0 as paid_amount, 0 as remaining");
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

    /** @return ReportDefinition */
    private function cashflow(): array
    {
        $query = $this->period($this->branch($this->table('payments'), 'payments.branch_id'), 'payment_date');
        $sums = (clone $query)->selectRaw("COALESCE(SUM(CASE WHEN type = 'receipt' THEN amount ELSE 0 END),0) as incoming, COALESCE(SUM(CASE WHEN type = 'payment' THEN amount ELSE 0 END),0) as outgoing")->first();

        return $this->definition($query->select('id', 'payment_date', 'voucher_number', 'payment_method', 'notes')->selectRaw("CASE WHEN type = 'receipt' THEN 'قبض' ELSE 'صرف' END as kind, CASE WHEN type = 'receipt' THEN amount ELSE -amount END as amount")->orderBy('payment_date')->orderBy('id'),
            ['payment_date' => 'التاريخ', 'voucher_number' => 'السند', 'kind' => 'النوع', 'payment_method' => 'الطريقة', 'notes' => 'البيان', 'amount' => 'الحركة'], ['المقبوضات' => $sums->incoming, 'المدفوعات' => $sums->outgoing, 'صافي السندات' => (float) $sums->incoming - (float) $sums->outgoing], ['يشمل السندات المسجلة بكل طرق الدفع، وليس رصيد الصندوق أو الربح. مدفوع الشراء داخل الفاتورة ودفعات الموظفين لها تقارير مستقلة.']);
    }
}
