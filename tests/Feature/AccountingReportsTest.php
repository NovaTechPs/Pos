<?php

use App\Models\Branch;
use App\Models\DailySettlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
    $this->tenant = Tenant::create(['name' => 'متجر الحسابات', 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الرئيسي']);
    $this->otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'فرع ثان']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => true, 'branch_id' => null]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->filters = ['from' => '2026-10-10', 'to' => '2026-10-20', 'party_id' => null, 'product_id' => null, 'search' => '', 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all', 'source' => '', 'sale_kind' => '', 'voucher_type' => '', 'payment_method' => ''];
    $this->customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون الحسابات', 'type' => 'customer', 'opening_balance' => 10, 'created_at' => '2026-01-01']);
    $this->supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد الحسابات', 'type' => 'supplier', 'opening_balance' => 100, 'tax_number' => 'TAX-77', 'created_at' => '2026-01-01']);
    $this->makeOrder = function (array $attributes = []): Order {
        return Order::create($attributes + ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'invoice_number' => 'ACC-'.str()->uuid(), 'type' => 'pos', 'source' => 'pos', 'status' => 'completed', 'subtotal' => 200, 'total' => 200, 'total_cost' => 100,
            'tax_amount' => 0, 'discount' => 0, 'delivery_fee' => 0, 'paid_amount' => 0, 'created_at' => '2026-10-15 12:00:00']);
    };
    $this->makePayment = function (array $attributes = []): Payment {
        return Payment::create($attributes + ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $this->customer->id,
            'type' => 'receipt', 'voucher_number' => 'V-'.str()->uuid(), 'amount' => 50, 'payment_method' => 'cash', 'payment_date' => '2026-10-16 12:00:00']);
    };
    $this->report = fn (string $report, array $filters = [], ?int $branchId = null): array => (new ReportingService($this->user, $branchId, false))->prepare($report, array_replace($this->filters, $filters));
});

test('POS invoices issued to a customer are reported with sales invoices and stay POS invoices', function (): void {
    $customerPos = ($this->makeOrder)(['invoice_number' => 'POS-CUSTOMER', 'total' => 300, 'total_cost' => 100]);
    ($this->makeOrder)(['invoice_number' => 'POS-WALK-IN', 'customer_id' => null, 'total' => 100, 'total_cost' => 40]);
    ($this->makeOrder)(['invoice_number' => 'WEB-INVOICE', 'source' => 'web', 'type' => 'wholesale', 'total' => 50, 'total_cost' => 20]);
    ($this->makeOrder)(['invoice_number' => 'POS-CUSTOMER-RETURN', 'type' => 'return', 'original_order_id' => $customerPos->id, 'total' => -30]);

    $revenue = fn (string $source): float => (float) ($this->report)('profit', ['source' => $source])['query']->get()->pluck('amount', 'label')['صافي الإيرادات دون ضريبة وتوصيل'];
    expect($revenue('pos'))->toBe(100.0)
        ->and($revenue('web'))->toBe(320.0)
        ->and($revenue(''))->toBe(420.0)
        ->and((float) ($this->report)('sales')['summary']['صافي المبيعات'])->toBe(100.0)
        ->and($customerPos->fresh()->source)->toBe('pos');
});

test('profit and shift reports exclude delivery fees collected by the delivery company and tax', function (): void {
    $shift = Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opening_cash' => 0, 'status' => 'closed', 'opened_at' => '2026-10-15 08:00:00']);
    ($this->makeOrder)(['shift_id' => $shift->id, 'total' => 260, 'tax_amount' => 30, 'delivery_fee' => 30, 'total_cost' => 120, 'discount' => 15]);

    $profit = ($this->report)('profit')['query']->get()->pluck('amount', 'label');
    expect((float) $profit['صافي الإيرادات دون ضريبة وتوصيل'])->toBe(200.0)
        ->and((float) $profit['رسوم التوصيل المحصلة لشركة التوصيل (تُستبعد)'])->toBe(30.0)
        ->and((float) $profit['ضريبة المبيعات الصافية (تُستبعد)'])->toBe(30.0)
        ->and((float) $profit['الخصومات الممنوحة على الفواتير (للعرض؛ مخصومة من الفواتير)'])->toBe(15.0)
        ->and((float) $profit['مجمل الربح'])->toBe(80.0);

    $row = ($this->report)('shifts')['query']->sole();
    expect((float) $row->sales)->toBe(260.0)->and((float) $row->gross_profit)->toBe(80.0);
});

