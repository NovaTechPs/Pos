<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر الفواتير']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->branch = Branch::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'الفرع الرئيسي',
    ]);
    $this->user->update(['branch_id' => $this->branch->id]);
    $this->actingAs($this->user);
    $this->withSession([
        'active_tenant_id' => $this->tenant->id,
        'active_branch_id' => $this->branch->id,
    ]);

    $this->product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_number' => 'ITEM-100',
        'name' => 'صنف تجريبي',
        'cost_price' => 4.25,
    ]);
    BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'stock_quantity' => 12,
        'retail_price' => 8.5,
        'wholesale_price' => 7.5,
    ]);
    ProductBarcode::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->product->id,
        'barcode' => '1234567890123',
    ]);
});

test('invoice product search matches the full product number and its middle', function (string $page, string $search): void {
    $otherTenant = Tenant::query()->create(['name' => 'متجر آخر']);
    Product::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'صنف مخفي', 'product_number' => 'ITEM-100']);
    Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف غير مطابق', 'product_number' => 'OTHER-200']);

    $component = Livewire::test('pages::tenant.'.$page.'.invoices')->set('rowSearch', $search);
    if ($search === 'ITEM-100') {
        // An exact item number, like a full barcode, selects the product straight away.
        $component->assertSet('selectedProductId', $this->product->id);
    } else {
        $component->assertViewHas('productResults', fn ($results): bool => $results->pluck('id')->all() === [$this->product->id]);
    }
})->with([
    ['sales', 'ITEM-100'],
    ['sales', 'EM-10'],
    ['purchases', 'ITEM-100'],
    ['purchases', 'EM-10'],
]);

test('invoice Enter selects a generated item number from its short numeric suffix regardless of I casing', function (string $page, string $action): void {
    $shortNumberProduct = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'product_number' => 'I00000001',
        'name' => 'صنف الرقم المختصر',
        'cost_price' => 6.5,
    ]);
    BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'product_id' => $shortNumberProduct->id,
        'stock_quantity' => 9,
        'retail_price' => 12,
        'wholesale_price' => 10,
    ]);

    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->set('productLookup', '1')
        ->assertSet('selectedProductId', null)
        ->call($action, '1', true)
        ->assertReturned(true)
        ->assertSet('selectedProductId', $shortNumberProduct->id)
        ->assertSet('selectedProductName', 'صنف الرقم المختصر')
        ->assertDispatched('focus-quantity');

    if ($page === 'purchases') {
        expect($component->get('cart'))->toBeEmpty();
    }
})->with([
    ['sales', 'selectProductByBarcode'],
    ['purchases', 'scanBarcodeToCart'],
]);

test('invoice entry separates the product code lookup from the product name search', function (string $page): void {
    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->call('selectProduct', $this->product->id)
        ->assertSet('productLookup', 'ITEM-100')
        ->assertSet('rowSearch', 'صنف تجريبي');

    expect($component->html())
        ->toContain('id="product-code-input"')
        ->toContain('wire:model.live.debounce.120ms="productLookup"')
        ->toContain('id="product-name-input"')
        ->toContain('wire:model.live.debounce.120ms="rowSearch"');
})->with(['sales', 'purchases']);

test('purchase invoice scanner loads barcode details and Enter progression commits the row', function (): void {
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProductByBarcode', '1234567890123')
        ->assertReturned(true)
        ->assertSet('selectedProductId', $this->product->id)
        ->assertSet('selectedProductName', 'صنف تجريبي')
        ->assertSet('selectedPurchasePrice', 4.25)
        ->assertSet('selectedStock', 12.0)
        ->assertDispatched('focus-quantity')
        ->set('selectedQuantity', 3)
        ->set('selectedPurchasePrice', 5.0)
        ->call('commitRowToCart')
        ->assertSet('selectedProductId', null)
        ->assertDispatched('focus-product-search');

    $cart = array_values($component->get('cart'));
    expect($cart)->toHaveCount(1)
        ->and($cart[0]['quantity'])->toBe(3.0)
        ->and($cart[0]['price'])->toBe(5.0);
});

