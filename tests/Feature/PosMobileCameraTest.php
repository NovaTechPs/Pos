<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

test('mobile point of sale exposes search and accepts a camera barcode through the existing cart action', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Camera store']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    Shift::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'opening_cash' => 0, 'status' => 'open', 'opened_at' => now()]);
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'Camera product', 'cost_price' => 4]);
    BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'retail_price' => 10, 'stock_quantity' => 5]);
    ProductBarcode::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'barcode' => '0012345678905']);

    $component = Livewire::test('pages::tenant.pos.sales')
        ->assertSee('data-pos-product-search-header', false)
        ->assertSee('البحث عن صنف بالاسم أو الرقم أو الباركود')
        ->assertSee('data-pos-camera-button', false)
        ->assertSee('playsinline', false)
        ->set('productSearchQuery', 'Camera product');
    expect($component->get('quickProducts'))->toHaveCount(1);
    $component->call('scanBarcode', '0012345678905');
    expect($component->get('cart'))->toHaveCount(1)
        ->and(collect($component->get('cart'))->first()['id'])->toBe($product->id);
});
