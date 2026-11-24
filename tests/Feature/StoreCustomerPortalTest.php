<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\CustomerAccount;
use App\Models\CustomerOrder;
use App\Models\Party;
use App\Models\Product;
use App\Models\Shift;
use App\Models\StoreCustomer;
use App\Models\StoreCustomerAddress;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\OnlineShop;
use App\Services\StoreCustomerAddresses;
use App\Services\StoreCustomerRegistration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'First', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'المتجر الأول', 'domain' => 'first']);
    TenantSetting::setBool($this->tenant->id, OnlineShop::SETTING, true);
    $this->otherTenant = Tenant::query()->create(['name' => 'Second', 'is_active' => true]);
    $this->otherBranch = Branch::query()->create(['tenant_id' => $this->otherTenant->id, 'name' => 'المتجر الثاني', 'domain' => 'second']);
    TenantSetting::setBool($this->otherTenant->id, OnlineShop::SETTING, true);
    $this->customer = StoreCustomer::factory()->create(['tenant_id' => $this->tenant->id, 'phone' => '0599111222']);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'اسم المنتج الأصلي', 'cost_price' => 5, 'show_in_website' => true]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 20, 'retail_price' => 30, 'wholesale_price' => 15]);
    $this->addressInput = ['label' => 'المنزل', 'recipient_name' => 'سامي', 'phone' => '0599111222', 'city' => 'الخليل', 'address_line' => 'شارع السلام', 'is_default' => true];
    $this->makeOrder = fn (StoreCustomer $customer, Branch $branch, string $reference): CustomerOrder => CustomerOrder::query()->create([
        'tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'store_customer_id' => $customer->id,
        'reference' => $reference, 'tracking_token' => str()->random(48), 'customer_name' => $customer->name,
        'customer_phone' => $customer->phone, 'status' => 'new', 'price_list' => 'retail', 'total' => 30,
    ]);
});

test('retail registration is store scoped and never creates a commercial party', function (): void {
    $registration = app(StoreCustomerRegistration::class);
    $first = $registration->register($this->branch, 'سامي', '٠٥٩٨ ١٢٣ ٤٥٦', 'First-password');
    $second = $registration->register($this->otherBranch, 'سامي', '0598123456', 'Second-password');
    expect($first->id)->not->toBe($second->id)->and($first->phone)->toBe($second->phone)
        ->and(Hash::check('First-password', $first->password))->toBeTrue()
        ->and(CustomerAccount::query()->count())->toBe(0)->and(Party::withoutGlobalScopes()->count())->toBe(0);
    expect(fn () => $registration->register($this->branch, 'آخر', '0598123456', 'Secret-password'))->toThrow(ValidationException::class);
    expect(fn () => $registration->register($this->branch, 'آخر', '-------', 'Secret-password'))->toThrow(ValidationException::class);
});

test('a customer must sign into each store separately and can share the account across its branches', function (): void {
    StoreCustomer::factory()->create(['tenant_id' => $this->otherTenant->id, 'phone' => $this->customer->phone, 'password' => 'Different-password']);
    Livewire::test('pages::shop.retail-login', ['shop' => 'first'])->set('phone', '0599 111 222')->set('password', 'Customer-password-123')->call('login')->assertHasNoErrors();
    $this->get(route('shop.retail.account', ['shop' => 'first']))->assertOk();
    $this->get(route('shop.retail.account', ['shop' => 'second']))->assertRedirect(route('shop.login', ['shop' => 'second']));
    $this->get(route('shop.login', ['shop' => 'second']))->assertOk();
    Livewire::test('pages::shop.retail-login', ['shop' => 'second'])->set('phone', $this->customer->phone)->set('password', 'Customer-password-123')->call('login')->assertHasErrors('phone');
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الثاني', 'domain' => 'first-branch']);
    $this->get(route('shop.retail.account', ['shop' => $branch->domain]))->assertOk();
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->get(route('shop.account', ['shop' => 'first']))->assertRedirect(route('shop.wholesale.login', ['shop' => 'first']));
});

