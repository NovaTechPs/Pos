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

test('report center shows the header, compact filters and quick ranges without the period cards', function (): void {
    ($this->makeOrder)(['created_at' => now()->toDateTimeString()]);

    Livewire::test('pages::tenant.reports.center')->call('selectReport', 'sales')
        ->assertSee(['مركز التقارير', 'طباعة التقرير', 'تصدير PDF', 'تصدير Excel', 'التحليلات السابقة'])
        ->assertSee(['الحسابات', 'المبيعات', 'المشتريات', 'المخزون', 'الموظفون', 'المالية'])
        ->assertDontSeeHtml('reports-metrics')->assertDontSee(['عدد الحركات', 'إجمالي المشتريات', 'إجمالي الأرباح'])
        ->assertSeeHtml('reports-filter-actions')
        ->assertSee(['اليوم', 'أمس', 'هذا الشهر', 'الشهر السابق', 'آخر 30 يوم', 'إعادة التعيين'])
        ->assertSeeHtml('aria-pressed="true"')
        ->assertSeeHtml('data-report-filter-toggle')
        ->assertSeeHtml('reports-data-scroll')
        ->assertSeeHtml('class="reports-notes"')
        ->assertSee('طي / عرض الفلاتر')
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
    $receiptNotes = 'دفعة على الفاتورة رقم: SAL-20261105160837-873 — تمت معالجته تلقائياً كسند قبض';
    ($this->makeOrder)(['invoice_number' => 'CTR-OPEN', 'total' => 40, 'created_at' => '2026-09-20 12:00:00']);
    ($this->makeOrder)(['invoice_number' => 'CTR-SALE', 'total' => 300]);
    Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $this->customer->id, 'type' => 'receipt',
        'voucher_number' => 'CTR-RCPT', 'amount' => 120, 'payment_method' => 'cash', 'payment_date' => '2026-10-20 12:00:00', 'notes' => $receiptNotes]);

    $component = Livewire::test('pages::tenant.reports.center')->set('report', 'statement')
        ->set('from', '2026-10-01')->set('to', '2026-10-30')
        ->call('showStatementParty', $this->customer->id)
        ->assertSee(['رصيد افتتاحي', 'CTR-SALE', 'CTR-RCPT', 'الإجمالي'])
        ->assertSeeHtml('data-statement-opening')->assertSeeHtml('data-statement-total')
        ->assertSeeHtml('data-statement-scroll')
        ->assertSeeHtml('data-report-filter-toggle')
        ->assertDontSeeHtml('data-statement-closing')
        ->assertDontSee('الرصيد في نهاية الفترة')
        ->assertDontSeeHtml('data-statement-result-end')
        ->assertDontSee('نهاية كشف حساب')
        ->assertSeeHtml('data-statement-filter-panel')
        ->assertSee(['فلاتر كشف الحساب', 'من تاريخ', 'إلى تاريخ', 'الفرع'])
        ->assertDontSee('ملاحظات حول طريقة احتساب الكشف')
        ->assertDontSee('الرصيد المسجل حالياً')
        ->assertDontSeeHtml('reports-ledger-totals')
        ->assertDontSeeHtml('reports-ledger-footer')
        ->assertSeeHtml('data-statement-party-search')
        ->assertSeeHtml('$wire.$commit()');

    $html = $component->html();
    expect($html)->toContain('<td class="reports-statement-notes">'.$receiptNotes.'</td>');
    preg_match('/<tr data-statement-total>.*?<\/tr>/s', $html, $total);
    expect($total[0])->toContain('300.00', '120.00', '320.00');
    preg_match('/<tr data-statement-opening>.*?<\/tr>/s', $html, $opening);
    expect($opening[0])->toContain('140.00');
    expect((string) file_get_contents(resource_path('css/reports.css')))->toContain('max-height:max(220px,calc(100dvh - 400px))', '.reports-account-list{max-height:max(200px,calc(100dvh - 420px))}');
    expect(file_get_contents(resource_path('views/pages/tenant/reports/partials/statement-result.blade.php')))->not->toContain('عدد الحركات');
});