test('shift report keeps invoices and expenses recorded after midnight inside the shift', function (): void {
    $shift = Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opening_cash' => 0, 'status' => 'closed', 'opened_at' => '2026-10-20 22:00:00', 'closed_at' => '2026-10-21 02:00:00']);
    ($this->makeOrder)(['shift_id' => $shift->id, 'total' => 50, 'total_cost' => 20, 'created_at' => '2026-10-20 23:00:00']);
    ($this->makeOrder)(['shift_id' => $shift->id, 'total' => 70, 'total_cost' => 30, 'created_at' => '2026-10-21 01:00:00']);
    ($this->makePayment)(['shift_id' => $shift->id, 'type' => 'payment', 'party_id' => null, 'amount' => 5, 'payment_date' => '2026-10-21 01:30:00']);

    $row = ($this->report)('shifts')['query']->sole();
    expect((float) $row->sales)->toBe(120.0)->and((float) $row->invoices)->toBe(2.0)
        ->and((float) $row->expenses)->toBe(5.0)->and((float) $row->net_profit)->toBe(65.0);
});

test('cash flow includes walk-in POS collections without counting their legacy vouchers twice', function (): void {
    $walkIn = ($this->makeOrder)(['customer_id' => null, 'invoice_number' => 'WALK-1', 'total' => 120, 'delivery_fee' => 20, 'paid_amount' => 120, 'payment_method' => 'card']);
    ($this->makePayment)(['party_id' => null, 'order_id' => $walkIn->id, 'amount' => 100, 'payment_method' => 'card', 'voucher_number' => 'LEGACY-WALK']);
    ($this->makeOrder)(['customer_id' => null, 'invoice_number' => 'WALK-DELETED', 'paid_amount' => 200, 'payment_method' => 'cash', 'deleted_at' => now()]);
    ($this->makeOrder)(['customer_id' => null, 'invoice_number' => 'WALK-OTHER-BRANCH', 'branch_id' => $this->otherBranch->id, 'paid_amount' => 200, 'payment_method' => 'cash']);
    ($this->makePayment)(['voucher_number' => 'CUSTOMER-RECEIPT', 'amount' => 40]);
    ($this->makePayment)(['voucher_number' => 'EXPENSE', 'type' => 'payment', 'party_id' => null, 'amount' => 15]);

    $report = ($this->report)('cashflow', [], $this->branch->id);
    $rows = $report['query']->get();
    expect($rows->pluck('reference')->all())->toEqualCanonicalizing(['WALK-1', 'CUSTOMER-RECEIPT', 'EXPENSE'])
        ->and((float) $rows->firstWhere('reference', 'WALK-1')->amount)->toBe(100.0)
        ->and((float) $report['summary']['المقبوضات'])->toBe(140.0)
        ->and((float) $report['summary']['المدفوعات'])->toBe(15.0)
        ->and((float) $report['summary']['صافي النقد'])->toBe(25.0);

    expect(($this->report)('cashflow', ['payment_method' => 'card'], $this->branch->id)['query']->pluck('reference')->all())->toBe(['WALK-1'])
        ->and(($this->report)('cashflow', ['voucher_type' => 'payment'], $this->branch->id)['query']->pluck('reference')->all())->toBe(['EXPENSE'])
        ->and(($this->report)('cashflow', ['party_id' => $this->customer->id], $this->branch->id)['query']->pluck('reference')->all())->toBe(['CUSTOMER-RECEIPT']);
});

