<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Every screen that saves a credit sale or a customer payment must leave the stored balance equal
 * to the customer's statement. Each step below is followed by that check.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Audit store', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true, 'is_active' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opening_cash' => 0,
        'status' => 'open', 'opened_at' => now(), 'opened_by' => $this->owner->id]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Audit item', 'cost_price' => 40]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id,
        'retail_price' => 100, 'wholesale_price' => 100, 'stock_quantity' => 50]);
    $this->customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => null, 'name' => 'Credit customer',
        'type' => 'customer', 'is_active' => true, 'opening_balance' => 200, 'current_balance' => 200]);
    $this->second = Party::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => null, 'name' => 'Second customer',
        'type' => 'customer', 'is_active' => true, 'opening_balance' => 0, 'current_balance' => 0]);
});

function expectBalanced(object $context, Party $party, float $expected, string $step): void
{
    $ledger = (new ReportingService($context->owner, null, false))->partyLedgerBalance($party->id);
    $stored = round((float) $party->fresh()->current_balance, 2);
    expect([$step, $stored, $ledger])->toBe([$step, $expected, $expected]);
}

test('POS credit sale, edit and deletion', function (): void {
    $pos = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->call('selectCustomer', $this->customer->id)->set('paid_amount', 30)->call('checkout');
    if ($pos->get('showCustomerPaymentModal')) {
        $pos->call('setCustomerPaymentAmount', 30)->call('confirmCustomerPayment');
    }
    expect($pos->get('errorMessage'))->toBeNull();
    $order = Order::query()->sole();
    expectBalanced($this, $this->customer, 270.0, 'pos sale 100 paid 30');

    $edit = Livewire::test('pages::tenant.sales.invoices')->call('openInvoiceFromSearch', $order->id)->call('editLoadedInvoice');
    $edit->call('updateItemQuantity', array_key_first($edit->get('cart')), 3)->call('saveInvoice')->assertSet('errorMessage', null);
    expectBalanced($this, $this->customer, 470.0, 'pos invoice edited to 300 on the sales invoices page');

    Livewire::test('pages::tenant.pos.sales')->call('loadInvoice', $order->id)->call('deleteInvoice')->assertSet('errorMessage', null);
    expectBalanced($this, $this->customer, 200.0, 'pos invoice deleted');
});

test('sales invoice page credit sale, partial payment, edit and customer change', function (): void {
    Livewire::test('pages::tenant.sales.invoices')->call('selectCustomer', $this->customer->id)
        ->call('selectProduct', $this->product->id)->set('selectedQuantity', 2)->set('selectedPrice', 100)->call('commitRowToCart')
        ->set('paidAmount', 50)->call('saveInvoice')->assertSet('errorMessage', null);
    $order = Order::query()->sole();
    expect((float) $order->total)->toBe(200.0)->and((float) $order->paid_amount)->toBe(50.0);
    expectBalanced($this, $this->customer, 350.0, 'sales invoice 200 paid 50');

    $edit = Livewire::test('pages::tenant.sales.invoices')->call('openInvoiceFromSearch', $order->id)->call('editLoadedInvoice');
    $edit->call('updateItemQuantity', array_key_first($edit->get('cart')), 1)->call('saveInvoice')->assertSet('errorMessage', null);
    expectBalanced($this, $this->customer, 250.0, 'sales invoice edited to 100');

    Livewire::test('pages::tenant.sales.invoices')->call('openInvoiceFromSearch', $order->id)->call('editLoadedInvoice')
        ->call('selectCustomer', $this->second->id)->call('saveInvoice')->assertSet('errorMessage', null);
    expectBalanced($this, $this->customer, 200.0, 'invoice moved away from the first customer');
    expectBalanced($this, $this->second, 50.0, 'invoice moved to the second customer');
});

test('receipt vouchers: standalone, linked to an invoice, edited, moved and deleted', function (): void {
    Livewire::test('pages::tenant.sales.invoices')->call('selectCustomer', $this->customer->id)
        ->call('selectProduct', $this->product->id)->set('selectedQuantity', 1)->set('selectedPrice', 100)->call('commitRowToCart')
        ->set('paidAmount', 0)->call('saveInvoice')->assertSet('errorMessage', null);
    $order = Order::query()->sole();
    expectBalanced($this, $this->customer, 300.0, 'unpaid invoice 100');

    Livewire::test('pages::tenant.accounting.receipts')->call('openCreate')->set('partyId', $this->customer->id)
        ->set('amount', '40')->call('saveReceipt')->assertHasNoErrors();
    expectBalanced($this, $this->customer, 260.0, 'standalone receipt 40');

    Livewire::test('pages::tenant.accounting.receipts')->call('openCreate')->set('partyId', $this->customer->id)->set('orderId', $order->id)
        ->set('amount', '60')->call('saveReceipt')->assertHasNoErrors();
    expect((float) $order->fresh()->paid_amount)->toBe(60.0);
    expectBalanced($this, $this->customer, 200.0, 'receipt 60 linked to the invoice');

    $standalone = Payment::query()->where('amount', 40)->sole();
    Livewire::test('pages::tenant.accounting.receipts')->call('edit', $standalone->id)->set('amount', '25')->call('saveReceipt')->assertHasNoErrors();
    expectBalanced($this, $this->customer, 215.0, 'standalone receipt edited to 25');

    Livewire::test('pages::tenant.accounting.receipts')->call('edit', $standalone->id)->set('partyId', $this->second->id)->call('saveReceipt')->assertHasNoErrors();
    expectBalanced($this, $this->customer, 240.0, 'receipt moved away');
    expectBalanced($this, $this->second, -25.0, 'receipt moved to the second customer');

    Livewire::test('pages::tenant.accounting.receipts')->call('confirmDelete', $standalone->id)->call('delete');
    expectBalanced($this, $this->second, 0.0, 'moved receipt deleted');
});

