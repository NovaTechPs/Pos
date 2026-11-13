<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Alerts shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Second']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->stock = function (string $name, float $quantity, float $alert, ?Branch $branch = null): BranchProduct {
        $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => $name, 'cost_price' => 1]);

        return BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => ($branch ?? $this->branch)->id,
            'product_id' => $product->id, 'stock_quantity' => $quantity, 'alert_quantity' => $alert, 'retail_price' => 2]);
    };
});

test('the stock alerts menu lists items of the active branch that reached their alert threshold', function (): void {
    ($this->stock)('سكر نفد', 0, 5);
    ($this->stock)('أرز قارب على النفاد', 3, 5);
    ($this->stock)('زيت متوفر', 20, 5);
    ($this->stock)('شاي في فرع آخر', 1, 5, $this->otherBranch);

    Livewire::test('stock-alerts')
        ->assertSee('تنبيهات المخزون')
        ->assertSeeInOrder(['سكر نفد', 'أرز قارب على النفاد'])
        ->assertDontSee('زيت متوفر')
        ->assertDontSee('شاي في فرع آخر')
        ->assertSee('عرض كل الأصناف (2)')
        ->assertSee(route('tenant.product', ['stock' => 'low']), false);

    Livewire::test('pages::tenant.products.index')->assertSet('stockFilter', '');
    $this->get(route('tenant.product', ['stock' => 'low']))->assertOk()->assertSee('أرز قارب على النفاد')->assertDontSee('زيت متوفر');
});

test('the stock alerts menu stays hidden without low items or without access to products', function (): void {
    ($this->stock)('زيت متوفر', 20, 5);
    Livewire::test('stock-alerts')->assertDontSee('تنبيهات المخزون');

    ($this->stock)('سكر نفد', 0, 5);
    $cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => false,
        'role_id' => Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier'])->id]);
    $this->actingAs($cashier);
    Livewire::test('stock-alerts')->assertDontSee('تنبيهات المخزون');
});

test('the product form saves the alert threshold of the current branch', function (): void {
    $stock = ($this->stock)('حليب', 8, 5);

    Livewire::test('pages::tenant.products.index')
        ->call('edit', $stock->product_id)
        ->assertSet('alertQuantity', '5')
        ->set('alertQuantity', '10')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $stock->fresh()->alert_quantity)->toBe(10.0);
    Livewire::test('stock-alerts')->assertSee('حليب');
});
