<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\Paginator;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الأرقام']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'فرع آخر']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);

    $this->target = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'تيرموس قهوة', 'product_number' => 'I00000022', 'cost_price' => 5]);
    $this->other = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'سلة ثلاجة', 'product_number' => 'I00000035', 'cost_price' => 5]);
    foreach ([$this->target, $this->other] as $product) {
        BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'stock_quantity' => 10, 'retail_price' => 10, 'wholesale_price' => 9]);
    }
});

test('pos finds items by any part of the item number', function (string $search): void {
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', $search);
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$this->target->id]);

    $component->set('inlineSearchQuery', $search);
    expect(array_column($component->get('inlineSearchResults'), 'id'))->toBe([$this->target->id]);
})->with(['I00000022', 'i00000022', '00000022', '0022']);

test('invoices and products page find items by any part of the item number', function (string $page, string $property, string $results, string $search): void {
    Livewire::test($page)->set($property, $search)
        ->assertViewHas($results, fn ($products): bool => collect($products instanceof Paginator ? $products->items() : $products)->pluck('id')->all() === [$this->target->id]);
})->with([
    ['pages::tenant.sales.invoices', 'rowSearch', 'productResults'],
    ['pages::tenant.purchases.invoices', 'rowSearch', 'productResults'],
    ['pages::tenant.products.index', 'search', 'products'],
    ['pages::tenant.wholesale.sales', 'search', 'products'],
])->with(['00000022', '0022']);

test('stock transfers find items by any part of the item number', function (): void {
    Livewire::test('pages::tenant.stock-transfers.index')
        ->set('fromBranchId', $this->branch->id)
        ->set('productSearch', '0022')
        ->assertViewHas('products', fn ($products): bool => collect($products)->pluck('id')->all() === [$this->target->id]);
});
