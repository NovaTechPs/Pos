<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Models\EmployeePayroll;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PayrollPeriod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
    $this->tenant = Tenant::create(['name' => 'متجر التقارير', 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الرئيسي']);
    $this->otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'فرع ثان']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => true, 'branch_id' => null]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->filters = ['from' => '2026-10-10', 'to' => '2026-10-20', 'party_id' => null, 'product_id' => null, 'search' => '', 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all', 'source' => '', 'sale_kind' => ''];
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'قهوة التقارير', 'product_number' => 'P-42', 'cost_price' => 900]);
    $this->customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون التقرير', 'type' => 'customer', 'opening_balance' => 10, 'created_at' => '2026-10-01']);
    $this->supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد التقرير', 'type' => 'supplier', 'opening_balance' => 100, 'created_at' => '2026-10-01']);
    $this->makeOrder = function (array $attributes = []): Order {
        return Order::create($attributes + ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
            'invoice_number' => 'RPT-'.str()->uuid(), 'type' => 'pos', 'status' => 'completed', 'subtotal' => 200, 'total' => 200, 'total_cost' => 100, 'tax_amount' => 0, 'discount' => 0, 'delivery_fee' => 0, 'paid_amount' => 0, 'created_at' => '2026-10-15 12:00:00']);
    };
    $this->makePayment = function (array $attributes = []): Payment {
        return Payment::create($attributes + ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $this->customer->id,
            'type' => 'receipt', 'voucher_number' => 'V-'.str()->uuid(), 'amount' => 50, 'payment_method' => 'cash', 'payment_date' => '2026-10-16 12:00:00']);
    };
});

test('customer statement has brought-forward opening and correct running balance across pages', function (): void {
    ($this->makeOrder)(['total' => 100, 'created_at' => '2026-10-01']);
    ($this->makePayment)(['amount' => 20, 'payment_date' => '2026-10-02']);
    ($this->makeOrder)();
    ($this->makeOrder)(['type' => 'return', 'total' => -40, 'created_at' => '2026-10-17']);
    ($this->makePayment)();
    ($this->makePayment)(['type' => 'payment', 'amount' => 10, 'payment_date' => '2026-10-18']);
    ($this->makeOrder)(['status' => 'pending', 'total' => 999]);
    $definition = (new ReportingService($this->user))->prepare('statement', $this->filters + []);
    expect($definition['query']->count())->toBe(0);
    $definition = (new ReportingService($this->user))->prepare('statement', array_replace($this->filters, ['party_id' => $this->customer->id]));
    expect((float) $definition['summary']['رصيد أول الفترة'])->toBe(90.0)
        ->and((float) $definition['summary']['رصيد آخر الفترة'])->toBe(210.0);
    $query = $definition['query'];
    $page = $query->paginate(2, ['*'], 'page', 2);
    expect((float) $page->items()[1]->balance)->toBe(210.0);
});

test('supplier and mixed-party ledgers normalize debit and credit without treating payments as sales', function (): void {
    Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'PUR-1', 'total' => 200, 'paid_amount' => 50, 'created_at' => '2026-10-12']);
    ($this->makePayment)(['party_id' => $this->supplier->id, 'type' => 'payment', 'amount' => 30]);
    PurchaseReturn::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'RET-1', 'total' => 20, 'created_at' => '2026-10-17']);
    $service = new ReportingService($this->user);
    $statement = $service->prepare('statement', array_replace($this->filters, ['party_id' => $this->supplier->id]));
    expect((float) $statement['summary']['رصيد آخر الفترة'])->toBe(-200.0);
    $balances = $service->prepare('balances', array_replace($this->filters, ['balance_side' => 'credit']));
    expect($balances['query']->get()->pluck('name')->all())->toBe(['مورد التقرير']);
    expect((float) $balances['summary']['الذمم الدائنة'])->toBe(200.0);
    $mixed = Party::create(['tenant_id' => $this->tenant->id, 'type' => 'both', 'name' => 'زبون ومورد', 'created_at' => '2026-10-01']);
    ($this->makeOrder)(['customer_id' => $mixed->id, 'total' => 100]);
    Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $mixed->id, 'reference_number' => 'PUR-2', 'total' => 40, 'paid_amount' => 0, 'created_at' => '2026-10-12']);
    ($this->makePayment)(['party_id' => $mixed->id, 'amount' => 20]);
    ($this->makePayment)(['party_id' => $mixed->id, 'type' => 'payment', 'amount' => 10]);
    $statement = $service->prepare('statement', array_replace($this->filters, ['party_id' => $mixed->id]));
    expect((float) $statement['summary']['رصيد آخر الفترة'])->toBe(50.0);
});

