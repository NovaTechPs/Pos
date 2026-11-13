<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductOffer;
use App\Models\StockCount;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Barcode search shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'شامبو الأعشاب', 'product_number' => 'P-100', 'cost_price' => 4]);
    ProductBarcode::query()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'barcode' => '6291041500213']);
    $this->other = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صابون', 'product_number' => 'P-200', 'cost_price' => 2]);
    // A movement for each product, so the stock movements list has something to filter.
    foreach ([$this->product, $this->other] as $product) {
        BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'stock_quantity' => 5, 'retail_price' => 9]);
    }
});

test('the offers list and its product picker find products by barcode', function (): void {
    foreach ([$this->product, $this->other] as $product) {
        ProductOffer::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id,
            'offer_quantity' => 2, 'offer_price' => 15, 'start_at' => now(), 'is_active' => true]);
    }

    Livewire::test('pages::tenant.offers.index')
        ->set('search', '1500213')->assertSee('شامبو الأعشاب')->assertDontSee('صابون')
        ->call('openCreateModal')->set('productSearch', '62910415')->assertSee('P-100');
});

test('stock movements are filtered by a barcode', function (): void {
    Livewire::test('pages::tenant.stock-movements.index')
        ->set('search', '6291041500213')->assertSee('شامبو الأعشاب')->assertDontSee('صابون');
});

test('the stocktake search finds a product by part of its barcode', function (): void {
    Livewire::test('pages::tenant.stock-counts.index')->call('startCount')
        ->set('scanInput', '041500')->assertSee('شامبو الأعشاب')->assertDontSee('صابون');
    expect(StockCount::query()->count())->toBe(1);
});
