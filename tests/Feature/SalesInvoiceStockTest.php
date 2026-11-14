<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Stock shop', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->owner->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $this->customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'زبون', 'type' => 'customer', 'is_active' => true]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف', 'cost_price' => 2]);
    $this->stock = BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id,
        'stock_quantity' => 10, 'retail_price' => 5, 'wholesale_price' => 5]);
    $this->sell = fn (float $quantity) => Livewire::test('pages::tenant.sales.invoices')
        ->set('selectedCustomerId', $this->customer->id)
        ->call('selectProduct', $this->product->id)->set('selectedQuantity', $quantity)->call('commitRowToCart')
        ->call('saveInvoice');
});

test('a new sales invoice takes its quantities from the branch stock', function (): void {
    ($this->sell)(3)->assertSet('errorMessage', null);

    $order = Order::query()->sole();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(7.0)
        ->and((float) StockMovement::query()->where('source_type', 'order')->where('source_id', $order->id)->sum('quantity'))->toBe(-3.0);
});

test('editing a sales invoice gives back its old quantities before taking the new ones', function (): void {
    ($this->sell)(3);
    $order = Order::query()->sole();

    $page = Livewire::test('pages::tenant.sales.invoices')->call('loadOrder', $order->id)->call('editLoadedInvoice');
    $key = array_key_first($page->get('cart'));
    $page->set("cart.{$key}.quantity", 5)->call('saveInvoice')->assertSet('errorMessage', null);

    expect((float) $this->stock->fresh()->stock_quantity)->toBe(5.0);
});

test('an invoice saved before stock was deducted can still be edited and then takes its stock', function (): void {
    $legacy = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'customer_name' => 'زبون', 'invoice_number' => 'OLD-1', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
    $legacy->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 5, 'total_price' => 10, 'cost_price' => 2, 'total_cost' => 4]);

    Livewire::test('pages::tenant.sales.invoices')->call('loadOrder', $legacy->id)->call('editLoadedInvoice')
        ->call('saveInvoice')->assertSet('errorMessage', null);

    expect((float) $this->stock->fresh()->stock_quantity)->toBe(8.0);
});

test('a sales invoice for more than the stock is refused with the reason', function (): void {
    ($this->sell)(11)->assertSet('errorMessage', 'المخزون غير كافٍ لإتمام البيع.');

    expect(Order::query()->count())->toBe(0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(10.0);
});