test('daily cash report groups vouchers and POS collections per day and payment method', function (): void {
    ($this->makeOrder)(['customer_id' => null, 'paid_amount' => 200, 'payment_method' => 'cash', 'created_at' => '2026-10-15 09:00:00']);
    ($this->makePayment)(['amount' => 30, 'payment_method' => 'bank_transfer', 'payment_date' => '2026-10-15 10:00:00']);
    ($this->makePayment)(['type' => 'payment', 'party_id' => null, 'amount' => 25, 'payment_date' => '2026-10-15 11:00:00']);
    ($this->makePayment)(['amount' => 60, 'payment_date' => '2026-10-16 11:00:00']);

    $report = ($this->report)('daily-cash');
    $rows = $report['query']->get()->keyBy('day');
    expect($rows->keys()->all())->toBe(['2026-10-15', '2026-10-16'])
        ->and((float) $rows['2026-10-15']->cash_in)->toBe(200.0)
        ->and((float) $rows['2026-10-15']->cash_out)->toBe(25.0)
        ->and((float) $rows['2026-10-15']->net_cash)->toBe(175.0)
        ->and((float) $rows['2026-10-15']->transfer_in)->toBe(30.0)
        ->and((float) $rows['2026-10-15']->net_total)->toBe(205.0)
        ->and((float) $report['summary']['صافي النقد'])->toBe(235.0);
});

test('expenses report lists only operating expense vouchers and filters by payment method', function (): void {
    ($this->makePayment)(['type' => 'payment', 'party_id' => null, 'amount' => 15, 'notes' => 'كهرباء', 'voucher_number' => 'EXP-1']);
    ($this->makePayment)(['type' => 'payment', 'party_id' => null, 'amount' => 7, 'payment_method' => 'card', 'notes' => 'إنترنت', 'voucher_number' => 'EXP-2']);
    ($this->makePayment)(['type' => 'payment', 'party_id' => $this->supplier->id, 'amount' => 500, 'voucher_number' => 'SUPPLIER-PAY']);
    ($this->makePayment)(['type' => 'payment', 'party_id' => null, 'order_id' => ($this->makeOrder)(['type' => 'return', 'total' => -20])->id, 'amount' => 20, 'voucher_number' => 'REFUND']);
    ($this->makePayment)(['type' => 'payment', 'party_id' => null, 'amount' => 99, 'voucher_number' => 'DELETED', 'deleted_at' => now()]);

    $report = ($this->report)('expenses');
    expect($report['query']->pluck('voucher_number')->all())->toBe(['EXP-1', 'EXP-2'])
        ->and((float) $report['summary']['إجمالي المصروفات'])->toBe(22.0)
        ->and((float) $report['summary']['منها نقداً'])->toBe(15.0)
        ->and(($this->report)('expenses', ['payment_method' => 'card'])['query']->pluck('voucher_number')->all())->toBe(['EXP-2'])
        ->and(($this->report)('expenses', ['search' => 'كهرباء'])['query']->pluck('voucher_number')->all())->toBe(['EXP-1']);
});

test('aging report matches open balances to the newest documents and keeps payables negative', function (): void {
    ($this->makeOrder)(['total' => 100, 'created_at' => '2026-06-01 10:00:00']);
    ($this->makeOrder)(['total' => 80, 'created_at' => '2026-09-01 10:00:00']);
    ($this->makeOrder)(['total' => 200, 'created_at' => '2026-10-15 10:00:00']);
    ($this->makePayment)(['amount' => 150, 'payment_date' => '2026-10-16 10:00:00']);
    ($this->makeOrder)(['total' => 999, 'created_at' => '2026-10-25 10:00:00']);
    Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون مسدد', 'type' => 'customer', 'opening_balance' => 0]);

    $report = ($this->report)('aging');
    $rows = $report['query']->get()->keyBy('name');
    $customer = $rows[$this->customer->name];
    expect($rows->keys()->all())->toBe([$this->customer->name, $this->supplier->name])
        ->and((float) $customer->balance)->toBe(240.0)
        ->and((float) $customer->age_0_30)->toBe(200.0)
        ->and((float) $customer->age_31_60)->toBe(40.0)
        ->and((float) $customer->age_over_90)->toBe(0.0)
        ->and((float) $rows[$this->supplier->name]->age_over_90)->toBe(-100.0)
        ->and((float) $report['summary']['إجمالي الذمم المدينة (لنا)'])->toBe(240.0)
        ->and((float) $report['summary']['ذمم دائنة أقدم من 90 يوماً'])->toBe(100.0);

    expect(($this->report)('aging', ['balance_side' => 'debit'])['query']->pluck('name')->all())->toBe([$this->customer->name]);
});