test('commercial credentials do not authenticate a retail account with the same phone', function (): void {
    $party = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'جملة', 'type' => 'customer']);
    CustomerAccount::query()->create(['tenant_id' => $this->tenant->id, 'party_id' => $party->id, 'phone' => $this->customer->phone, 'password' => 'Wholesale-password', 'price_list' => 'wholesale']);
    Livewire::test('pages::shop.retail-login', ['shop' => 'first'])->set('phone', $this->customer->phone)->set('password', 'Wholesale-password')->call('login')->assertHasErrors('phone');
    Livewire::test('pages::shop.login', ['shop' => 'first'])->set('phone', $this->customer->phone)->set('password', 'Customer-password-123')->call('login')->assertHasErrors('phone');
    expect(Auth::guard('store_customer')->check())->toBeFalse()->and(Auth::guard('customer')->check())->toBeFalse();
});

test('guests keep the cart through login and orders snapshot delivery data and product names', function (): void {
    Livewire::test('pages::shop.index', ['shop' => 'first'])->call('add', $this->product->id)->call('placeOrder')->assertRedirect(route('shop.login', ['shop' => 'first']));
    expect(CustomerOrder::query()->count())->toBe(0)->and(session('shop_cart.'.$this->branch->id))->toHaveKey($this->product->id);
    $address = app(StoreCustomerAddresses::class)->save($this->customer, $this->addressInput);
    Auth::guard('store_customer')->login($this->customer);
    Livewire::test('pages::shop.index', ['shop' => 'first'])->assertSet('addressId', $address->id)->assertSet('address', 'الخليل — شارع السلام')->call('placeOrder')->assertHasNoErrors();
    $order = CustomerOrder::query()->sole();
    expect($order->store_customer_id)->toBe($this->customer->id)->and($order->party_id)->toBeNull()
        ->and($order->customer_account_id)->toBeNull()->and((float) $order->total)->toBe(30.0)->and($order->price_list)->toBe('retail');
    $address->update(['city' => 'مدينة أخرى']);
    $this->product->update(['name' => 'اسم جديد']);
    expect($order->fresh()->customer_address)->toBe('الخليل — شارع السلام');
    $this->get(route('shop.track', ['shop' => 'first', 'token' => $order->tracking_token]))->assertOk()->assertSee('اسم المنتج الأصلي')->assertDontSee('اسم جديد');
});

test('order history and tracking are isolated by both tenant and customer', function (): void {
    $mine = ($this->makeOrder)($this->customer, $this->branch, 'MY-ORDER');
    $other = StoreCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
    $theirs = ($this->makeOrder)($other, $this->branch, 'OTHER-ORDER');
    $foreign = StoreCustomer::factory()->create(['tenant_id' => $this->otherTenant->id, 'phone' => $this->customer->phone]);
    ($this->makeOrder)($foreign, $this->otherBranch, 'FOREIGN-ORDER');
    Auth::guard('store_customer')->login($this->customer);
    Livewire::test('pages::shop.retail-account', ['shop' => 'first'])->assertSee('MY-ORDER')->assertDontSee('OTHER-ORDER')->assertDontSee('FOREIGN-ORDER')->assertDontSee('كشف الحساب');
    $this->get(route('shop.track', ['shop' => 'first', 'token' => $theirs->tracking_token]))->assertNotFound();
    $this->get(route('shop.track', ['shop' => 'second', 'token' => $mine->tracking_token]))->assertNotFound();
    Auth::guard('store_customer')->logout();
    $this->get(route('shop.track', ['shop' => 'first', 'token' => $mine->tracking_token]))->assertNotFound();
});

test('address management supports editing deleting and a single default address', function (): void {
    Auth::guard('store_customer')->login($this->customer);
    $page = Livewire::test('pages::shop.retail-account', ['shop' => 'first'])->set('addressForm', $this->addressInput)->call('saveAddress')->assertHasNoErrors();
    $home = $this->customer->addresses()->sole();
    $page->set('addressForm', array_replace($this->addressInput, ['label' => 'العمل', 'address_line' => 'المكتب']))->call('saveAddress')->assertHasNoErrors();
    $work = $this->customer->addresses()->where('label', 'العمل')->sole();
    expect($home->fresh()->is_default)->toBeFalse()->and($work->is_default)->toBeTrue();
    $page->call('editAddress', $home->id)->set('addressForm.city', 'رام الله')->call('saveAddress')->assertHasNoErrors();
    expect($home->fresh()->city)->toBe('رام الله');
    $page->call('deleteAddress', $work->id)->assertHasNoErrors();
    expect($home->fresh()->is_default)->toBeTrue()->and($this->customer->addresses()->count())->toBe(1);
});

