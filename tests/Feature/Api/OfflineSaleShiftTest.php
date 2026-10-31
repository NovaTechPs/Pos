<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Offline tenant', 'is_active' => true]);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true, 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Coffee', 'cost_price' => 4]);
    $this->stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 20, 'retail_price' => 10]);
    $this->actingAs($this->user);
    $this->shiftId = $this->postJson('/api/shifts/open', ['branch_id' => $this->branch->id, 'opening_cash' => 0])->assertCreated()->json('shift.id');
    $this->payload = ['branch_id' => $this->branch->id, 'offline_shift_id' => $this->shiftId, 'operation_uuid' => (string) Str::uuid(), 'subtotal' => 20, 'total_amount' => 20, 'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'price' => 10]]];
});

test('offline sync binds atomically to the original shift and remains idempotent after closure', function (): void {
    $first = $this->postJson('/api/pos/orders', $this->payload)->assertCreated()->assertJsonPath('order.shift_id', $this->shiftId);
    $this->postJson('/api/shifts/close', ['shift_id' => $this->shiftId, 'actual_cash' => 20])->assertOk();
    $this->postJson('/api/pos/orders', $this->payload)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('order.id', $first->json('order.id'));
    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1)->and((float) $this->stock->fresh()->stock_quantity)->toBe(18.0);
});

test('closed or replaced original shift rejects offline sync without stock or cash changes', function (bool $replace): void {
    $this->postJson('/api/shifts/close', ['shift_id' => $this->shiftId, 'actual_cash' => 0])->assertOk();
    if ($replace) {
        $this->postJson('/api/shifts/open', ['branch_id' => $this->branch->id, 'opening_cash' => 0])->assertCreated();
    }
    $this->postJson('/api/pos/orders', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('offline_shift_id');
    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0)->and((float) $this->stock->fresh()->stock_quantity)->toBe(20.0);
})->with([false, true]);

test('a different cashier cannot assign offline sales to the original cashiers shift', function (): void {
    $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true, 'is_active' => true]);
    $this->actingAs($other)->postJson('/api/pos/orders', $this->payload)->assertUnprocessable()->assertJsonValidationErrors('offline_shift_id');
    expect(Order::count())->toBe(0)->and((float) $this->stock->fresh()->stock_quantity)->toBe(20.0);
});

test('offline protocol support is advertised by live catalog and shift responses', function (): void {
    $this->getJson('/api/pos/products?branch_id='.$this->branch->id)->assertOk()->assertJsonPath('offline_contract_version', 1);
    $this->getJson('/api/shifts/active?branch_id='.$this->branch->id)->assertOk()->assertJsonPath('offline_contract_version', 1);
});
