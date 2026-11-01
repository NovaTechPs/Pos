<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\BranchStockAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Shared stock']);
    $this->remoteBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Warehouse']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Selling branch']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id,
        'opening_cash' => 0, 'status' => 'open', 'opened_at' => now()]);
    TenantSetting::setBool($this->tenant->id, 'unified_stock', true);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'Shared item', 'cost_price' => 4]);
    $this->remote = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->remoteBranch->id,
        'product_id' => $this->product->id, 'stock_quantity' => 10, 'retail_price' => 10]);
    $this->local = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'stock_quantity' => 2, 'retail_price' => 10]);
});

test('web checkout consumes its own stock first then borrows the remainder', function (float $quantity): void {
    $this->local->update(['stock_quantity' => $quantity]);
    $component = Livewire::test('pages::tenant.pos.sales')->set('cart', [
        'item_'.$this->product->id => ['id' => $this->product->id, 'name' => $this->product->name,
            'quantity' => 3, 'price' => 10, 'cost_price' => 4, 'subtotal' => 30],
    ])->set('paid_amount', 30)->call('checkout')->assertSet('errorMessage', null);
    expect(Order::count())->toBe(1)
        ->and((float) $this->local->fresh()->stock_quantity)->toBe(max(0.0, $quantity - 3))
        ->and((float) $this->remote->fresh()->stock_quantity)->toBe(10.0 - max(0.0, 3 - $quantity));

    $order = Order::sole();
    $component->set('currentInvoiceId', $order->id)->call('deleteInvoice')->assertSet('errorMessage', null);
    expect((float) $this->local->fresh()->stock_quantity)->toBe($quantity)
        ->and((float) $this->remote->fresh()->stock_quantity)->toBe(10.0);
})->with([0.0, 2.0, 5.0]);

test('disabled sharing rejects borrowing without changing either stock row', function (): void {
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'subtotal' => 0, 'total' => 0, 'invoice_number' => 'SHARED-TEST', 'type' => 'pos', 'status' => 'completed', 'created_by' => $this->user->id]);
    expect(fn () => app(BranchStockAllocator::class)->deduct($order, $this->product->id, 3, false, false))
        ->toThrow(DomainException::class);
    expect((float) $this->local->fresh()->stock_quantity)->toBe(2.0)
        ->and((float) $this->remote->fresh()->stock_quantity)->toBe(10.0);
});

test('editing a shared invoice twice restores each original branch before reallocating', function (): void {
    $cart = fn (int $quantity): array => ['item_'.$this->product->id => ['id' => $this->product->id,
        'name' => $this->product->name, 'quantity' => $quantity, 'price' => 10, 'cost_price' => 4, 'subtotal' => 10 * $quantity]];
    $component = Livewire::test('pages::tenant.pos.sales')->set('cart', $cart(3))->set('paid_amount', 30)
        ->call('checkout')->assertSet('errorMessage', null);
    $order = Order::sole();
    foreach ([2, 4] as $quantity) {
        $component->call('loadInvoice', $order->id)->call('editLoadedInvoice')->set('cart', $cart($quantity))->set('paid_amount', 10 * $quantity)
            ->call('checkout')->assertSet('errorMessage', null);
        expect((float) $this->local->fresh()->stock_quantity)->toBe(0.0)
            ->and((float) $this->remote->fresh()->stock_quantity)->toBe(10.0 - ($quantity - 2));
    }
    $component->set('currentInvoiceId', $order->id)->call('deleteInvoice')->assertSet('errorMessage', null);
    expect((float) $this->local->fresh()->stock_quantity)->toBe(2.0)
        ->and((float) $this->remote->fresh()->stock_quantity)->toBe(10.0);
});

test('shared allocation exhausts available positive stock before permitted negative stock', function (): void {
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'subtotal' => 0, 'total' => 0, 'invoice_number' => 'SHARED-NEGATIVE', 'type' => 'pos', 'status' => 'completed', 'created_by' => $this->user->id]);
    app(BranchStockAllocator::class)->deduct($order, $this->product->id, 15, true, true);
    expect((float) $this->local->fresh()->stock_quantity)->toBe(-3.0)
        ->and((float) $this->remote->fresh()->stock_quantity)->toBe(0.0);
});
