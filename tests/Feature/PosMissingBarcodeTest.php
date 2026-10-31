<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر الاختبار']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الرئيسي']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $this->user->id, 'opening_cash' => 0]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'إبريق تجريبي', 'product_number' => 'P01', 'cost_price' => 4]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'retail_price' => 10, 'stock_quantity' => 20]);
});

test('POS displays product numbers and supports searching by number', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')
        ->set('inlineSearchQuery', 'P01')
        ->assertSet('inlineSearchResults.0.product_number', 'P01')
        ->set('productSearchQuery', 'P01')
        ->call('loadQuickProducts')
        ->assertSet('quickProducts.0.product_number', 'P01')
        ->set('showProductsModal', true)
        ->assertSee('رقم المنتج: P01')
        ->call('selectInlineProduct', $this->product->id)
        ->assertSee('رقم المنتج')
        ->assertSee('P01');

    expect(collect($component->get('cart'))->first()['product_number'])->toBe('P01');
});

test('unknown barcode opens the choices without changing the current cart and cancel restores scanning', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $cart = $component->get('cart');
    $component->call('scanBarcode', '000123')->assertSet('showMissingBarcodeModal', true)
        ->assertSet('missingBarcode', '000123')->assertSee('الباركود غير موجود')
        ->assertSee('نعم')->assertSee('لا')->assertSee('إلغاء')->assertSet('cart', $cart)
        ->assertSet('showNewProductModal', false)
        ->assertSee('x-trap.inert.noscroll.noautofocus', false)
        ->assertSee('x-on:keydown.enter.self.prevent.stop', false)
        ->assertSee('wire:click="createProductFromMissingBarcode"', false)
        ->call('scanBarcode', 'OTHER')->assertSet('missingBarcode', '000123')
        ->call('cancelMissingBarcode')->assertSet('showMissingBarcodeModal', false)
        ->assertSet('missingBarcode', '')->assertSet('cart', $cart)->assertDispatched('pos-focus-barcode');
    expect(ProductBarcode::query()->count())->toBe(0);
});

test('unknown barcode can be linked to an existing product then scanned normally', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('scanBarcode', '000123')
        ->call('chooseExistingProductForBarcode')->set('missingBarcodeSearch', 'إبريق')
        ->assertSee('إبريق تجريبي')->assertSee('رقم الصنف:')->assertSee('P01')
        ->assertSee('aria-label="مسح بحث الأصناف"', false)
        ->assertSee('data-pos-barcode-result', false)->assertSee('اختر الصنف من النتائج لإضافة الباركود إليه.')
        ->call('attachMissingBarcode', $this->product->id)
        ->assertHasNoErrors()->assertSet('showMissingBarcodeModal', false)->assertSet('cart', []);
    expect(ProductBarcode::query()->sole()->product_id)->toBe($this->product->id);
    $component->call('scanBarcode', '000123')->assertSet('showMissingBarcodeModal', false);
    expect($component->get('cart'))->toHaveCount(1);
});

test('a barcode that was claimed meanwhile cannot be reassigned to another product', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('scanBarcode', '000123')->call('chooseExistingProductForBarcode');
    $other = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف آخر', 'cost_price' => 1]);
    ProductBarcode::query()->create(['tenant_id' => $this->tenant->id, 'product_id' => $other->id, 'barcode' => '000123']);
    $component->call('attachMissingBarcode', $this->product->id)->assertHasErrors('missingBarcode');
    expect(ProductBarcode::query()->sole()->product_id)->toBe($other->id);
});

test('linking rejects a product belonging to another tenant', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر آخر']);
    $other = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'صنف خاص', 'cost_price' => 1]);
    Livewire::test('pages::tenant.pos.sales')->call('scanBarcode', '000123')->call('chooseExistingProductForBarcode')
        ->call('attachMissingBarcode', $other->id)->assertNotFound();
    expect(ProductBarcode::query()->count())->toBe(0);
});

test('cashier without barcode permission cannot link products', function (): void {
    $this->user->update(['is_owner' => false]);
    Livewire::test('pages::tenant.pos.sales')->call('scanBarcode', '000123')
        ->assertSee('تحتاج صلاحية إدارة الباركود')->call('chooseExistingProductForBarcode')->assertForbidden();
    Livewire::test('pages::tenant.pos.sales')->call('scanBarcode', '000123')->set('linkMissingBarcode', true)
        ->call('attachMissingBarcode', $this->product->id)->assertForbidden();
    expect(ProductBarcode::query()->count())->toBe(0);
});

test('new product form opens inside POS with the barcode and leaves the cart unchanged', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $cart = $component->get('cart');
    $component->call('scanBarcode', '000123')->call('createProductFromMissingBarcode')
        ->assertSet('showNewProductModal', true)->assertSet('showMissingBarcodeModal', false)
        ->assertSet('cart', $cart)->assertSeeLivewire('pages::tenant.products.index')
        ->assertSee('إضافة منتج جديد')->assertDontSee('new_barcode=000123', false)
        ->dispatch('pos-product-form-closed')->assertSet('showNewProductModal', false)
        ->assertSet('cart', $cart)->assertDispatched('pos-focus-barcode');
    expect(Product::query()->count())->toBe(1);
});

test('embedded product form reuses normal validation and saves the new product and barcode', function (): void {
    $component = Livewire::test('pages::tenant.products.index', ['posEmbedded' => true, 'newBarcode' => '000123'])
        ->assertSet('showModal', true)->assertSet('barcodes', ['000123'])
        ->call('save')->assertHasErrors('name')->assertNotDispatched('pos-product-form-closed')
        ->set('name', 'صنف جديد من نقطة البيع')->set('retail_price', 10)->set('wholesale_price', 8)
        ->call('save')->assertHasNoErrors()->assertSet('showModal', false)
        ->assertDispatched('pos-product-form-closed');
    $record = ProductBarcode::query()->where('barcode', '000123')->sole();
    expect($record->product->name)->toBe('صنف جديد من نقطة البيع');
});

test('closing the embedded form using its close control notifies POS', function (): void {
    Livewire::test('pages::tenant.products.index', ['posEmbedded' => true, 'newBarcode' => '000123'])
        ->set('showModal', false)->assertDispatched('pos-product-form-closed');
});
