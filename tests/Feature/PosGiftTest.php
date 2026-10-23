<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncOperation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Gift store']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    Shift::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opening_cash' => 0, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $this->user->id,
    ]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Gift product', 'cost_price' => 4]);
    $this->stock = BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id,
        'retail_price' => 10, 'stock_quantity' => 5,
    ]);
});

test('gift stays separate from a paid copy and survives pricing quantity merge save reload and receipt', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')
        ->assertSee('إضافة هدية')->assertSee('wire:click="startGiftSelection"', false)
        ->call('selectInlineProduct', $this->product->id)
        ->call('startGiftSelection')->assertSet('showProductsModal', true)->assertSee('اختر صنف الهدية')
        ->call('selectInlineProduct', $this->product->id)->assertSet('selectingGift', false)->assertSee('هدية');
    $giftKey = collect($component->get('cart'))->search(fn (array $item): bool => ! empty($item['is_gift']));
    $component->call('updateCartField', $giftKey, 'price', 99)
        ->call('updateUnitPrice', $giftKey, 99)->call('updateLineTotal', $giftKey, 99)
        ->call('updateQuantity', $giftKey, 2)->call('toggleMergeSimilarProducts')->call('toggleMergeSimilarProducts')->call('toggleMergeSimilarProducts');
    expect(count($component->get('cart')))->toBe(2);
    $component->set('paid_amount', 10)->call('checkoutAndPrint')->assertDispatched('print-receipt');
    $order = Order::query()->sole();
    $gift = $order->items()->where('is_gift', true)->sole();
    expect((float) $order->total)->toBe(10.0)->and((float) $order->total_cost)->toBe(12.0)
        ->and((float) $gift->unit_price)->toBe(0.0)->and((float) $gift->total_price)->toBe(0.0)
        ->and((float) $gift->quantity)->toBe(2.0)->and((float) $this->stock->fresh()->stock_quantity)->toBe(2.0);
    $receipt = $component->get('receipt');
    expect($receipt['items'])->toHaveCount(2)
        ->and(collect($receipt['items'])->contains(fn (array $item): bool => str_contains($item['name'], 'هدية')))->toBeTrue();
    $component->call('loadInvoice', $order->id);
    expect($component->get('cart'))->toHaveCount(2)
        ->and(collect($component->get('cart'))->where('is_gift', true))->toHaveCount(1);
});

test('gift only invoice has zero amount and no payment but reduces stock', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('startGiftSelection')
        ->call('selectInlineProduct', $this->product->id)->call('checkoutAndPrint')->assertDispatched('print-receipt');
    $order = Order::query()->sole();
    expect((float) $order->total)->toBe(0.0)->and((float) $order->total_cost)->toBe(4.0)
        ->and(Payment::query()->count())->toBe(0)->and((float) $this->stock->fresh()->stock_quantity)->toBe(4.0);
});

test('sale and gift combined cannot exceed available stock', function (): void {
    $this->stock->update(['stock_quantity' => 1]);
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->call('startGiftSelection')->call('selectInlineProduct', $this->product->id)->call('checkoutAndPrint');
    expect($component->get('errorMessage'))->not->toBeNull();
    expect(Order::query()->count())->toBe(0)->and((float) $this->stock->fresh()->stock_quantity)->toBe(1.0);
});

test('closing gift chooser does not turn a subsequent ordinary selection into a gift', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('startGiftSelection')
        ->set('showProductsModal', false)->call('selectInlineProduct', $this->product->id);
    expect(collect($component->get('cart'))->first()['is_gift'])->toBeFalse();
});

test('gift flag is preserved in queued branch sync payload', function (): void {
    config(['branch-sync.mode' => 'edge']);
    Livewire::test('pages::tenant.pos.sales')->call('startGiftSelection')
        ->call('selectInlineProduct', $this->product->id)->call('checkoutAndPrint');
    $operation = BranchSyncOperation::query()->where('operation_type', 'sale.upsert')->sole();
    expect($operation->payload['items'][0]['is_gift'])->toBeTrue();
});
