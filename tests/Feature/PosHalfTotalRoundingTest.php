<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Half total shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Jar', 'cost_price' => 0]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'retail_price' => 1.43, 'stock_quantity' => 50]);

    $this->cartOfSix = function (): mixed {
        $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);

        return $component->call('updateCartField', array_key_first($component->get('cart')), 'quantity', 6);
    };
});

test('a sale total drops to the nearest half and the difference is saved as discount', function (): void {
    TenantSetting::setBool($this->tenant->id, 'pos_round_total_to_half', true);

    $component = ($this->cartOfSix)();
    expect($component->instance()->subtotal)->toBe(8.58)->and($component->instance()->total)->toBe(8.5);

    $component->call('checkout')->assertHasNoErrors();

    $order = Order::query()->sole();
    expect((float) $order->subtotal)->toBe(8.58)
        ->and((float) $order->discount)->toBe(0.08)
        ->and((float) $order->total)->toBe(8.5)
        ->and((float) $order->paid_amount)->toBe(8.5)
        ->and($order->payment_status)->toBe('paid');
});

test('totals that are already a whole or a half stay unchanged', function (): void {
    TenantSetting::setBool($this->tenant->id, 'pos_round_total_to_half', true);
    BranchProduct::query()->update(['retail_price' => 1.25]);

    $component = ($this->cartOfSix)();
    expect($component->instance()->total)->toBe(7.5);

    $component->call('checkout')->assertHasNoErrors();
    expect((float) Order::query()->sole()->discount)->toBe(0.0)->and((float) Order::query()->sole()->total)->toBe(7.5);
});

test('sale totals keep their fractions unless the store turns rounding on', function (): void {
    $component = ($this->cartOfSix)();
    expect($component->instance()->total)->toBe(8.58);

    $component->call('checkout')->assertHasNoErrors();
    expect((float) Order::query()->sole()->total)->toBe(8.58)->and((float) Order::query()->sole()->discount)->toBe(0.0);
});

test('the store settings page turns total rounding on', function (): void {
    Livewire::test('pages::settings.store')->assertSet('roundTotalToHalf', false)
        ->set('roundTotalToHalf', true)->call('save')->assertHasNoErrors();

    expect(TenantSetting::getBool($this->tenant->id, 'pos_round_total_to_half'))->toBeTrue();
});
