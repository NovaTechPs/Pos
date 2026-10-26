<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر الأسعار']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الرئيسي']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => true]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف الأسعار', 'cost_price' => 12]);
    $this->customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الزبون الأول', 'type' => 'customer']);
    $this->otherCustomer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الزبون الثاني', 'type' => 'customer']);
    $this->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
});

function createPriceHistorySale(int $tenantId, int $productId, int $customerId, string $number, float $price, string $type = 'wholesale', string $status = 'completed'): Order
{
    $order = Order::query()->create(['tenant_id' => $tenantId, 'customer_id' => $customerId, 'customer_name' => 'زبون السجل', 'invoice_number' => $number, 'type' => $type, 'status' => $status, 'subtotal' => $price, 'total' => $price]);
    OrderItem::query()->create(['tenant_id' => $tenantId, 'order_id' => $order->id, 'product_id' => $productId, 'quantity' => 1, 'unit_price' => $price, 'total_price' => $price, 'cost_price' => 12, 'total_cost' => 12]);

    return $order;
}

test('invoice price modal separates customer prices and includes purchase costs', function (string $page): void {
    createPriceHistorySale($this->tenant->id, $this->product->id, $this->customer->id, 'FIRST', 21);
    createPriceHistorySale($this->tenant->id, $this->product->id, $this->otherCustomer->id, 'SECOND', 29);
    createPriceHistorySale($this->tenant->id, $this->product->id, $this->customer->id, 'RETURN-HIDDEN', 5, 'return');
    createPriceHistorySale($this->tenant->id, $this->product->id, $this->customer->id, 'CANCEL-HIDDEN', 5, 'wholesale', 'cancelled');
    $deleted = createPriceHistorySale($this->tenant->id, $this->product->id, $this->customer->id, 'DELETED-HIDDEN', 5);
    $deleted->delete();
    $purchase = Purchase::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'reference_number' => 'COST-HISTORY', 'total' => 11]);
    PurchaseItem::query()->create(['purchase_id' => $purchase->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_cost' => 11, 'subtotal' => 11]);

    Livewire::actingAs($this->user)->test($page)->assertSeeLivewire('product-price-history-modal');

    $component = Livewire::actingAs($this->user)->test('product-price-history-modal')
        ->call('openPriceHistory', $this->product->id, $this->customer->id)
        ->assertSet('showPriceHistory', true)
        ->assertSee('أسعار البيع لهذا الزبون')->assertSee('COST-HISTORY')->assertSee('FIRST')->assertSee('SECOND')
        ->assertDontSee('RETURN-HIDDEN')->assertDontSee('CANCEL-HIDDEN')->assertDontSee('DELETED-HIDDEN');

    expect($component->get('productPriceHistory')['customerSales']->pluck('invoice')->all())->toBe(['FIRST']);
    $component->set('priceHistoryCustomerId', $this->otherCustomer->id);
    expect($component->get('productPriceHistory')['customerSales']->pluck('invoice')->all())->toBe(['SECOND']);
    $component->set('priceHistoryCustomerId', null)->assertSee('اختر زبوناً لعرض سجل أسعاره.');
    $component->set('showPriceHistory', false)->assertDontSee('COST-HISTORY');
})->with(['pages::tenant.sales.invoices', 'pages::tenant.purchases.invoices']);

test('price history rejects products belonging to another tenant', function (string $page): void {
    $otherTenant = Tenant::query()->create(['name' => 'متجر آخر']);
    $otherProduct = Product::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'سري']);
    Livewire::actingAs($this->user)->test($page)->assertSeeLivewire('product-price-history-modal');
    Livewire::actingAs($this->user)->test('product-price-history-modal')->dispatch('open-product-price-history', productId: $otherProduct->id)->assertNotFound();
})->with(['pages::tenant.sales.invoices', 'pages::tenant.purchases.invoices']);

