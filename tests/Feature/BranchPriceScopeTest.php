<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Prices of $product in a branch.
 */
function branchRetailPrice(Branch $branch, Product $product): float
{
    return (float) BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->value('retail_price');
}

function grantPriceBranch(User $user, Branch $branch): void
{
    DB::table('user_price_branches')->insert([
        'tenant_id' => $branch->tenant_id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Scope tenant', 'is_active' => true]);
    $this->ownBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Own branch']);
    $this->otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other branch']);
    $this->thirdBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Third branch']);

    $this->branchPricesPermission = Permission::query()->create([
        'name' => 'products.branch_prices.edit',
        'display_name' => 'Edit branch prices',
        'group' => 'Products',
    ]);
    $this->role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Branch pricer']);
    $this->role->permissions()->attach($this->branchPricesPermission);

    $this->employee = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->ownBranch->id,
        'role_id' => $this->role->id,
        'is_owner' => false,
    ]);

    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Scoped item', 'cost_price' => 4]);
    foreach ([$this->ownBranch, $this->otherBranch, $this->thirdBranch] as $index => $branch) {
        BranchProduct::query()->create([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'product_id' => $this->product->id,
            'retail_price' => 10 * ($index + 1),
            'wholesale_price' => 0,
            'stock_quantity' => 0,
        ]);
    }

    $this->actingAs($this->employee)->withSession([
        'active_tenant_id' => $this->tenant->id,
        'active_branch_id' => $this->ownBranch->id,
    ]);
});

test('by default the permission covers only the employee own branch', function (): void {
    expect($this->employee->priceEditableBranchIds($this->tenant->id))->toBe([$this->ownBranch->id]);

    Livewire::test('pages::tenant.products.index')
        ->assertSee('تعديل سعر هذا الفرع')
        ->call('editBranchPrice', $this->product->id)
        ->assertSet('branchPriceBranchId', $this->ownBranch->id)
        ->set('branchRetailPrice', '11.50')
        ->call('saveBranchPrice')
        ->assertHasNoErrors();

    expect(branchRetailPrice($this->ownBranch, $this->product))->toBe(11.5)
        ->and(branchRetailPrice($this->otherBranch, $this->product))->toBe(20.0);
});

test('an employee cannot edit prices of a branch outside their scope', function (): void {
    Livewire::test('pages::tenant.products.index')
        ->call('editBranchPrice', $this->product->id, $this->otherBranch->id)
        ->assertForbidden();

    Livewire::test('pages::tenant.products.index')
        ->call('editBranchPrice', $this->product->id)
        ->set('branchPriceBranchId', $this->otherBranch->id)
        ->assertForbidden();

    expect(branchRetailPrice($this->otherBranch, $this->product))->toBe(20.0);
});

test('branches granted by the owner become editable, others stay read-only', function (): void {
    grantPriceBranch($this->employee, $this->otherBranch);

    Livewire::test('pages::tenant.products.index')
        ->assertSee('تعديل أسعار الفروع')
        ->call('editBranchPrice', $this->product->id, $this->otherBranch->id)
        ->assertSet('branchRetailPrice', '20.00')
        ->assertSee('للعرض فقط')
        ->set('branchRetailPrice', '25')
        ->call('saveBranchPrice')
        ->assertHasNoErrors();

    expect(branchRetailPrice($this->otherBranch, $this->product))->toBe(25.0)
        ->and(branchRetailPrice($this->thirdBranch, $this->product))->toBe(30.0);

    Livewire::test('pages::tenant.products.index')
        ->call('editBranchPrice', $this->product->id, $this->thirdBranch->id)
        ->assertForbidden();
});

test('granted branches need the branch prices permission', function (): void {
    grantPriceBranch($this->employee, $this->otherBranch);
    $ownBranchOnly = Permission::query()->create(['name' => 'products.price.edit', 'display_name' => 'Edit prices', 'group' => 'Products']);
    $this->role->permissions()->sync([$ownBranchOnly->id]);
    $this->employee->unsetRelation('role');

    expect($this->employee->priceEditableBranchIds($this->tenant->id))->toBe([$this->ownBranch->id]);
});

