<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الاختبار']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'الصنف', 'cost_price' => 6]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);
});

test('the purchase entry row discount is applied to the added line', function (): void {
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)
        ->call('commitRowWithValues', '5', '6', '3')
        ->assertSet('selectedDiscount', 0.0);

    $key = array_key_first($component->get('cart'));

    $component->assertSet("cart.$key.discount", 3.0)
        ->assertDontSee('السعر بعد الخصم')
        ->assertSee('27.00')
        ->assertSee('إجمالي قبل الخصم')
        ->assertSee('30.00')
        ->assertSee('اختصارات لوحة المفاتيح');
});

test('the purchase entry row discount is capped and rejects negative values', function (): void {
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)
        ->call('commitRowWithValues', '2', '6', '100');

    $key = array_key_first($component->get('cart'));
    $component->assertSet("cart.$key.discount", 12.0);

    Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)
        ->call('commitRowWithValues', '2', '6', '-1')
        ->assertSet('cart', [])
        ->assertSet('errorMessage', 'الخصم يجب ألا يكون سالباً.');
});

test('adding a purchase row without a discount keeps the previous behaviour', function (): void {
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)
        ->call('commitRowWithValues', '2', '6');

    $key = array_key_first($component->get('cart'));
    $component->assertSet("cart.$key.discount", 0.0);
});

test('enter moves from quantity to cost to total before adding the purchase line', function (): void {
    Livewire::test('pages::tenant.purchases.invoices')
        ->assertSeeInOrder([
            'x-ref="quantity"',
            'x-on:keydown.enter.stop.prevent="$refs.purchasePriceInput?.focus(); $refs.purchasePriceInput?.select()"',
            'x-ref="purchasePriceInput"',
            'x-on:keydown.enter.stop.prevent="$refs.purchaseEntryTotalInput?.focus(); $refs.purchaseEntryTotalInput?.select()"',
            'x-ref="purchaseEntryTotalInput"',
            'x-on:keydown.enter.stop.prevent="addCurrentPurchaseRow()"',
        ], false)
        ->assertDontSee('x-ref="purchaseDiscountPercentInput"', false);

    expect(file_get_contents(resource_path('views/pages/tenant/purchases/⚡invoices.blade.php')))->toContain("this.entryRef('quantity')");
});

test('the purchase invoice fits the screen and only the items table scrolls', function (): void {
    Livewire::test('pages::tenant.purchases.invoices')
        ->assertSee('xl:h-[var(--invoice-fit-height,calc(100dvh-4rem))] xl:overflow-y-auto', false)
        ->assertSee('overflow-auto rounded-xl xl:max-h-none xl:min-h-0', false)
        ->assertDontSee('sticky bottom-0 z-20 mt-3', false)
        ->assertSee('focus-within:z-50', false);

    expect(file_get_contents(resource_path('views/pages/tenant/purchases/⚡invoices.blade.php')))->toContain('fitToViewport()')->toContain("setProperty('--invoice-fit-height'")->not->toContain('main.style.height')->toContain('maxHeight: searchMaxHeight');
});

test('purchase search results stay inside the items card', function (): void {
    Livewire::test('pages::tenant.purchases.invoices')
        ->assertSee('xl:min-h-56 xl:flex-1" data-invoice-search-group', false);

    expect(file_get_contents(resource_path('views/pages/tenant/purchases/⚡invoices.blade.php')))
        ->toContain('const bottomLimit = fitsInCard ? cardRect.bottom : window.innerHeight;')
        ->toContain("{ top: searchOffset + 'px' }");
});

test('the purchases items table scrolls to the newest line after adding an item', function (): void {
    expect(file_get_contents(resource_path('views/pages/tenant/purchases/⚡invoices.blade.php')))
        ->toContain('scrollItemsToNewest() {')
        ->toContain("area.scrollTo({ top: area.scrollHeight, behavior: 'smooth' });")
        ->toContain('this.scrollItemsToNewest();');
});

test('the purchase items table shows the item number instead of the internal id', function (): void {
    $this->product->update(['product_number' => 'I00000001']);

    Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)
        ->call('commitRowWithValues', '1', '6')
        ->assertViewHas('cartProductNumbers', [$this->product->id => 'I00000001'])
        ->assertSeeHtml('text-slate-700">I00000001</td>');
});
