<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Price tenant']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Current branch']);
    $this->otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other branch']);

    $this->pricePermission = Permission::query()->create([
        'name' => 'products.price.edit',
        'display_name' => 'Edit product prices',
        'group' => 'Products',
    ]);
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Price editor']);
    $role->permissions()->attach($this->pricePermission);
    $this->employee = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'role_id' => $role->id,
        'is_owner' => false,
    ]);
    $this->actingAs($this->employee)->withSession([
        'active_tenant_id' => $this->tenant->id,
        'active_branch_id' => $this->branch->id,
    ]);

    $this->product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Branch priced item',
        'cost_price' => 5,
        'is_price_unified' => true,
    ]);
    BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'retail_price' => 10,
        'wholesale_price' => 8,
        'stock_quantity' => 7,
    ]);
    BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->otherBranch->id,
        'product_id' => $this->product->id,
        'retail_price' => 20,
        'wholesale_price' => 18,
        'stock_quantity' => 12,
    ]);
});

test('employee with price permission can change only the active branch prices', function (): void {
    Livewire::test('pages::tenant.products.index')
        ->assertSee('تعديل سعر هذا الفرع')
        ->call('editBranchPrice', $this->product->id)
        ->assertSet('showBranchPriceModal', true)
        ->assertSet('branchRetailPrice', '10.00')
        ->set('branchRetailPrice', '14.50')
        ->set('branchWholesalePrice', '9.00')
        ->call('saveBranchPrice')
        ->assertSet('showBranchPriceModal', false);

    expect((float) BranchProduct::query()->where('branch_id', $this->branch->id)->where('product_id', $this->product->id)->value('retail_price'))->toBe(14.5)
        ->and((float) BranchProduct::query()->where('branch_id', $this->otherBranch->id)->where('product_id', $this->product->id)->value('retail_price'))->toBe(20.0)
        ->and((float) BranchProduct::query()->where('branch_id', $this->branch->id)->where('product_id', $this->product->id)->value('stock_quantity'))->toBe(7.0)
        ->and((bool) $this->product->fresh()->is_price_unified)->toBeFalse();
});

test('employee without price permission cannot open the branch price editor', function (): void {
    $this->employee->role->permissions()->detach($this->pricePermission);
    $this->employee->unsetRelation('role');

    Livewire::test('pages::tenant.products.index')
        ->assertDontSee('تعديل سعر هذا الفرع')
        ->call('editBranchPrice', $this->product->id)
        ->assertForbidden();
});

test('full product edit shows and saves prices for the active branch only', function (): void {
    $productEditPermission = Permission::query()->create([
        'name' => 'products.edit',
        'display_name' => 'Edit products',
        'group' => 'Products',
    ]);
    $this->employee->role->permissions()->attach($productEditPermission);
    $this->employee->unsetRelation('role');

    $component = Livewire::test('pages::tenant.products.index')
        ->call('edit', $this->product->id)
        ->assertSet('is_price_unified', false)
        ->set('branchPricesInput.'.$this->branch->id.'.retail_price', '13')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) BranchProduct::query()->where('branch_id', $this->branch->id)->where('product_id', $this->product->id)->value('retail_price'))->toBe(13.0)
        ->and((float) BranchProduct::query()->where('branch_id', $this->otherBranch->id)->where('product_id', $this->product->id)->value('retail_price'))->toBe(20.0)
        ->and((bool) $this->product->fresh()->is_price_unified)->toBeFalse();
});

test('editing a product can add a new offer for the active branch and leaving it empty adds none', function (): void {
    $productEditPermission = Permission::query()->create([
        'name' => 'products.edit',
        'display_name' => 'Edit products',
        'group' => 'Products',
    ]);
    $this->employee->role->permissions()->attach($productEditPermission);
    $this->employee->unsetRelation('role');

    Livewire::test('pages::tenant.products.index')
        ->call('edit', $this->product->id)
        ->assertSee('إضافة عرض جديد لهذا الصنف')
        ->assertSee('wire:model="newOfferQuantity"', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(ProductOffer::query()->count())->toBe(0);

    Livewire::test('pages::tenant.products.index')
        ->call('edit', $this->product->id)
        ->set('newOfferQuantity', '3')
        ->set('newOfferPrice', '25')
        ->call('save')
        ->assertHasNoErrors();

    $offer = ProductOffer::query()->sole();
    expect($offer->product_id)->toBe($this->product->id)
        ->and($offer->branch_id)->toBe($this->branch->id)
        ->and((float) $offer->offer_quantity)->toBe(3.0)
        ->and((float) $offer->offer_price)->toBe(25.0)
        ->and($offer->end_at)->toBeNull();
});
