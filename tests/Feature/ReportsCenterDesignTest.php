<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
    $this->tenant = Tenant::create(['name' => 'متجر المركز', 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الرئيسي']);
    $this->otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'فرع ثان']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => true, 'branch_id' => null]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون المركز', 'type' => 'customer', 'opening_balance' => 100, 'created_at' => '2026-09-01']);
    $this->supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد المركز', 'type' => 'supplier', 'opening_balance' => 0, 'created_at' => '2026-09-01']);
    $this->makeOrder = fn (array $attributes = []): Order => Order::create($attributes + ['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'invoice_number' => 'CTR-'.str()->uuid(), 'type' => 'pos', 'status' => 'completed', 'subtotal' => 300, 'total' => 300, 'total_cost' => 120, 'tax_amount' => 0, 'discount' => 0, 'delivery_fee' => 0, 'paid_amount' => 0, 'created_at' => '2026-10-15 12:00:00']);
    $this->filters = ['from' => '2026-10-01', 'to' => '2026-10-30'];
});

test('overview totals sales purchases and gross profit for the period and branch only', function (): void {
    ($this->makeOrder)();
    ($this->makeOrder)(['type' => 'return', 'total' => -50, 'total_cost' => 0, 'created_at' => '2026-10-16 12:00:00']);
    ($this->makeOrder)(['created_at' => '2026-09-15 12:00:00']);
    ($this->makeOrder)(['branch_id' => $this->otherBranch->id]);
    ($this->makeOrder)(['status' => 'cancelled']);
    Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'CTR-PUR', 'total' => 80, 'paid_amount' => 0, 'created_at' => '2026-10-10']);
    Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->otherBranch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'CTR-PUR-2', 'total' => 999, 'paid_amount' => 0, 'created_at' => '2026-10-10']);

    $overview = (new ReportingService($this->user, $this->branch->id, false))->overview($this->filters);

    expect($overview['sales'])->toBe(250.0)
        ->and($overview['purchases'])->toBe(80.0)
        ->and($overview['profit'])->toBe(130.0);
});

test('overview hides figures the user may not see and the page shows only allowed cards', function (): void {
    $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Accounts only']);
    foreach (['reports.analytics', 'customers.view'] as $name) {
        $role->permissions()->attach(Permission::create(['name' => $name, 'display_name' => $name, 'group' => 'reports'])->id);
    }
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id, 'branch_id' => $this->branch->id, 'is_owner' => false, 'type' => 'tenant_user']);

    expect((new ReportingService($staff))->overview($this->filters))->toBe(['sales' => null, 'purchases' => null, 'profit' => null]);
    Livewire::actingAs($staff)->test('pages::tenant.reports.center')
        ->assertDontSee('إجمالي المبيعات')->assertDontSee('إجمالي الأرباح')
        ->assertSee('الحسابات')->assertDontSeeHtml('selectReportGroup(\'المبيعات\')');
});

test('report center shows the designed header tabs cards and quick ranges with real totals', function (): void {
    ($this->makeOrder)(['created_at' => now()->toDateTimeString()]);

    Livewire::test('pages::tenant.reports.center')->call('selectReport', 'sales')
        ->assertSee(['مركز التقارير', 'طباعة التقرير', 'تصدير PDF', 'تصدير Excel', 'التحليلات السابقة'])
        ->assertSee(['الحسابات', 'المبيعات', 'المشتريات', 'المخزون', 'الموظفون', 'المالية'])
        ->assertSee(['عدد الحركات', 'إجمالي المبيعات', 'إجمالي المشتريات', 'إجمالي الأرباح', '₪ 300.00'])
        ->assertSee(['اليوم', 'أمس', 'هذا الشهر', 'الشهر السابق', 'آخر 30 يوم', 'إعادة التعيين'])
        ->assertSeeHtml('aria-pressed="true"')
        ->call('setPeriod', 'last-30')
        ->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-30');
});

test('tabs switch to the first allowed report of the group and the side menu lists its reports', function (): void {
    Livewire::test('pages::tenant.reports.center')
        ->call('selectReportGroup', 'المخزون')->assertSet('report', 'product-history')
        ->assertSee('تقارير المخزون')->assertSee(ReportingService::REPORTS['inventory'][0])
        ->call('selectReport', 'balances')->assertSet('report', 'balances')
        ->call('selectReportGroup', 'الحسابات')->assertSet('report', 'statement');
});

