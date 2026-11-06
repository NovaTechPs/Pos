<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncOperation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApiShiftLedger;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'المتجر']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'لعبة', 'cost_price' => 4]);
    $this->stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'retail_price' => 10, 'stock_quantity' => 5]);
    $this->shift = Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 100]);
});

test('cash return refunds cash once preserves the sale cart and restocks only usable goods', function (string $condition, string $mode): void {
    config(['branch-sync.mode' => $mode]);
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $cart = $component->get('cart');
    $component->call('openCashReturnModal')->assertSet('showCashReturnModal', true)
        ->set('cashReturnSearch', 'لعبة')->assertSee('لعبة')
        ->call('selectCashReturnProduct', $this->product->id)
        ->set('cashReturnQuantity', 2)->set('cashReturnPrice', 9)
        ->set('cashReturnCondition', $condition)->set('cashReturnReason', 'لم يعد يريده')
        ->call('saveCashReturn')->assertHasNoErrors()->assertSet('showCashReturnModal', false)
        ->assertSet('cart', $cart)->call('saveCashReturn')
        ->set('showCashReturnModal', true)->call('saveCashReturn');

    $order = Order::query()->sole();
    expect($order->type)->toBe('return')
        ->and($order->customer_id)->toBeNull()
        ->and((float) $order->total)->toBe(-18.0)
        ->and((float) $order->paid_amount)->toBe(-18.0)
        ->and((float) $order->total_cost)->toBe($condition === 'resellable' ? -8.0 : 0.0)
        ->and((float) $order->items()->sole()->quantity)->toBe(-2.0)
        ->and((float) Payment::query()->sole()->amount)->toBe(18.0)
        ->and(Payment::query()->sole()->type)->toBe('payment')
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe($condition === 'resellable' ? 7.0 : 5.0)
        ->and(ApiShiftLedger::summary($this->shift)['expected_cash'])->toBe(82.0);
    if ($mode === 'edge') {
        $operation = BranchSyncOperation::where('operation_type', 'sale.upsert')->sole();
        expect($operation->payload['items'][0]['restock'])->toBe($condition === 'resellable');
    }
})->with(['resellable', 'damaged'])->with(['central', 'edge']);

test('cash returns require an open shift and valid amounts', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('openCashReturnModal')
        ->call('selectCashReturnProduct', $this->product->id)
        ->set('cashReturnQuantity', '')->set('cashReturnReason', 'مكسور')
        ->assertSet('cashReturnQuantity', null)->assertSet('showCashReturnModal', true)
        ->call('saveCashReturn')->assertHasErrors('cashReturnQuantity')
        ->set('cashReturnQuantity', 1)->set('cashReturnPrice', -1)
        ->call('saveCashReturn')->assertHasErrors('cashReturnPrice')
        ->set('cashReturnPrice', 10);
    $this->shift->update(['status' => 'closed']);
    $component->call('saveCashReturn')->assertHasErrors('cashReturnQuantity');
    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(5.0);
});

test('cash returns deny employees without return permission', function (): void {
    $employee = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => false]);
    Livewire::actingAs($employee)->test('pages::tenant.pos.sales')->call('openCashReturnModal')->assertForbidden();
    Livewire::actingAs($employee)->test('pages::tenant.pos.sales')->call('saveCashReturn')->assertForbidden();
});

test('cash returns reject a product from another tenant', function (): void {
    $other = Tenant::create(['name' => 'متجر آخر']);
    $product = Product::create(['tenant_id' => $other->id, 'name' => 'صنف آخر']);
    Livewire::test('pages::tenant.pos.sales')->call('openCashReturnModal')
        ->set('cashReturnProductId', $product->id)->set('cashReturnPrice', 10)
        ->set('cashReturnReason', 'مرتجع')->call('saveCashReturn')->assertNotFound();
    expect(Order::count())->toBe(0)->and(Payment::count())->toBe(0);
});

test('editing a cash return updates the same refund and applies only the inventory difference', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('openCashReturnModal')
        ->call('selectCashReturnProduct', $this->product->id)->set('cashReturnQuantity', 2)
        ->set('cashReturnReason', 'لا يريده')->call('saveCashReturn')->assertHasNoErrors();
    $order = Order::query()->sole();
    $voucher = Payment::query()->sole()->voucher_number;

    $component->call('editCashReturn', $order->id)
        ->assertSet('cashReturnOrderId', $order->id)->assertSet('cashReturnQuantity', 2.0)
        ->set('cashReturnQuantity', 3)->set('cashReturnPrice', 8)
        ->call('saveCashReturn')->assertHasNoErrors();
    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1)
        ->and((float) $order->fresh()->total)->toBe(-24.0)
        ->and((float) $order->fresh()->total_cost)->toBe(-12.0)
        ->and((float) Payment::query()->sole()->amount)->toBe(24.0)
        ->and(Payment::query()->sole()->voucher_number)->toBe($voucher)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(8.0)
        ->and(ApiShiftLedger::summary($this->shift)['expected_cash'])->toBe(76.0);

    $component->call('editCashReturn', $order->id)->set('cashReturnQuantity', 1)
        ->set('cashReturnCondition', 'damaged')->call('saveCashReturn')->assertHasNoErrors();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(5.0)
        ->and($order->items()->sole()->returned_to_stock)->toBeFalse()
        ->and((float) $order->fresh()->total_cost)->toBe(0.0)
        ->and(ApiShiftLedger::summary($this->shift)['expected_cash'])->toBe(92.0);

    $component->call('editCashReturn', $order->id)->assertSet('cashReturnCondition', 'damaged')
        ->set('cashReturnCondition', 'resellable')->call('saveCashReturn')->assertHasNoErrors();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(6.0)
        ->and((float) $order->fresh()->total_cost)->toBe(-4.0)
        ->and(Payment::count())->toBe(1);
});

