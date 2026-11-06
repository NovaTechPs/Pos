<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function returnsTestUser(Tenant $tenant): User
{
    $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'returns_manager']);
    $permissions = collect(['returns.create', 'returns.view'])->map(fn (string $name): Permission => Permission::query()->create([
        'name' => $name,
        'display_name' => 'Returns',
        'group' => 'Returns',
    ]));
    $role->permissions()->attach($permissions->pluck('id')->all());

    return User::factory()->create(['tenant_id' => $tenant->id, 'role_id' => $role->id, 'type' => 'tenant_user']);
}

test('sales and purchase returns have separate invoice pages', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر صفحات المرتجعات']);
    $user = returnsTestUser($tenant);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);

    $this->get(route('tenant.sales-returns'))
        ->assertOk()
        ->assertSee('فاتورة مردود مبيعات')
        ->assertSee('العميل')
        ->assertSee('الفاتورة جاهزة لإضافة الأصناف')
        ->assertSee('اختر العميل أولاً')
        ->assertSee(route('tenant.sales-returns'))
        ->assertSee(route('tenant.purchase-returns'))
        ->assertSee(__('Sales Returns'))
        ->assertSee(__('Purchase Returns'))
        ->assertDontSee('Return type');

    $this->get(route('tenant.purchase-returns'))
        ->assertOk()
        ->assertSee('فاتورة مردود مشتريات')
        ->assertSee('المورد')
        ->assertSee('الفاتورة جاهزة لإضافة الأصناف')
        ->assertSee('اختر المورد أولاً')
        ->assertDontSee('Return type');
});

test('a delayed party search update keeps the selected customer enabled for item entry', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر اختيار العميل']);
    $user = returnsTestUser($tenant);
    $customer = Party::query()->create(['tenant_id' => $tenant->id, 'name' => 'عميل الاختبار', 'type' => 'customer']);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::test('pages::tenant.returns.index')
        ->set('partySearch', $customer->name)
        ->call('selectParty', $customer->id)
        ->set('partySearch', $customer->name)
        ->assertSet('selectedPartyId', $customer->id)
        ->assertSet('selectedPartyName', $customer->name)
        ->assertDontSee('اختر العميل أولاً لتفعيل إضافة الأصناف.');
});

test('sales return is account-based, uses the customer last sale price, and tracks aggregate return limits', function (string $partyType, float $balance): void {
    $tenant = Tenant::query()->create(['name' => 'متجر مرتجعات المبيعات']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = returnsTestUser($tenant);
    $customer = Party::query()->create(['tenant_id' => $tenant->id, 'name' => 'عميل المرتجع', 'type' => $partyType]);
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'منتج', 'cost_price' => 5]);
    ProductBarcode::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'barcode' => 'RET-SKU-1']);
    $stock = BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'product_id' => $product->id, 'stock_quantity' => 3, 'retail_price' => 15]);
    $olderSale = Order::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'customer_id' => $customer->id, 'customer_name' => $customer->name,
        'invoice_number' => 'SALE-RET-0', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 8,
        'total' => 8, 'total_cost' => 5, 'total_profit' => 3, 'paid_amount' => 8,
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    OrderItem::query()->create(['tenant_id' => $tenant->id, 'order_id' => $olderSale->id, 'product_id' => $product->id,
        'quantity' => 1, 'unit_price' => 8, 'total_price' => 8, 'cost_price' => 5, 'total_cost' => 5, 'discount' => 0]);
    $sale = Order::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'customer_id' => $customer->id, 'customer_name' => $customer->name,
        'invoice_number' => 'SALE-RET-1', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 20,
        'total' => 20, 'total_cost' => 10, 'total_profit' => 10, 'paid_amount' => 20]);
    OrderItem::query()->create(['tenant_id' => $tenant->id, 'order_id' => $sale->id, 'product_id' => $product->id,
        'quantity' => 2, 'unit_price' => 10, 'total_price' => 20, 'cost_price' => 5, 'total_cost' => 10, 'discount' => 0]);

    $this->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    $component = Livewire::actingAs($user)->test('pages::tenant.returns.index')
        ->set('kind', 'sale')->set('selectedPartyId', $customer->id)->set('selectedPartyName', $customer->name)
        ->set('productSearch', 'RET-SKU-1')->call('addFirstProduct')->assertSet('lines.0.price', 10.0)
        ->set('lines.0.quantity', 2)->call('saveReturn')->assertHasNoErrors();

    expect((float) $stock->fresh()->stock_quantity)->toBe(5.0)->and((float) $customer->fresh()->current_balance)->toBe($balance);
    $return = Order::query()->where('type', 'return')->firstOrFail();
    expect((int) $return->customer_id)->toBe($customer->id)
        ->and($return->original_order_id)->toBeNull()
        ->and((float) $return->total)->toBe(-20.0)
        ->and($return->items()->firstOrFail()->original_order_item_id)->toBeNull();

    $component->call('startNewReturn')->call('selectParty', $customer->id)
        ->call('addProduct', $product->id)->set('lines.0.quantity', 2)->call('saveReturn')
        ->assertSee('كمية المرتجع تتجاوز الكمية التي تعامل بها هذا العميل');
})->with([['customer', -20.0], ['supplier', 20.0]]);

