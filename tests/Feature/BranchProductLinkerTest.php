<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchProductLinker;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Linker tenant']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'First branch']);
    $this->otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Second branch']);
});

function linkerProduct(Tenant $tenant, Branch $branch, bool $unified): Product
{
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'Item '.($unified ? 'unified' : 'separate'),
        'cost_price' => 5, 'is_price_unified' => $unified]);
    BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id,
        'retail_price' => 12, 'wholesale_price' => 9, 'min_wholesale_quantity' => 6, 'stock_quantity' => 30]);

    return $product;
}

test('a product that exists in one branch is linked to the other branches', function (): void {
    $unified = linkerProduct($this->tenant, $this->branch, true);
    $separate = linkerProduct($this->tenant, $this->branch, false);

    expect(app(BranchProductLinker::class)->linkMissing())->toBe(2);

    $row = BranchProduct::query()->where('branch_id', $this->otherBranch->id)->where('product_id', $unified->id)->sole();
    expect((float) $row->retail_price)->toBe(12.0)
        ->and((float) $row->wholesale_price)->toBe(9.0)
        ->and((float) $row->min_wholesale_quantity)->toBe(6.0)
        ->and((float) $row->stock_quantity)->toBe(0.0);

    $row = BranchProduct::query()->where('branch_id', $this->otherBranch->id)->where('product_id', $separate->id)->sole();
    expect((float) $row->retail_price)->toBe(0.0)
        ->and((float) $row->wholesale_price)->toBe(0.0)
        ->and((float) $row->stock_quantity)->toBe(0.0);

    expect((float) BranchProduct::query()->where('branch_id', $this->branch->id)->where('product_id', $unified->id)->sole()->stock_quantity)->toBe(30.0)
        ->and(app(BranchProductLinker::class)->linkMissing())->toBe(0);
});

test('linking never crosses stores', function (): void {
    linkerProduct($this->tenant, $this->branch, true);
    $otherTenant = Tenant::query()->create(['name' => 'Another store']);
    $foreignBranch = Branch::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Foreign branch']);

    app(BranchProductLinker::class)->linkMissing();

    expect(BranchProduct::query()->withoutGlobalScopes()->where('branch_id', $foreignBranch->id)->count())->toBe(0);
});

test('a new branch receives the products the store already has', function (): void {
    $product = linkerProduct($this->tenant, $this->branch, true);
    app(BranchProductLinker::class)->linkMissing();
    $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);

    Livewire::test('pages::tenant.branches.index')
        ->call('openCreateModal')
        ->set('name', 'Third branch')
        ->set('domain', 'third-branch')
        ->set('type', 'branch')
        ->call('save')
        ->assertHasNoErrors();

    $newBranch = Branch::query()->where('tenant_id', $this->tenant->id)->where('name', 'Third branch')->sole();
    $row = BranchProduct::query()->where('branch_id', $newBranch->id)->where('product_id', $product->id)->sole();
    expect((float) $row->retail_price)->toBe(12.0)->and((float) $row->stock_quantity)->toBe(0.0);
});