test('the owner can edit prices in every branch', function (): void {
    $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->ownBranch->id, 'is_owner' => true]);
    $this->actingAs($owner);

    Livewire::test('pages::tenant.products.index')
        ->call('editBranchPrice', $this->product->id, $this->thirdBranch->id)
        ->set('branchRetailPrice', '33')
        ->call('saveBranchPrice')
        ->assertHasNoErrors();

    expect(branchRetailPrice($this->thirdBranch, $this->product))->toBe(33.0);
});

test('branches of another store are never in scope', function (): void {
    $foreignTenant = Tenant::query()->create(['name' => 'Foreign tenant', 'is_active' => true]);
    $foreignBranch = Branch::query()->create(['tenant_id' => $foreignTenant->id, 'name' => 'Foreign branch']);

    expect($this->employee->priceEditableBranchIds($foreignTenant->id))->toBe([])
        ->and($this->employee->canEditBranchPrice($foreignBranch->id))->toBeFalse();

    Livewire::test('pages::tenant.products.index')
        ->call('editBranchPrice', $this->product->id, $foreignBranch->id)
        ->assertForbidden();
});

test('offers can be saved only for branches in scope', function (): void {
    $component = Livewire::test('pages::tenant.products.index')
        ->call('openOfferModal', $this->product->id)
        ->assertSet('offerBranchId', $this->ownBranch->id)
        ->set('offerBranchId', $this->otherBranch->id)
        ->set('offerQuantity', '2')
        ->set('offerPrice', '15')
        ->call('saveOffer')
        ->assertForbidden();

    expect(ProductOffer::query()->count())->toBe(0);
});

test('a full product edit cannot change prices without price scope', function (): void {
    $productEdit = Permission::query()->create(['name' => 'products.edit', 'display_name' => 'Edit products', 'group' => 'Products']);
    $this->role->permissions()->sync([$productEdit->id]);
    $this->employee->unsetRelation('role');

    Livewire::test('pages::tenant.products.index')
        ->call('edit', $this->product->id)
        ->assertSee('أسعار هذا الفرع للعرض فقط')
        ->set('branchPricesInput.'.$this->ownBranch->id.'.retail_price', '99')
        ->call('save')
        ->assertForbidden();

    expect(branchRetailPrice($this->ownBranch, $this->product))->toBe(10.0);

    Livewire::test('pages::tenant.products.index')
        ->call('edit', $this->product->id)
        ->set('name', 'Renamed item')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->product->fresh()->name)->toBe('Renamed item')
        ->and(branchRetailPrice($this->ownBranch, $this->product))->toBe(10.0);
});

test('the owner grants extra price branches from the employees screen', function (): void {
    $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->ownBranch->id, 'is_owner' => true]);
    $profile = Employee::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->ownBranch->id,
        'user_id' => $this->employee->id,
        'employee_code' => 'EMP-'.$this->employee->id,
        'first_name' => 'Price',
        'last_name' => 'Editor',
        'hire_date' => '2026-01-01',
        'basic_salary' => 1000,
    ]);
    $this->actingAs($owner);

    Livewire::test('pages::tenant.employees.index')
        ->call('edit', $this->employee->id)
        ->assertSet('employee_profile_id', $profile->id)
        ->assertSee('فروع إضافية لتعديل أسعار البيع')
        ->set('price_branch_ids', [(string) $this->otherBranch->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('user_price_branches')->where('user_id', $this->employee->id)->pluck('branch_id')->map(fn ($id) => (int) $id)->all())
        ->toBe([$this->otherBranch->id])
        ->and($this->employee->fresh()->priceEditableBranchIds($this->tenant->id))->toBe([$this->ownBranch->id, $this->otherBranch->id]);

    $foreignBranch = Branch::query()->create([
        'tenant_id' => Tenant::query()->create(['name' => 'Other store', 'is_active' => true])->id,
        'name' => 'Foreign',
    ]);

    Livewire::test('pages::tenant.employees.index')
        ->call('edit', $this->employee->id)
        ->set('price_branch_ids', [(string) $foreignBranch->id])
        ->call('save')
        ->assertHasErrors('price_branch_ids.0');

    expect(DB::table('user_price_branches')->where('branch_id', $foreignBranch->id)->exists())->toBeFalse();
});

