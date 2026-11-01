<?php

use App\Models\Branch;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Party selection', 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true, 'is_active' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
});

test('web transaction selectors accept either party classification', function (string $page, string $method, string $property, string $type): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'name' => 'Selected '.$type, 'type' => $type, 'is_active' => true]);
    Livewire::test('pages::tenant.'.$page)->call($method, $party->id)->assertSet($property, $party->id);
    expect($party->fresh()->type)->toBe($type);
})->with([
    ['pos.sales', 'selectCustomer', 'selectedCustomerId', 'supplier'],
    ['wholesale.sales', 'selectCustomer', 'selectedCustomerId', 'supplier'],
    ['sales.invoices', 'selectCustomer', 'selectedCustomerId', 'supplier'],
    ['purchases.invoices', 'selectSupplier', 'selectedSupplierId', 'customer'],
    ['accounting.receipts', 'selectParty', 'partyId', 'supplier'],
    ['accounting.payment', 'selectParty', 'partyId', 'customer'],
    ['returns.index', 'selectParty', 'selectedPartyId', 'supplier'],
]);

test('both API party lists include all active classifications only inside the tenant', function (string $endpoint, string $key): void {
    foreach (['customer', 'supplier', 'both'] as $type) {
        Party::create(['tenant_id' => $this->tenant->id, 'name' => $type, 'type' => $type, 'is_active' => true]);
    }
    Party::create(['tenant_id' => $this->tenant->id, 'name' => 'Inactive', 'type' => 'supplier', 'is_active' => false]);
    $other = Tenant::create(['name' => 'Other', 'is_active' => true]);
    Party::create(['tenant_id' => $other->id, 'name' => 'Foreign', 'type' => 'supplier', 'is_active' => true]);
    $this->getJson('/api/'.$endpoint)->assertOk()->assertJsonCount(3, $key)->assertJsonMissing(['name' => 'Foreign'])->assertJsonMissing(['name' => 'Inactive']);
})->with([['customers', 'customers'], ['suppliers', 'suppliers']]);

test('voucher operations respect the selected account orientation', function (string $type, string $operation, string $idField, float $expected): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => $type, 'type' => $type, 'is_active' => true, 'current_balance' => 100]);
    $this->postJson('/api/vouchers/'.$operation, ['branch_id' => $this->branch->id, $idField => $party->id, 'amount' => 20, 'payment_method' => 'cash'])->assertCreated();
    expect((float) $party->fresh()->current_balance)->toBe($expected)->and($party->fresh()->type)->toBe($type);
})->with([
    ['supplier', 'receipts', 'customer_id', 120.0],
    ['customer', 'payments', 'supplier_id', 120.0],
    ['supplier', 'payments', 'supplier_id', 80.0],
    ['customer', 'receipts', 'customer_id', 80.0],
]);

test('web cross role vouchers restore balances on edit and delete', function (string $page, string $type, string $save): void {
    $party = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'Opposite role', 'type' => $type, 'is_active' => true, 'current_balance' => 100]);
    $component = Livewire::test('pages::tenant.accounting.'.$page)->call('openCreate')->call('selectParty', $party->id)
        ->set('voucherNumber', 'CROSS-1')->set('paymentDate', '2026-11-01')->set('paymentMethod', 'cash')->set('amount', 20)->call($save)->assertHasNoErrors();
    expect((float) $party->fresh()->current_balance)->toBe(120.0);
    $id = Payment::query()->sole()->id;
    $component->call('edit', $id)->set('amount', 30)->call($save)->assertHasNoErrors();
    expect((float) $party->fresh()->current_balance)->toBe(130.0);
    $component->call('confirmDelete', $id)->call('delete');
    expect((float) $party->fresh()->current_balance)->toBe(100.0);
})->with([['receipts', 'supplier', 'saveReceipt'], ['payment', 'customer', 'savePayment']]);
