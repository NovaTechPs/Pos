<?php

use App\Models\Branch;
use App\Models\Category;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Permissions shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'مراقب']);
    foreach (['products.view', 'categories.view', 'customers.view'] as $name) {
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'test']));
    }
    $this->viewer = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $this->actingAs($this->viewer)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
});

test('view-only staff cannot change products, categories or customers', function (): void {
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف', 'show_in_website' => true]);
    $category = Category::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'تصنيف', 'is_active' => true]);
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'زبون', 'type' => 'customer', 'is_active' => true]);

    Livewire::test('pages::tenant.products.index')->call('toggleWebsiteStatus', $product->id)->assertForbidden();
    Livewire::test('pages::tenant.products.index')->call('removeSingleExistingImage')->assertForbidden();
    Livewire::test('pages::tenant.products.index')->call('removeExistingImage', 0)->assertForbidden();
    Livewire::test('pages::tenant.categories.index')->call('toggleStatus', $category->id)->assertForbidden();
    Livewire::test('pages::tenant.customers.index')->call('deactivateParty', $customer->id)->assertForbidden();

    expect($product->fresh()->show_in_website)->toBeTrue()
        ->and((bool) $category->fresh()->is_active)->toBeTrue()
        ->and((bool) $customer->fresh()->is_active)->toBeTrue();
});

test('staff with edit permissions can still use these actions', function (): void {
    $this->viewer->role->permissions()->attach(collect(['products.edit', 'categories.edit', 'customers.delete'])
        ->map(fn (string $name) => Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'test'])->id));
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف', 'show_in_website' => true]);
    $category = Category::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'تصنيف', 'is_active' => true]);

    Livewire::test('pages::tenant.products.index')->call('toggleWebsiteStatus', $product->id)->assertOk();
    Livewire::test('pages::tenant.categories.index')->call('toggleStatus', $category->id)->assertOk();

    expect($product->fresh()->show_in_website)->toBeFalse()
        ->and((bool) $category->fresh()->is_active)->toBeFalse();
});
