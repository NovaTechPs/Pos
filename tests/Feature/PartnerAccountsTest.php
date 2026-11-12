<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeePayroll;
use App\Models\PayrollPeriod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Partners', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->founder = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->tenant->update(['owner_id' => $this->founder->id]);
    $this->profile = Employee::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'employee_code' => 'P-2',
        'first_name' => 'خالد', 'last_name' => 'الشريك', 'hire_date' => '2026-01-01', 'salary_type' => 'monthly', 'basic_salary' => 4000, 'is_active' => true]);
    $this->withSession(['active_tenant_id' => $this->tenant->id]);
});

test('the store owner creates a partner account with a salary profile and no role', function (): void {
    $this->actingAs($this->founder);

    Livewire::test('pages::tenant.employees.index')->assertSee('شريك / صاحب متجر')
        ->set('employee_profile_id', $this->profile->id)->set('email', 'partner@example.test')->set('password', 'password123')
        ->set('branch_id', $this->branch->id)->set('is_partner', true)->set('is_active', true)
        ->call('save')->assertHasNoErrors()->assertSee('صاحب متجر');

    $partner = User::query()->where('email', 'partner@example.test')->sole();
    expect($partner->is_owner)->toBeTrue()
        ->and($partner->role_id)->toBeNull()
        ->and($partner->isTenantOwner())->toBeTrue()
        ->and($this->profile->fresh()->user_id)->toBe($partner->id);

    // The partner is paid like any employee.
    $period = PayrollPeriod::query()->create(['title' => 'October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft']);
    app(PayrollCalculatorService::class)->calculateForPeriod($period);
    expect((float) EmployeePayroll::query()->where('employee_id', $this->profile->id)->sole()->net_salary)->toBe(4000.0);
});

test('managers who are not owners neither see partners nor create them', function (): void {
    $partner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true, 'email' => 'other-partner@example.test']);
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'مدير الموارد']);
    foreach (['employees.view', 'employees.create', 'employees.edit'] as $name) {
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'test']));
    }
    $manager = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $this->actingAs($manager);

    $page = Livewire::test('pages::tenant.employees.index')->assertDontSee('other-partner@example.test')->assertDontSee('شريك / صاحب متجر');
    $page->call('edit', $partner->id)->assertNotFound();

    Livewire::test('pages::tenant.employees.index')
        ->set('employee_profile_id', $this->profile->id)->set('email', 'sneaky@example.test')->set('password', 'password123')
        ->set('branch_id', $this->branch->id)->set('role_id', $role->id)->set('is_partner', true)->set('is_active', true)
        ->call('save')->assertHasNoErrors();
    expect(User::query()->where('email', 'sneaky@example.test')->sole()->is_owner)->toBeFalse();
});

test('owners cannot remove owner status from themselves or the founder, nor deactivate themselves', function (): void {
    $founderProfile = Employee::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'user_id' => $this->founder->id,
        'employee_code' => 'P-1', 'first_name' => 'سامي', 'last_name' => 'المؤسس', 'hire_date' => '2026-01-01', 'basic_salary' => 5000, 'is_active' => true]);
    $partner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->profile->update(['user_id' => $partner->id]);

    $this->actingAs($this->founder);
    Livewire::test('pages::tenant.employees.index')->call('edit', $this->founder->id)->assertSet('is_partner', true)
        ->set('is_partner', false)->call('save')->assertHasErrors('is_partner');
    Livewire::test('pages::tenant.employees.index')->call('edit', $this->founder->id)
        ->set('is_active', false)->call('save')->assertHasErrors('is_active');

    $this->actingAs($partner);
    Livewire::test('pages::tenant.employees.index')->call('edit', $this->founder->id)
        ->set('is_partner', false)->call('save')->assertHasErrors('is_partner');

    expect($this->founder->fresh()->is_owner)->toBeTrue()->and($this->founder->fresh()->is_active)->toBeTrue()
        ->and($founderProfile->fresh()->user_id)->toBe($this->founder->id);

    // The founder can still turn a partner back into a regular employee account.
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier']);
    $this->actingAs($this->founder);
    Livewire::test('pages::tenant.employees.index')->call('edit', $partner->id)
        ->set('is_partner', false)->set('role_id', $role->id)->call('save')->assertHasNoErrors();
    expect($partner->fresh()->is_owner)->toBeFalse();
});
