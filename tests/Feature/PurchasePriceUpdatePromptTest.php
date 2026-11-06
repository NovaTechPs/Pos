<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر تحديث الأسعار']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الرئيسي']);
    $this->owner = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => 'tenant_user',
        'branch_id' => $this->branch->id,
        'is_owner' => true,
    ]);
    $this->product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'صنف تغيرت تكلفته',
        'cost_price' => 5,
    ]);
    $this->branchProduct = BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'product_id' => $this->product->id,
        'stock_quantity' => 0,
        'retail_price' => 10,
        'wholesale_price' => 8,
    ]);

    $this->actingAs($this->owner)->withSession([
        'active_tenant_id' => $this->tenant->id,
        'active_branch_id' => $this->branch->id,
    ]);
});

function savePurchaseWithChangedCost(int $productId): Testable
{
    return Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $productId)
        ->set('selectedPurchasePrice', 8)
        ->call('commitRowToCart');
}

test('changed purchase cost prompts and can update both product cost and branch retail price', function (): void {
    $component = savePurchaseWithChangedCost($this->product->id)
        ->assertSet('showPriceUpdateModal', true)
        ->assertSee('تحديث أسعار الأصناف؟')
        ->set('priceUpdateSelections.'.$this->product->id.'.retail', true)
        ->set('priceUpdateRetailPrices.'.$this->product->id, 14)
        ->call('applyPurchasePriceUpdates')
        ->assertSet('showPriceUpdateModal', false)
        ->call('saveInvoice');

    expect((float) $this->product->fresh()->cost_price)->toBe(8.0)
        ->and((float) $this->branchProduct->fresh()->retail_price)->toBe(14.0)
        ->and((float) PurchaseItem::query()->sole()->unit_cost)->toBe(8.0)
        ->and(Purchase::query()->count())->toBe(1)
        ->and($component->get('errorMessage'))->toBeNull();
});

test('skipping the price prompt keeps existing product prices intact', function (): void {
    $component = savePurchaseWithChangedCost($this->product->id)
        ->assertSet('showPriceUpdateModal', true)
        ->call('dismissPriceUpdateModal')
        ->assertSet('showPriceUpdateModal', false)
        ->call('saveInvoice');

    expect((float) $this->product->fresh()->cost_price)->toBe(5.0)
        ->and((float) $this->branchProduct->fresh()->retail_price)->toBe(10.0)
        ->and(Purchase::query()->count())->toBe(1)
        ->and($component->get('errorMessage'))->toBeNull();
});

test('changing the cost of an item already in the purchase cart opens the prompt immediately', function (): void {
    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)
        ->call('commitRowToCart');
    $cartKey = array_key_first($component->get('cart'));

    $component->call('updateItemPrice', $cartKey, 7)
        ->assertSet('showPriceUpdateModal', true)
        ->assertSet('priceUpdateCandidates.'.$this->product->id.'.purchase_cost', 7)
        ->assertSee('تحديث أسعار الأصناف؟');

    expect((float) $this->product->fresh()->cost_price)->toBe(5.0)
        ->and(Purchase::query()->count())->toBe(0);
});
