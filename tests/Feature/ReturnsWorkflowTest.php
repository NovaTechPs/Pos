<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Role;
use App\Models\Tenant;
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

test('sales return is account-based, uses the customer last sale price, and tracks aggregate return limits', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر مرتجعات المبيعات']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = returnsTestUser($tenant);
    $customer = Party::query()->create(['tenant_id' => $tenant->id, 'name' => 'عميل المرتجع', 'type' => 'customer']);
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

    expect((float) $stock->fresh()->stock_quantity)->toBe(5.0);
    $return = Order::query()->where('type', 'return')->firstOrFail();
    expect((int) $return->customer_id)->toBe($customer->id)
        ->and($return->original_order_id)->toBeNull()
        ->and((float) $return->total)->toBe(-20.0)
        ->and($return->items()->firstOrFail()->original_order_item_id)->toBeNull();

    $component->call('addProduct', $product->id)->set('lines.0.quantity', 2)->call('saveReturn')
        ->assertSee('كمية المرتجع تتجاوز الكمية التي تعامل بها هذا العميل');
});

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