test('product sales report includes final-day sales and fractional returns but excludes cancelled and deleted invoices', function (): void {
    $sale = ($this->makeOrder)(['created_at' => '2026-10-20 23:59:59']);
    OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $sale->id, 'product_id' => $this->product->id, 'quantity' => 4.5, 'unit_price' => 40, 'total_price' => 180, 'cost_price' => 20, 'total_cost' => 90]);
    $return = ($this->makeOrder)(['type' => 'return', 'total' => -20]);
    OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $return->id, 'product_id' => $this->product->id, 'quantity' => -.5, 'unit_price' => 40, 'total_price' => -20, 'cost_price' => 20, 'total_cost' => -10]);
    foreach ([['status' => 'cancelled'], ['deleted_at' => now()], ['created_at' => '2026-10-21 00:00:00']] as $attributes) {
        $excluded = ($this->makeOrder)($attributes);
        OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $excluded->id, 'product_id' => $this->product->id, 'quantity' => 100, 'unit_price' => 40, 'total_price' => 4000]);
    }
    $report = (new ReportingService($this->user))->prepare('product-sales', $this->filters);
    $row = $report['query']->first();
    expect((float) $row->sold)->toBe(4.5)->and((float) $row->returned)->toBe(.5)->and((float) $row->net_quantity)->toBe(4.0)->and((float) $row->revenue)->toBe(160.0);
});

test('profit uses sale-time costs reverses return cost excludes tax and supplier payments and keeps advances separate', function (): void {
    ($this->makeOrder)(['total' => 220, 'tax_amount' => 20, 'total_cost' => 100]);
    $return = ($this->makeOrder)(['type' => 'return', 'total' => -55, 'tax_amount' => -5]);
    OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $return->id, 'product_id' => $this->product->id, 'quantity' => -1, 'unit_price' => 50, 'total_price' => -50, 'cost_price' => 25, 'total_cost' => -25]);
    ($this->makePayment)(['type' => 'payment', 'party_id' => null, 'amount' => 10]);
    ($this->makePayment)(['type' => 'payment', 'party_id' => $this->supplier->id, 'amount' => 900]);
    $employee = Employee::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'first_name' => 'موظف', 'last_name' => 'التقرير', 'employee_code' => 'EMP-1', 'hire_date' => '2026-01-01']);
    EmployeePayment::create(['employee_id' => $employee->id, 'payment_type' => 'payroll', 'amount' => 15, 'payment_date' => '2026-10-15']);
    EmployeePayment::create(['employee_id' => $employee->id, 'payment_type' => 'advance', 'amount' => 9, 'payment_date' => '2026-10-15']);
    $report = (new ReportingService($this->user))->prepare('profit', $this->filters);
    $values = $report['query']->get()->pluck('amount', 'label');
    expect((float) $values['مجمل الربح'])->toBe(75.0)
        ->and((float) $values['صافي تقديري بعد الرواتب المصروفة'])->toBe(50.0);
});

test('branch-bound users cannot widen reports or import a different tenant from session', function (): void {
    ($this->makeOrder)(['total' => 25]);
    ($this->makeOrder)(['branch_id' => $this->otherBranch->id, 'total' => 500]);
    $outsider = Tenant::create(['name' => 'متجر آخر']);
    $this->withSession(['active_tenant_id' => $outsider->id]);
    $this->user->branch_id = $this->branch->id;
    $this->user->save();
    $service = new ReportingService($this->user, $this->otherBranch->id);
    expect($service->tenantId)->toBe($this->tenant->id)->and($service->branchId)->toBe($this->branch->id);
    $row = $service->prepare('sales', $this->filters)['query']->first();
    expect((float) $row->net)->toBe(25.0);
    // Shared parties remain available to their branch without allocating global opening balances.
    $statement = $service->prepare('statement', array_replace($this->filters, ['party_id' => $this->customer->id]));
    expect((float) $statement['summary']['رصيد آخر الفترة'])->toBe(25.0);
    expect($statement['summary'])->not->toHaveKey('الرصيد المسجل حالياً');
});

