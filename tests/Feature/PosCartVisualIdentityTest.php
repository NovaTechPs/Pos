<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $tenant = Tenant::create(['name' => 'متجر ألوان السلة']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    Shift::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'opened_by' => $user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    foreach (['سلك الأرجيلة', 'كوب زجاج'] as $name) {
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => $name, 'cost_price' => 2]);
        BranchProduct::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock_quantity' => 50, 'retail_price' => 10]);
        $this->products[] = $product;
    }
});

test('each product type has its own color and repeated rows share that color with occurrence numbering', function (): void {
    [$first, $second] = $this->products;
    $component = Livewire::test('pages::tenant.pos.sales')->set('mergeSimilarProducts', false)
        ->call('selectInlineProduct', $first->id)->call('selectInlineProduct', $second->id)->call('selectInlineProduct', $first->id);
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//tr[@data-pos-cart-line]');
    expect($rows->length)->toBe(3)
        ->and($rows->item(0)->getAttribute('style'))->toBe($rows->item(2)->getAttribute('style'))
        ->and($rows->item(0)->getAttribute('style'))->not->toBe($rows->item(1)->getAttribute('style'));
    $colors = [];
    foreach ($rows as $row) {
        $colors[] = $row->getAttribute('style');
    }
    expect(array_unique($colors))->toHaveCount(2);
    $occurrences = [];
    foreach ($xpath->query('//*[@data-pos-product-occurrence]') as $badge) {
        $occurrences[] = $badge->getAttribute('data-pos-product-occurrence');
    }
    expect($occurrences)->toBe(['1', '1', '2']);
    $cart = $component->get('cart');
    expect(array_sum(array_column($cart, 'subtotal')))->toBe(30.0);
    $component->call('removeFromCart', array_key_first($cart));
    $component->assertDontSee('data-pos-product-occurrence="2"', false);
    $component->call('toggleMergeSimilarProducts')->assertDontSee('data-pos-product-occurrence', false)->assertDontSee('data-pos-product-color', false);
});
