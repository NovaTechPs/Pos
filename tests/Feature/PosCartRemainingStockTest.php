<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Stock preview shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Current branch']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cart stock product', 'cost_price' => 4]);
    $this->stock = BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'retail_price' => 10, 'stock_quantity' => 20]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
});

/** @return list<float|null> */
function cartStockValues(Testable $component, int $productId): array
{
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//tr[@data-pos-cart-line]/td[@data-pos-stock-remaining]/preceding-sibling::td[1]//input[@data-pos-field="price"]')->length)
        ->toBe($xpath->query('//tr[@data-pos-cart-line]')->length)
        ->and($xpath->query('//tr[@data-pos-cart-line]/td[@data-pos-stock-remaining]/following-sibling::td[1]//input[@data-pos-field="subtotal"]')->length)
        ->toBe($xpath->query('//tr[@data-pos-cart-line]')->length);
    $values = [];
    foreach ($xpath->query('//*[@data-pos-stock-remaining="'.$productId.'"]') as $element) {
        $value = $element->getAttribute('data-remaining-quantity');
        $values[] = $value === '' ? null : (float) $value;
    }

    return $values;
}

test('cart stock updates with quantity and removal without changing actual inventory', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    expect(cartStockValues($component, $this->product->id))->toBe([19.0]);
    $key = array_key_first($component->get('cart'));
    $component->call('updateCartField', $key, 'quantity', 3.25);
    expect(cartStockValues($component, $this->product->id))->toBe([16.75])
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(20.0);
    $component->call('removeFromCart', $key);
    expect(cartStockValues($component, $this->product->id))->toBe([]);
});

test('duplicate and gift lines share a remaining count based on their combined quantities', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->call('selectInlineProduct', $this->product->id)->call('startGiftSelection')->call('selectInlineProduct', $this->product->id);
    $count = count($component->get('cart'));
    expect(cartStockValues($component, $this->product->id))->toBe(array_fill(0, $count, 17.0));
    $giftKey = collect($component->get('cart'))->search(fn (array $item): bool => ! empty($item['is_gift']));
    $component->call('removeFromCart', $giftKey);
    expect(cartStockValues($component, $this->product->id))->toBe(array_fill(0, count($component->get('cart')), 18.0));
});

test('ordinary stock preview is restricted to the current branch', function (): void {
    $other = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $other->id,
        'product_id' => $this->product->id, 'stock_quantity' => 100]);
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    expect(cartStockValues($component, $this->product->id))->toBe([19.0]);
});

test('unified stock preview uses the tenant total', function (): void {
    TenantSetting::setBool($this->tenant->id, 'unified_stock', true);
    $other = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $other->id,
        'product_id' => $this->product->id, 'stock_quantity' => 5]);
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)->assertSee('(موحد)');
    expect(cartStockValues($component, $this->product->id))->toBe([24.0]);
});

test('offline shared stock uses the synchronized quantity', function (): void {
    config(['app.desktop' => true, 'branch-sync.mode' => 'edge']);
    TenantSetting::setBool($this->tenant->id, 'unified_stock', true);
    TenantSetting::set($this->tenant->id, 'desktop_shared_stock_'.$this->branch->id,
        json_encode([$this->product->id => 80], JSON_THROW_ON_ERROR));
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    expect(cartStockValues($component, $this->product->id))->toBe([79.0]);
});

test('saved invoice stock is not deducted again and edits add back the original quantities', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $key = array_key_first($component->get('cart'));
    $component->call('updateCartField', $key, 'quantity', 2)->set('paid_amount', 20)->call('checkout');
    $order = Order::query()->sole();
    $component->call('loadInvoice', $order->id)->assertSee('المخزون الحالي:');
    expect(cartStockValues($component, $this->product->id))->toBe([18.0]);
    $component->call('editLoadedInvoice');
    $key = array_key_first($component->get('cart'));
    $component->call('updateCartField', $key, 'quantity', 4);
    expect(cartStockValues($component, $this->product->id))->toBe([16.0])
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(18.0);
});

test('negative remaining stock is displayed rather than hidden as zero', function (): void {
    $this->stock->update(['stock_quantity' => 0]);
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    expect(cartStockValues($component, $this->product->id))->toBe([-1.0]);
});

test('return quantities increase the projected stock', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->set('isReturnMode', true)->call('selectInlineProduct', $this->product->id);
    expect(cartStockValues($component, $this->product->id))->toBe([21.0]);
});

test('a missing branch stock record is reported as unavailable', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $this->stock->delete();
    $component->call('refreshBranchSyncStatus')->assertSee('غير متاح');
    expect(cartStockValues($component, $this->product->id))->toBe([null]);
});
