<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Party;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الاختبار']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'الصنف', 'cost_price' => 2]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);
});

test('the entry row discount is applied to the added sales line and then reset', function (): void {
    $component = Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 3)
        ->set('selectedDiscount', 4.5)
        ->call('commitRowToCart')
        ->assertSet('selectedDiscount', 0.0);

    $key = array_key_first($component->get('cart'));

    $component->assertSet("cart.$key.discount", 4.5)
        ->assertSee('25.50')
        ->assertDontSee('السعر بعد الخصم');
});

test('the entry row discount cannot exceed the line value or be negative', function (): void {
    $component = Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 2)
        ->set('selectedDiscount', 50)
        ->call('commitRowToCart');

    $key = array_key_first($component->get('cart'));
    $component->assertSet("cart.$key.discount", 20.0);

    Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProduct', $this->product->id)
        ->set('selectedDiscount', -1)
        ->call('commitRowToCart')
        ->assertSet('cart', [])
        ->assertSet('errorMessage', 'الخصم يجب ألا يكون سالباً.');
});

test('the sales invoice summary shows quantity, item count, discounts and balance after invoice', function (): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'الزبون', 'type' => 'customer', 'current_balance' => 0]);

    Livewire::test('pages::tenant.sales.invoices')
        ->call('selectCustomer', $party->id)
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 4)
        ->set('selectedDiscount', 5)
        ->call('commitRowToCart')
        ->set('discount', 1)
        ->assertSee('إجمالي الكمية')
        ->assertSee('عدد الأصناف')
        ->assertSee('إجمالي قبل الخصم')
        ->assertSee('40.00')
        ->assertSee('الخصم الإجمالي')
        ->assertSee('6.00')
        ->assertSee('34.00')
        ->assertSee('الرصيد بعد الفاتورة')
        ->assertSee('اختصارات لوحة المفاتيح');
});

test('enter moves from quantity to price to total before adding the sales line', function (): void {
    Livewire::test('pages::tenant.sales.invoices')
        ->assertSeeInOrder([
            'x-ref="quantityInput"',
            'x-on:keydown.enter.prevent.stop="$refs.priceInput?.focus(); $refs.priceInput?.select()"',
            'x-ref="priceInput"',
            'x-on:keydown.enter.prevent.stop="$refs.entryTotalInput?.focus(); $refs.entryTotalInput?.select()"',
            'x-ref="entryTotalInput"',
            'wire:keydown.enter.prevent.stop="commitRowToCart"',
        ], false)
        ->assertDontSee('x-ref="discountPercentInput"', false);

    expect(file_get_contents(resource_path('views/pages/tenant/sales/⚡invoices.blade.php')))->toContain("this.entryRef('quantityInput')");
});

test('the sales invoice fits the screen and only the items table scrolls', function (): void {
    Livewire::test('pages::tenant.sales.invoices')
        ->assertSee('xl:h-[var(--invoice-fit-height,calc(100dvh-4rem))] xl:overflow-y-auto', false)
        ->assertSee('overflow-auto rounded-xl xl:max-h-none xl:min-h-0', false)
        ->assertDontSee('sticky bottom-0 z-20 mt-3', false)
        ->assertSee('focus-within:z-50', false);

    expect(file_get_contents(resource_path('views/pages/tenant/sales/⚡invoices.blade.php')))->toContain('fitToViewport()')->toContain("setProperty('--invoice-fit-height'")->not->toContain('main.style.height')->toContain('maxHeight: searchMaxHeight');
});

test('sales search results stay inside the items card', function (): void {
    Livewire::test('pages::tenant.sales.invoices')
        ->assertSee('xl:min-h-56 xl:flex-1" data-invoice-search-group', false);

    expect(file_get_contents(resource_path('views/pages/tenant/sales/⚡invoices.blade.php')))
        ->toContain('const bottomLimit = fitsInCard ? cardRect.bottom : window.innerHeight;')
        ->toContain("{ top: searchOffset + 'px' }");
});

test('the sales items table scrolls to the newest line after adding an item', function (): void {
    expect(file_get_contents(resource_path('views/pages/tenant/sales/⚡invoices.blade.php')))
        ->toContain('scrollItemsToNewest() {')
        ->toContain("area.scrollTo({ top: area.scrollHeight, behavior: 'smooth' });")
        ->toContain('this.scrollItemsToNewest();');
});

test('the sales items table shows the item number instead of the internal id', function (): void {
    $this->product->update(['product_number' => 'I00000001']);

    Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart')
        ->assertViewHas('cartProductNumbers', [$this->product->id => 'I00000001'])
        ->assertSeeHtml('text-slate-700">'."\n".'                                    I00000001');
});
