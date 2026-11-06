<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ClientSetupSeeder;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    config(['client-setup.password' => 'Client-Initial-Password42!']);
    $this->seed(ClientSetupSeeder::class);
    $this->employee = User::query()->where('email', 'hade@fanous.com')->sole();
});

test('wholesale employee can log in and reach the dashboard with the configured permissions', function (): void {
    $this->post(route('login.store'), ['email' => $this->employee->email, 'password' => 'Client-Initial-Password42!'])
        ->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertSuccessful()->assertViewIs('pages.tenant.dashboard');
});

test('employee without dashboard access is sent to their permitted wholesale screen after login', function (): void {
    $this->employee->role->permissions()->detach(Permission::query()->where('name', 'dashboard.view')->value('id'));
    $this->post(route('login.store'), ['email' => $this->employee->email, 'password' => 'Client-Initial-Password42!'])
        ->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertRedirect(route('tenant.wholesale-sales'));
    $this->get(route('tenant.wholesale-sales'))->assertSuccessful();
    expect($this->employee->fresh()->hasPermission('dashboard.view'))->toBeFalse();
    $this->get(route('tenant.sale-invoices'))->assertForbidden();
});

test('dashboard fallback respects the permission available to an employee', function (string $permission, string $route): void {
    $this->employee->role->permissions()->sync(Permission::query()->where('name', $permission)->pluck('id'));
    $this->actingAs($this->employee)->get('/dashboard')->assertRedirect(route($route));
})->with([
    ['van_sales.view', 'tenant.wholesale-invoices'],
    ['pos.view', 'tenant.pos'],
    ['sales_invoices.view', 'tenant.sale-invoices'],
    ['purchases.view', 'tenant.purchase-invoices'],
    ['products.view', 'tenant.product'],
    ['customers.view', 'tenant.customer'],
    ['reports.analytics', 'tenant.analytics'],
]);

test('employee without any screen permission remains forbidden', function (): void {
    $this->employee->role->permissions()->sync([]);
    $this->actingAs($this->employee)->get('/dashboard')->assertForbidden();
});

test('permissions assigned to a role in another tenant cannot authorize a dashboard fallback', function (): void {
    $otherTenant = Tenant::query()->create(['name' => 'Other tenant']);
    $role = Role::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other role']);
    $role->permissions()->attach(Permission::query()->where('name', 'wholesale_sales.view')->value('id'));
    expect(fn () => $this->employee->update(['role_id' => $role->id]))->toThrow(QueryException::class);
    $this->employee->setAttribute('role_id', $role->id)->unsetRelation('role');
    $this->actingAs($this->employee)->get('/dashboard')->assertForbidden();
});
