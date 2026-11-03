<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Voucher tenant']);
    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_owner' => true,
    ]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
});

test('saving a payment voucher closes the form and dispatches one success notice', function (): void {
    $supplier = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Voucher supplier',
        'type' => 'supplier',
        'is_active' => true,
    ]);

    Livewire::test('pages::tenant.accounting.payment')
        ->call('openCreate')
        ->call('selectParty', $supplier->id)
        ->set('amount', '40')
        ->call('savePayment')
        ->assertSet('showForm', false)
        ->assertSet('amount', '')
        ->assertDontSee('id="payment-voucher-form"', false)
        ->assertDispatched('toast');

    expect(Payment::query()->where('tenant_id', $this->tenant->id)->where('type', 'payment')->count())->toBe(1);
});

test('saving a receipt voucher closes the form and dispatches one success notice', function (): void {
    $customer = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Voucher customer',
        'type' => 'customer',
        'is_active' => true,
    ]);

    Livewire::test('pages::tenant.accounting.receipts')
        ->call('openCreate')
        ->call('selectParty', $customer->id)
        ->set('amount', '25')
        ->call('saveReceipt')
        ->assertSet('showForm', false)
        ->assertSet('amount', '')
        ->assertDontSee('id="receipt-voucher-form"', false)
        ->assertDispatched('toast');

    expect(Payment::query()->where('tenant_id', $this->tenant->id)->where('type', 'receipt')->count())->toBe(1);
});

test('receipt voucher created from a sales invoice is linked and updates its paid amount', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Voucher branch']);
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Invoice customer', 'type' => 'customer', 'is_active' => true]);
    $order = Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'customer_id' => $customer->id, 'invoice_number' => 'SALE-VOUCHER', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 100, 'total' => 100, 'paid_amount' => 0, 'payment_status' => 'unpaid']);

    Livewire::withQueryParams(['order_id' => $order->id, 'amount' => 25])
        ->test('pages::tenant.accounting.receipts')
        ->assertSet('showForm', true)
        ->assertSet('partyId', $customer->id)
        ->set('amount', '25')
        ->call('saveReceipt')
        ->assertHasNoErrors();

    expect((int) Payment::query()->sole()->order_id)->toBe($order->id)
        ->and((float) $order->fresh()->paid_amount)->toBe(25.0)
        ->and($order->fresh()->payment_status)->toBe('partial');
});

test('payment voucher created from a purchase invoice is linked and updates its paid amount', function (): void {
    $branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Voucher branch']);
    $supplier = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Invoice supplier', 'type' => 'supplier', 'is_active' => true]);
    $purchase = Purchase::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $branch->id, 'supplier_id' => $supplier->id, 'reference_number' => 'PUR-VOUCHER', 'total' => 100, 'paid_amount' => 0, 'payment_status' => 'unpaid']);

    Livewire::withQueryParams(['purchase_id' => $purchase->id, 'amount' => 40])
        ->test('pages::tenant.accounting.payment')
        ->assertSet('showForm', true)
        ->assertSet('partyId', $supplier->id)
        ->set('amount', '40')
        ->call('savePayment')
        ->assertHasNoErrors();

    expect((int) Payment::query()->sole()->purchase_id)->toBe($purchase->id)
        ->and((float) $purchase->fresh()->paid_amount)->toBe(40.0)
        ->and($purchase->fresh()->payment_status)->toBe('partial');
});
