<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

test('retail checkout batches product and stock reads without changing the ledger', function (bool $customerPricing): void {
    $pricingBranch = $customerPricing ? Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Customer prices']) : $this->branch;
    $payload = $this->payload;
    $payload['items'] = [];
    if ($customerPricing) {
        $customer = Party::create(['tenant_id' => $this->tenant->id, 'branch_id' => $pricingBranch->id,
            'name' => 'Pricing customer', 'type' => 'customer', 'is_active' => true]);
        $payload['customer_id'] = $customer->id;
    }
    $stocks = [];
    for ($i = 0; $i < 12; $i++) {
        $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Batch '.$i, 'cost_price' => 4]);
        $stocks[] = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'product_id' => $product->id, 'stock_quantity' => 20, 'retail_price' => 10]);
        if ($customerPricing) {
            BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $pricingBranch->id,
                'product_id' => $product->id, 'stock_quantity' => 5, 'retail_price' => 12]);
        }
        $payload['items'][] = ['product_id' => $product->id, 'quantity' => 2, 'price' => $customerPricing ? 12 : 10];
    }
    $payload['subtotal'] = $payload['total_amount'] = $customerPricing ? 288 : 240;
    $reads = ['products' => 0, 'branch_products' => 0];
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        foreach (array_keys($reads) as $table) {
            if (preg_match('/^select .*from ["`]'.$table.'["`]/i', $query->sql)) {
                $reads[$table]++;
            }
        }
    });
    $sale = $this->postJson('/api/pos/orders', $payload)->assertCreated()
        ->assertJsonCount(12, 'order.items')->assertJsonPath('order.total', $payload['total_amount']);
    expect($reads['products'])->toBeLessThanOrEqual(2)
        ->and($reads['branch_products'])->toBeLessThanOrEqual($customerPricing ? 2 : 1);
    foreach ($stocks as $stock) {
        expect((float) $stock->fresh()->stock_quantity)->toBe(18.0);
    }
    expect((float) Payment::where('order_id', $sale->json('order.id'))->sum('amount'))->toBe((float) $payload['total_amount']);
})->with([false, true]);

test('zero total checkout completes without inserting a zero value payment', function (bool $freeProduct): void {
    DB::statement("CREATE TRIGGER reject_zero_payment BEFORE INSERT ON payments WHEN NEW.amount <= 0 BEGIN SELECT RAISE(ABORT, 'payments_amount_check'); END");
    $payload = $this->payload;
    if ($freeProduct) {
        $this->stock->update(['retail_price' => 0]);
        $payload['items'][0]['price'] = 0;
        $payload['subtotal'] = 0;
    } else {
        $payload['discount'] = 20;
    }
    $payload['total_amount'] = 0;
    $first = $this->postJson('/api/pos/orders', $payload)->assertCreated();
    $this->postJson('/api/pos/orders', $payload)->assertCreated()
        ->assertJsonPath('order.id', $first->json('order.id'));
    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(18.0);
})->with([true, false]);

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

