<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Party;
use App\Models\Payment;
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
        ->assertJsonPath('active_shift.cash_summary.sales', 20)
        ->assertJsonPath('active_shift.cash_summary.card_receipts', 10)
        ->assertJsonPath('active_shift.cash_summary.bank_receipts', 10);
    expect(Payment::query()->whereNotNull('order_id')->exists())->toBeFalse();
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

test('an app delivery sale saves the delivery details like the web and keeps the delivery fee out of the cash drawer', function (): void {
    $tenant = Tenant::create(['name' => 'Delivery tenant', 'is_active' => true]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Coffee']);
    BranchProduct::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id,
        'retail_price' => 10, 'stock_quantity' => 10]);
    $company = Party::create(['tenant_id' => $tenant->id, 'name' => 'شركة التوصيل', 'type' => 'supplier', 'is_active' => true]);
    $this->actingAs($user);
    $this->postJson('/api/shifts/open', ['branch_id' => $branch->id, 'opening_cash' => 0])->assertCreated();
    $payload = ['branch_id' => $branch->id, 'payment_method' => 'cash', 'delivery_fee' => 5,
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'price' => 10]]];

    $this->postJson('/api/pos/orders', $payload + ['delivery_party_id' => $company->id])->assertUnprocessable()
        ->assertJsonValidationErrors(['delivery_customer_name', 'delivery_customer_phone', 'delivery_customer_address']);

    $order = $this->postJson('/api/pos/orders', $payload + ['delivery_party_id' => $company->id, 'notes' => 'بدون سكر',
        'delivery_customer_name' => 'مستلم', 'delivery_customer_phone' => '0590000000',
        'delivery_customer_address' => 'الخليل - رأس الجورة', 'delivery_notes' => 'اتصل قبل الوصول'])->assertCreated()->json('order');

    expect($order['customer_name'])->toBe('مستلم')
        ->and($order['customer_phone'])->toBe('0590000000')
        ->and($order['customer_address'])->toBe('الخليل - رأس الجورة')
        ->and($order['delivery_party_id'])->toBe($company->id)
        ->and($order['notes'])->toBe("بدون سكر\n\nملاحظة التوصيل: اتصل قبل الوصول");
    $this->getJson('/api/shifts/active?branch_id='.$branch->id)->assertOk()
        ->assertJsonPath('active_shift.cash_summary.expected_cash', 20);

    $this->postJson('/api/pos/orders', $payload + ['delivery_party_id' => 999999, 'delivery_customer_name' => 'مستلم',
        'delivery_customer_phone' => '0590000000', 'delivery_customer_address' => 'الخليل'])->assertUnprocessable()
        ->assertJsonValidationErrors('delivery_party_id');

    // An app version that sends the fee alone keeps saving, and the fee stays in its cash as before.
    $this->postJson('/api/pos/orders', $payload)->assertCreated()->assertJsonPath('order.delivery_party_id', null);
    $this->getJson('/api/shifts/active?branch_id='.$branch->id)->assertOk()
        ->assertJsonPath('active_shift.cash_summary.expected_cash', 45);
});
