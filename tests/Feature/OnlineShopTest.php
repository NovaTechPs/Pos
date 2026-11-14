<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\OnlineShop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Fanous', 'is_active' => true]);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true]);
    $this->tenant->update(['owner_id' => $this->owner->id]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'فانوس', 'domain' => 'fanous']);
    TenantSetting::setBool($this->tenant->id, OnlineShop::SETTING, true);
    $this->product = function (string $name, float $retail, float $wholesale, float $stock, bool $website = true): Product {
        $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => $name, 'cost_price' => 1, 'show_in_website' => $website]);
        BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id,
            'retail_price' => $retail, 'wholesale_price' => $wholesale, 'stock_quantity' => $stock]);

        return $product;
    };
    $this->kettle = ($this->product)('غلاية كهرباء', 25, 20, 4);
    $this->cup = ($this->product)('كاسة ستانلس', 10, 8, 0);
    $this->hidden = ($this->product)('صنف داخلي', 5, 4, 9, false);
});

test('the shop stays closed until the store opens it', function (): void {
    TenantSetting::setBool($this->tenant->id, OnlineShop::SETTING, false);
    request()->attributes->remove('tenant_settings_values_'.$this->tenant->id);

    $this->get('/shop/fanous')->assertNotFound();
    $this->get('/shop/unknown')->assertNotFound();
});

test('a guest browses retail prices, sees availability only, and orders by name and phone', function (): void {
    $this->get('/shop/fanous')->assertOk()
        ->assertSee('غلاية كهرباء')->assertSee('25.00')->assertSee('متوفر')->assertSee('غير متوفر')
        ->assertDontSee('صنف داخلي')->assertDontSee('20.00');

    $page = Livewire::test('pages::shop.index', ['shop' => 'fanous'])
        ->call('add', $this->kettle->id)->call('add', $this->kettle->id)
        ->call('$set', 'checkingOut', true)
        ->set('name', 'أم محمد')->set('phone', '0599 123 456')->set('address', 'الخليل')
        ->call('placeOrder')->assertHasNoErrors();

    $order = CustomerOrder::query()->sole();
    $page->assertRedirect(route('shop.track', ['shop' => 'fanous', 'token' => $order->tracking_token]));
    expect($order->status)->toBe('new')
        ->and((float) $order->total)->toBe(50.0)
        ->and($order->party_id)->toBeNull()
        ->and($order->items()->sole()->product_id)->toBe($this->kettle->id)
        // An order request is not a sale: no invoice and no stock movement yet.
        ->and(Order::withoutGlobalScopes()->count())->toBe(0)
        ->and((float) BranchProduct::query()->where('product_id', $this->kettle->id)->value('stock_quantity'))->toBe(4.0);

    $this->get(route('shop.track', ['shop' => 'fanous', 'token' => $order->tracking_token]))->assertOk()
        ->assertSee($order->reference)->assertSee('جديد')->assertSee('غلاية كهرباء');
    $this->get(route('shop.track', ['shop' => 'fanous', 'token' => 'wrong-token']))->assertNotFound();
});

test('prices come from the branch, never from the browser, and hidden products cannot be ordered', function (): void {
    expect(fn () => app(OnlineShop::class)->placeOrder($this->branch, [$this->hidden->id => 1], ['name' => 'x', 'phone' => '0599000000']))
        ->toThrow(ValidationException::class);

    $order = app(OnlineShop::class)->placeOrder($this->branch, [$this->kettle->id => 3], ['name' => 'x', 'phone' => '0599000000']);
    expect((float) $order->items()->sole()->unit_price)->toBe(25.0);
});

