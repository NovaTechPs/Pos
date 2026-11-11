<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الأسعار']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);

    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'تيرموس', 'product_number' => 'I00000022', 'cost_price' => 12]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 20, 'wholesale_price' => 17]);
    $this->customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'أبو أحمد', 'type' => 'customer', 'is_active' => true]);
    $this->otherCustomer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون آخر', 'type' => 'customer', 'is_active' => true]);

    foreach ([[$this->customer, 16.5, now()->subDays(2)], [$this->customer, 18.0, now()->subDay()], [$this->otherCustomer, 15.0, now()]] as $index => [$party, $price, $date]) {
        $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $party->id, 'customer_name' => $party->name,
            'invoice_number' => 'OLD-'.$index, 'type' => 'pos', 'status' => 'completed', 'subtotal' => $price, 'total' => $price, 'paid_amount' => $price]);
        $order->forceFill(['created_at' => $date])->save();
        OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => $price, 'total_price' => $price, 'cost_price' => 12, 'total_cost' => 12]);
    }
});

test('items added after choosing a customer use the last price sold to that customer', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')
        ->call('selectCustomer', $this->customer->id)
        ->call('selectInlineProduct', $this->product->id);

    $line = collect($component->get('cart'))->first();
    expect((float) $line['price'])->toBe(18.0)
        ->and($line['customer_price'])->toBeTrue()
        ->and($line['price_manual'])->toBeTrue();
    $component->assertSee('آخر سعر للزبون');
});

test('choosing, changing and clearing the customer reprices cart lines', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    expect((float) collect($component->get('cart'))->first()['price'])->toBe(20.0);

    $component->call('selectCustomer', $this->customer->id);
    expect((float) collect($component->get('cart'))->first()['price'])->toBe(18.0);

    $component->call('selectCustomer', $this->otherCustomer->id);
    expect((float) collect($component->get('cart'))->first()['price'])->toBe(15.0);

    $component->call('clearCustomer');
    $line = collect($component->get('cart'))->first();
    expect((float) $line['price'])->toBe(20.0)
        ->and($line['customer_price'])->toBeFalse()
        ->and($line['price_manual'])->toBeFalse();
});

test('a price typed by the cashier is not replaced by the customer price', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $lineKey = array_key_first($component->get('cart'));

    $component->call('updateCartField', $lineKey, 'price', 19.25)
        ->call('selectCustomer', $this->customer->id);

    expect((float) $component->get('cart')[$lineKey]['price'])->toBe(19.25);
});

test('customers without previous sales keep the branch price', function (): void {
    $newCustomer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون جديد', 'type' => 'customer', 'is_active' => true]);

    $line = collect(Livewire::test('pages::tenant.pos.sales')
        ->call('selectCustomer', $newCustomer->id)
        ->call('selectInlineProduct', $this->product->id)
        ->get('cart'))->first();

    expect((float) $line['price'])->toBe(20.0)->and($line['customer_price'])->toBeFalse();
});

test('the customer price is kept when the invoice is saved', function (): void {
    Livewire::test('pages::tenant.pos.sales')
        ->call('selectCustomer', $this->customer->id)
        ->call('selectInlineProduct', $this->product->id)
        ->call('checkout')
        ->assertSet('showCustomerPaymentModal', true)
        ->set('customerPaymentAmount', 18)
        ->call('confirmCustomerPayment')
        ->assertSet('errorMessage', null);

    $order = Order::query()->where('invoice_number', 'not like', 'OLD-%')->sole();
    expect((float) $order->items()->sole()->unit_price)->toBe(18.0)
        ->and((float) $order->total)->toBe(18.0);
});

test('pos cart lines open the price history with the customer, branch prices and costs', function (): void {
    Livewire::test('pages::tenant.pos.sales')
        ->call('selectCustomer', $this->customer->id)
        ->call('selectInlineProduct', $this->product->id)
        ->assertSee('data-pos-price-history', false)
        ->assertSee("open-product-price-history', { productId: {$this->product->id}, customerId: {$this->customer->id} }", false);

    Livewire::test('product-price-history-modal')
        ->dispatch('open-product-price-history', productId: $this->product->id, customerId: $this->customer->id)
        ->assertSee('data-price-history-branch-prices', false)
        ->assertSee('سعر البيع (مفرق)')
        ->assertSee('20.00')
        ->assertSee('17.00')
        ->assertSee('أسعار هذا الزبون')
        ->assertSee('18.00')
        ->assertSee('16.50')
        ->assertSee('أسعار كل الزبائن')
        ->assertSee('15.00')
        ->assertSee('التكلفة الحالية: 12.00');
});

test('the customer picker focuses its search and supports arrow keys, enter and escape', function (): void {
    Livewire::test('pages::tenant.pos.sales')
        ->call('openCustomerModal')
        ->assertSet('showCustomerModal', true)
        ->assertSee('data-pos-customer-picker', false)
        ->assertSee('x-init="$nextTick(() => requestAnimationFrame(() => { $el.focus({ preventScroll: true }); $el.select(); }))"', false)
        ->assertSee('x-on:keydown.arrow-down.prevent.stop="move(1)"', false)
        ->assertSee('x-on:keydown.arrow-up.prevent.stop="move(-1)"', false)
        ->assertSee('x-on:keydown.enter.prevent.stop="$root.querySelector(\'[data-pos-customer-result]\')?.click()"', false)
        ->assertSee('x-on:keydown.escape.stop="$wire.set(\'showCustomerModal\', false)"', false)
        ->assertSee('data-pos-customer-result', false)
        ->assertSee('أبو أحمد');
});
