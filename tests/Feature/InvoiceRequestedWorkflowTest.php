<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الاختبار']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'الصنف', 'cost_price' => 2]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);
});

test('saving a sales or purchase invoice immediately starts a fresh invoice after printing the saved data', function (string $page, string $model, string $idField): void {
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 3)->call('commitRowToCart');
    $number = $component->get('invoiceNumber');
    $component->call('saveAndPrint')->assertHasNoErrors()->assertSet('errorMessage', null)
        ->assertSet($idField, null)->assertSet('cart', [])->assertSet('invoiceEditMode', true)
        ->assertDispatched($page === 'sales' ? 'print-sales-invoice' : 'print-purchase-invoice');
    expect($model::query()->count())->toBe(1)
        ->and($component->get('invoiceNumber'))->not->toBe($number);
    $component->call('saveInvoice');
    expect($model::query()->count())->toBe(1);
})->with([
    ['sales', Order::class, 'currentOrderId'],
    ['purchases', Purchase::class, 'currentPurchaseId'],
]);

test('scanning the printed invoice barcode opens that exact invoice without an open shift', function (): void {
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'POS-000123', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
    Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'POS-0001230', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
    Shift::query()->update(['status' => 'closed']);
    Livewire::test('pages::tenant.pos.sales')->call('scanBarcode', $order->invoice_number)
        ->assertSet('currentInvoiceId', $order->id)->assertSet('invoiceEditMode', false)->assertSet('showMissingBarcodeModal', false);
    Livewire::test('pages::tenant.pos.sales')->set('searchInvoiceQuery', $order->invoice_number)->call('searchInvoice')
        ->assertSet('currentInvoiceId', $order->id);
});

test('delivery notes survive checkout and appear on the printed receipt even for free delivery', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->set('notes', 'ملاحظة الفاتورة')->set('deliveryNotes', 'اتصل قبل الوصول')
        ->assertSee('اتصل قبل الوصول')->set('paid_amount', 10)->call('checkoutAndPrint')
        ->assertDispatched('print-receipt')->assertSet('errorMessage', null);
    $order = Order::query()->sole();
    expect($order->notes)->toContain('ملاحظة الفاتورة', 'ملاحظة التوصيل: اتصل قبل الوصول');
    expect($component->get('receipt')['notes'])->toContain('اتصل قبل الوصول');
    expect(view('pages.tenant.pos.partials.thermal-receipt', ['receipt' => $component->get('receipt')])->render())->toContain('اتصل قبل الوصول');
});

test('product history shows date quantity party and invoice links for both sales and purchases', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون الحركة', 'type' => 'customer']);
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد الحركة', 'type' => 'supplier']);
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'invoice_number' => 'POS-HISTORY', 'type' => 'pos', 'status' => 'completed', 'subtotal' => 30, 'total' => 30, 'paid_amount' => 30]);
    $order->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 10, 'total_price' => 30, 'cost_price' => 2, 'total_cost' => 6]);
    $purchase = Purchase::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $supplier->id, 'reference_number' => 'PUR-HISTORY', 'total' => 20, 'paid_amount' => 20, 'payment_status' => 'paid']);
    $purchase->items()->create(['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 2, 'subtotal' => 20]);
    Livewire::test('product-history')->dispatch('show-product-history', productId: $this->product->id)
        ->assertSet('showHistory', true)->assertSee('زبون الحركة')->assertSee('مورد الحركة')->assertSee('3.00')->assertSee('10.00')
        ->assertSee(route('tenant.pos', ['invoice' => $order->id]), false)->assertSee(route('tenant.purchase-invoices', ['invoice' => $purchase->id]), false);
    $purchase->delete();
    Livewire::test('product-history')->call('openHistory', $this->product->id)->assertDontSee('PUR-HISTORY');
});

test('product history rejects another tenants product', function (): void {
    $otherTenant = Tenant::create(['name' => 'متجر آخر']);
    $product = Product::create(['tenant_id' => $otherTenant->id, 'name' => 'صنف مخفي']);
    Livewire::test('product-history')->call('openHistory', $product->id)->assertNotFound();
});

test('product history excludes invoices from other branches and tenants', function (): void {
    $otherBranch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'فرع آخر']);
    $otherTenant = Tenant::create(['name' => 'متجر آخر']);
    foreach ([$otherBranch, Branch::create(['tenant_id' => $otherTenant->id, 'name' => 'فرع مخفي'])] as $branch) {
        $product = $branch->tenant_id === $this->tenant->id ? $this->product : Product::create(['tenant_id' => $branch->tenant_id, 'name' => 'صنف مخفي']);
        $order = Order::create(['tenant_id' => $branch->tenant_id, 'branch_id' => $branch->id, 'invoice_number' => 'HIDDEN-'.$branch->id, 'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10]);
        $order->items()->create(['tenant_id' => $branch->tenant_id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'cost_price' => 2, 'total_cost' => 2]);
    }
    Livewire::test('product-history')->call('openHistory', $this->product->id)->assertDontSee('HIDDEN-')->assertSee('لا توجد حركة');
});

test('party report shortcut opens the selected customer or supplier statement', function (string $type): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'طرف التقرير', 'type' => $type]);
    Livewire::withQueryParams(['report' => 'statement', 'party_id' => $party->id, 'party_type' => $type])
        ->test('pages::tenant.reports.center')->assertSet('partyId', $party->id)->assertSet('statementPartyType', $type);
    Livewire::withQueryParams([])->test('pages::tenant.'.($type === 'customer' ? 'sales' : 'purchases').'.invoices')
        ->set($type === 'customer' ? 'selectedCustomerId' : 'selectedSupplierId', $party->id)
        ->assertSee(route('tenant.reports', ['report' => 'statement', 'party_id' => $party->id, 'party_type' => $type]));
})->with(['customer', 'supplier']);

test('notes button and dialog share the workspace Alpine state', function (): void {
    $toolbar = file_get_contents(resource_path('views/pages/tenant/pos/partials/toolbar.blade.php'));
    expect($toolbar)->not->toContain('mobileToolsOpen: false, notesOpen: false')
        ->and($toolbar)->toContain('x-on:click="notesOpen = true"', 'x-show="notesOpen"');
});
