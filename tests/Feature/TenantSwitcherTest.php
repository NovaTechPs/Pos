<?php

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
