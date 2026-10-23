<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('can find invoice and store return order', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create(['name' => 'Main', 'tenant_id' => 1]);
    $product = Product::create(['name' => 'Coffee', 'tenant_id' => 1]);

    $order = Order::create([
        'tenant_id' => 1,
        'branch_id' => $branch->id,
        'invoice_number' => 'INV-12345',
        'subtotal' => 50.00,
        'total' => 50.00,
        'status' => 'completed',
    ]);

    $order->items()->create([
        'tenant_id' => 1,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 25.00,
        'total_price' => 50.00,
    ]);
    BranchProduct::create([
        'tenant_id' => 1,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_quantity' => 0,
    ]);

    // 1. Find invoice
    $response = $this->actingAs($user)->getJson('/api/orders/invoice/INV-12345');
    $response->assertStatus(200)
        ->assertJsonPath('order.invoice_number', 'INV-12345');

    // 2. Store return
    $returnResponse = $this->actingAs($user)->postJson('/api/orders/returns', [
        'original_order_id' => $order->id,
        'branch_id' => $branch->id,
        'subtotal' => 25.00,
        'total_amount' => 25.00,
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 1,
                'price' => 25.00,
            ],
        ],
    ]);

    $returnResponse->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'return_order' => ['id', 'invoice_number', 'total'],
        ]);

    $this->assertDatabaseHas('order_items', [
        'order_id' => $returnResponse->json('return_order.id'),
        'original_order_item_id' => $order->items()->first()->id,
        'quantity' => -1,
    ]);
    $this->assertDatabaseHas('branch_products', [
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_quantity' => 1,
    ]);
});
