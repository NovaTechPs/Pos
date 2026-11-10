<?php

use App\Models\Branch;
use App\Models\Party;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

test('purchase invoice summary spans the full entry grid width', function (): void {
    $tenant = Tenant::create(['name' => 'المتجر']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);

    Livewire::test('pages::tenant.purchases.invoices')
        ->assertSee('data-purchase-invoice-summary', false)
        ->assertSee('xl:col-span-12', false);
});

test('purchase summary keeps discounts and payment totals reactive in the compact layout', function (): void {
    $tenant = Tenant::create(['name' => 'المتجر']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);

    Livewire::test('pages::tenant.purchases.invoices')
        ->assertSee('data-purchase-invoice-summary', false)
        ->assertSee('xl:col-span-12', false)
        ->assertSee('wire:model.live.debounce.800ms="paidAmount"', false)
        ->assertSee('إجمالي الفاتورة')
        ->assertSee('الدفعة')
        ->assertSee('الرصيد بعد الدفعة')
        ->assertSee('طريقة الدفع')
        ->assertDontSee('دفع كامل')
        ->assertDontSee('المتبقي للمورد')
        ->set('cart', [['id' => 1, 'name' => 'الصنف', 'quantity' => 2, 'price' => 100, 'discount' => 0]])
        ->set('discountType', 'percentage')
        ->set('discount', 10)
        ->set('paidAmount', 50)
        ->assertViewHas('grandTotal', 180.0)
        ->assertViewHas('remainingTotal', 130.0)
        ->assertViewHas('paymentStatus', 'partial')
        ->set('paidAmount', '')
        ->assertSet('paidAmount', null)
        ->assertViewHas('paidTotal', 0.0)
        ->assertViewHas('remainingTotal', 180.0)
        ->set('paidAmount', null)
        ->assertViewHas('paymentStatus', 'unpaid')
        ->set('paidAmount', 180)
        ->assertViewHas('remainingTotal', 0.0)
        ->assertViewHas('paymentStatus', 'paid')
        ->set('paidAmount', 0)
        ->assertViewHas('remainingTotal', 180.0)
        ->assertViewHas('paymentStatus', 'unpaid');
});

test('balance after payment includes prior account balance without counting the saved purchase twice', function (string $type, float $balance, float $expected): void {
    $tenant = Tenant::create(['name' => 'المتجر']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    $party = Party::create(['tenant_id' => $tenant->id, 'name' => 'الحساب', 'type' => $type, 'current_balance' => $balance]);

    $component = Livewire::test('pages::tenant.purchases.invoices')
        ->set('selectedSupplierId', $party->id)
        ->set('cart', [['id' => 1, 'name' => 'الصنف', 'quantity' => 2, 'price' => 100, 'discount' => 0]])
        ->set('paidAmount', 50)
        ->assertViewHas('remainingTotal', 150.0)
        ->assertViewHas('accountBalanceAfterPayment', $expected);

    $purchase = Purchase::create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'supplier_id' => $party->id,
        'reference_number' => 'BALANCE-1', 'total' => 200, 'paid_amount' => 50, 'payment_status' => 'partial',
    ]);
    $party->update(['current_balance' => $expected]);

    $component->set('currentPurchaseId', $purchase->id)
        ->assertViewHas('accountBalanceAfterPayment', $expected)
        ->set('paidAmount', 200)
        ->assertViewHas('accountBalanceAfterPayment', $balance);

    $otherParty = Party::create(['tenant_id' => $tenant->id, 'name' => 'حساب آخر', 'type' => 'supplier', 'current_balance' => 30]);
    $component->set('selectedSupplierId', $otherParty->id)
        ->set('paidAmount', 50)
        ->assertViewHas('accountBalanceAfterPayment', 180.0)
        ->set('selectedSupplierId', null)
        ->assertViewHas('accountBalanceAfterPayment', 150.0);
})->with([
    ['supplier', 100.0, 250.0],
    ['supplier', -200.0, -50.0],
    ['customer', 100.0, -50.0],
    ['both', 100.0, 250.0],
]);