test('editing returns is blocked after shift closure and stock failures roll back the refund', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('openCashReturnModal')
        ->call('selectCashReturnProduct', $this->product->id)->set('cashReturnQuantity', 2)
        ->set('cashReturnReason', 'مرتجع')->call('saveCashReturn');
    $order = Order::query()->sole();
    $this->stock->update(['stock_quantity' => 0]);
    $component->call('editCashReturn', $order->id)->set('cashReturnCondition', 'damaged')
        ->call('saveCashReturn')->assertHasErrors('cashReturnQuantity');
    expect($order->items()->sole()->returned_to_stock)->toBeTrue()
        ->and((float) Payment::query()->sole()->amount)->toBe(20.0);
    $this->shift->update(['status' => 'closed']);
    $component->call('saveCashReturn')->assertHasErrors('cashReturnQuantity');
    Livewire::test('pages::tenant.pos.sales')->call('editCashReturn', $order->id)
        ->assertSet('showCashReturnModal', false)->assertSee('شيفتك المفتوح');
    expect((float) $order->fresh()->total)->toBe(-20.0);
});

test('POS return mode uses red cart lines and saves multiple mixed-condition items as one editable refund', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('toggleReturnMode')
        ->assertSet('isReturnMode', true)->assertSet('showCashReturnModal', false)
        ->call('selectInlineProduct', $this->product->id)
        ->call('selectInlineProduct', $this->product->id)
        ->assertSee('data-pos-return-line', false);
    $keys = array_keys($component->get('cart'));
    expect($keys)->toHaveCount(2);
    $component->call('updateCartField', $keys[0], 'quantity', 2)
        ->set('cart.'.$keys[1].'.returned_to_stock', false)
        ->call('checkoutAndPrint')->assertHasNoErrors()->assertDispatched('print-receipt');

    $order = Order::query()->sole();
    expect($order->type)->toBe('return')->and($order->items()->count())->toBe(2)
        ->and((float) $order->total)->toBe(-30.0)
        ->and((float) Payment::query()->sole()->amount)->toBe(30.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(7.0)
        ->and(ApiShiftLedger::summary($this->shift)['expected_cash'])->toBe(70.0);

    $component->call('editLoadedInvoice')->assertSet('invoiceEditMode', true)
        ->assertSet('showCashReturnModal', false)->assertSet('cashReturnOrderId', $order->id);
    $cart = $component->get('cart');
    expect(array_values($cart)[1]['returned_to_stock'])->toBeFalse();
    $component->call('updateCartField', array_key_first($cart), 'quantity', 3)
        ->call('checkout')->assertHasNoErrors();
    expect(Order::count())->toBe(1)->and(Payment::count())->toBe(1)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(8.0)
        ->and((float) Payment::query()->sole()->amount)->toBe(40.0);
});

test('switching between sale and return modes keeps the cart and normalizes quantity signs', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $key = array_key_first($component->get('cart'));
    $component->call('toggleReturnMode')->assertSet('cart.'.$key.'.quantity', -1.0)
        ->call('toggleReturnMode')->assertSet('cart.'.$key.'.quantity', 1.0)->assertSet('isReturnMode', false);
    expect(Order::count())->toBe(0);
});

test('return quantities display as positive while refund accounting stays negative', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('toggleReturnMode')
        ->call('selectInlineProduct', $this->product->id);
    $key = array_key_first($component->get('cart'));
    $assertQuantity = function (float $expected) use ($component): void {
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $inputs = (new DOMXPath($dom))->query('//input[@data-pos-field="quantity"]');
        expect($inputs->length)->toBe(1)
            ->and((float) $inputs->item(0)->getAttribute('value'))->toBe($expected)
            ->and($inputs->item(0)->getAttribute('min'))->toBe('0.01');
    };
    $assertQuantity(1.0);
    $component->call('updateCartField', $key, 'quantity', 44)
        ->assertSet('cart.'.$key.'.quantity', -44.0);
    $assertQuantity(44.0);
    $component->call('checkout')->assertHasNoErrors();
    $assertQuantity(44.0);
    expect((float) Order::query()->sole()->total)->toBe(-440.0)
        ->and((float) Payment::query()->sole()->amount)->toBe(440.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(49.0);
    $component->call('editLoadedInvoice');
    $assertQuantity(44.0);
});
