<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

test('creating a dashboard account links an existing payroll employee profile', function (): void {
    $tenant = Tenant::create(['name' => 'Account link tenant', 'is_active' => true]);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Cashier']);
    $profile = Employee::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'employee_code' => 'EMP-0042',
        'first_name' => 'ليلى',
        'last_name' => 'محمود',
        'hire_date' => today(),
    ]);

    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $this->actingAs($owner);
    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::test('pages::tenant.employees.index')
        ->set('employee_profile_id', $profile->id)
        ->set('email', 'layla@example.test')
        ->set('password', 'password123')
        ->set('branch_id', $branch->id)
        ->set('role_id', $role->id)
        ->set('is_active', true)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'layla@example.test')->firstOrFail();
    $profile->refresh();

    expect($profile->user_id)->toBe($user->id)
        ->and($user->name)->toBe('ليلى محمود')
        ->and($user->employee?->id)->toBe($profile->id)
        ->and(Employee::query()->count())->toBe(1);
});

test('a linked payroll profile cannot be linked to a second account', function (): void {
    $tenant = Tenant::create(['name' => 'Account link tenant', 'is_active' => true]);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Cashier']);
    $existingUser = User::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'type' => 'tenant_user',
        'is_owner' => false,
    ]);
    $profile = Employee::create([
        'user_id' => $existingUser->id,
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'employee_code' => 'EMP-0043',
        'first_name' => 'سامي',
        'last_name' => 'علي',
        'hire_date' => today(),
    ]);

    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $this->actingAs($owner);
    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::test('pages::tenant.employees.index')
        ->set('employee_profile_id', $profile->id)
        ->set('email', 'second@example.test')
        ->set('password', 'password123')
        ->set('branch_id', $branch->id)
        ->set('role_id', $role->id)
        ->set('is_active', true)
        ->call('save')
        ->assertHasErrors(['employee_profile_id']);

    expect(User::query()->where('email', 'second@example.test')->exists())->toBeFalse();
});

test('editing an account can relink it without losing the previous employee record', function (): void {
    $tenant = Tenant::create(['name' => 'Account relink tenant', 'is_active' => true]);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Cashier']);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'role_id' => $role->id,
        'type' => 'tenant_user',
        'is_owner' => false,
    ]);
    $linkedProfile = Employee::create([
        'user_id' => $user->id,
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'employee_code' => 'EMP-0044',
        'first_name' => 'سامي',
        'last_name' => 'علي',
        'hire_date' => today(),
    ]);
    $replacementProfile = Employee::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'employee_code' => 'EMP-0045',
        'first_name' => 'عمر',
        'last_name' => 'حسن',
        'hire_date' => today(),
    ]);

    $owner = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $this->actingAs($owner);
    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::test('pages::tenant.employees.index')
        ->call('edit', $user->id)
        ->set('employee_profile_id', $replacementProfile->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($linkedProfile->fresh()->user_id)->toBeNull()
        ->and($replacementProfile->fresh()->user_id)->toBe($user->id)
        ->and(Employee::query()->where('user_id', $user->id)->count())->toBe(1);
});