test('purchase return is supplier-based, uses last supplier cost, updates stock and statement', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر مرتجعات المشتريات']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = returnsTestUser($tenant);
    $supplier = Party::query()->create(['tenant_id' => $tenant->id, 'name' => 'مورد المرتجع', 'type' => 'supplier', 'current_balance' => 30]);
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'منتج مشتريات', 'cost_price' => 8]);
    $stock = BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'product_id' => $product->id, 'stock_quantity' => 5, 'retail_price' => 15]);
    $olderPurchaseId = DB::table('purchases')->insertGetId(['tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'supplier_id' => $supplier->id, 'reference_number' => 'PUR-RET-0', 'total' => 14, 'paid_amount' => 14,
        'payment_status' => 'paid', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    DB::table('purchase_items')->insert(['purchase_id' => $olderPurchaseId, 'product_id' => $product->id,
        'quantity' => 2, 'unit_cost' => 7, 'subtotal' => 14, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
    $purchaseId = DB::table('purchases')->insertGetId(['tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'supplier_id' => $supplier->id, 'reference_number' => 'PUR-RET-1', 'total' => 50, 'paid_amount' => 20,
        'payment_status' => 'partial', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_items')->insert(['purchase_id' => $purchaseId, 'product_id' => $product->id,
        'quantity' => 5, 'unit_cost' => 10, 'subtotal' => 50, 'created_at' => now(), 'updated_at' => now()]);

    $this->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    Livewire::actingAs($user)->test('pages::tenant.returns.index')
        ->set('kind', 'purchase')->set('selectedPartyId', $supplier->id)->set('selectedPartyName', $supplier->name)
        ->call('addProduct', $product->id)->assertSet('lines.0.price', 10.0)
        ->set('lines.0.quantity', 2)->call('saveReturn')->assertHasNoErrors()->assertSee('تم تسجيل مردود المشتريات');

    expect((float) $stock->fresh()->stock_quantity)->toBe(3.0)
        ->and((float) $supplier->fresh()->current_balance)->toBe(10.0)
        ->and(DB::table('purchase_returns')->value('purchase_id'))->toBeNull()
        ->and(DB::table('purchase_returns')->value('supplier_id'))->toBe($supplier->id)
        ->and(DB::table('purchase_return_items')->value('purchase_item_id'))->toBeNull()
        ->and((float) DB::table('purchase_returns')->value('total'))->toBe(20.0);

    Livewire::actingAs($user)->test('pages::tenant.customers.index')
        ->call('openStatementModal', $supplier->id)
        ->assertSee('مردود مشتريات #PR-');
});

function editableReturnFixture(string $kind): array
{
    $tenant = Tenant::create(['name' => 'Return invoice store']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'Return branch']);
    $user = returnsTestUser($tenant);
    $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'Return account', 'type' => $kind === 'sale' ? 'customer' : 'supplier', 'current_balance' => 200]);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Return product', 'product_number' => 'RET-PRODUCT', 'cost_price' => 5]);
    $stock = BranchProduct::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock_quantity' => 20, 'retail_price' => 10]);
    if ($kind === 'sale') {
        $invoice = Order::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'customer_id' => $party->id, 'invoice_number' => 'ORIGINAL', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 200, 'total' => 200, 'paid_amount' => 0]);
        OrderItem::create(['tenant_id' => $tenant->id, 'order_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 20, 'unit_price' => 10, 'total_price' => 200, 'cost_price' => 5, 'total_cost' => 100, 'discount' => 0]);
    } else {
        $id = DB::table('purchases')->insertGetId(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'supplier_id' => $party->id, 'reference_number' => 'ORIGINAL', 'total' => 200, 'paid_amount' => 0, 'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_items')->insert(['purchase_id' => $id, 'product_id' => $product->id, 'quantity' => 20, 'unit_cost' => 10, 'subtotal' => 200, 'created_at' => now(), 'updated_at' => now()]);
    }
    test()->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    $component = Livewire::test('pages::tenant.returns.index')->set('kind', $kind)->call('selectParty', $party->id)->call('addProduct', $product->id);

    return compact('tenant', 'branch', 'user', 'party', 'product', 'stock', 'component');
}

