<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockVoucher;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\MovingAverageCost;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Average cost shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Second']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->supplier = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'المورد', 'type' => 'supplier']);
    // 10 pieces at 5: 6 here and 4 in the other branch, since the average covers the whole store.
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف المتوسط', 'cost_price' => 5]);
    $this->stock = BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 6, 'retail_price' => 12]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->otherBranch->id, 'product_id' => $this->product->id, 'stock_quantity' => 4, 'retail_price' => 12]);
    $this->cost = fn (): float => (float) $this->product->fresh()->cost_price;
    $this->purchase = fn (float $quantity, float $price) => Livewire::test('pages::tenant.purchases.invoices')
        ->set('selectedSupplierId', $this->supplier->id)
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', $quantity)
        ->set('selectedPurchasePrice', $price)
        ->call('commitRowToCart')
        ->call('saveInvoice')
        ->assertSet('errorMessage', null);
});

test('the average blends received goods with the stock on hand and handles returning more than is left', function (): void {
    $service = app(MovingAverageCost::class);

    $service->apply($this->product, 10, [[10, 8]]);
    expect(($this->cost)())->toBe(6.5);

    // Returning everything leaves no average, so the last cost stays.
    $service->apply($this->product->fresh(), 5, [[-5, 9]]);
    expect(($this->cost)())->toBe(6.5);

    // Negative stock is treated as empty: the new receipt sets the cost.
    $service->apply($this->product->fresh(), -3, [[4, 7]]);
    expect(($this->cost)())->toBe(7.0);
});

test('a purchase updates the cost to the moving average, and editing it recalculates from the stock before it', function (): void {
    ($this->purchase)(10, 8);
    expect(($this->cost)())->toBe(6.5);

    $purchase = Purchase::query()->sole();
    $page = Livewire::test('pages::tenant.purchases.invoices')->call('loadPurchase', $purchase->id)->call('editLoadedInvoice');
    $key = array_key_first($page->get('cart'));
    $page->set("cart.{$key}.quantity", 30)->set("cart.{$key}.price", 9)->call('saveInvoice')->assertSet('errorMessage', null);

    // 10 at 5 + 30 at 9 = 320 for 40 pieces.
    expect(($this->cost)())->toBe(8.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(36.0);
});

test('the price prompt leaves the cost to the moving average and only offers the retail price', function (): void {
    Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)->set('selectedPurchasePrice', 8)->call('commitRowToCart')
        ->assertSet('showPriceUpdateModal', true)
        ->assertSee('data-moving-average-note', false)
        ->assertDontSee('wire:model="priceUpdateSelections.'.$this->product->id.'.cost"', false)
        ->set('priceUpdateSelections.'.$this->product->id.'.cost', true)
        ->call('applyPurchasePriceUpdates');

    expect(($this->cost)())->toBe(5.0);
});

test('a purchase return takes the goods out at their return price', function (): void {
    ($this->purchase)(10, 8);

    Livewire::test('pages::tenant.returns.index')
        ->set('kind', 'purchase')->set('selectedPartyId', $this->supplier->id)->set('selectedPartyName', $this->supplier->name)
        ->call('addProduct', $this->product->id)->set('lines.0.quantity', 5)->set('lines.0.price', 8)
        ->call('saveReturn')->assertHasNoErrors();

    // 20 pieces worth 130, minus 5 at 8 = 90 for 15 pieces.
    expect(($this->cost)())->toBe(6.0);
});

test('stock-in vouchers receive at their unit cost and deleting one reverses it', function (): void {
    Livewire::test('pages::tenant.stock-vouchers.index')
        ->set('type', 'in')
        ->set('lines', [['product_id' => (string) $this->product->id, 'quantity' => 10, 'unit_cost' => 2]])
        ->call('save')->assertHasNoErrors();
    expect(($this->cost)())->toBe(3.5);

    $voucherId = StockVoucher::query()->sole()->id;
    Livewire::test('pages::tenant.stock-vouchers.index')->call('deleteVoucher', $voucherId);
    expect(($this->cost)())->toBe(5.0);
});

test('turning the moving average off keeps the cost as it was', function (): void {
    TenantSetting::setBool($this->tenant->id, MovingAverageCost::SETTING, false);

    ($this->purchase)(10, 8);

    expect(($this->cost)())->toBe(5.0);
});

test('the store settings switch the costing method', function (): void {
    Livewire::test('pages::settings.store')
        ->assertSet('movingAverageCost', true)
        ->assertSee('التكلفة بالمتوسط المرجّح المتحرك')
        ->set('movingAverageCost', false)->call('save')->assertHasNoErrors();

    expect(TenantSetting::query()->where('key', MovingAverageCost::SETTING)->value('value'))->toBeIn(['0', 'false', false, 0]);
});
