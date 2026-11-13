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
        ->assertSee('رقم الصنف')
        ->call('selectInlineProduct', $this->product->id)
        ->assertSee('رقم الصنف')
        ->assertSee('P01');

    expect(collect($component->get('cart'))->first()['product_number'])->toBe('P01');
});

test('barcode linking search finds products when the query skips words between name parts', function (): void {
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'غاز 3 عيون زجاج نوفا', 'product_number' => 'GAS-03']);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'retail_price' => 20, 'stock_quantity' => 5]);

    Livewire::test('pages::tenant.pos.sales')
        ->call('scanBarcode', '999888')
        ->call('chooseExistingProductForBarcode')
        ->set('missingBarcodeSearch', 'غاز نوفا')
        ->assertSee('غاز 3 عيون زجاج نوفا')
        ->assertSee('GAS-03');
});

test('the reported scanner barcode adds its exact product and confirms field handling', function (): void {
    ProductBarcode::create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'barcode' => '6958326545504']);
    $component = Livewire::test('pages::tenant.pos.sales')->call('scanBarcode', '6958326545504')
        ->assertSet('showMissingBarcodeModal', false)->assertSet('barcode', '')
        ->assertDispatched('pos-barcode-handled', barcode: '6958326545504')
        ->assertSee('wire:keydown.enter.prevent.stop="scanBarcode($event.target.value)"', false)
        ->assertSee('إضافة');
    expect(array_column($component->get('cart'), 'id'))->toBe([$this->product->id]);
});

test('unknown barcode opens the choices without changing the current cart and cancel restores scanning', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $cart = $component->get('cart');
    $component->call('scanBarcode', '000123')->assertSet('showMissingBarcodeModal', true)
        ->assertSet('missingBarcode', '000123')->assertSee('الباركود غير موجود')
        ->assertSee('نعم')->assertSee('لا')->assertSee('إلغاء')->assertSet('cart', $cart)
        ->assertSet('showNewProductModal', false)
        ->assertSee('x-trap.inert.noscroll.noautofocus', false)
        ->assertSee('data-pos-dialog-action', false)
        ->assertSee('x-on:keydown.arrow-right=', false)
        ->assertSee('wire:click="chooseExistingProductForBarcode"', false)
        ->assertSee('x-on:keydown.arrow-down=', false)
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

test('a cart item opens its product editor from the POS', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')
        ->call('selectInlineProduct', $this->product->id)
        ->assertSee('wire:click="editCartProduct('.$this->product->id.')"', false)
        ->call('editCartProduct', $this->product->id)
        ->assertSet('showNewProductModal', true)
        ->assertSet('editingProductId', $this->product->id)
        ->assertSeeLivewire('pages::tenant.products.index');

    Livewire::test('pages::tenant.products.index', ['posEmbedded' => true, 'editProductId' => $this->product->id])
        ->assertSet('showModal', true)
        ->assertSet('isEditing', true)
        ->assertSet('name', 'إبريق تجريبي')
        ->assertSet('product_id', $this->product->id);

    $component->dispatch('pos-product-form-closed')
        ->assertSet('showNewProductModal', false)
        ->assertSet('editingProductId', null);
});

test('embedded product form reuses normal validation and saves the new product and barcode', function (): void {
    $component = Livewire::test('pages::tenant.products.index', ['posEmbedded' => true, 'newBarcode' => '000123'])
        ->assertSet('showModal', true)->assertSet('barcodes', ['000123'])
        ->call('save')->assertHasErrors('name')->assertNotDispatched('pos-product-form-closed')
        ->set('name', 'صنف جديد من نقطة البيع')->set('branchPricesInput.'.$this->branch->id.'.retail_price', 10)
        ->call('save')->assertHasNoErrors()->assertSet('showModal', false)
        ->assertDispatched('pos-product-form-closed');
    $record = ProductBarcode::query()->where('barcode', '000123')->sole();
    expect($record->product->name)->toBe('صنف جديد من نقطة البيع');
});

test('closing the embedded form using its close control notifies POS', function (): void {
    Livewire::test('pages::tenant.products.index', ['posEmbedded' => true, 'newBarcode' => '000123'])
        ->set('showModal', false)->assertDispatched('pos-product-form-closed');
});

test('linking a missing barcode lists each product with its number, branch price and remaining stock', function (): void {
    $otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'فرع آخر']);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $otherBranch->id, 'product_id' => $this->product->id, 'retail_price' => 99, 'stock_quantity' => 77]);

    Livewire::test('pages::tenant.pos.sales')
        ->call('scanBarcode', '000999')
        ->call('chooseExistingProductForBarcode')
        ->set('missingBarcodeSearch', 'إبريق')
        ->assertSee('data-pos-barcode-result-details', false)
        ->assertSee('P01')
        ->assertSee('10.00')
        ->assertSee('20.00')
        ->assertSee('الكمية المتبقية')
        ->assertDontSee('99.00')
        ->assertDontSee('77.00');
});

test('the product form offers the phone camera next to each barcode field', function (): void {
    $html = Livewire::test('pages::tenant.products.index')->call('openCreateModal')->call('addBarcodeField')->html();

    expect(substr_count($html, 'x-data="posBarcodeCamera()" data-barcode-camera-field="barcodes.'))->toBe(2)
        ->and(strpos($html, 'data-barcode-camera-field="barcodes.0"'))->toBeLessThan(strpos($html, 'data-barcode-camera-field="barcodes.1"'))
        ->and($html)->toContain('مسح الباركود بكاميرا الموبايل', 'سيُكتب في خانة الباركود تلقائيًا');
});

test('the product form is laid out in two compact columns', function (): void {
    $html = Livewire::test('pages::tenant.products.index')->call('openCreateModal')->html();
    $form = substr($html, strpos($html, 'data-product-form'));

    expect($form)->toContain('lg:grid-cols-5', 'lg:col-span-3', 'lg:col-span-2', 'سعر موحد لكل الفروع', 'كمية البداية', 'F3 للحفظ')
        ->and(strpos($form, 'الباركودات'))->toBeLessThan(strpos($form, 'الأسعار'))
        ->and(strpos($form, 'الأسعار'))->toBeLessThan(strpos($form, 'الصورة الرئيسية'))
        ->and($form)->not->toContain('max-h-[85vh]', 'المعلومات الأساسية');
});