test('every report renders and exports its columns without changing financial data', function (string $report): void {
    $employee = Employee::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'first_name' => 'موظف', 'last_name' => 'تقرير', 'employee_code' => 'EMP-1', 'hire_date' => '2026-01-01']);
    $period = PayrollPeriod::create(['tenant_id' => $this->tenant->id, 'title' => 'تشرين الأول', 'start_date' => '2026-10-01', 'end_date' => '2026-10-20', 'status' => 'approved']);
    EmployeePayroll::create(['employee_id' => $employee->id, 'payroll_period_id' => $period->id, 'basic_salary' => 1000, 'net_salary' => 900, 'paid_amount' => 100]);
    EmployeePayment::create(['employee_id' => $employee->id, 'payment_type' => 'payroll', 'amount' => 100, 'payment_date' => '2026-10-15']);
    ($this->makeOrder)();
    Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'PUR-1', 'total' => 20, 'paid_amount' => 0, 'created_at' => '2026-10-15']);
    ($this->makePayment)();
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 2, 'alert_quantity' => 5, 'retail_price' => 20]);

    Livewire::test('pages::tenant.reports.center')->set('report', $report)->set('from', $this->filters['from'])->set('to', $this->filters['to'])
        ->set('partyId', $report === 'statement' ? $this->customer->id : null)->call('applyFilters')->assertHasNoErrors()->assertSee(ReportingService::REPORTS[$report][0])
        ->call('exportCsv')->assertFileDownloaded('report-'.$report.'-2026-10-10-2026-10-20.csv');
    expect((float) $this->customer->fresh()->opening_balance)->toBe(10.0);
})->with(array_keys(ReportingService::REPORTS));

test('report dates reject invalid and reversed ranges and recover after correction', function (): void {
    Livewire::test('pages::tenant.reports.center')->set('from', '2026-10-30')->set('to', '2026-10-01')->call('applyFilters')
        ->assertHasErrors(['to'])->set('to', '2026-10-31')->call('applyFilters')->assertHasNoErrors();
});

test('print streams the complete report rather than just the visible page and safely escapes names', function (): void {
    $this->customer->update(['name' => '<script>unsafe</script>']);
    for ($i = 0; $i < 36; $i++) {
        ($this->makeOrder)(['invoice_number' => 'PRINT-'.$i]);
    }
    $response = $this->get(route('tenant.reports.print', ['report' => 'statement'] + array_replace($this->filters, ['party_id' => $this->customer->id])))->assertOk();
    $html = $response->streamedContent();
    expect($html)->toContain('PRINT-35')->toContain('&lt;script&gt;unsafe&lt;/script&gt;')->not->toContain('<script>unsafe</script>');
});

test('CSV neutralizes spreadsheet formulas without translating product names as statuses', function (): void {
    expect(ReportingService::csvValue('name', '=HYPERLINK("bad")'))->toStartWith("'")
        ->and(ReportingService::csvValue('reference', '  +CMD'))->toStartWith("'")
        ->and(ReportingService::formatCell('name', 'paid'))->toBe('paid');
});

test('purchase-linked vouchers are not counted twice and payments without party IDs inherit their document party', function (): void {
    $purchase = Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'LINK-PUR', 'total' => 100, 'paid_amount' => 50, 'created_at' => '2026-10-12']);
    ($this->makePayment)(['party_id' => null, 'purchase_id' => $purchase->id, 'type' => 'payment', 'amount' => 50]);
    $service = new ReportingService($this->user);
    $supplier = $service->prepare('statement', array_replace($this->filters, ['party_id' => $this->supplier->id]));
    expect((float) $supplier['summary']['رصيد آخر الفترة'])->toBe(-150.0);
    $sale = ($this->makeOrder)(['total' => 100]);
    ($this->makePayment)(['party_id' => null, 'order_id' => $sale->id, 'amount' => 100]);
    $customer = $service->prepare('statement', array_replace($this->filters, ['party_id' => $this->customer->id]));
    expect((float) $customer['summary']['رصيد آخر الفترة'])->toBe(10.0);
});

