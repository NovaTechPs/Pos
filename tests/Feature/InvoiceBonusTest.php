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
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\MovingAverageCost;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر البونص']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف البونص', 'cost_price' => 4]);
    $this->stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);
    $this->customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون البونص', 'type' => 'customer', 'current_balance' => 0]);
});

test('sales invoice bonus units leave stock and cost the store but are not charged', function (): void {
    $component = Livewire::test('pages::tenant.sales.invoices')
        ->assertSeeHtml('data-entry-bonus')
        ->call('selectCustomer', $this->customer->id)
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 10)
        ->set('selectedBonus', 2)
        ->call('commitRowToCart')
        ->assertSet('selectedBonus', 0.0)
        ->assertSeeHtml('data-bonus-input');

    $key = array_key_first($component->get('cart'));
    $component->assertSet("cart.$key.bonus", 2.0)->call('saveInvoice');

    $item = OrderItem::query()->sole();
    $order = Order::query()->sole();
    expect((float) $item->quantity)->toBe(10.0)
        ->and((float) $item->bonus_quantity)->toBe(2.0)
        ->and((float) $item->total_price)->toBe(100.0)
        ->and((float) $item->total_cost)->toBe(48.0)
        ->and((float) $order->total)->toBe(100.0)
        ->and((float) $order->total_cost)->toBe(48.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(38.0)
        ->and((float) $this->customer->fresh()->current_balance)->toBe(100.0);

    // Editing the invoice gives back the old quantity and bonus before taking the new ones.
    $edit = Livewire::test('pages::tenant.sales.invoices')->call('loadOrder', $order)->call('editLoadedInvoice');
    $editKey = array_key_first($edit->get('cart'));
    $edit->assertSet("cart.$editKey.bonus", 2.0)->call('updateItemBonus', $editKey, 5)->call('saveInvoice');

    expect((float) OrderItem::query()->sole()->bonus_quantity)->toBe(5.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(35.0)
        ->and((float) Order::query()->sole()->total)->toBe(100.0);

    $pdf = view('pages.tenant.pos.partials.invoice-pdf', [
        'order' => Order::query()->with(['items.product', 'branch', 'user'])->sole(),
        'accountBalance' => null,
        'storeName' => 'المتجر',
    ])->render();
    expect($pdf)->toContain('data-pdf-bonus', '5.00');
});

test('a negative bonus is refused', function (): void {
    Livewire::test('pages::tenant.sales.invoices')
        ->call('selectProduct', $this->product->id)
        ->set('selectedBonus', -1)
        ->call('commitRowToCart')
        ->assertSet('cart', [])
        ->assertSet('errorMessage', 'البونص يجب ألا يكون سالباً.');
});

test('purchase bonus units enter stock and spread the line cost over every unit received', function (): void {
    TenantSetting::setBool($this->tenant->id, MovingAverageCost::SETTING, true);
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد البونص', 'type' => 'supplier', 'current_balance' => 0]);
    $this->product->update(['cost_price' => 5]);
    $this->stock->update(['stock_quantity' => 10]);

    $page = Livewire::test('pages::tenant.purchases.invoices')
        ->assertSeeHtml('data-entry-bonus')
        ->set('selectedSupplierId', $supplier->id)
        ->call('selectProduct', $this->product->id)
        ->call('commitRowWithValues', '10', '12', '0', '2')
        ->assertSet('selectedBonus', 0.0);
    $key = array_key_first($page->get('cart'));
    $page->assertSet("cart.$key.bonus", 2.0)->call('saveInvoice')->assertSet('errorMessage', null);

    $item = PurchaseItem::query()->sole();
    $purchase = Purchase::query()->sole();
    // 10 in stock at 5 + 120 paid for 12 received units.
    expect((float) $item->quantity)->toBe(10.0)
        ->and((float) $item->bonus_quantity)->toBe(2.0)
        ->and((float) $purchase->total)->toBe(120.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(22.0)
        ->and((float) $this->product->fresh()->cost_price)->toEqualWithDelta(170 / 22, 0.01);

    $edit = Livewire::test('pages::tenant.purchases.invoices')->call('loadPurchase', $purchase->id)->call('editLoadedInvoice');
    $editKey = array_key_first($edit->get('cart'));
    $edit->assertSet("cart.$editKey.bonus", 2.0)->call('updateItemBonus', $editKey, 0)->call('saveInvoice')->assertSet('errorMessage', null);

    expect((float) $this->stock->fresh()->stock_quantity)->toBe(20.0)
        ->and((float) PurchaseItem::query()->sole()->bonus_quantity)->toBe(0.0)
        ->and((float) $this->product->fresh()->cost_price)->toEqualWithDelta(170 / 20, 0.01);
});

test('sale and purchase returns move their bonus units with stock but not with money', function (string $kind): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'طرف المرتجع', 'type' => $kind === 'sale' ? 'customer' : 'supplier', 'current_balance' => 0]);
    if ($kind === 'sale') {
        $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $party->id, 'invoice_number' => 'BONUS-SALE', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 100, 'total' => 100, 'paid_amount' => 0]);
        OrderItem::create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $this->product->id, 'quantity' => 10, 'bonus_quantity' => 2, 'unit_price' => 10, 'total_price' => 100, 'cost_price' => 4, 'total_cost' => 48, 'discount' => 0]);
    } else {
        $id = DB::table('purchases')->insertGetId(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'supplier_id' => $party->id, 'reference_number' => 'BONUS-PUR', 'total' => 100, 'paid_amount' => 0, 'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_items')->insert(['purchase_id' => $id, 'product_id' => $this->product->id, 'quantity' => 10, 'bonus_quantity' => 2, 'unit_cost' => 10, 'subtotal' => 100, 'created_at' => now(), 'updated_at' => now()]);
    }

    $page = Livewire::test('pages::tenant.returns.index')->set('kind', $kind)->call('selectParty', $party->id)
        ->assertSeeHtml('data-entry-bonus')
        // Bought or sold 10 + 2 bonus: 11 + 2 is more than that.
        ->call('addProduct', $this->product->id, 11, 10, 2)
        ->assertSet('errorMessage', 'الكمية مع البونص أكبر من الكمية المتاحة للإرجاع.')
        ->call('addProduct', $this->product->id, 3, 10, 1)
        ->assertSet('lines.0.bonus', 1.0)
        ->assertSeeHtml('data-bonus-input')
        ->call('saveReturn')
        ->assertHasNoErrors()
        ->assertSet('errorMessage', null);

    $stockDirection = $kind === 'sale' ? 1 : -1;
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(50.0 + $stockDirection * 4)
        ->and((float) $party->fresh()->current_balance)->toBe(-30.0);

    if ($kind === 'sale') {
        $line = OrderItem::query()->where('quantity', '<', 0)->sole();
        expect((float) $line->bonus_quantity)->toBe(-1.0)->and((float) $line->total_price)->toBe(-30.0)->and((float) $line->total_cost)->toBe(-16.0);
    } else {
        expect((float) DB::table('purchase_return_items')->value('bonus_quantity'))->toBe(1.0);
    }
})->with(['sale', 'purchase']);
