<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر المدفوع']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف', 'cost_price' => 2]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);

    $this->editing = function (): mixed {
        $component = Livewire::test('pages::tenant.pos.sales')
            ->call('selectInlineProduct', $this->product->id)
            ->call('selectInlineProduct', $this->product->id)
            ->call('checkout')->assertHasNoErrors();
        $order = Order::query()->sole();
        expect((float) $order->paid_amount)->toBe(20.0)->and($order->payment_status)->toBe('paid');

        return $component->call('loadInvoice', $order->id)->call('editLoadedInvoice')->assertSet('invoiceEditMode', true);
    };
});

test('the paid amount follows the total while a fully paid invoice is edited', function (): void {
    $component = ($this->editing)();
    $key = array_key_first($component->get('cart'));

    $component->call('updateCartField', $key, 'quantity', 5)->assertSet('paid_amount', 60.0)
        ->call('updateCartField', $key, 'quantity', 1)->assertSet('paid_amount', 20.0)
        ->call('updateCartField', $key, 'quantity', 4)
        ->call('checkout')->assertHasNoErrors();

    $order = Order::query()->sole();
    expect((float) $order->total)->toBe(50.0)
        ->and((float) $order->paid_amount)->toBe(50.0)
        ->and($order->payment_status)->toBe('paid');
});

test('a paid amount typed by the cashier is kept while the invoice is edited', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون', 'type' => 'customer']);
    $component = ($this->editing)();
    $key = array_key_first($component->get('cart'));

    $component->set('selectedCustomerId', $customer->id)->set('paid_amount', 15)
        ->call('updateCartField', $key, 'quantity', 5)
        ->assertSet('paid_amount', 15);
});

test('a partially paid invoice keeps its paid amount when edited', function (): void {
    $component = ($this->editing)();
    Order::query()->sole()->update(['paid_amount' => 5, 'payment_status' => 'partial']);
    $component = Livewire::test('pages::tenant.pos.sales')->call('loadInvoice', Order::query()->sole()->id)->call('editLoadedInvoice');
    $key = array_key_first($component->get('cart'));

    $component->call('updateCartField', $key, 'quantity', 5)->assertSet('paid_amount', 5.0);
});