test('tax report nets sales and purchase tax including returns and uses purchase invoice dates', function (): void {
    ($this->makeOrder)(['invoice_number' => 'TAX-SALE', 'total' => 250, 'tax_amount' => 30, 'delivery_fee' => 20]);
    ($this->makeOrder)(['invoice_number' => 'TAX-RETURN', 'type' => 'return', 'total' => -115, 'tax_amount' => -15]);
    Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'TAX-PUR', 'total' => 110, 'tax_amount' => 10, 'paid_amount' => 0, 'invoice_date' => '2026-10-12', 'created_at' => '2026-10-25 09:00:00']);
    Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'TAX-PUR-LATE', 'total' => 999, 'tax_amount' => 99, 'paid_amount' => 0, 'invoice_date' => '2026-10-25', 'created_at' => '2026-10-12 09:00:00']);
    PurchaseReturn::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'TAX-PRET', 'total' => 55, 'tax_amount' => 5, 'created_at' => '2026-10-18 09:00:00']);

    $report = ($this->report)('tax');
    $rows = $report['query']->get()->keyBy('reference');
    expect($rows->keys()->all())->toEqualCanonicalizing(['TAX-SALE', 'TAX-RETURN', 'TAX-PUR', 'TAX-PRET'])
        ->and((float) $rows['TAX-SALE']->taxable)->toBe(200.0)
        ->and((float) $rows['TAX-RETURN']->output_tax)->toBe(-15.0)
        ->and($rows['TAX-PUR']->tax_number)->toBe('TAX-77')
        ->and((float) $report['summary']['ضريبة المبيعات (المخرجات)'])->toBe(15.0)
        ->and((float) $report['summary']['ضريبة المشتريات (المدخلات)'])->toBe(5.0)
        ->and((float) $report['summary']['صافي الضريبة المستحقة'])->toBe(10.0);

    expect(($this->report)('purchases')['query']->pluck('reference')->all())->toContain('TAX-PUR')->not->toContain('TAX-PUR-LATE');
});

test('branch summary compares branches and respects the selected branch', function (): void {
    ($this->makeOrder)(['total' => 230, 'delivery_fee' => 30, 'total_cost' => 120]);
    $return = ($this->makeOrder)(['type' => 'return', 'total' => -50]);
    OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $return->id, 'product_id' => Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف'])->id, 'quantity' => -1, 'unit_price' => 50, 'total_price' => -50, 'cost_price' => 30, 'total_cost' => -30]);
    ($this->makePayment)(['type' => 'payment', 'party_id' => null, 'amount' => 10]);
    ($this->makeOrder)(['branch_id' => $this->otherBranch->id, 'total' => 90, 'total_cost' => 40]);
    Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->otherBranch->id, 'reference_number' => 'BR-PUR', 'total' => 300, 'paid_amount' => 0, 'created_at' => '2026-10-12']);

    $report = ($this->report)('branches');
    $rows = $report['query']->get()->keyBy('branch_name');
    expect((float) $rows['الرئيسي']->revenue)->toBe(150.0)
        ->and((float) $rows['الرئيسي']->cost)->toBe(90.0)
        ->and((float) $rows['الرئيسي']->net_profit)->toBe(50.0)
        ->and((float) $rows['فرع ثان']->gross_profit)->toBe(50.0)
        ->and((float) $rows['فرع ثان']->purchases_total)->toBe(300.0)
        ->and((float) $report['summary']['الربح بعد المصروفات'])->toBe(100.0);

    expect(($this->report)('branches', [], $this->otherBranch->id)['query']->pluck('branch_name')->all())->toBe(['فرع ثان']);
});

test('daily settlements report lists approved days of the tenant within the period', function (): void {
    $closer = ['closed_by' => $this->user->id, 'closed_at' => now()];
    DailySettlement::create($closer + ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'settlement_date' => '2026-10-15', 'total_sales' => 500, 'total_returns' => 20, 'total_cash' => 400, 'total_card' => 80, 'total_shifts_count' => 2]);
    DailySettlement::create($closer + ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'settlement_date' => '2026-10-25', 'total_sales' => 999, 'total_returns' => 0, 'total_cash' => 0, 'total_card' => 0, 'total_shifts_count' => 1]);
    $foreign = Tenant::create(['name' => 'متجر آخر']);
    DB::table('daily_settlements')->insert($closer + ['tenant_id' => $foreign->id, 'branch_id' => Branch::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'فرع آخر'])->id, 'settlement_date' => '2026-10-15', 'total_sales' => 777, 'total_returns' => 0, 'total_cash' => 0, 'total_card' => 0, 'total_shifts_count' => 1]);

    $report = ($this->report)('settlements');
    expect($report['query']->count())->toBe(1)
        ->and((float) $report['summary']['المبيعات'])->toBe(500.0)
        ->and((float) $report['summary']['النقد المعتمد'])->toBe(400.0);
});