test('return pages select products before adding chosen quantities and prices', function (string $kind): void {
    extract(editableReturnFixture($kind));

    $component->call('removeLine', 0)
        ->set('productSearch', 'Return product')
        ->assertViewHas('productResults', fn ($results): bool => count($results) === 1 && $results[0]['id'] === $product->id)
        ->call('selectProduct', $product->id)
        ->assertSet('selectedProductId', $product->id)
        ->assertSet('selectedQuantity', 1.0)
        ->assertSet('lines', [])
        ->set('selectedQuantity', 2.5)
        ->set('selectedPrice', 7.25)
        ->call('addSelectedProduct')
        ->assertSet('lines.0.quantity', 2.5)
        ->assertSet('lines.0.price', 7.25)
        ->assertSet('selectedProductId', null);
})->with(['sale', 'purchase']);

test('return invoices support payment discount tax editing printing and deletion without duplicate effects', function (string $kind): void {
    extract(editableReturnFixture($kind));
    $component->set('lines.0.quantity', 4)->set('discountType', 'percentage')->set('discountRate', 10)
        ->set('taxPercent', 20)->set('paidAmount', 6)->call('saveReturn', true)->assertHasNoErrors()
        ->assertSet('showPrint', true)->assertSet('printData.total', 36.0)->assertSet('printData.tax', 6.0)
        ->assertSet('printData.paid', 6.0)->assertSet('printData.balance', 170.0);
    $id = $component->get('currentReturnId');
    $number = $component->get('invoiceNumber');
    expect((float) $stock->fresh()->stock_quantity)->toBe($kind === 'sale' ? 24.0 : 16.0)
        ->and((float) $party->fresh()->current_balance)->toBe(170.0)
        ->and((float) Payment::query()->sole()->amount)->toBe(6.0);
    $component->call('saveReturn')->assertSet('currentReturnId', $id);
    expect((float) $stock->fresh()->stock_quantity)->toBe($kind === 'sale' ? 24.0 : 16.0);
    $component->call('editLoadedInvoice')->set('lines.0.quantity', 2)->set('paidAmount', 3)->call('saveReturn')
        ->assertHasNoErrors()->assertSet('currentReturnId', $id)->assertSet('invoiceNumber', $number);
    expect((float) $stock->fresh()->stock_quantity)->toBe($kind === 'sale' ? 22.0 : 18.0)
        ->and((float) $party->fresh()->current_balance)->toBe(185.0)
        ->and((float) Payment::query()->sole()->amount)->toBe(3.0);
    $component->call('startNewReturn')->set('returnSearch', $number)->call('searchReturn')->assertSet('currentReturnId', $id)
        ->call('printCurrentInvoice')->assertSet('printData.total', 18.0)->assertSet('printData.balance', 185.0)
        ->call('deleteReturn')->assertSet('currentReturnId', null);
    expect((float) $stock->fresh()->stock_quantity)->toBe(20.0)
        ->and((float) $party->fresh()->current_balance)->toBe(200.0)
        ->and(Payment::query()->count())->toBe(0);
})->with(['sale', 'purchase']);

test('return validation and failed stock changes leave saved documents and balances intact', function (string $kind): void {
    extract(editableReturnFixture($kind));
    $component->set('lines.0.quantity', 2)->call('saveReturn')->call('editLoadedInvoice');
    $component->set('lines.0.quantity', 21)->call('saveReturn')->assertSee('كمية المرتجع تتجاوز');
    expect((float) $party->fresh()->current_balance)->toBe(180.0);
    $component->set('lines.0.quantity', 2)->set('paidAmount', 100)->call('saveReturn')->assertSee('الدفعة أو الخصم أكبر');
    $stock->update(['stock_quantity' => 0]);
    if ($kind === 'sale') {
        $component->call('deleteReturn')->assertSee('المخزون غير كافٍ');
    } else {
        $component->set('lines.0.quantity', 3)->set('paidAmount', 0)->call('saveReturn')->assertSee('المخزون غير كافٍ');
    }
    expect((float) $party->fresh()->current_balance)->toBe(180.0)
        ->and((float) $stock->fresh()->stock_quantity)->toBe(0.0);
})->with(['sale', 'purchase']);