test('an employee manager cannot change price branch grants', function (): void {
    $employeesEdit = Permission::query()->create(['name' => 'employees.edit', 'display_name' => 'Edit employees', 'group' => 'Employees']);
    $managerRole = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'HR']);
    $managerRole->permissions()->attach($employeesEdit);
    $manager = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->ownBranch->id, 'role_id' => $managerRole->id, 'is_owner' => false]);
    $profile = Employee::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->ownBranch->id,
        'user_id' => $this->employee->id,
        'employee_code' => 'EMP-'.$this->employee->id,
        'first_name' => 'Price',
        'last_name' => 'Editor',
        'hire_date' => '2026-01-01',
        'basic_salary' => 1000,
    ]);
    $this->actingAs($manager);

    Livewire::test('pages::tenant.employees.index')
        ->call('edit', $this->employee->id)
        ->assertDontSee('فروع إضافية لتعديل أسعار البيع')
        ->set('price_branch_ids', [(string) $this->thirdBranch->id])
        ->call('save')
        ->assertHasNoErrors();

    expect(DB::table('user_price_branches')->count())->toBe(0);
});

test('the pos api keeps a branch employee on their own branch', function (): void {
    $productsCreate = Permission::query()->create(['name' => 'products.create', 'display_name' => 'Create', 'group' => 'Products']);
    $productsView = Permission::query()->create(['name' => 'products.view', 'display_name' => 'View', 'group' => 'Products']);
    $posView = Permission::query()->create(['name' => 'pos.view', 'display_name' => 'POS', 'group' => 'POS']);
    $this->role->permissions()->attach([$productsCreate->id, $productsView->id, $posView->id]);
    $payload = ['name' => 'Api item', 'cost_price' => 1, 'retail_price' => 5, 'wholesale_price' => 4, 'stock_quantity' => 0];
    $foreignBranch = Branch::query()->create([
        'tenant_id' => Tenant::query()->create(['name' => 'Api foreign', 'is_active' => true])->id,
        'name' => 'Foreign',
    ]);

    $this->postJson('/api/pos/products', $payload + ['branch_id' => $this->otherBranch->id])->assertNotFound();
    $this->postJson('/api/pos/products', $payload + ['branch_id' => $foreignBranch->id])->assertNotFound();
    $this->postJson('/api/pos/products', $payload + ['branch_id' => $this->ownBranch->id])->assertCreated();
});

test('excel import updates prices only in branches in scope', function (): void {
    $excel = Permission::query()->create(['name' => 'excel.manage', 'display_name' => 'Excel', 'group' => 'System']);
    $this->role->permissions()->attach($excel);
    $this->product->update(['product_number' => 'P-1']);
    $csv = "number,name,qty,purchase,sale,barcode,other\nP-1,Scoped item,0,77,70,,\n";

    Livewire::test('pages::tenant.system.excel')
        ->set('file', UploadedFile::fake()->createWithContent('items.csv', $csv))
        ->call('import')
        ->assertHasNoErrors();

    expect(branchRetailPrice($this->ownBranch, $this->product))->toBe(77.0)
        ->and(branchRetailPrice($this->otherBranch, $this->product))->toBe(20.0)
        ->and(branchRetailPrice($this->thirdBranch, $this->product))->toBe(30.0);
});