test('new accounting reports render in the reports center print and export with grouped choices', function (): void {
    ($this->makePayment)(['type' => 'payment', 'party_id' => null, 'amount' => 15, 'voucher_number' => 'EXP-CENTER']);
    $component = Livewire::test('pages::tenant.reports.center')->assertSee('الحسابات والذمم')->assertSee('أعمار الذمم المدينة والدائنة');
    foreach (['aging', 'cashflow', 'daily-cash', 'tax', 'branches', 'settlements'] as $report) {
        $component->set('report', $report)->set('from', $this->filters['from'])->set('to', $this->filters['to'])->call('applyFilters')->assertOk()->assertHasNoErrors();
    }
    $component->set('report', 'expenses')->set('from', $this->filters['from'])->set('to', $this->filters['to'])
        ->set('paymentMethod', 'cash')->call('applyFilters')->assertSee('EXP-CENTER')->assertSee('طريقة الدفع')
        ->call('exportCsv')->assertFileDownloaded('report-expenses-2026-10-10-2026-10-20.csv');
    $response = $this->get(route('tenant.reports.print', ['report' => 'expenses', 'payment_method' => 'cash'] + $this->filters))->assertSuccessful();
    expect($response->streamedContent())->toContain('EXP-CENTER', 'المصروفات التشغيلية');
    $this->get(route('tenant.reports.print', ['report' => 'expenses', 'payment_method' => 'card'] + $this->filters))->assertSuccessful();
});

test('accounting reports follow report permissions', function (): void {
    $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Sales Reports Only']);
    foreach (['reports.analytics', 'reports.sales'] as $name) {
        $role->permissions()->attach(Permission::create(['name' => $name, 'display_name' => $name, 'group' => 'reports'])->id);
    }
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id, 'branch_id' => $this->branch->id, 'is_owner' => false, 'type' => 'tenant_user']);

    expect(array_keys((new ReportingService($staff))->availableReports()))->toContain('tax')->not->toContain('expenses', 'aging', 'branches', 'daily-cash');
    Livewire::actingAs($staff)->test('pages::tenant.reports.center')->set('report', 'expenses')->assertForbidden();
    $this->actingAs($staff)->get(route('tenant.reports.print', ['report' => 'branches'] + $this->filters))->assertForbidden();
});

test('api summary keeps staff on their branch and excludes delivery fees and tax from profit', function (): void {
    ($this->makeOrder)(['customer_id' => null, 'total' => 260, 'tax_amount' => 30, 'delivery_fee' => 30, 'total_cost' => 120, 'created_at' => now()]);
    ($this->makeOrder)(['type' => 'return', 'total' => -50, 'created_at' => now()]);
    ($this->makeOrder)(['branch_id' => $this->otherBranch->id, 'total' => 999, 'created_at' => now()]);

    $this->getJson('/api/reports/summary?branch_id='.$this->branch->id)->assertOk()
        ->assertJsonPath('summary.total_sales_revenue', 260)
        ->assertJsonPath('summary.total_returns_revenue', 50)
        ->assertJsonPath('summary.net_revenue', 210)
        ->assertJsonPath('summary.gross_profit', 30);

    $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Reports']);
    $role->permissions()->attach(Permission::create(['name' => 'reports.analytics', 'display_name' => 'reports', 'group' => 'reports'])->id);
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id, 'branch_id' => $this->branch->id, 'is_owner' => false, 'type' => 'tenant_user']);
    $this->actingAs($staff)->getJson('/api/reports/summary')->assertOk()->assertJsonPath('summary.total_sales_revenue', 260);
    $this->actingAs($staff)->getJson('/api/reports/summary?branch_id='.$this->otherBranch->id)->assertNotFound();

    $foreign = Tenant::create(['name' => 'متجر آخر']);
    $foreignBranch = Branch::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'فرع آخر']);
    $this->actingAs($this->user)->getJson('/api/reports/summary?branch_id='.$foreignBranch->id)->assertNotFound();
});