test('return search navigation is scoped to tenant and saved values are used for printing', function (string $kind): void {
    extract(editableReturnFixture($kind));
    $component->call('saveReturn');
    $first = $component->get('currentReturnId');
    $component->call('startNewReturn')->call('selectParty', $party->id)->call('addProduct', $product->id)->call('saveReturn');
    $second = $component->get('currentReturnId');
    $component->call('previousInvoice')->assertSet('currentReturnId', $first)->call('nextInvoice')->assertSet('currentReturnId', $second)
        ->call('editLoadedInvoice')->set('lines.0.price', 999)->call('printCurrentInvoice')->assertSet('printData.total', 10.0);
    $other = Tenant::create(['name' => 'Other store']);
    test()->withSession(['active_tenant_id' => $other->id]);
    Livewire::test('pages::tenant.returns.index')->set('kind', $kind)->call('loadReturn', $first)->assertNotFound();
})->with(['sale', 'purchase']);

test('return tax follows the store visibility preference', function (string $kind): void {
    extract(editableReturnFixture($kind));
    TenantSetting::setBool($tenant->id, 'show_tax', false);
    $component->set('taxPercent', 20)->call('saveReturn', true)->assertSet('printData.tax', 0.0)
        ->assertSet('printData.total', 10.0)->assertDontSee('نسبة الضريبة المضمنة');
})->with(['sale', 'purchase']);

test('return PDFs are downloadable and tenant scoped', function (string $kind): void {
    extract(editableReturnFixture($kind));
    $component->set('paidAmount', 3)->call('saveReturn');
    $id = $component->get('currentReturnId');
    $url = route('tenant.returns.pdf', ['kind' => $kind, 'returnId' => $id]);
    $response = $this->get($url)->assertOk()->assertDownload('return-'.$kind.'-'.$id.'.pdf');
    expect($response->streamedContent())->toStartWith('%PDF');
    $component->call('shareInvoicePdf')->assertDispatched('share-return-pdf');
    $other = Tenant::create(['name' => 'Other PDF store']);
    $this->withSession(['active_tenant_id' => $other->id])->get($url)->assertNotFound();
})->with(['sale', 'purchase']);

test('damaged sales returns change account balances without adding saleable stock', function (): void {
    extract(editableReturnFixture('sale'));
    $component->set('lines.0.quantity', 3)->set('lines.0.returned_to_stock', false)->call('saveReturn');
    expect((float) $stock->fresh()->stock_quantity)->toBe(20.0)
        ->and((float) $party->fresh()->current_balance)->toBe(170.0);
    $component->call('editLoadedInvoice')->set('lines.0.returned_to_stock', true)->call('saveReturn');
    expect((float) $stock->fresh()->stock_quantity)->toBe(23.0);
    $component->call('deleteReturn');
    expect((float) $stock->fresh()->stock_quantity)->toBe(20.0)
        ->and((float) $party->fresh()->current_balance)->toBe(200.0);
});

test('linked return payments cannot be independently changed through vouchers', function (string $kind, string $page, string $save): void {
    extract(editableReturnFixture($kind));
    $component->set('paidAmount', 5)->call('saveReturn');
    $payment = Payment::query()->sole();
    $user->update(['is_owner' => true]);
    Livewire::test('pages::tenant.accounting.'.$page)->call('edit', $payment->id)->assertSet('showForm', false)
        ->set('paymentId', $payment->id)->set('amount', 999)->call($save)
        ->set('deleteId', $payment->id)->call('delete');
    expect((float) $payment->fresh()->amount)->toBe(5.0)
        ->and((float) $party->fresh()->current_balance)->toBe(195.0);
})->with([['sale', 'payment', 'savePayment'], ['purchase', 'receipts', 'saveReceipt']]);

test('supplier return refunds appear as debit payments in the supplier statement', function (): void {
    extract(editableReturnFixture('purchase'));
    $component->set('paidAmount', 5)->call('saveReturn');
    $instance = Livewire::test('pages::tenant.customers.index')->instance();
    $transactions = (new ReflectionMethod($instance, 'buildStatementTransactions'))->invoke($instance, $party->fresh());
    $payment = $transactions->firstWhere('id', 'payment-'.Payment::query()->sole()->id);
    expect((float) $payment['debit'])->toBe(5.0)->and((float) $payment['credit'])->toBe(0.0);
});

test('return schema migration can be resumed without overwriting existing invoice values', function (): void {
    extract(editableReturnFixture('purchase'));
    $component->set('discountRate', 2)->set('paidAmount', 3)->call('saveReturn');
    $migration = require database_path('migrations/2026_11_06_165932_add_invoice_fields_to_purchase_returns.php');
    $migration->up();
    $return = DB::table('purchase_returns')->first();
    expect((float) $return->subtotal)->toBe(10.0)->and((float) $return->total)->toBe(8.0)
        ->and((float) $return->paid_amount)->toBe(3.0);
});