test('selecting a report the user may not open is forbidden', function (): void {
    $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Sales only']);
    foreach (['reports.analytics', 'reports.sales'] as $name) {
        $role->permissions()->attach(Permission::create(['name' => $name, 'display_name' => $name, 'group' => 'reports'])->id);
    }
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'role_id' => $role->id, 'branch_id' => $this->branch->id, 'is_owner' => false, 'type' => 'tenant_user']);

    Livewire::actingAs($staff)->test('pages::tenant.reports.center')->call('selectReport', 'statement')->assertForbidden();
    Livewire::actingAs($staff)->test('pages::tenant.reports.center')->call('selectReportGroup', 'الموظفون')->assertForbidden();
});

test('reset filters clears the party and returns to this month', function (): void {
    Livewire::test('pages::tenant.reports.center')
        ->set('partyId', $this->customer->id)->set('partySearch', 'زبون')->call('setPeriod', 'yesterday')
        ->call('resetFilters')
        ->assertSet('partyId', null)->assertSet('partySearch', '')
        ->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-30');
});

test('statement shows opening balance, linked documents and a totals row', function (): void {
    ($this->makeOrder)(['invoice_number' => 'CTR-OPEN', 'total' => 40, 'created_at' => '2026-09-20 12:00:00']);
    ($this->makeOrder)(['invoice_number' => 'CTR-SALE', 'total' => 300]);
    Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $this->customer->id, 'type' => 'receipt',
        'voucher_number' => 'CTR-RCPT', 'amount' => 120, 'payment_method' => 'cash', 'payment_date' => '2026-10-20 12:00:00']);

    $component = Livewire::test('pages::tenant.reports.center')->set('report', 'statement')
        ->set('from', '2026-10-01')->set('to', '2026-10-30')
        ->call('showStatementParty', $this->customer->id)
        ->assertSee(['رصيد افتتاحي', 'CTR-SALE', 'CTR-RCPT', 'الإجمالي', 'نهاية كشف حساب: زبون المركز'])
        ->assertSeeHtml('data-statement-opening')->assertSeeHtml('data-statement-total')
        ->assertSeeHtml('data-statement-result-end')
        ->assertSeeHtml('data-statement-filter-panel')
        ->assertSee(['فلاتر كشف الحساب', 'من تاريخ', 'إلى تاريخ', 'الفرع'])
        ->assertDontSee('ملاحظات حول طريقة احتساب الكشف')
        ->assertDontSee('الرصيد المسجل حالياً')
        ->assertDontSeeHtml('reports-ledger-totals')
        ->assertDontSeeHtml('reports-ledger-footer');

    $html = $component->html();
    preg_match('/<tr data-statement-total>.*?<\/tr>/s', $html, $total);
    expect($total[0])->toContain('300.00', '120.00', '320.00');
    preg_match('/<tr data-statement-opening>.*?<\/tr>/s', $html, $opening);
    expect($opening[0])->toContain('140.00');
});

test('statement document links open the matching purchase, return and voucher', function (): void {
    $purchase = Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'CTR-PURCHASE', 'total' => 70, 'paid_amount' => 0, 'created_at' => '2026-10-10']);
    $payment = Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $this->supplier->id, 'type' => 'payment',
        'voucher_number' => 'CTR-VOUCHER', 'amount' => 30, 'payment_method' => 'cash', 'payment_date' => '2026-10-12 12:00:00']);
    $rows = (new ReportingService($this->user))->prepare('statement', $this->filters + ['party_id' => $this->supplier->id, 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all'])['query']->get()->keyBy('reference');

    $component = Livewire::test('pages::tenant.reports.center');
    foreach (['CTR-PURCHASE' => $purchase->id, 'CTR-VOUCHER' => $payment->id] as $reference => $id) {
        expect((int) $rows[$reference]->source_id)->toBe($id);
        $component->call('openStatementDocument', (int) $rows[$reference]->source_order, (int) $rows[$reference]->source_id)
            ->assertSet('statementDocument.reference', $reference);
    }
});

test('reports export as a PDF download with the report rows', function (): void {
    ($this->makeOrder)(['invoice_number' => 'CTR-PDF']);

    $response = $this->get(route('tenant.reports.print', ['report' => 'statement', 'format' => 'pdf', 'party_id' => $this->customer->id, 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all'] + $this->filters))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect($response->streamedContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain('report-statement-2026-10-01-2026-10-30.pdf');
});
