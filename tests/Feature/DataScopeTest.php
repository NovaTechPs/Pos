<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleDataScope;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserBranchScope;
use App\Services\DataScope;
use App\Services\ReportingService;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر النطاق', 'is_active' => true]);
    $this->home = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'طوباس']);
    $this->second = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'نابلس']);
    $this->third = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'جنين']);
    $this->role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'بائع']);
    $permissions = collect(['sales_invoices.view', 'sales_invoices.create', 'sales_invoices.edit', 'sales_invoices.delete', 'reports.analytics', 'reports.sales'])
        ->map(fn (string $name): Permission => Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'Sales']));
    $this->role->permissions()->attach($permissions->pluck('id')->all());
    $this->employee = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->home->id, 'role_id' => $this->role->id, 'type' => 'tenant_user', 'is_owner' => false]);
    $this->colleague = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->home->id, 'type' => 'tenant_user', 'is_owner' => false]);
    $this->customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'زبون', 'type' => 'customer']);
    $this->invoice = fn (Branch $branch, User $creator, string $number): Order => Order::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'customer_id' => $this->customer->id, 'created_by' => $creator->id,
        'invoice_number' => $number, 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10,
    ]);
    $this->visible = fn (User $user): array => app(DataScope::class)->apply(Order::query(), $user, 'sales')->orderBy('invoice_number')->pluck('invoice_number')->all();
});

test('without saved scopes an employee keeps today\'s rules: own sales, branch returns, branch reports, every statement', function (): void {
    ($this->invoice)($this->home, $this->employee, 'A-MINE');
    ($this->invoice)($this->home, $this->colleague, 'B-COLLEAGUE');
    ($this->invoice)($this->second, $this->employee, 'C-OTHER-BRANCH');
    $scope = app(DataScope::class);

    expect(($this->visible)($this->employee))->toBe(['A-MINE'])
        ->and($scope->level($this->employee, 'returns', $this->home->id))->toBe(DataScope::FULL)
        ->and($scope->level($this->employee, 'returns', $this->second->id))->toBe(DataScope::NONE)
        ->and($scope->visibleBranchIds($this->employee, 'reports'))->toBe([$this->home->id])
        ->and($scope->visibleBranchIds($this->employee, 'statement'))->toBeNull();
});

test('a job sets its own branch and the other branches, and an employee overrides single branches', function (): void {
    ($this->invoice)($this->home, $this->colleague, 'A-HOME');
    ($this->invoice)($this->second, $this->colleague, 'B-SECOND');
    ($this->invoice)($this->third, $this->colleague, 'C-THIRD');
    RoleDataScope::query()->create(['tenant_id' => $this->tenant->id, 'role_id' => $this->role->id, 'area' => 'sales', 'own_branch' => DataScope::FULL, 'other_branches' => DataScope::VIEW]);
    $scope = app(DataScope::class);

    // The job: everything in his branch, read-only elsewhere.
    expect(($this->visible)($this->employee))->toBe(['A-HOME', 'B-SECOND', 'C-THIRD'])
        ->and($scope->canChange($this->employee, 'sales', $this->home->id, $this->colleague->id))->toBeTrue()
        ->and($scope->canChange($this->employee, 'sales', $this->second->id, $this->colleague->id))->toBeFalse()
        ->and($scope->canView($this->employee, 'sales', $this->second->id, $this->colleague->id))->toBeTrue();

    // This employee: nothing in the third branch.
    UserBranchScope::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id, 'branch_id' => $this->third->id, 'area' => 'sales', 'level' => DataScope::NONE]);
    expect(($this->visible)($this->employee))->toBe(['A-HOME', 'B-SECOND'])
        ->and($scope->canView($this->employee, 'sales', $this->third->id, $this->colleague->id))->toBeFalse()
        ->and($scope->creatableBranchIds($this->employee, 'sales'))->toBe([$this->home->id])
        // The owner always sees every branch.
        ->and(($this->visible)(User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => true])))->toBe(['A-HOME', 'B-SECOND', 'C-THIRD']);
});

