<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Integrity tenant', 'is_active' => true]);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true, 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Coffee', 'cost_price' => 4]);
    $this->stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'stock_quantity' => 20, 'retail_price' => 10, 'wholesale_price' => 8]);
    $this->payload = ['branch_id' => $this->branch->id, 'subtotal' => 20, 'total_amount' => 20,
        'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'price' => 10]],
        'operation_uuid' => (string) Str::uuid()];
    $this->actingAs($this->user);
});

test('replayed checkout returns the original invoice without duplicating stock or payment', function (): void {
    $first = $this->postJson('/api/pos/orders', $this->payload)->assertCreated();
    $this->postJson('/api/pos/orders', $this->payload)->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('order.id', $first->json('order.id'));
    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(18.0);
    $this->getJson('/api/sale-operations/'.$this->payload['operation_uuid'])->assertOk()
        ->assertJsonPath('result.order.id', $first->json('order.id'));
});

test('an operation key cannot be reused with another payload or by another user', function (): void {
    $this->postJson('/api/pos/orders', $this->payload)->assertCreated();
    $this->postJson('/api/pos/orders', array_replace($this->payload, ['discount' => 1]))->assertConflict();
    $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true]);
    $this->actingAs($other)->postJson('/api/pos/orders', $this->payload)->assertConflict();
    $this->getJson('/api/sale-operations/'.$this->payload['operation_uuid'])->assertNotFound();
});

test('a rejected checkout does not reserve an operation key or mutate stock', function (): void {
    $this->postJson('/api/pos/orders', array_replace($this->payload, ['total_amount' => 1]))->assertUnprocessable();
    expect(Order::count())->toBe(0)->and((float) $this->stock->fresh()->stock_quantity)->toBe(20.0);
    $this->postJson('/api/pos/orders', $this->payload)->assertCreated();
});

test('a held invoice is updated and converted atomically without a second invoice', function (): void {
    $held = $this->postJson('/api/pos/held', $this->payload)->assertCreated()->json('order.id');
    $updated = array_replace($this->payload, ['held_order_id' => $held, 'operation_uuid' => (string) Str::uuid()]);
    $this->postJson('/api/pos/held', $updated)->assertCreated()->assertJsonPath('order.id', $held);
    expect(Order::count())->toBe(1)->and((float) $this->stock->fresh()->stock_quantity)->toBe(20.0);
    $sale = array_replace($updated, ['operation_uuid' => (string) Str::uuid()]);
    $this->postJson('/api/pos/orders', $sale)->assertCreated()->assertJsonPath('order.id', $held)
        ->assertJsonPath('order.status', 'completed');
    $this->postJson('/api/pos/orders', array_replace($sale, ['operation_uuid' => (string) Str::uuid()]))->assertNotFound();
    expect(Order::count())->toBe(1)->and((float) $this->stock->fresh()->stock_quantity)->toBe(18.0);
});

test('catalog explicitly identifies the customer pricing branch', function (): void {
    $pricingBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Pricing']);
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'branch_id' => $pricingBranch->id,
        'name' => 'Customer', 'type' => 'customer', 'is_active' => true]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $pricingBranch->id,
        'product_id' => $this->product->id, 'retail_price' => 12]);
    $this->getJson('/api/pos/products?branch_id='.$this->branch->id.'&customer_id='.$customer->id)
        ->assertOk()->assertJsonPath('categories.0.products.0.pricing_branch_id', $pricingBranch->id);
});

