<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

test('clearing the bonus or discount box in the sales invoice entry row still adds the line', function (): void {
    $tenant = Tenant::create(['name' => 'متجر البونص']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'الصنف', 'cost_price' => 2]);
    BranchProduct::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);

    $component = Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProduct', $product->id)
        ->set('selectedQuantity', 2)
        // The browser sends the cleared boxes and the add click in one request.
        ->update(calls: [['method' => 'commitRowToCart', 'params' => [], 'path' => '']], updates: ['selectedBonus' => '', 'selectedDiscount' => ''])
        ->assertHasNoErrors()
        ->assertSet('selectedBonus', 0.0);

    $line = collect($component->get('cart'))->sole();
    expect((float) $line['bonus'])->toBe(0.0)
        ->and((float) $line['discount'])->toBe(0.0);
});
