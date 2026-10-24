<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر العروض']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الرئيسي']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    Shift::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $user->id, 'opening_cash' => 0]);
    $this->product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'صنف عليه عرض', 'cost_price' => 2]);
    $this->stock = BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $this->product->id, 'retail_price' => 12, 'stock_quantity' => 30, 'offer_quantity' => 2, 'offer_price' => 10]);
});

test('quantity offer applies to separate rows and survives merge save and receipt', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->set('mergeSimilarProducts', false)
        ->call('selectInlineProduct', $this->product->id)->call('selectInlineProduct', $this->product->id);
    $cart = $component->get('cart');
    expect($cart)->toHaveCount(2)
        ->and(array_sum(array_column($cart, 'subtotal')))->toBe(10.0)
        ->and(array_sum(array_column($cart, 'promotion_savings')))->toBe(14.0);
    $component->assertSee('bg-amber-50', false)->assertSee('عرض 2 بـ 10.00')
        ->call('toggleMergeSimilarProducts');
    expect(array_sum(array_column($component->get('cart'), 'subtotal')))->toBe(10.0);
    $component->call('toggleMergeSimilarProducts')->set('paid_amount', 10)->call('checkoutAndPrint')->assertDispatched('print-receipt');
    expect((float) Order::query()->sole()->total)->toBe(10.0)
        ->and((float) Order::query()->sole()->items()->sum('total_price'))->toBe(10.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(28.0)
        ->and($component->get('receipt')['total_amount'])->toBe('10.00');
});

test('split offer recalculates remainder quantities and removing a row', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->set('mergeSimilarProducts', false);
    for ($i = 0; $i < 3; $i++) {
        $component->call('selectInlineProduct', $this->product->id);
    }
    $cart = $component->get('cart');
    expect(array_sum(array_column($cart, 'subtotal')))->toBe(22.0);
    $component->call('removeFromCart', array_key_first($cart));
    expect(array_sum(array_column($component->get('cart'), 'subtotal')))->toBe(10.0);
    $component->call('removeFromCart', array_key_first($component->get('cart')));
    expect(array_sum(array_column($component->get('cart'), 'subtotal')))->toBe(12.0);
});

test('gift and manually priced rows do not complete a paid offer', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->set('mergeSimilarProducts', false)
        ->call('selectInlineProduct', $this->product->id)->call('selectInlineProduct', $this->product->id);
    $key = array_key_last($component->get('cart'));
    $component->call('updateUnitPrice', $key, 7);
    expect(array_sum(array_column($component->get('cart'), 'subtotal')))->toBe(19.0);
    $component->call('startGiftSelection')->call('selectInlineProduct', $this->product->id);
    expect(array_sum(array_column($component->get('cart'), 'subtotal')))->toBe(19.0);
});

test('split offer allocation keeps cents exact across three rows', function (): void {
    $this->stock->update(['offer_quantity' => 3, 'offer_price' => 10]);
    $component = Livewire::test('pages::tenant.pos.sales')->set('mergeSimilarProducts', false);
    for ($i = 0; $i < 3; $i++) {
        $component->call('selectInlineProduct', $this->product->id);
    }
    expect(array_sum(array_column($component->get('cart'), 'subtotal')))->toBe(10.0);
    $component->set('paid_amount', 10)->call('checkoutAndPrint')->assertDispatched('print-receipt');
    expect((float) Order::query()->sole()->items()->sum('total_price'))->toBe(10.0);
});

test('scheduled active offers color product cards while expired offers do not', function (): void {
    $this->stock->update(['offer_quantity' => null, 'offer_price' => null]);
    $offer = ProductOffer::query()->create([
        'tenant_id' => $this->stock->tenant_id, 'branch_id' => $this->stock->branch_id, 'product_id' => $this->product->id,
        'offer_quantity' => 2, 'offer_price' => 10, 'start_at' => now()->subHour(), 'end_at' => now()->addHour(), 'is_active' => true,
    ]);
    $component = Livewire::test('pages::tenant.pos.sales');
    expect($component->get('quickProducts')[0]['has_offer'])->toBeTrue();
    $component->set('mergeSimilarProducts', false)->call('selectInlineProduct', $this->product->id)->call('selectInlineProduct', $this->product->id);
    expect(array_sum(array_column($component->get('cart'), 'subtotal')))->toBe(10.0);
    $offer->update(['end_at' => now()->subMinute()]);
    $component->call('loadQuickProducts');
    expect($component->get('quickProducts')[0]['has_offer'])->toBeFalse();
});