test('customers register with phone and password, sign in, and wholesale accounts see wholesale prices', function (): void {
    Livewire::test('pages::shop.register', ['shop' => 'fanous'])
        ->set('name', 'سامي')->set('phone', '٠٥٩٩١١١٢٢٢')->set('password', 'Secret-1234')->set('password_confirmation', 'Secret-1234')
        ->call('register')->assertHasNoErrors()->assertRedirect(route('shop.home', ['shop' => 'fanous']));

    $account = CustomerAccount::query()->sole();
    expect($account->phone)->toBe('0599111222')
        ->and($account->party->type)->toBe('customer')
        ->and($account->party->tenant_id)->toBe($this->tenant->id)
        ->and(Auth::guard('customer')->id())->toBe($account->id);

    Auth::guard('customer')->logout();
    Livewire::test('pages::shop.login', ['shop' => 'fanous'])->set('phone', '0599111222')->set('password', 'wrong-password')
        ->call('login')->assertHasErrors('phone');
    Livewire::test('pages::shop.login', ['shop' => 'fanous'])->set('phone', '0599 111 222')->set('password', 'Secret-1234')
        ->call('login')->assertHasNoErrors();
    expect(Auth::guard('customer')->id())->toBe($account->id);

    $account->update(['price_list' => 'wholesale']);
    Auth::guard('customer')->setUser($account->fresh());
    $this->get('/shop/fanous')->assertSee('20.00')->assertSee('أسعار الجملة');
    $order = app(OnlineShop::class)->placeOrder($this->branch, [$this->kettle->id => 1], ['name' => 'سامي', 'phone' => '0599111222'], $account->fresh());
    expect((float) $order->total)->toBe(20.0)->and($order->party_id)->toBe($account->party_id);
});

test('a customer sees only their own orders, invoices and statement, and downloads their invoice', function (): void {
    $party = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'محل النور', 'type' => 'customer', 'is_active' => true, 'current_balance' => 0]);
    $account = CustomerAccount::query()->create(['tenant_id' => $this->tenant->id, 'party_id' => $party->id, 'phone' => '0598000000', 'password' => 'Secret-1234', 'price_list' => 'wholesale']);
    $other = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'زبون آخر', 'type' => 'customer', 'is_active' => true]);
    $mine = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $party->id, 'invoice_number' => 'INV-MINE',
        'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 40, 'total' => 40, 'paid_amount' => 10]);
    $theirs = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $other->id, 'invoice_number' => 'INV-THEIRS',
        'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 99, 'total' => 99, 'paid_amount' => 0]);

    $this->get('/shop/fanous/account')->assertRedirect(route('shop.login', ['shop' => 'fanous']));

    Auth::guard('customer')->login($account);
    Livewire::test('pages::shop.account', ['shop' => 'fanous'])->set('tab', 'invoices')
        ->assertSee('INV-MINE')->assertDontSee('INV-THEIRS')
        ->set('tab', 'statement')->assertSee('INV-MINE')->assertDontSee('INV-THEIRS');

    $this->get(route('shop.invoice.pdf', ['shop' => 'fanous', 'order' => $mine->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('shop.invoice.pdf', ['shop' => 'fanous', 'order' => $theirs->id]))->assertNotFound();
    // A staff login page is never reached by shop customers.
    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('an account from another store cannot use this store', function (): void {
    $otherTenant = Tenant::query()->create(['name' => 'Other', 'is_active' => true]);
    $otherParty = Party::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'غريب', 'type' => 'customer', 'is_active' => true]);
    $stranger = CustomerAccount::query()->create(['tenant_id' => $otherTenant->id, 'party_id' => $otherParty->id, 'phone' => '0597000000', 'password' => 'Secret-1234']);

    Auth::guard('customer')->login($stranger);
    $this->get('/shop/fanous/account')->assertForbidden();
    Livewire::test('pages::shop.login', ['shop' => 'fanous'])->set('phone', '0597000000')->set('password', 'Secret-1234')
        ->call('login')->assertHasErrors('phone');
});

