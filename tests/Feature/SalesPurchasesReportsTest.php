<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('analytics report includes sales purchases customers suppliers and low stock for the selected tenant', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر التقارير']);
    $branch = Branch::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'الفرع الرئيسي',
    ]);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'tenant_user',
        'is_owner' => true,
    ]);
    $customer = Party::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'عميل التقرير',
        'type' => 'customer',
        'current_balance' => 70,
    ]);
    $supplier = Party::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'مورد التقرير',
        'type' => 'supplier',
        'current_balance' => 30,
    ]);
    $product = Product::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'صنف منخفض المخزون',
        'cost_price' => 15,
    ]);

    BranchProduct::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_quantity' => 2,
        'alert_quantity' => 5,
        'retail_price' => 30,
    ]);

    $order = Order::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'invoice_number' => 'RPT-SALE-1',
        'type' => 'pos',
        'status' => 'completed',
        'subtotal' => 90,
        'tax_amount' => 0,
        'discount' => 0,
        'total' => 90,
        'paid_amount' => 30,
        'total_cost' => 45,
        'total_profit' => 45,
    ]);

    OrderItem::query()->create([
        'tenant_id' => $tenant->id,
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'unit_price' => 30,
        'total_price' => 90,
        'cost_price' => 15,
        'total_cost' => 45,
        'discount' => 0,
    ]);

    DB::table('purchases')->insert([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'reference_number' => 'RPT-PUR-1',
        'total' => 40,
        'paid_amount' => 10,
        'payment_status' => 'partial',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->withSession([
        'active_tenant_id' => $tenant->id,
        'active_branch_id' => $branch->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::tenant.reports.analytics')
        ->assertSee('عميل التقرير')
        ->assertSee('مورد التقرير')
        ->assertSee('صنف منخفض المخزون')
        ->assertSee('تقرير العملاء')
        ->assertSee('تقرير الموردين')
        ->assertSee('إجمالي المشتريات');
});

test('supplier statement includes purchase invoices and payments with matching balance direction', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر كشف المورد']);
    $branch = Branch::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'الفرع الرئيسي',
    ]);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'tenant_user',
        'is_owner' => true,
    ]);
    $supplier = Party::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'مورد كشف الحساب',
        'type' => 'supplier',
        'current_balance' => 50,
    ]);

    DB::table('purchases')->insert([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'reference_number' => 'PUR-STATEMENT-1',
        'total' => 100,
        'paid_amount' => 20,
        'payment_status' => 'partial',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    Payment::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'created_by' => $user->id,
        'party_id' => $supplier->id,
        'type' => 'payment',
        'voucher_number' => 'PAY-STATEMENT-1',
        'amount' => 30,
        'payment_method' => 'cash',
        'payment_date' => now(),
    ]);

    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::actingAs($user)
        ->test('pages::tenant.customers.index')
        ->call('openStatementModal', $supplier->id)
        ->assertSee('فاتورة مشتريات #PUR-STATEMENT-1')
        ->assertSee('سداد للمورد')
        ->assertSee('50.00');
});

test('editing purchase invoice cannot erase stock already consumed', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر تعديل المشتريات']);
    $branch = Branch::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'الفرع الرئيسي',
    ]);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'tenant_user',
        'is_owner' => true,
    ]);
    $product = Product::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'صنف مستهلك',
        'cost_price' => 10,
    ]);
    $supplier = Party::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'المورد',
        'type' => 'supplier',
    ]);
    $branchProduct = BranchProduct::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_quantity' => 0,
        'alert_quantity' => 5,
        'retail_price' => 20,
    ]);
    $purchaseId = DB::table('purchases')->insertGetId([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'supplier_id' => $supplier->id,
        'reference_number' => 'PUR-USED-STOCK',
        'total' => 50,
        'paid_amount' => 0,
        'payment_status' => 'unpaid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('purchase_items')->insert([
        'purchase_id' => $purchaseId,
        'product_id' => $product->id,
        'quantity' => 5,
        'unit_cost' => 10,
        'subtotal' => 50,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->withSession([
        'active_tenant_id' => $tenant->id,
        'active_branch_id' => $branch->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::tenant.purchases.invoices')
        ->call('loadPurchase', $purchaseId)
        ->call('saveInvoice')
        ->assertSet('errorMessage', 'لا يمكن تعديل فاتورة الشراء لأن جزءًا من كمياتها استُهلك أو أُخرج من المخزون. سجّل حركة تصحيح للمخزون أولًا.');

    expect((float) $branchProduct->fresh()->stock_quantity)->toBe(0.0)
        ->and((float) DB::table('purchases')->where('id', $purchaseId)->value('total'))->toBe(50.0)
        ->and(DB::table('purchase_items')->where('purchase_id', $purchaseId)->count())->toBe(1);
});

test('invoice source and sale kind remain independent and reports filter both', function (): void {
    $tenant = Tenant::create(['name' => 'تقارير المصادر']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'فرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    foreach ([['SRC-POS', 'pos', 'retail'], ['SRC-WEB', 'web', 'wholesale']] as [$number, $source, $kind]) {
        Order::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'invoice_number' => $number,
            'type' => 'wholesale', 'source' => $source, 'sale_kind' => $kind, 'status' => 'completed',
            'subtotal' => 100, 'total' => 100, 'paid_amount' => 0, 'payment_status' => 'unpaid']);
    }
    Livewire::test('pages::tenant.reports.analytics')->set('invoiceSource', 'pos')->set('saleKind', 'retail')
        ->assertSee('SRC-POS')->assertDontSee('SRC-WEB');
    $original = Order::where('invoice_number', 'SRC-WEB')->firstOrFail();
    $return = Order::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'invoice_number' => 'SRC-RETURN', 'type' => 'return', 'original_order_id' => $original->id,
        'subtotal' => -10, 'total' => -10]);
    expect($return->source)->toBe('web')->and($return->sale_kind)->toBe('wholesale');
});