test('the sales screen and the invoice PDF follow the employee\'s scope per branch', function (): void {
    $home = ($this->invoice)($this->home, $this->colleague, 'A-HOME');
    $second = ($this->invoice)($this->second, $this->colleague, 'B-SECOND');
    $third = ($this->invoice)($this->third, $this->colleague, 'C-THIRD');
    RoleDataScope::query()->create(['tenant_id' => $this->tenant->id, 'role_id' => $this->role->id, 'area' => 'sales', 'own_branch' => DataScope::FULL, 'other_branches' => DataScope::NONE]);
    UserBranchScope::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id, 'branch_id' => $this->second->id, 'area' => 'sales', 'level' => DataScope::VIEW]);
    $this->actingAs($this->employee)->withSession(['active_tenant_id' => $this->tenant->id]);

    // His branch: opens and edits any invoice, even a colleague's.
    Livewire::test('pages::tenant.sales.invoices')->call('loadOrder', $home)->assertSet('currentOrderId', $home->id)
        ->call('editLoadedInvoice')->assertSet('invoiceEditMode', true);

    // Second branch: opens and prints, but cannot edit.
    Livewire::test('pages::tenant.sales.invoices')->call('loadOrder', $second)->assertSet('currentOrderId', $second->id)
        ->call('editLoadedInvoice')->assertSet('invoiceEditMode', false)->assertSet('errorMessage', DataScope::refusal('sales'))
        // The invoice search filters only by his branches.
        ->set('showInvoiceSearch', true)->assertSee('كل فروعي')
        ->assertViewHas('invoiceBranches', fn ($branches): bool => $branches->pluck('id')->sort()->values()->all() === [$this->home->id, $this->second->id]);
    $this->get(route('tenant.sale-invoices.pdf', $second->id))->assertOk();

    // Third branch: nothing.
    Livewire::test('pages::tenant.sales.invoices')->call('loadOrder', $third)->assertSet('currentOrderId', null)
        ->assertSet('errorMessage', DataScope::refusal('sales'));
    $this->get(route('tenant.sale-invoices.pdf', $third->id))->assertNotFound();
});

test('reports offer only the branches in scope, without «كل الفروع» unless every branch is allowed', function (): void {
    RoleDataScope::query()->create(['tenant_id' => $this->tenant->id, 'role_id' => $this->role->id, 'area' => 'reports', 'own_branch' => DataScope::VIEW, 'other_branches' => DataScope::NONE]);
    UserBranchScope::query()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id, 'branch_id' => $this->second->id, 'area' => 'reports', 'level' => DataScope::VIEW]);

    expect(ReportingService::allowedBranchIds($this->employee, 'sales'))->toBe([$this->home->id, $this->second->id])
        ->and(ReportingService::choosesBranch($this->employee, 'sales'))->toBeTrue()
        ->and((new ReportingService($this->employee, null, false, 'sales'))->branchId)->toBe($this->home->id)
        ->and((new ReportingService($this->employee, $this->second->id, false, 'sales'))->branchId)->toBe($this->second->id)
        // A branch out of scope falls back to his own.
        ->and((new ReportingService($this->employee, $this->third->id, false, 'sales'))->branchId)->toBe($this->home->id);

    $this->actingAs($this->employee)->withSession(['active_tenant_id' => $this->tenant->id]);
    Livewire::withQueryParams(['report' => 'sales'])->test('pages::tenant.reports.center')
        ->assertSet('branchId', $this->home->id)
        ->assertSee('نابلس')->assertDontSee('جنين')->assertDontSee('كل الفروع')
        ->set('branchId', '')->assertSet('branchId', $this->home->id)
        ->set('branchId', $this->third->id)->assertSet('branchId', $this->home->id);
});

test('the owner sets a job\'s scope on the jobs screen and an employee\'s exceptions on the employees screen', function (): void {
    $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => true]);
    $this->actingAs($owner)->withSession(['active_tenant_id' => $this->tenant->id]);
    foreach (['roles.edit', 'roles.permissions', 'employees.edit'] as $name) {
        Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'Admin']);
    }

    Livewire::test('pages::tenant.roles.index')
        ->call('edit', $this->role->id)
        ->assertSeeHtml('data-role-data-scopes')->assertSee('نطاق البيانات')
        ->assertSet('dataScopes.sales', ['own' => DataScope::OWN, 'other' => DataScope::NONE])
        ->set('dataScopes.sales.own', DataScope::FULL)
        ->set('dataScopes.sales.other', DataScope::VIEW)
        ->set('dataScopes.reports.other', DataScope::FULL) // not accepted for reports: keeps the default
        ->call('save')->assertHasNoErrors();

    expect(app(DataScope::class)->roleDefault($this->role->id, 'sales'))->toBe(['own' => DataScope::FULL, 'other' => DataScope::VIEW])
        ->and(app(DataScope::class)->roleDefault($this->role->id, 'reports'))->toBe(DataScope::DEFAULTS['reports']);

    Employee::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->home->id, 'user_id' => $this->employee->id, 'employee_code' => 'E-1',
        'first_name' => 'بائع', 'last_name' => 'أول', 'hire_date' => '2026-01-01', 'basic_salary' => 1000]);
    Livewire::test('pages::tenant.employees.index')
        ->call('edit', $this->employee->id)
        ->assertSeeHtml('data-employee-branch-scopes')
        ->assertSee('حسب الوظيفة (كامل)')->assertSee('حسب الوظيفة (عرض فقط)')
        ->set("branchScopes.{$this->third->id}.sales", DataScope::NONE)
        ->call('save')->assertHasNoErrors();

    expect(UserBranchScope::query()->where('user_id', $this->employee->id)->get(['branch_id', 'area', 'level'])->toArray())
        ->toBe([['branch_id' => $this->third->id, 'area' => 'sales', 'level' => DataScope::NONE]])
        ->and(app(DataScope::class)->level($this->employee->fresh(), 'sales', $this->third->id))->toBe(DataScope::NONE)
        ->and(app(DataScope::class)->level($this->employee->fresh(), 'sales', $this->second->id))->toBe(DataScope::VIEW);
});

