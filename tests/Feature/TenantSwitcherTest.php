<?php

use App\Models\Branch;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

test('system administrators can switch between active stores', function (): void {
    $manager = User::factory()->create([
        'type' => 'saas_admin',
        'tenant_id' => null,
        'is_owner' => false,
    ]);
    $firstTenant = Tenant::query()->create(['name' => 'المتجر الأول', 'is_active' => true]);
    $secondTenant = Tenant::query()->create(['name' => 'المتجر الثاني', 'is_active' => true]);
    $inactiveTenant = Tenant::query()->create(['name' => 'متجر غير نشط', 'is_active' => false]);

    $this->actingAs($manager)->withSession(['active_tenant_id' => $firstTenant->id]);

    Livewire::test('tenant-switcher')
        ->assertSet('canSwitchTenants', true)
        ->assertSet('currentTenantId', $firstTenant->id)
        ->assertSee($firstTenant->name)
        ->assertSee($secondTenant->name)
        ->assertDontSee($inactiveTenant->name)
        ->call('switchTenant', $secondTenant->id)
        ->assertSet('currentTenantId', $secondTenant->id);

    expect(session('active_tenant_id'))->toBe($secondTenant->id);
});

test('store owners can switch only between their own active stores', function (): void {
    $owner = User::factory()->create([
        'type' => 'tenant_user',
        'is_owner' => true,
    ]);
    $firstTenant = Tenant::query()->create(['name' => 'متجر المالك الأول', 'owner_id' => $owner->id, 'is_active' => true]);
    $secondTenant = Tenant::query()->create(['name' => 'متجر المالك الثاني', 'owner_id' => $owner->id, 'is_active' => true]);
    $otherTenant = Tenant::query()->create(['name' => 'متجر مالك آخر', 'is_active' => true]);

    $this->actingAs($owner)->withSession(['active_tenant_id' => $firstTenant->id]);
    $component = Livewire::test('tenant-switcher')
        ->assertSet('canSwitchTenants', true)
        ->call('switchTenant', $otherTenant->id)
        ->assertSet('currentTenantId', $firstTenant->id);

    $component->call('switchTenant', $secondTenant->id)
        ->assertSet('currentTenantId', $secondTenant->id);

    expect(session('active_tenant_id'))->toBe($secondTenant->id);
});

test('store employees cannot switch to another store', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر الموظف', 'is_active' => true]);
    $otherTenant = Tenant::query()->create(['name' => 'متجر آخر', 'is_active' => true]);
    $employee = User::factory()->create([
        'type' => 'tenant_user',
        'tenant_id' => $tenant->id,
        'is_owner' => false,
    ]);

    $this->actingAs($employee)->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::test('tenant-switcher')
        ->assertSet('canSwitchTenants', false)
        ->call('switchTenant', $otherTenant->id)
        ->assertSet('currentTenantId', $tenant->id);

    expect(session('active_tenant_id'))->toBe($tenant->id);
});

test('store owners can switch between branches in their active store', function (): void {
    $owner = User::factory()->create([
        'type' => 'tenant_user',
        'is_owner' => true,
    ]);
    $tenant = Tenant::query()->create(['name' => 'متجر الفروع', 'owner_id' => $owner->id, 'is_active' => true]);
    $firstBranch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع الأول', 'type' => 'branch']);
    $secondBranch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع الثاني', 'type' => 'branch']);
    $otherTenant = Tenant::query()->create(['name' => 'متجر آخر', 'is_active' => true]);
    $otherBranch = Branch::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'فرع غير مسموح', 'type' => 'branch']);

    $this->actingAs($owner)->withSession([
        'active_tenant_id' => $tenant->id,
        'active_branch_id' => $firstBranch->id,
    ]);

    Livewire::test('tenant-switcher')
        ->assertSet('canSwitchBranches', true)
        ->assertSee($firstBranch->name)
        ->assertSee($secondBranch->name)
        ->call('switchBranch', $otherBranch->id)
        ->assertSet('currentBranchId', $firstBranch->id)
        ->call('switchBranch', $secondBranch->id)
        ->assertSet('currentBranchId', $secondBranch->id);

    expect(session('active_branch_id'))->toBe($secondBranch->id);
});

test('owner branch selection takes precedence over their assigned home branch', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر الاختبار']);
    $homeBranch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع الأساسي']);
    $selectedBranch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع المحدد']);
    $owner = User::factory()->create([
        'type' => 'tenant_user',
        'tenant_id' => $tenant->id,
        'branch_id' => $homeBranch->id,
        'is_owner' => true,
    ]);

    $this->actingAs($owner)->withSession(['active_branch_id' => $selectedBranch->id]);

    expect($owner->activeBranchId())->toBe($selectedBranch->id);
});

test('system administrators can link an existing store to its owner', function (): void {
    $admin = User::factory()->create(['type' => 'saas_admin', 'tenant_id' => null]);
    $owner = User::factory()->create(['type' => 'tenant_user', 'is_owner' => true]);
    $plan = Plan::query()->create(['name' => 'الباقة الأساسية', 'slug' => 'basic']);
    $tenant = Tenant::query()->create(['name' => 'متجر غير مربوط', 'plan_id' => $plan->id, 'is_active' => true]);

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('edit', $tenant->id)
        ->set('selected_user_id', $owner->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($tenant->fresh()->owner_id)->toBe($owner->id);

    $firstBranch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع الأول', 'type' => 'branch']);
    Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع الثاني', 'type' => 'branch']);
    $this->actingAs($owner)->withSession([
        'active_tenant_id' => $tenant->id,
        'active_branch_id' => $firstBranch->id,
    ]);

    Livewire::test('tenant-switcher')->assertSet('canSwitchBranches', true);
});