test('price history paginates all prices and resets pages when opening another product', function (): void {
    foreach (range(1, 12) as $index) {
        createPriceHistorySale($this->tenant->id, $this->product->id, $this->customer->id, 'PRICE-'.$index, 20 + $index);
    }
    $component = Livewire::actingAs($this->user)->test('product-price-history-modal')
        ->dispatch('open-product-price-history', productId: $this->product->id, customerId: $this->customer->id);
    expect($component->get('productPriceHistory')['sales']->total())->toBe(12);
    $component->call('setPage', 2, 'priceSalesPage');
    expect($component->get('productPriceHistory')['sales']->count())->toBe(2);
    $component->call('openPriceHistory', $this->product->id);
    expect($component->get('productPriceHistory')['sales']->currentPage())->toBe(1);
});

test('sales invoice retains customer search selection and original item entry with isolated price modal', function (): void {
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 10, 'retail_price' => 25]);
    $component = Livewire::actingAs($this->user)->test('pages::tenant.sales.invoices')
        ->assertSeeLivewire('product-price-history-modal')
        ->set('customerSearch', 'الزبون الأول')
        ->assertSee('الزبون الأول')
        ->call('selectCustomer', $this->customer->id)
        ->assertSet('selectedCustomerId', $this->customer->id)
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 2)
        ->call('commitRowToCart')
        ->assertSet('selectedProductId', null)
        ->assertSee('سجل الأسعار والتكلفة')
        ->assertSee('productId: '.$this->product->id.', customerId: '.$this->customer->id, false);
    expect($component->get('cart'))->toHaveCount(1);
    expect(array_values($component->get('cart'))[0])->toMatchArray(['quantity' => 2, 'price' => 25]);
    $component->call('clearCustomer')->assertSet('selectedCustomerId', null)->assertSee('الزبون الثاني');
});

test('price modal displays customer names when invoice name snapshot is empty', function (): void {
    $order = createPriceHistorySale($this->tenant->id, $this->product->id, $this->customer->id, 'EMPTY-NAME', 20);
    $order->update(['customer_name' => null]);
    Livewire::actingAs($this->user)->test('product-price-history-modal')
        ->dispatch('open-product-price-history', productId: $this->product->id, customerId: $this->customer->id)
        ->assertSee('الزبون الأول')->assertSee('EMPTY-NAME');
});

test('purchase invoice shows the inline entry row before selecting a product and adds its entered quantity and cost', function (): void {
    $component = Livewire::actingAs($this->user)->test('pages::tenant.purchases.invoices')
        ->assertSee('wire:model.live="selectedQuantity"', false)
        ->assertSee('wire:model.live="selectedPurchasePrice"', false)
        ->assertSee('+ إضافة')
        ->assertSee('الإجمالي')
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 3)
        ->set('selectedPurchasePrice', 14)
        ->assertSee('42.00')
        ->call('commitRowToCart')
        ->assertSet('selectedProductId', null)
        ->assertSee('سجل الأسعار والتكلفة')
        ->assertSee('wire:model.live="selectedPurchasePrice"', false);
    expect(array_values($component->get('cart'))[0])->toMatchArray(['quantity' => 3, 'price' => 14]);
});

test('invoice offers price history for the selected product before adding it to the cart', function (string $page): void {
    $component = Livewire::actingAs($this->user)->test($page)
        ->assertSee('data-entry-price-history', false)
        ->assertSee('الأسعار F2')
        ->assertSee('Shift+F2')
        ->call('selectProduct', $this->product->id)
        ->assertSet('selectedProductId', $this->product->id)
        ->assertSee('productId: '.$this->product->id.', customerId:', false);
    expect($component->get('cart'))->toBeEmpty();
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NOERROR | LIBXML_NOWARNING);
    $button = (new DOMXPath($dom))->query('//button[@data-entry-price-history]')->item(0);
    expect($button->hasAttribute('disabled'))->toBeFalse();
})->with(['pages::tenant.sales.invoices', 'pages::tenant.purchases.invoices']);
