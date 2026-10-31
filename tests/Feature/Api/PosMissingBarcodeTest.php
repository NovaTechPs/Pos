<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Barcode tenant', 'is_active' => true]);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true, 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Existing', 'cost_price' => 3]);
    $this->stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'retail_price' => 10, 'stock_quantity' => 9]);
    $this->actingAs($this->user);
    $this->payload = ['branch_id' => $this->branch->id, 'barcode' => '00AbC', 'name' => 'New product',
        'cost_price' => 3, 'retail_price' => 12, 'wholesale_price' => 8, 'stock_quantity' => 4];
});

test('missing barcode creates a priced product with stock only in the selling branch', function (): void {
    $other = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
    $category = Category::create(['tenant_id' => $this->tenant->id, 'name' => 'Category']);
    $result = $this->postJson('/api/pos/products', $this->payload + ['category_id' => $category->id])
        ->assertCreated()->assertJsonPath('product.barcodes.0.barcode', '00AbC');
    $id = $result->json('product.id');
    expect((float) BranchProduct::where('product_id', $id)->where('branch_id', $this->branch->id)->first()->stock_quantity)->toBe(4.0)
        ->and((float) BranchProduct::where('product_id', $id)->where('branch_id', $other->id)->first()->stock_quantity)->toBe(0.0)
        ->and(Order::count())->toBe(0);
    $this->postJson('/api/pos/products', $this->payload)->assertUnprocessable();
    expect(Product::count())->toBe(2)->and(ProductBarcode::count())->toBe(1);
    $this->getJson('/api/pos/products?branch_id='.$this->branch->id)->assertOk();
});

test('attaching an alias is idempotent and cannot steal another product barcode', function (): void {
    ProductBarcode::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'barcode' => 'BASE']);
    $payload = ['branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'barcode' => '00AbC'];
    $this->postJson('/api/pos/product-barcodes', $payload)->assertOk()->assertJsonCount(2, 'product.barcodes');
    $this->postJson('/api/pos/product-barcodes', $payload)->assertOk()->assertJsonCount(2, 'product.barcodes');
    $other = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
    $this->postJson('/api/pos/product-barcodes', array_replace($payload, ['product_id' => $other->id]))->assertUnprocessable();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(9.0)->and(Order::count())->toBe(0);
    $this->postJson('/api/pos/products', array_replace($this->payload, ['barcode' => '00abc']))->assertUnprocessable();
});

test('barcode maintenance enforces tenant and branch boundaries', function (): void {
    $foreign = Tenant::create(['name' => 'Foreign', 'is_active' => true]);
    $branch = Branch::create(['tenant_id' => $foreign->id, 'name' => 'Foreign']);
    $product = Product::create(['tenant_id' => $foreign->id, 'name' => 'Foreign']);
    $category = Category::create(['tenant_id' => $foreign->id, 'name' => 'Foreign']);
    $this->postJson('/api/pos/product-barcodes', ['branch_id' => $this->branch->id, 'barcode' => 'B', 'product_id' => $product->id])->assertNotFound();
    $this->postJson('/api/pos/products', array_replace($this->payload, ['branch_id' => $branch->id]))->assertNotFound();
    $this->postJson('/api/pos/products', $this->payload + ['category_id' => $category->id])->assertUnprocessable();
    expect(ProductBarcode::count())->toBe(0);
});

test('cashiers without maintenance permissions cannot create or attach barcodes', function (): void {
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => false]);
    $this->actingAs($staff)->postJson('/api/pos/products', $this->payload)->assertForbidden();
    $this->postJson('/api/pos/product-barcodes', ['branch_id' => $this->branch->id, 'barcode' => 'B', 'product_id' => $this->product->id])->assertForbidden();
    expect(ProductBarcode::count())->toBe(0);
});

test('invalid prices and empty barcodes cannot create partial product or stock records', function (): void {
    $this->postJson('/api/pos/products', array_replace($this->payload, ['retail_price' => -1]))->assertUnprocessable();
    $this->postJson('/api/pos/products', array_replace($this->payload, ['barcode' => '  ']))->assertUnprocessable();
    expect(Product::count())->toBe(1)->and(BranchProduct::count())->toBe(1)->and(ProductBarcode::count())->toBe(0);
});