test('paid and gift lines of one product keep separate prices stock and gift identity', function (): void {
    $payload = array_replace($this->payload, [
        'notes' => 'هدية للعميل',
        'items' => [
            ['product_id' => $this->product->id, 'quantity' => 2, 'price' => 10],
            ['product_id' => $this->product->id, 'quantity' => 1, 'price' => 99, 'is_gift' => true],
        ],
    ]);
    $sale = $this->postJson('/api/pos/orders', $payload)->assertCreated()
        ->assertJsonPath('order.notes', 'هدية للعميل')
        ->assertJsonPath('order.items.1.is_gift', true)->json('order');
    expect((float) $sale['items'][1]['unit_price'])->toBe(0.0)
        ->and((float) $sale['total'])->toBe(20.0)
        ->and((float) $sale['total_cost'])->toBe(12.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(17.0);
    $this->postJson('/api/pos/orders', $payload)->assertCreated()->assertJsonPath('order.id', $sale['id']);
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(17.0);
});

test('combined paid and gift quantity cannot oversell a product', function (): void {
    $payload = array_replace($this->payload, [
        'items' => [
            ['product_id' => $this->product->id, 'quantity' => 2, 'price' => 10],
            ['product_id' => $this->product->id, 'quantity' => 19, 'price' => 0, 'is_gift' => true],
        ],
    ]);
    $this->postJson('/api/pos/orders', $payload)->assertUnprocessable();
    expect(Order::count())->toBe(0)->and((float) $this->stock->fresh()->stock_quantity)->toBe(20.0);
});

test('held invoice preserves paid and gift lines when converted into a sale', function (): void {
    $payload = array_replace($this->payload, [
        'items' => [
            ['product_id' => $this->product->id, 'quantity' => 2, 'price' => 10],
            ['product_id' => $this->product->id, 'quantity' => .5, 'price' => 0, 'is_gift' => true],
        ],
    ]);
    $held = $this->postJson('/api/pos/held', $payload)->assertCreated()
        ->assertJsonPath('order.items.1.is_gift', true)->json('order.id');
    $payload['held_order_id'] = $held;
    $payload['operation_uuid'] = (string) Str::uuid();
    $this->postJson('/api/pos/orders', $payload)->assertCreated()->assertJsonPath('order.id', $held)
        ->assertJsonPath('order.items.1.is_gift', true);
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(17.5)->and(Order::count())->toBe(1);
});

test('invoice editing replaces items and payment without changing its identity or doubling stock', function (): void {
    $original = $this->postJson('/api/pos/orders', $this->payload)->assertCreated()->json('order');
    $payload = array_replace($this->payload, ['invoice_id' => $original['id'], 'operation_uuid' => (string) Str::uuid(),
        'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 10]], 'subtotal' => 30, 'total_amount' => 35,
        'delivery_fee' => 5, 'notes' => 'edited']);
    $this->postJson('/api/pos/orders', $payload)->assertCreated()->assertJsonPath('order.id', $original['id'])
        ->assertJsonPath('order.invoice_number', $original['invoice_number'])->assertJsonPath('order.notes', 'edited');
    $this->postJson('/api/pos/orders', $payload)->assertCreated();
    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1)
        ->and((float) Payment::first()->amount)->toBe(35.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(17.0);
});

test('invalid invoice edit rolls back its stock reversal and payment removal', function (): void {
    $original = $this->postJson('/api/pos/orders', $this->payload)->assertCreated()->json('order');
    $this->postJson('/api/pos/orders', array_replace($this->payload, [
        'invoice_id' => $original['id'], 'operation_uuid' => (string) Str::uuid(), 'total_amount' => 999,
    ]))->assertUnprocessable();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(18.0)->and(Payment::count())->toBe(1)
        ->and((float) Order::first()->total)->toBe(20.0);
});

test('invoice navigation searches and moves in both directions within the branch', function (): void {
    $one = $this->postJson('/api/pos/orders', $this->payload)->assertCreated()->json('order');
    $two = $this->postJson('/api/pos/orders', array_replace($this->payload, ['operation_uuid' => (string) Str::uuid()]))->assertCreated()->json('order');
    $base = '/api/pos/invoices?branch_id='.$this->branch->id;
    $this->getJson($base)->assertOk()->assertJsonPath('order.id', $two['id']);
    $this->getJson($base.'&before='.$two['id'])->assertOk()->assertJsonPath('order.id', $one['id']);
    $this->getJson($base.'&after='.$one['id'])->assertOk()->assertJsonPath('order.id', $two['id']);
    $this->getJson($base.'&search='.$one['invoice_number'])->assertOk()->assertJsonPath('order.id', $one['id']);
});

test('deleting an invoice restores stock and removes its payment only once', function (): void {
    $order = $this->postJson('/api/pos/orders', $this->payload)->assertCreated()->json('order');
    $this->deleteJson('/api/pos/invoices/'.$order['id'])->assertOk();
    $this->deleteJson('/api/pos/invoices/'.$order['id'])->assertNotFound();
    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(20.0);
});

test('catalog and checkout apply branch bundle offers while gifts stay free', function (): void {
    $this->stock->update(['offer_quantity' => 3, 'offer_price' => 25]);
    $this->getJson('/api/pos/products?branch_id='.$this->branch->id)->assertOk()
        ->assertJsonPath('pos_line_contract_version', 2);
    $payload = array_replace($this->payload, ['subtotal' => 25, 'total_amount' => 25,
        'items' => [
            ['product_id' => $this->product->id, 'quantity' => 3, 'price' => 10, 'use_offer' => true],
            ['product_id' => $this->product->id, 'quantity' => 1, 'price' => 0, 'is_gift' => true],
        ],
    ]);
    $sale = $this->postJson('/api/pos/orders', $payload)->assertCreated()->json('order');
    expect((float) $sale['items'][0]['total_price'])->toBe(25.0)
        ->and((float) $sale['items'][0]['unit_price'])->toBe(10.0)
        ->and((float) $sale['total'])->toBe(25.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(16.0);
    $found = $this->getJson('/api/orders/invoice/'.$sale['invoice_number'])->assertOk()->json('order');
    expect((float) $found['items'][0]['refund_unit_price'])->toBe(25 / 3)
        ->and((float) $found['items'][1]['refund_unit_price'])->toBe(0.0);
    $this->postJson('/api/orders/returns', ['branch_id' => $this->branch->id, 'original_order_id' => $sale['id'],
        'items' => [['product_id' => $this->product->id, 'original_order_item_id' => $sale['items'][0]['id'],
            'quantity' => 3, 'price' => 25 / 3]]])->assertCreated()->assertJsonPath('return_order.total', -25);
});

test('held offers retain their exact total on restoration and checkout', function (): void {
    $this->stock->update(['offer_quantity' => 3, 'offer_price' => 25]);
    $payload = array_replace($this->payload, ['subtotal' => 25, 'total_amount' => 25,
        'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 10, 'use_offer' => true]],
    ]);
    $held = $this->postJson('/api/pos/held', $payload)->assertCreated()->json('order');
    expect((float) $held['items'][0]['total_price'])->toBe(25.0)
        ->and((float) $held['items'][0]['offer_price'])->toBe(25.0);
    $payload['held_order_id'] = $held['id'];
    $payload['operation_uuid'] = (string) Str::uuid();
    $this->postJson('/api/pos/orders', $payload)->assertCreated()->assertJsonPath('order.id', $held['id']);
});

test('unified stock invoice edits and deletion reverse quantities into their original branches', function (): void {
    TenantSetting::setBool($this->tenant->id, 'unified_stock', true);
    $other = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Warehouse']);
    $otherStock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $other->id, 'product_id' => $this->product->id, 'stock_quantity' => 10, 'retail_price' => 10]);
    $this->stock->update(['stock_quantity' => 1]);
    $original = $this->postJson('/api/pos/orders', $this->payload)->assertCreated()->json('order');
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(0.0)->and((float) $otherStock->fresh()->stock_quantity)->toBe(9.0);
    $edit = array_replace($this->payload, ['invoice_id' => $original['id'], 'operation_uuid' => (string) Str::uuid(), 'items' => [['product_id' => $this->product->id, 'quantity' => 3, 'price' => 10]], 'subtotal' => 30, 'total_amount' => 30]);
    $this->postJson('/api/pos/orders', $edit)->assertCreated();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(0.0)->and((float) $otherStock->fresh()->stock_quantity)->toBe(8.0);
    $this->deleteJson('/api/pos/invoices/'.$original['id'])->assertOk();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(1.0)->and((float) $otherStock->fresh()->stock_quantity)->toBe(10.0);
});
