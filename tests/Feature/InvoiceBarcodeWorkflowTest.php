<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر الفواتير']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->branch = Branch::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'الفرع الرئيسي',
    ]);
    $this->user->update(['branch_id' => $this->branch->id]);
    $this->actingAs($this->user);
    $this->withSession([
        'active_tenant_id' => $this->tenant->id,
        'active_branch_id' => $this->branch->id,
    ]);

    $this->product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_number' => 'ITEM-100',
        'name' => 'صنف تجريبي',
        'cost_price' => 4.25,
    ]);
    BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'stock_quantity' => 12,
        'retail_price' => 8.5,
        'wholesale_price' => 7.5,
    ]);
    ProductBarcode::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->product->id,
        'barcode' => '1234567890123',
    ]);
});

test('invoice product search matches the full product number and its middle', function (string $page, string $search): void {
    $otherTenant = Tenant::query()->create(['name' => 'متجر آخر']);
    Product::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'صنف مخفي', 'product_number' => 'ITEM-100']);
    Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف غير مطابق', 'product_number' => 'OTHER-200']);

    Livewire::test('pages::tenant.'.$page.'.invoices')
        ->set('rowSearch', $search)
        ->assertViewHas('productResults', fn ($results): bool => $results->pluck('id')->all() === [$this->product->id]);
})->with([
    ['sales', 'ITEM-100'],
    ['sales', 'EM-10'],
    ['purchases', 'ITEM-100'],
    ['purchases', 'EM-10'],
]);

test('purchase invoice scanner loads barcode details and Enter progression commits the row', function (): void {
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProductByBarcode', '1234567890123')
        ->assertReturned(true)
        ->assertSet('selectedProductId', $this->product->id)
        ->assertSet('selectedProductName', 'صنف تجريبي')
        ->assertSet('selectedPurchasePrice', 4.25)
        ->assertSet('selectedStock', 12.0)
        ->assertDispatched('focus-quantity')
        ->set('selectedQuantity', 3)
        ->set('selectedPurchasePrice', 5.0)
        ->call('commitRowToCart')
        ->assertSet('selectedProductId', null)
        ->assertDispatched('focus-product-search');

    $cart = array_values($component->get('cart'));
    expect($cart)->toHaveCount(1)
        ->and($cart[0]['quantity'])->toBe(3.0)
        ->and($cart[0]['price'])->toBe(5.0);
});

test('sales invoice scanner loads barcode details and Enter progression commits the row', function (): void {
    $component = Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProductByBarcode', '1234567890123')
        ->assertReturned(true)
        ->assertSet('selectedProductId', $this->product->id)
        ->assertSet('selectedProductName', 'صنف تجريبي')
        ->assertSet('selectedPrice', 7.5)
        ->assertSet('selectedStock', 12.0)
        ->assertDispatched('focus-quantity')
        ->set('selectedQuantity', 2)
        ->set('selectedPrice', 8.0)
        ->call('commitRowToCart')
        ->assertSet('selectedProductId', null)
        ->assertDispatched('focus-product-search');

    $cart = array_values($component->get('cart'));
    expect($cart)->toHaveCount(1)
        ->and($cart[0]['quantity'])->toBe(2.0)
        ->and($cart[0]['price'])->toBe(8.0);
});

test('barcode lookup does not select products from another tenant', function (): void {
    Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProductByBarcode', 'not-a-barcode')
        ->assertReturned(false)
        ->assertSet('selectedProductId', null);
});
test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
