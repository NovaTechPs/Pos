<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Tenant::create(['id' => 1, 'name' => 'Wholesale', 'is_active' => true]);
    $this->user = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => 1, 'name' => 'Main']);
    $this->product = Product::create(['tenant_id' => 1, 'name' => 'Wholesale item', 'cost_price' => 3]);
    $this->stock = BranchProduct::create(['tenant_id' => 1, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 20, 'wholesale_price' => 10, 'retail_price' => 12, 'min_wholesale_quantity' => 2]);
    $this->party = Party::create(['tenant_id' => 1, 'branch_id' => $this->branch->id, 'type' => 'customer', 'name' => 'Customer', 'is_active' => true, 'current_balance' => 100]);
    $this->payload = ['branch_id' => $this->branch->id, 'customer_id' => $this->party->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'price' => 8]],
        'discount_type' => 'percentage', 'discount_rate' => 10, 'paid_amount' => 4, 'payment_method' => 'cash', 'operation_uuid' => (string) Str::uuid()];
    $this->actingAs($this->user);
});

test('wholesale prices discount balances and idempotent edits stay consistent', function () {
    $response = $this->postJson('/api/wholesale/orders', $this->payload)->assertCreated()->assertJsonPath('order.total', 14.4)->assertJsonPath('order.previous_balance', 100);
    $id = $response->json('order.id');
    $number = $response->json('order.invoice_number');
    $this->postJson('/api/wholesale/orders', $this->payload)->assertCreated();
    expect(Order::count())->toBe(1)->and((float) $this->stock->fresh()->stock_quantity)->toBe(18.0)->and((float) $this->party->fresh()->current_balance)->toBe(110.4);
    $edit = array_replace($this->payload, ['invoice_id' => $id, 'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 9]], 'discount_type' => 'fixed', 'discount_amount' => 2, 'paid_amount' => 5, 'operation_uuid' => (string) Str::uuid()]);
    $this->postJson('/api/wholesale/orders', $edit)->assertCreated()->assertJsonPath('order.id', $id)->assertJsonPath('order.invoice_number', $number)->assertJsonPath('order.total', 25);
    $this->postJson('/api/wholesale/orders', $edit)->assertCreated();
    expect(Order::count())->toBe(1)->and((float) $this->stock->fresh()->stock_quantity)->toBe(17.0)->and((float) $this->party->fresh()->current_balance)->toBe(120.0)->and(Payment::count())->toBe(1);
    $edit['operation_uuid'] = (string) Str::uuid();
    $edit['items'][0]['quantity'] = 4;
    $this->postJson('/api/wholesale/orders', $edit)->assertCreated();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(16.0)->and((float) $this->party->fresh()->current_balance)->toBe(129.0);
    $this->getJson('/api/wholesale/invoice?branch_id='.$this->branch->id.'&id='.$id)->assertOk()->assertJsonPath('order.id', $id);
    $this->getJson('/api/wholesale/price-history?branch_id='.$this->branch->id.'&customer_id='.$this->party->id.'&product_id='.$this->product->id)->assertOk()->assertJsonCount(1, 'history');
});

test('invalid edit rolls back the old invoice inventory and balance', function () {
    $id = $this->postJson('/api/wholesale/orders', $this->payload)->assertCreated()->json('order.id');
    $edit = array_replace($this->payload, ['invoice_id' => $id, 'paid_amount' => 999, 'operation_uuid' => (string) Str::uuid()]);
    $this->postJson('/api/wholesale/orders', $edit)->assertUnprocessable();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(18.0)->and((float) $this->party->fresh()->current_balance)->toBe(110.4)->and(Order::find($id)->items()->count())->toBe(1)->and(Payment::count())->toBe(1);
});

test('standalone receipts can be edited and replayed without double credit', function () {
    $payload = ['branch_id' => $this->branch->id, 'customer_id' => $this->party->id, 'amount' => 30, 'payment_method' => 'cash', 'payment_date' => '2026-10-31', 'operation_uuid' => (string) Str::uuid()];
    $id = $this->postJson('/api/wholesale/receipts', $payload)->assertCreated()->assertJsonPath('voucher.previous_balance', 100)->json('voucher.id');
    $this->postJson('/api/wholesale/receipts', $payload)->assertCreated();
    expect((float) $this->party->fresh()->current_balance)->toBe(70.0);
    $payload = array_replace($payload, ['receipt_id' => $id, 'amount' => 40, 'operation_uuid' => (string) Str::uuid()]);
    $this->postJson('/api/wholesale/receipts', $payload)->assertCreated();
    expect((float) $this->party->fresh()->current_balance)->toBe(60.0)->and(Payment::count())->toBe(1);
    $this->getJson('/api/wholesale/receipts?branch_id='.$this->branch->id.'&id='.$id)->assertOk()->assertJsonPath('voucher.id', $id);
});

test('wholesale workspace rejects foreign branches parties and invoices', function () {
    Tenant::create(['id' => 2, 'name' => 'Other', 'is_active' => true]);
    $branch = Branch::create(['tenant_id' => 2, 'name' => 'Foreign']);
    $this->getJson('/api/wholesale/invoice?branch_id='.$branch->id)->assertNotFound();
    $this->getJson('/api/wholesale/products?branch_id='.$this->branch->id)->assertOk()->assertJsonPath('workspace_version', 1);
    $this->getJson('/api/wholesale/invoice?branch_id='.$this->branch->id.'&id=999999')->assertNotFound();
    $this->payload['customer_id'] = Party::create(['tenant_id' => 2, 'type' => 'customer', 'name' => 'Foreign', 'is_active' => true])->id;
    $this->postJson('/api/wholesale/orders', $this->payload)->assertNotFound();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(20.0)->and(Order::count())->toBe(0);
});

test('unified stock editing restores each source branch and supports replacing products', function () {
    TenantSetting::set(1, 'unified_stock', '1');
    $second = Branch::create(['tenant_id' => 1, 'name' => 'Second']);
    $otherStock = BranchProduct::create(['tenant_id' => 1, 'branch_id' => $second->id, 'product_id' => $this->product->id, 'stock_quantity' => 10]);
    $this->stock->update(['stock_quantity' => 1]);
    $this->payload['items'][0]['quantity'] = 4;
    $id = $this->postJson('/api/wholesale/orders', $this->payload)->assertCreated()->json('order.id');
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(0.0)->and((float) $otherStock->fresh()->stock_quantity)->toBe(7.0);
    $product = Product::create(['tenant_id' => 1, 'name' => 'Replacement']);
    $stock = BranchProduct::create(['tenant_id' => 1, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'stock_quantity' => 10, 'retail_price' => 5]);
    $edit = array_replace($this->payload, ['invoice_id' => $id, 'discount_rate' => 0, 'paid_amount' => 0, 'items' => [['product_id' => $product->id, 'quantity' => 2]], 'operation_uuid' => (string) Str::uuid()]);
    $this->postJson('/api/wholesale/orders', $edit)->assertCreated();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(1.0)->and((float) $otherStock->fresh()->stock_quantity)->toBe(10.0)->and((float) $stock->fresh()->stock_quantity)->toBe(8.0);
    $edit['operation_uuid'] = (string) Str::uuid();
    $edit['items'][0]['quantity'] = 3;
    $this->postJson('/api/wholesale/orders', $edit)->assertCreated();
    expect((float) $stock->fresh()->stock_quantity)->toBe(7.0);
});

test('editing a receipt to another customer reverses the old credit', function () {
    $payload = ['branch_id' => $this->branch->id, 'customer_id' => $this->party->id, 'amount' => 30, 'payment_method' => 'cash', 'payment_date' => '2026-10-31'];
    $id = $this->postJson('/api/wholesale/receipts', $payload)->assertCreated()->json('voucher.id');
    $other = Party::create(['tenant_id' => 1, 'type' => 'customer', 'name' => 'Second customer', 'is_active' => true, 'current_balance' => 80]);
    $payload['receipt_id'] = $id;
    $payload['customer_id'] = $other->id;
    $this->postJson('/api/wholesale/receipts', $payload)->assertCreated();
    expect((float) $this->party->fresh()->current_balance)->toBe(100.0)->and((float) $other->fresh()->current_balance)->toBe(50.0);
});

test('staff with view permission cannot write wholesale sales or receipts', function () {
    $permission = Permission::firstOrCreate(['name' => 'wholesale_sales.view'], ['display_name' => 'Wholesale', 'group' => 'Wholesale']);
    $role = Role::create(['tenant_id' => 1, 'name' => 'View only']);
    $role->permissions()->attach($permission);
    $staff = User::factory()->create(['tenant_id' => 1, 'type' => 'tenant_user', 'role_id' => $role->id, 'is_owner' => false, 'is_active' => true]);
    $this->actingAs($staff)->getJson('/api/wholesale/products?branch_id='.$this->branch->id)->assertOk();
    $this->postJson('/api/wholesale/orders', $this->payload)->assertForbidden();
    $this->getJson('/api/wholesale/receipts?branch_id='.$this->branch->id)->assertForbidden();
    $this->postJson('/api/wholesale/receipts', [])->assertForbidden();
});