test('statement document links open invoices and every voucher in its voucher screen', function (): void {
    $purchase = Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $this->supplier->id, 'reference_number' => 'CTR-PURCHASE', 'total' => 70, 'paid_amount' => 0, 'created_at' => '2026-10-10']);
    $payment = Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $this->supplier->id, 'type' => 'payment',
        'voucher_number' => 'CTR-VOUCHER', 'amount' => 30, 'payment_method' => 'cash', 'payment_date' => '2026-10-12 12:00:00']);
    $linkedPayment = Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'party_id' => $this->supplier->id, 'purchase_id' => $purchase->id, 'type' => 'payment',
        'voucher_number' => 'CTR-LINKED-VOUCHER', 'amount' => 40, 'payment_method' => 'cash', 'payment_date' => '2026-10-13 12:00:00']);
    $rows = (new ReportingService($this->user))->prepare('statement', $this->filters + ['party_id' => $this->supplier->id, 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all'])['query']->get()->keyBy('reference');

    $component = Livewire::test('pages::tenant.reports.center');
    $urls = ['CTR-PURCHASE' => route('tenant.purchase-invoices', ['purchase' => $purchase->id]), 'CTR-VOUCHER' => route('tenant.payment', ['voucher' => $payment->id]), 'CTR-LINKED-VOUCHER' => route('tenant.payment', ['voucher' => $linkedPayment->id])];
    foreach (['CTR-PURCHASE' => $purchase->id, 'CTR-VOUCHER' => $payment->id, 'CTR-LINKED-VOUCHER' => $linkedPayment->id] as $reference => $id) {
        expect((int) $rows[$reference]->source_id)->toBe($id);
        $component->call('openStatementDocument', (int) $rows[$reference]->source_order, (int) $rows[$reference]->source_id)
            ->assertDispatched('open-statement-document', url: $urls[$reference]);
    }
});

test('opening a legacy sales payment creates and opens its linked receipt without changing the statement balance', function (): void {
    $order = ($this->makeOrder)([
        'type' => 'wholesale',
        'invoice_number' => 'CTR-LEGACY-PAYMENT',
        'invoice_date' => '2026-10-15',
        'subtotal' => 100,
        'total' => 100,
        'paid_amount' => 60,
        'payment_status' => 'partial',
    ]);
    $ledgerBefore = (new ReportingService($this->user, null, false))->partyLedgerBalance($this->customer->id);
    $recordedBalance = (float) $this->customer->current_balance;

    $component = Livewire::test('pages::tenant.reports.center')
        ->call('openStatementDocument', 6, $order->id);

    $receipt = Payment::query()->sole();

    $component->assertDispatched(
        'open-statement-document',
        url: route('tenant.receipts', ['voucher' => $receipt->id]),
        title: 'سند قبض '.$receipt->voucher_number,
    );

    expect($receipt->type)->toBe('receipt')
        ->and((int) $receipt->order_id)->toBe($order->id)
        ->and((int) $receipt->party_id)->toBe($this->customer->id)
        ->and((float) $receipt->amount)->toBe(60.0)
        ->and($receipt->payment_date->toDateString())->toBe('2026-10-15')
        ->and((float) $this->customer->fresh()->current_balance)->toBe($recordedBalance)
        ->and((new ReportingService($this->user, null, false))->partyLedgerBalance($this->customer->id))->toBe($ledgerBefore);
});