test('wholesale sale on credit and its receipt', function (): void {
    Livewire::test('pages::tenant.wholesale.sales')->call('selectCustomer', $this->customer->id)->call('addToCart', $this->product->id)
        ->call('updateQuantity', $this->product->id, 3)->set('paidAmount', 100)->call('completeSale')->assertHasNoErrors();
    $order = Order::query()->sole();
    expect((float) $order->total)->toBe(300.0);
    expectBalanced($this, $this->customer, 400.0, 'wholesale 300 paid 100');

    Livewire::test('pages::tenant.wholesale.sales')->call('openReceiptForm')->call('selectReceiptParty', $this->customer->id)
        ->set('receiptAmount', '150')->call('saveReceipt')->assertHasNoErrors();
    expectBalanced($this, $this->customer, 250.0, 'wholesale receipt 150');
});

test('Android API credit sale, edit and deletion', function (): void {
    $payload = ['branch_id' => $this->branch->id, 'subtotal' => 200, 'total_amount' => 200, 'customer_id' => $this->customer->id, 'paid_amount' => 50,
        'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'price' => 100]], 'operation_uuid' => (string) Str::uuid()];
    $sale = $this->postJson('/api/pos/orders', $payload)->assertCreated();
    expectBalanced($this, $this->customer, 350.0, 'api sale 200 paid 50');

    $payload = array_replace($payload, ['operation_uuid' => (string) Str::uuid(), 'invoice_id' => $sale->json('order.id'), 'paid_amount' => 200]);
    $this->postJson('/api/pos/orders', $payload)->assertCreated();
    expectBalanced($this, $this->customer, 200.0, 'api sale edited to fully paid');

    $this->deleteJson('/api/pos/invoices/'.$sale->json('order.id'))->assertSuccessful();
    expectBalanced($this, $this->customer, 200.0, 'api sale deleted');
});

test('sales return for a credit customer: on account, with a refund, and deleted', function (): void {
    Livewire::test('pages::tenant.sales.invoices')->call('selectCustomer', $this->customer->id)
        ->call('selectProduct', $this->product->id)->set('selectedQuantity', 2)->set('selectedPrice', 100)->call('commitRowToCart')
        ->set('paidAmount', 0)->call('saveInvoice')->assertSet('errorMessage', null);
    expectBalanced($this, $this->customer, 400.0, 'credit invoice 200');

    $return = Livewire::test('pages::tenant.returns.index')->call('selectParty', $this->customer->id)
        ->call('selectProduct', $this->product->id)->set('selectedQuantity', 1)->set('selectedPrice', 100)->call('addSelectedProduct')
        ->set('paidAmount', 0)->call('saveReturn')->assertHasNoErrors();
    expectBalanced($this, $this->customer, 300.0, 'return 100 on account');

    $return->call('editLoadedInvoice')->set('paidAmount', 40)->call('saveReturn')->assertHasNoErrors();
    expectBalanced($this, $this->customer, 340.0, 'return edited: 40 refunded in cash');

    $return->call('deleteReturn');
    expectBalanced($this, $this->customer, 400.0, 'return deleted');
});

test('POS invoice edited in the POS screen and moved to another customer', function (): void {
    $pos = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->call('selectCustomer', $this->customer->id)->set('paid_amount', 0)->call('checkout');
    if ($pos->get('showCustomerPaymentModal')) {
        $pos->call('setCustomerPaymentAmount', 0)->call('confirmCustomerPayment');
    }
    $order = Order::query()->sole();
    expectBalanced($this, $this->customer, 300.0, 'pos credit sale 100');

    $pos = Livewire::test('pages::tenant.pos.sales')->call('loadInvoice', $order->id)->call('editLoadedInvoice')
        ->call('selectCustomer', $this->second->id)->call('checkout');
    if ($pos->get('showCustomerPaymentModal')) {
        $pos->call('setCustomerPaymentAmount', 0)->call('confirmCustomerPayment');
    }
    expect($pos->get('errorMessage'))->toBeNull()->and((int) $order->fresh()->customer_id)->toBe($this->second->id);
    expectBalanced($this, $this->customer, 200.0, 'invoice moved away in the POS');
    expectBalanced($this, $this->second, 100.0, 'invoice moved to the second customer in the POS');
});
