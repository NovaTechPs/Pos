<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

test('vehicle wholesale invoice details calculate party payments through party_id', function (): void {
    $tenant = Tenant::create(['name' => 'مبيعات السيارة']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'زبون السيارة', 'type' => 'customer', 'opening_balance' => 10]);
    $order = Order::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'customer_id' => $party->id,
        'invoice_number' => 'VAN-100',
        'type' => 'wholesale',
        'status' => 'completed',
        'subtotal' => 100,
        'total' => 100,
        'paid_amount' => 0,
    ]);
    Payment::create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'party_id' => $party->id,
        'type' => 'receipt',
        'voucher_number' => 'REC-100',
        'amount' => 25,
        'payment_method' => 'cash',
        'payment_date' => now(),
    ]);

    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::test('pages::tenant.wholesale.invoices')
        ->call('viewOrder', $order->id)
        ->assertSet('showDetailModal', true)
        ->assertSet('selectedCustomerBalance.receipts', 25.0)
        ->assertSet('selectedCustomerBalance.balance', 85.0);

    Livewire::test('pages::tenant.customers.index')
        ->call('openStatementModal', $party->id)
        ->call('openStatementDocument', 'order', $order->id)
        ->assertDispatched('open-statement-document', url: route('tenant.wholesale-sales', ['invoice' => $order->id, 'customer_copy' => 1]));
});
