<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Line total shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
});

function lineTotalProduct(object $context, string $name, float $price): Product
{
    $product = Product::query()->create(['tenant_id' => $context->tenant->id, 'name' => $name, 'cost_price' => 0]);
    BranchProduct::query()->create(['tenant_id' => $context->tenant->id, 'branch_id' => $context->branch->id,
        'product_id' => $product->id, 'retail_price' => $price, 'stock_quantity' => 50]);

    return $product;
}

test('editing a line total updates the amount to pay', function (): void {
    $batteries = lineTotalProduct($this, 'Batteries', 1);
    $sharpener = lineTotalProduct($this, 'Sharpener', 3);
    $toy = lineTotalProduct($this, 'Toy', 9);

    $component = Livewire::test('pages::tenant.pos.sales')
        ->call('selectInlineProduct', $batteries->id)
        ->call('selectInlineProduct', $sharpener->id)
        ->call('selectInlineProduct', $toy->id);
    $toyLine = array_key_last($component->get('cart'));
    $component->call('updateCartField', $toyLine, 'quantity', 2);
    expect($component->instance()->total)->toBe(22.0);

    $component->call('updateCartField', $toyLine, 'subtotal', 19);

    expect($component->instance()->subtotal)->toBe(23.0)
        ->and($component->instance()->total)->toBe(23.0)
        ->and((float) $component->get('custom_final_total'))->toBe(23.0)
        ->and((float) $component->get('cart')[$toyLine]['price'])->toBe(9.5);
});
