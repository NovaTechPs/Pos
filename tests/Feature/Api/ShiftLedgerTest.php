<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;

test('shift cash includes cash receipts and expenses but excludes card sales', function (): void {
    $tenant = Tenant::create(['name' => 'Shift tenant', 'is_active' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Coffee']);
    BranchProduct::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id,
        'retail_price' => 10, 'stock_quantity' => 10]);
    $this->actingAs($user);
    $shift = $this->postJson('/api/shifts/open', ['branch_id' => $branch->id, 'opening_cash' => 100])->assertCreated()->json('shift.id');
    $this->postJson('/api/shifts/open', ['branch_id' => $branch->id, 'opening_cash' => 100])->assertConflict();
    $payload = ['branch_id' => $branch->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 10]]];
    $this->postJson('/api/pos/orders', $payload + ['payment_method' => 'cash'])->assertCreated()->assertJsonPath('order.shift_id', $shift);
    $this->postJson('/api/pos/orders', $payload + ['payment_method' => 'card'])->assertCreated();
    $this->postJson('/api/expenses', ['branch_id' => $branch->id, 'amount' => 3])->assertCreated();
    $this->postJson('/api/vouchers/receipts', ['branch_id' => $branch->id, 'amount' => 5])->assertCreated();
    $this->getJson('/api/shifts/active?branch_id='.$branch->id)->assertOk()
        ->assertJsonPath('active_shift.cash_summary.expected_cash', 112)
        ->assertJsonPath('active_shift.cash_summary.sales', 20);
    $this->postJson('/api/shifts/close', ['shift_id' => $shift, 'actual_cash' => 112, 'expected_cash' => 100])->assertConflict();
    $this->postJson('/api/shifts/close', ['shift_id' => $shift, 'actual_cash' => 112, 'expected_cash' => 112])->assertOk()
        ->assertJsonPath('shift.expected_cash', '112.00')->assertJsonPath('shift.difference', '0.00');
});

test('cashiers in the same branch have separate active shifts', function (): void {
    $tenant = Tenant::create(['name' => 'Shift tenant', 'is_active' => true]);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $first = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $second = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $one = $this->actingAs($first)->postJson('/api/shifts/open', ['branch_id' => $branch->id, 'opening_cash' => 10])->assertCreated()->json('shift.id');
    $two = $this->actingAs($second)->postJson('/api/shifts/open', ['branch_id' => $branch->id, 'opening_cash' => 20])->assertCreated()->json('shift.id');
    expect($one)->not->toBe($two);
    $this->getJson('/api/shifts/active?branch_id='.$branch->id)->assertOk()->assertJsonPath('active_shift.id', $two);
});