test('a purchase invoice scan ending in Enter moves to the quantity without adding the item', function (): void {
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->call('scanBarcodeToCart', '1234567890123')
        ->assertReturned(true)
        ->assertSet('selectedProductId', $this->product->id)
        ->assertSet('selectedPurchasePrice', 4.25)
        ->assertDispatched('focus-quantity')
        ->assertNotDispatched('focus-product-search');

    expect($component->get('cart'))->toBeEmpty();

    // The live search can select the product before Enter arrives; Enter then only moves on.
    $component->call('scanBarcodeToCart', 'صنف تجريبي')
        ->assertReturned(true)
        ->assertSet('selectedProductId', $this->product->id);
    expect($component->get('cart'))->toBeEmpty();

    $component->call('commitRowWithValues', '2', '4.25')
        ->assertSet('selectedProductId', null)
        ->assertSet('selectedPurchasePrice', 0.0)
        ->assertSet('selectedQuantity', 1.0);
    expect(array_values($component->get('cart'))[0]['quantity'])->toBe(2.0);
});

test('an unknown barcode scanned in a purchase invoice selects nothing', function (): void {
    Livewire::test('pages::tenant.purchases.invoices')
        ->call('scanBarcodeToCart', '0000000000000')
        ->assertReturned(false)
        ->assertSet('selectedProductId', null);
});

test('the purchase entry row guards its fields against a barcode scan', function (): void {
    $this->user->update(['is_owner' => true]);

    $this->get(route('tenant.purchase-invoices'))
        ->assertOk()
        ->assertSee('rejectScanInEntryField(event)', false)
        ->assertSee('$wire.selectedProductId ? Math.max(0', false);
});

test('sales invoice scanner loads barcode details and Enter progression commits the row', function (): void {
    $component = Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProductByBarcode', '1234567890123')
        ->assertReturned(true)
        ->assertSet('selectedProductId', $this->product->id)
        ->assertSet('selectedProductName', 'صنف تجريبي')
        ->assertSet('selectedPrice', 7.5)
        ->assertSet('selectedStock', 12.0)
        ->assertDispatched('focus-quantity')
        ->set('selectedQuantity', 2)
        ->set('selectedPrice', 8.0)
        ->call('commitRowToCart')
        ->assertSet('selectedProductId', null)
        ->assertDispatched('focus-product-search');

    $cart = array_values($component->get('cart'));
    expect($cart)->toHaveCount(1)
        ->and($cart[0]['quantity'])->toBe(2.0)
        ->and($cart[0]['price'])->toBe(8.0);
});

test('sales invoice search becomes available again after selecting a product', function (): void {
    Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProduct', $this->product->id)
        ->assertSet('selectedProductId', $this->product->id)
        ->set('rowSearch', 'بحث جديد')
        ->assertSet('selectedProductId', null)
        ->assertSet('selectedProductName', '');
});

test('barcode lookup does not select products from another tenant', function (): void {
    Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProductByBarcode', 'not-a-barcode')
        ->assertReturned(false)
        ->assertSet('selectedProductId', null);
});

test('loaded sales and purchase invoices stay read-only until edit is explicitly enabled', function (): void {
    $order = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'invoice_number' => 'SAL-READ-ONLY',
        // Employees reopen only invoices they created.
        'created_by' => $this->user->id,
        'type' => 'wholesale',
        'status' => 'completed',
        'subtotal' => 10,
        'total' => 10,
        'paid_amount' => 0,
    ]);
    $purchaseId = DB::table('purchases')->insertGetId([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'reference_number' => 'PUR-READ-ONLY',
        'total' => 10,
        'paid_amount' => 0,
        'payment_status' => 'unpaid',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Livewire::test('pages::tenant.sales.invoices')
        ->call('loadOrder', $order->id)
        ->assertSet('invoiceEditMode', false)
        ->assertSee('تحميل PDF')
        ->call('shareInvoicePdf')
        ->assertDispatched('share-invoice-pdf')
        ->call('editLoadedInvoice')
        ->assertSet('invoiceEditMode', true);

    Livewire::test('pages::tenant.purchases.invoices')
        ->call('loadPurchase', $purchaseId)
        ->assertSet('invoiceEditMode', false)
        ->assertSee('تحميل PDF')
        ->call('shareInvoicePdf')
        ->assertDispatched('share-invoice-pdf')
        ->call('editLoadedInvoice')
        ->assertSet('invoiceEditMode', true);
});
test('example', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