test('staff open a shop account for a customer and change it later', function (): void {
    $party = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'محل النور', 'phone' => '0596 555 444', 'type' => 'customer', 'is_active' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.customers.shop-account')
        ->call('openFor', $party->id)->assertSet('phone', '0596555444')->assertSet('exists', false)
        ->set('priceList', 'wholesale')->call('save')->assertHasErrors('password')
        ->set('password', 'Shop-Pass-1')->call('save')->assertHasNoErrors();

    $account = CustomerAccount::query()->sole();
    expect($account->party_id)->toBe($party->id)->and($account->price_list)->toBe('wholesale')
        ->and(Auth::guard('customer')->validate(['tenant_id' => $this->tenant->id, 'phone' => '0596555444', 'password' => 'Shop-Pass-1']))->toBeTrue();

    Livewire::test('pages::tenant.customers.shop-account')
        ->call('openFor', $party->id)->assertSet('exists', true)
        ->set('isActive', false)->call('save')->assertHasNoErrors();
    expect($account->fresh()->is_active)->toBeFalse()
        ->and(Auth::guard('customer')->validate(['tenant_id' => $this->tenant->id, 'phone' => '0596555444', 'password' => 'Shop-Pass-1']))->toBeTrue();
    Livewire::test('pages::shop.login', ['shop' => 'fanous'])->set('phone', '0596555444')->set('password', 'Shop-Pass-1')->call('login')->assertHasErrors('phone');

    $this->get(route('tenant.customer'))->assertOk()->assertSee('data-shop-account-button', false);
});

test('staff review a shop order and turn it into a sales invoice that moves stock and the customer balance', function (): void {
    $party = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'محل النور', 'type' => 'customer', 'is_active' => true, 'current_balance' => 0]);
    $account = CustomerAccount::query()->create(['tenant_id' => $this->tenant->id, 'party_id' => $party->id, 'phone' => '0598000000', 'password' => 'Secret-1234', 'price_list' => 'wholesale']);
    $request = app(OnlineShop::class)->placeOrder($this->branch, [$this->kettle->id => 2], ['name' => 'محل النور', 'phone' => '0598000000'], $account);
    $this->owner->update(['branch_id' => $this->branch->id]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->owner->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);

    Livewire::test('pages::tenant.customer-orders.index')->assertSee($request->reference)
        ->call('show', $request->id)->assertSee('حوّل لفاتورة مبيعات')
        ->call('setStatus', $request->id, 'processing');
    expect($request->fresh()->status)->toBe('processing');

    $invoicePage = Livewire::withQueryParams(['customer_order' => $request->id])->test('pages::tenant.sales.invoices')
        ->assertSet('selectedCustomerId', $party->id)->assertSet('customerOrderId', $request->id);
    $line = collect($invoicePage->get('cart'))->sole();
    expect($line['id'])->toBe($this->kettle->id)->and((float) $line['quantity'])->toBe(2.0)->and((float) $line['price'])->toBe(20.0);

    $invoicePage->call('saveInvoice')->assertSet('errorMessage', null);
    $invoice = Order::query()->where('customer_id', $party->id)->sole();
    expect($request->fresh()->status)->toBe('invoiced')
        ->and($request->fresh()->order_id)->toBe($invoice->id)
        ->and((float) $invoice->total)->toBe(40.0)
        ->and((float) BranchProduct::query()->where('product_id', $this->kettle->id)->value('stock_quantity'))->toBe(2.0)
        ->and((float) $party->fresh()->current_balance)->toBe(40.0);

    // An invoiced request cannot be invoiced twice or cancelled.
    Livewire::withQueryParams(['customer_order' => $request->id])->test('pages::tenant.sales.invoices')->assertSet('customerOrderId', null)->assertSet('cart', []);
    Livewire::test('pages::tenant.customer-orders.index')->call('setStatus', $request->id, 'cancelled')->assertNotFound();
    expect($request->fresh()->status)->toBe('invoiced');

    Auth::guard('customer')->login($account);
    Livewire::test('pages::shop.account', ['shop' => 'fanous'])->assertSee('تمت الفوترة')->assertSee($invoice->invoice_number);
});

test('the store settings open the shop and list each branch link', function (): void {
    TenantSetting::setBool($this->tenant->id, OnlineShop::SETTING, false);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::settings.store')->assertSet('onlineShop', false)
        ->assertSee(route('shop.home', ['shop' => 'fanous']))
        ->set('onlineShop', true)->call('save')->assertHasNoErrors();

    expect(TenantSetting::query()->where('tenant_id', $this->tenant->id)->where('key', OnlineShop::SETTING)->value('value'))->toBeIn(['1', 'true', true, 1]);
});