test('reports export as a PDF download with the report rows', function (): void {
    ($this->makeOrder)(['invoice_number' => 'CTR-PDF']);

    $response = $this->get(route('tenant.reports.print', ['report' => 'statement', 'format' => 'pdf', 'party_id' => $this->customer->id, 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all'] + $this->filters))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');

    expect($response->streamedContent())->toStartWith('%PDF')
        ->and($response->headers->get('Content-Disposition'))->toContain(rawurlencode('كشف حساب '.$this->customer->name.' 2026-10-01 - 2026-10-30.pdf'));
});

test('report filters flow in one compact grid with small fields', function (): void {
    expect(file_get_contents(resource_path('css/reports.css')))
        ->toContain('.reports-filter-fields>.grid{display:contents}', '.reports-filter-fields>.reports-filter-actions{grid-column:1/-1', '.reports-filter-fields [data-flux-control]{height:30px!important');
});

test('column searches combine across all statement pages without changing ledger balances', function (): void {
    for ($index = 1; $index <= 31; $index++) {
        ($this->makeOrder)(['invoice_number' => 'COL-'.$index, 'notes' => $index === 31 ? 'ملاحظة خاصة' : 'عادي']);
    }

    Livewire::test('pages::tenant.reports.center')
        ->call('showStatementParty', $this->customer->id)
        ->assertSeeHtml('columnFilters.notes')
        ->assertSeeHtml('columnFilters.event_date')
        ->call('gotoPage', 2)
        ->set('columnFilters.notes', 'خاصة')
        ->assertSet('paginators.page', 1)
        ->assertViewHas('rows', function ($rows): bool {
            expect($rows->total())->toBe(1);
            expect($rows->first()->reference)->toBe('COL-31');
            expect((float) $rows->first()->balance)->toBe(9400.0);

            return true;
        })
        ->set('columnFilters.event_date', '2026-10-15')
        ->set('columnFilters.debit', '300.00')
        ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1)
        ->set('columnFilters.balance', '9400.00')
        ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1)
        ->set('columnFilters.reference', 'missing')
        ->assertSee('لا توجد حركات مطابقة للبحث.')
        ->call('clearColumnFilters')
        ->assertSet('columnFilters', [])
        ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 31)
        ->set('columnFilters.notes', 'خاصة')
        ->call('selectReport', 'sales')
        ->assertSet('columnFilters', []);
});

test('column searches treat wildcards literally and ignore unknown column names', function (): void {
    ($this->makeOrder)(['notes' => 'خصم 10%_خاص']);
    ($this->makeOrder)(['notes' => 'خصم عادي']);

    Livewire::test('pages::tenant.reports.center')
        ->call('showStatementParty', $this->customer->id)
        ->set('columnFilters.notes', '%_')
        ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 1)
        ->set('columnFilters', ['unknown' => 'anything'])
        ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 2)
        ->call('resetFilters')
        ->assertSet('columnFilters', []);
});

test('every report supports column searches on its result query', function (string $report): void {
    ($this->makeOrder)();
    $component = Livewire::test('pages::tenant.reports.center')->call('selectReport', $report);
    if ($report === 'statement') {
        $component->call('showStatementParty', $this->customer->id);
    }
    $columns = $component->viewData('definition')['columns'];
    foreach (array_keys($columns) as $column) {
        $component->set('columnFilters', [$column => 'unmatched-value'])->assertHasNoErrors()
            ->assertViewHas('rows', fn ($rows): bool => $rows->total() === 0);
    }
})->with(array_keys(ReportingService::REPORTS));

test('horizontal report layout collapses the account picker and keeps balances and actions available', function (): void {
    ($this->makeOrder)(['invoice_number' => 'DESIGN-203', 'notes' => 'فاتورة توريد أصناف']);
    $component = Livewire::test('pages::tenant.reports.center')
        ->assertSet('showAccountDirectory', true)
        ->assertSeeHtml('reports-category-tabs')
        ->assertSeeHtml('reports-report-tabs')
        ->assertSeeHtml('aria-controls="report-account-picker"')
        ->assertDontSeeHtml('reports-balance-cards')
        ->call('showStatementParty', $this->customer->id)
        ->assertSet('showAccountDirectory', false)
        ->assertSee(['اختيار زبون / مورد', 'الرصيد السابق', 'إجمالي المدين', 'إجمالي الدائن', 'الرصيد النهائي', 'عدد الحركات'])
        ->assertSeeHtml('reports-balance-cards')
        ->assertSeeHtml('columnFilters.notes')
        ->assertSeeHtml('columnFilters.event_date')
        ->assertSeeHtml('reports-result-footer');

    expect($component->html())->toMatch('/id="report-account-picker"\s+hidden/');

    $summary = $component->viewData('definition')['summary'];
    expect((float) $summary['رصيد أول الفترة'])->toBe(100.0)
        ->and((float) $summary['إجمالي المدين'])->toBe(300.0)
        ->and((float) $summary['رصيد آخر الفترة'])->toBe(400.0);

    $component->set('showAccountDirectory', true)
        ->assertSee('طي قائمة الحسابات')
        ->assertViewHas('statementReady', true)
        ->call('resetFilters')->assertSet('showAccountDirectory', true)
        ->assertDontSeeHtml('reports-balance-cards');
});