test('receipt and payment vouchers follow their own scope per branch', function (): void {
    $voucher = fn (Branch $branch, User $creator, string $type, string $number): Payment => Payment::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'party_id' => $this->customer->id, 'created_by' => $creator->id,
        'type' => $type, 'voucher_number' => $number, 'amount' => 5, 'payment_method' => 'cash', 'payment_date' => now(),
    ]);
    $mine = $voucher($this->home, $this->employee, 'receipt', 'R-MINE');
    $colleagues = $voucher($this->home, $this->colleague, 'receipt', 'R-COLLEAGUE');
    $voucher($this->second, $this->colleague, 'receipt', 'R-SECOND');
    $voucher($this->second, $this->colleague, 'payment', 'P-SECOND');
    $numbers = fn (): array => Payment::query()->visibleTo($this->employee)->orderBy('voucher_number')->pluck('voucher_number')->all();

    // Without a saved scope: only the vouchers he wrote.
    expect($numbers())->toBe(['R-MINE'])
        ->and($mine->mayBeChangedBy($this->employee))->toBeTrue()
        ->and($colleagues->mayBeChangedBy($this->employee))->toBeFalse();

    // Receipts: everything in his branch, read-only in the second; payments stay his own.
    RoleDataScope::query()->create(['tenant_id' => $this->tenant->id, 'role_id' => $this->role->id, 'area' => 'receipts', 'own_branch' => DataScope::FULL, 'other_branches' => DataScope::VIEW]);
    expect($numbers())->toBe(['R-COLLEAGUE', 'R-MINE', 'R-SECOND'])
        ->and($colleagues->mayBeChangedBy($this->employee))->toBeTrue()
        ->and(Payment::query()->where('voucher_number', 'R-SECOND')->first()->mayBeChangedBy($this->employee))->toBeFalse();
});

test('the app shows and changes only the point of sale invoices in the employee\'s scope', function (): void {
    $order = fn (Branch $branch, User $creator, string $number): Order => Order::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'created_by' => $creator->id,
        'invoice_number' => $number, 'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10,
    ]);
    $colleagues = $order($this->home, $this->colleague, 'POS-COLLEAGUE');
    $mine = $order($this->home, $this->employee, 'POS-MINE');
    $permissions = collect(['pos.invoice.search', 'sales_invoices.view', 'sales_invoices.delete'])
        ->map(fn (string $name): Permission => Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'Sales']));
    $this->role->permissions()->syncWithoutDetaching($permissions->pluck('id')->all());
    $this->actingAs($this->employee);

    // By default an employee reaches only the invoices they created.
    $this->getJson('/api/pos/invoices?branch_id='.$this->home->id)->assertOk()->assertJsonPath('order.invoice_number', 'POS-MINE');
    $this->getJson('/api/pos/invoices?branch_id='.$this->home->id.'&search=POS-COLLEAGUE')->assertNotFound();

    // «عرض فقط» in his branch: sees the colleague's invoice but cannot delete it.
    RoleDataScope::query()->create(['tenant_id' => $this->tenant->id, 'role_id' => $this->role->id, 'area' => 'pos', 'own_branch' => DataScope::VIEW, 'other_branches' => DataScope::NONE]);
    $this->getJson('/api/pos/invoices?branch_id='.$this->home->id.'&search=POS-COLLEAGUE')->assertOk()
        ->assertJsonPath('order.editable_in_pos', false);
    $this->deleteJson('/api/pos/invoices/'.$colleagues->id)->assertForbidden();
    expect($mine->fresh())->not->toBeNull();
});