test('return uses the original line and refunds the discounted amount only once', function (): void {
    $sale = $this->postJson('/api/pos/orders', array_replace($this->payload, ['discount' => 4, 'total_amount' => 16]))
        ->assertCreated()->json('order');
    $this->getJson('/api/orders/invoice/'.$sale['invoice_number'])->assertOk()
        ->assertJsonPath('order.items.0.remaining_quantity', 2)->assertJsonPath('order.items.0.refund_unit_price', 8);
    $payload = ['branch_id' => $this->branch->id, 'original_order_id' => $sale['id'], 'subtotal' => 10, 'total_amount' => 8,
        'operation_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $this->product->id, 'original_order_item_id' => $sale['items'][0]['id'], 'quantity' => 1, 'price' => 10]]];
    $this->postJson('/api/orders/returns', $payload)->assertCreated()->assertJsonPath('return_order.total', -8);
    $this->postJson('/api/orders/returns', $payload)->assertCreated();
    $this->getJson('/api/orders/invoice/'.$sale['invoice_number'])->assertOk()->assertJsonPath('order.items.0.remaining_quantity', 1);
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(19.0);
});

test('wholesale credit requires a customer and keyed retries do not repeat the sale', function (): void {
    $payload = ['branch_id' => $this->branch->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 2]],
        'paid_amount' => 0, 'payment_method' => 'cash', 'operation_uuid' => (string) Str::uuid()];
    $this->postJson('/api/wholesale/orders', $payload)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    $payload['paid_amount'] = 16;
    $first = $this->postJson('/api/wholesale/orders', $payload)->assertCreated();
    $this->postJson('/api/wholesale/orders', $payload)->assertCreated()->assertJsonPath('order.id', $first->json('order.id'));
    expect(Order::count())->toBe(1)->and((float) $this->stock->fresh()->stock_quantity)->toBe(18.0);
});

test('summary includes wholesale and returns costs without counting refunds as expenses', function (): void {
    $sale = $this->postJson('/api/pos/orders', $this->payload)->assertCreated()->json('order');
    $this->postJson('/api/orders/returns', ['branch_id' => $this->branch->id, 'original_order_id' => $sale['id'],
        'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 10]]])->assertCreated();
    $this->postJson('/api/wholesale/orders', ['branch_id' => $this->branch->id, 'paid_amount' => 8, 'payment_method' => 'cash',
        'items' => [['product_id' => $this->product->id, 'quantity' => 1]]])->assertCreated();
    Payment::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'type' => 'payment',
        'voucher_number' => 'EXP-test', 'amount' => 2, 'payment_method' => 'cash', 'payment_date' => now()]);
    $this->getJson('/api/reports/summary?branch_id='.$this->branch->id)->assertOk()
        ->assertJsonPath('summary.total_sales_count', 2)->assertJsonPath('summary.net_revenue', 18)
        ->assertJsonPath('summary.total_expenses', 2)->assertJsonPath('summary.gross_profit', 10)
        ->assertJsonPath('summary.net_profit_estimate', 8);
});

test('a credit return reduces outstanding customer debt before refunding cash', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'Credit customer', 'type' => 'customer', 'is_active' => true]);
    $sale = $this->postJson('/api/wholesale/orders', ['branch_id' => $this->branch->id, 'customer_id' => $customer->id,
        'paid_amount' => 4, 'payment_method' => 'cash', 'items' => [['product_id' => $this->product->id, 'quantity' => 2]]])
        ->assertCreated()->json('order');
    expect((float) $customer->fresh()->current_balance)->toBe(12.0);
    $payload = ['branch_id' => $this->branch->id, 'original_order_id' => $sale['id'],
        'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'price' => 8]]];
    $this->postJson('/api/orders/returns', $payload)->assertCreated()->assertJsonPath('return_order.paid_amount', 0);
    expect((float) $customer->fresh()->current_balance)->toBe(4.0);
    $this->postJson('/api/orders/returns', $payload)->assertCreated()->assertJsonPath('return_order.paid_amount', -4);
    expect((float) $customer->fresh()->current_balance)->toBe(0.0);
    $this->postJson('/api/orders/returns', $payload)->assertUnprocessable();
});

test('report dates are validated and filter sales consistently', function (): void {
    $this->postJson('/api/pos/orders', $this->payload)->assertCreated();
    $prefix = '/api/reports/summary?branch_id='.$this->branch->id;
    $this->getJson($prefix.'&from=2020-01-01&to=2020-01-02')->assertOk()->assertJsonPath('summary.total_sales_count', 0);
    $this->getJson($prefix.'&from=2026-02-01&to=2026-01-01')->assertUnprocessable();
    $this->getJson($prefix.'&from=not-a-date')->assertUnprocessable();
});