test('address actions and checkout reject another customers address even in the same store', function (): void {
    $other = StoreCustomer::factory()->create(['tenant_id' => $this->tenant->id]);
    $address = app(StoreCustomerAddresses::class)->save($other, $this->addressInput);
    Auth::guard('store_customer')->login($this->customer);
    Livewire::test('pages::shop.retail-account', ['shop' => 'first'])->call('editAddress', $address->id)->assertNotFound();
    Livewire::test('pages::shop.retail-account', ['shop' => 'first'])->call('deleteAddress', $address->id)->assertNotFound();
    Livewire::test('pages::shop.index', ['shop' => 'first'])->call('add', $this->product->id)->set('addressId', $address->id)->call('placeOrder')->assertNotFound();
    expect(CustomerOrder::query()->count())->toBe(0)->and($address->fresh())->not->toBeNull();
});

test('database foreign keys reject cross store address and order ownership', function (): void {
    expect(fn () => StoreCustomerAddress::factory()->create(['tenant_id' => $this->otherTenant->id, 'store_customer_id' => $this->customer->id]))->toThrow(QueryException::class);
    $order = ($this->makeOrder)($this->customer, $this->branch, 'FK-ORDER');
    expect(fn () => DB::table('customer_orders')->where('id', $order->id)->update(['tenant_id' => $this->otherTenant->id]))->toThrow(QueryException::class);
});

test('inactive and expired accounts cannot mutate profiles or place orders', function (): void {
    Auth::guard('store_customer')->login($this->customer);
    $page = Livewire::test('pages::shop.retail-account', ['shop' => 'first'])->set('name', 'غير مسموح');
    Auth::guard('store_customer')->logout();
    $page->call('saveProfile')->assertForbidden();
    $this->customer->forceFill(['is_active' => false])->save();
    Livewire::test('pages::shop.retail-login', ['shop' => 'first'])->set('phone', $this->customer->phone)->set('password', 'Customer-password-123')->call('login')->assertHasErrors('phone');
    Auth::guard('store_customer')->login($this->customer);
    Livewire::test('pages::shop.index', ['shop' => 'first'])->call('add', $this->product->id)->call('placeOrder')->assertRedirect(route('shop.login', ['shop' => 'first']));
    expect(CustomerOrder::query()->count())->toBe(0);
});

test('password changes verify the old password and logout preserves staff sessions', function (): void {
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true]);
    $this->actingAs($staff);
    Auth::guard('store_customer')->login($this->customer);
    $page = Livewire::test('pages::shop.retail-account', ['shop' => 'first'])->set('currentPassword', 'wrong')->set('password', 'New-password-123')->set('password_confirmation', 'New-password-123')->call('changePassword')->assertHasErrors('currentPassword');
    $page->set('currentPassword', 'Customer-password-123')->call('changePassword')->assertHasNoErrors();
    expect(Hash::check('New-password-123', $this->customer->fresh()->password))->toBeTrue();
    $this->post(route('shop.retail.logout', ['shop' => 'first']))->assertRedirect(route('shop.home', ['shop' => 'first']));
    expect(Auth::guard('store_customer')->check())->toBeFalse()->and(Auth::guard('web')->id())->toBe($staff->id);
});

test('staff can invoice retail orders without creating wholesale accounts', function (): void {
    $request = app(OnlineShop::class)->placeOrder($this->branch, [$this->product->id => 2], ['name' => 'مشتري', 'phone' => $this->customer->phone], storeCustomer: $this->customer);
    $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $owner->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    Livewire::test('pages::tenant.customer-orders.index')->assertSee($request->reference)->call('setStatus', $request->id, 'processing');
    // The invoice needs a customer account picked by staff; the shop login stays separate.
    $buyer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'مشتري المتجر', 'type' => 'customer']);
    Livewire::withQueryParams(['customer_order' => $request->id])->test('pages::tenant.sales.invoices')->assertSet('selectedCustomerId', null)
        ->call('selectCustomer', $buyer->id)->set('paidAmount', 60)->call('saveInvoice')->assertSet('errorMessage', null);
    expect($request->fresh()->status)->toBe('invoiced')->and($request->fresh()->store_customer_id)->toBe($this->customer->id)
        ->and((float) $request->fresh()->order->total)->toBe(60.0)->and(CustomerAccount::query()->count())->toBe(0);
});
