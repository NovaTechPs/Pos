<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Order;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'API test tenant', 'is_active' => true]);
});

test('can fetch pos products and categories', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create([
        'name' => 'Main Branch',
        'tenant_id' => 1,
    ]);
    $category = Category::create([
        'name' => 'General',
        'tenant_id' => 1,
    ]);
    Product::create([
        'name' => 'Test Product',
        'category_id' => $category->id,
        'tenant_id' => 1,
    ]);

    $response = $this->actingAs($user)->getJson('/api/pos/products?branch_id='.$branch->id);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'categories' => [
                '*' => [
                    'id',
                    'name',
                    'products',
                ],
            ],
        ]);
});

test('staff without the matching permission cannot access pos data', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_active' => true]);

    $this->actingAs($user)->getJson('/api/pos/products')->assertForbidden();
});

test('permissions from another tenant do not grant api access', function () {
    $otherTenant = Tenant::create(['id' => 2, 'name' => 'Other tenant', 'is_active' => true]);
    $permission = Permission::create([
        'name' => 'pos.view',
        'display_name' => 'POS',
        'group' => 'POS',
    ]);
    $foreignRole = Role::create(['tenant_id' => $otherTenant->id, 'name' => 'Foreign role']);
    $foreignRole->permissions()->attach($permission->id);
    $user = User::factory()->create([
        'tenant_id' => 1,
        'type' => 'tenant_user',
        'role_id' => $foreignRole->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)->getJson('/api/pos/products')->assertForbidden();
});

test('cannot create a shift in another tenants branch', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $otherTenant = Tenant::create(['id' => 2, 'name' => 'Other tenant', 'is_active' => true]);
    $otherBranch = Branch::create(['name' => 'Other branch', 'tenant_id' => $otherTenant->id]);

    $this->actingAs($user)->postJson('/api/shifts/open', [
        'branch_id' => $otherBranch->id,
        'opening_cash' => 0,
    ])->assertNotFound();
});

test('can store pos order successfully', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create([
        'name' => 'Main Branch',
        'tenant_id' => 1,
    ]);
    $product = Product::create([
        'name' => 'Test Product',
        'tenant_id' => 1,
    ]);
    BranchProduct::create([
        'tenant_id' => 1,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_quantity' => 10,
        'retail_price' => 75,
    ]);

    $response = $this->actingAs($user)->postJson('/api/pos/orders', [
        'branch_id' => $branch->id,
        'subtotal' => 150.00,
        'tax_amount' => 0.00,
        'discount' => 0.00,
        'total_amount' => 150.00,
        'payment_method' => 'cash',
        'items' => [
            [
                'product_id' => $product->id,
                'quantity' => 2,
                'price' => 75.00,
            ],
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'order' => [
                'id',
                'total',
                'items',
            ],
        ]);

    $this->assertDatabaseHas('orders', [
        'total' => 150.00,
        'branch_id' => $branch->id,
    ]);

    $this->assertDatabaseHas('branch_products', [
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_quantity' => 8,
    ]);
});

test('retail checkout uses the customer branch price', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $saleBranch = Branch::create(['name' => 'فرع البيع', 'tenant_id' => 1]);
    $customerBranch = Branch::create(['name' => 'فرع العميل', 'tenant_id' => 1]);
    $customer = Party::create(['name' => 'عميل الفرع', 'tenant_id' => 1, 'branch_id' => $customerBranch->id, 'type' => 'customer']);
    $product = Product::create(['name' => 'صنف', 'tenant_id' => 1]);
    BranchProduct::create(['tenant_id' => 1, 'branch_id' => $saleBranch->id, 'product_id' => $product->id, 'stock_quantity' => 10, 'retail_price' => 10]);
    BranchProduct::create(['tenant_id' => 1, 'branch_id' => $customerBranch->id, 'product_id' => $product->id, 'stock_quantity' => 0, 'retail_price' => 15]);

    $this->actingAs($user)->postJson('/api/pos/orders', [
        'branch_id' => $saleBranch->id, 'customer_id' => $customer->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 15]],
        'subtotal' => 15, 'total_amount' => 15,
    ])->assertCreated()->assertJsonPath('order.total', 15);
});

test('sales and wholesale order lists return only the selected tenant invoices', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create(['name' => 'Main', 'tenant_id' => 1]);
    Tenant::create(['id' => 2, 'name' => 'Other', 'is_active' => true]);
    Order::create(['tenant_id' => 1, 'branch_id' => $branch->id, 'invoice_number' => 'W-100', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 50, 'total' => 50]);
    Order::create(['tenant_id' => 1, 'branch_id' => $branch->id, 'invoice_number' => 'S-100', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 20, 'total' => 20]);
    Order::create(['tenant_id' => 2, 'branch_id' => null, 'invoice_number' => 'FOREIGN', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 20, 'total' => 20]);

    $this->actingAs($user)->getJson('/api/wholesale/orders?branch_id='.$branch->id)
        ->assertOk()->assertJsonPath('orders.data.0.invoice_number', 'W-100')->assertJsonMissing(['invoice_number' => 'FOREIGN']);
    $this->actingAs($user)->getJson('/api/sales/orders?branch_id='.$branch->id)
        ->assertOk()->assertJsonPath('orders.data.0.invoice_number', 'S-100');
});

test('pos order ignores forged totals and rejects a mismatch', function () {
    $user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $branch = Branch::create(['name' => 'Main Branch', 'tenant_id' => 1]);
    $product = Product::create(['name' => 'Test Product', 'tenant_id' => 1]);
    BranchProduct::create([
        'tenant_id' => 1,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_quantity' => 10,
        'retail_price' => 75,
    ]);

    $this->actingAs($user)->postJson('/api/pos/orders', [
        'branch_id' => $branch->id,
        'subtotal' => 1,
        'total_amount' => 1,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 75]],
    ])->assertUnprocessable();

    $this->assertDatabaseCount('orders', 0);
});