test('search matches saved product barcodes for sales and product options without requiring a products barcode column', function (): void {
    $this->product->barcodes()->create(['tenant_id' => $this->tenant->id, 'barcode' => '000123']);
    $sale = ($this->makeOrder)();
    OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $sale->id, 'product_id' => $this->product->id, 'quantity' => 2.5, 'unit_price' => 40, 'total_price' => 100]);
    $report = (new ReportingService($this->user))->prepare('product-sales', array_replace($this->filters, ['search' => '000123']));
    expect($report['query']->get())->toHaveCount(1);
    expect((float) $report['summary']['الكمية المباعة'])->toBe(2.5);
    Livewire::test('pages::tenant.reports.center')->set('report', 'product-sales')->set('productSearch', '000123')->assertSee('قهوة التقارير');
});

test('report permissions are enforced on livewire selection exports and direct print endpoints', function (): void {
    $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Sales Reports Only']);
    foreach (['reports.analytics', 'reports.sales'] as $name) {
        $role->permissions()->attach(Permission::create(['name' => $name, 'display_name' => $name, 'group' => 'reports'])->id);
    }
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id, 'branch_id' => $this->branch->id, 'is_owner' => false, 'type' => 'tenant_user']);
    Livewire::actingAs($staff)->test('pages::tenant.reports.center')->assertSet('report', 'sales')->set('report', 'profit')->assertForbidden();
    $this->actingAs($staff)->get(route('tenant.reports.print', ['report' => 'payroll'] + $this->filters))->assertForbidden();
    $this->get(route('tenant.reports.print', ['report' => 'profit'] + $this->filters))->assertForbidden();
    $staff->role_id = null;
    $staff->save();
    $staff->unsetRelation('role');
    $this->actingAs($staff)->get(route('tenant.reports'))->assertForbidden();
});

test('foreign tenant branches and parties cannot be selected or exported', function (): void {
    $foreign = Tenant::create(['name' => 'متجر محجوب']);
    $branch = Branch::withoutGlobalScopes()->create(['tenant_id' => $foreign->id, 'name' => 'فرع محجوب']);
    $party = Party::create(['tenant_id' => $foreign->id, 'name' => 'طرف محجوب', 'type' => 'customer']);
    $this->get(route('tenant.reports.print', ['report' => 'sales', 'branch_id' => $branch->id] + $this->filters))->assertNotFound();
    $this->get(route('tenant.reports.print', ['report' => 'statement'] + array_replace($this->filters, ['party_id' => $party->id])))->assertNotFound();
    Livewire::test('pages::tenant.reports.center')->set('partyId', $party->id)->assertNotFound();
    $this->get(route('tenant.reports'))->assertOk()->assertDontSee('طرف محجوب');
});

test('stock movement reports preserve before after and period totals and inventory explicitly reports current value', function (): void {
    $stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 10, 'alert_quantity' => 5, 'retail_price' => 20]);
    $stock->decrement('stock_quantity', 2.5);
    $stock->increment('stock_quantity', 1);
    $filters = array_replace($this->filters, ['from' => '2026-10-01', 'to' => '2026-10-31']);
    $service = new ReportingService($this->user);
    $movement = $service->prepare('movements', $filters);
    $rows = $movement['query']->get();
    expect((float) $rows->last()->quantity_before)->toBe(7.5)->and((float) $rows->last()->quantity_after)->toBe(8.5);
    expect((float) $movement['summary']['إجمالي الصادر'])->toBe(2.5);
    $summary = $service->prepare('movement-summary', $filters)['query']->first();
    expect((float) $summary->net_quantity)->toBe(8.5);
    $inventory = $service->prepare('inventory', $filters);
    expect((float) $inventory['summary']['قيمة المخزون الحالي'])->toBe(7650.0);
    expect($service->prepare('inventory', array_replace($filters, ['stock_filter' => 'low']))['query']->count())->toBe(0);
});

test('product report separates gifted units from paid sales', function (): void {
    $sale = ($this->makeOrder)();
    OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $sale->id, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 40, 'total_price' => 80]);
    OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $sale->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 0, 'total_price' => 0, 'is_gift' => true]);
    $row = (new ReportingService($this->user))->prepare('product-sales', $this->filters)['query']->first();
    expect((float) $row->sold)->toBe(2.0)->and((float) $row->gift_quantity)->toBe(1.0)->and((float) $row->net_quantity)->toBe(3.0);
});
