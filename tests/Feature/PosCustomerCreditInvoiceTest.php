<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncOperation;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

test('customer checkout saves a sales invoice and only its actual payment enters the shift', function (float $paid, string $status, string $mode, string $paymentMethod = 'cash'): void {
    config(['branch-sync.mode' => $mode]);
    $tenant = Tenant::query()->create(['name' => 'متجر الآجل']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $customer = Party::query()->create(['tenant_id' => $tenant->id, 'name' => 'زبون آجل', 'type' => 'customer', 'is_active' => true]);
    $shift = Shift::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'opening_cash' => 50, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $user->id]);
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'صنف الآجل', 'cost_price' => 4]);
    $stock = BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock_quantity' => 5, 'retail_price' => 10]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);

    $component = Livewire::test('pages::tenant.pos.sales')
        ->call('selectInlineProduct', $product->id)
        ->set('payment_method', $paymentMethod)
        ->set('selectedCustomerId', $customer->id)
        ->call('checkout')
        ->assertSet('showCustomerPaymentModal', true)
        ->set('customerPaymentAmount', $paid)
        ->call('confirmCustomerPayment')
        ->assertSet('errorMessage', null);

    $order = Order::query()->sole();
    expect($order->type)->toBe('wholesale')
        ->and($order->shift_id)->toBeNull()
        ->and((float) $order->paid_amount)->toBe($paid)
        ->and($order->payment_status)->toBe($status)
        ->and((float) $stock->fresh()->stock_quantity)->toBe(4.0)
        ->and(Payment::query()->count())->toBe($paid > 0 ? 1 : 0);

    if ($paid > 0) {
        $payment = Payment::query()->sole();
        expect((int) $payment->shift_id)->toBe($shift->id)
            ->and((float) $payment->amount)->toBe($paid)
            ->and((int) $payment->party_id)->toBe($customer->id);
    }

    $cashPaid = $paymentMethod === 'cash' ? $paid : 0.0;
    $component->call('prepareCloseShift')
        ->assertSet('shift_total_sales', 0.0)
        ->assertSet('shift_cash_receipts', $cashPaid)
        ->assertSet('shift_expected_cash', 50 + $cashPaid)
        ->set('searchInvoiceQuery', $order->invoice_number)->call('searchInvoice')
        ->assertSet('currentInvoiceId', null);

    Livewire::test('pages::tenant.customers.index')->call('openStatementModal', $customer->id)
        ->assertSee('فاتورة مبيعات #'.$order->invoice_number);

    Livewire::test('pages::tenant.sales.invoices')
        ->set('searchInvoiceQuery', $order->invoice_number)->call('searchInvoice')
        ->assertSet('currentOrderId', $order->id)
        ->assertSet('savedPaidAmount', $paid)
        ->assertSee('المتبقي على الزبون')
        ->call('saveInvoice')->assertSet('errorMessage', null);

    expect((float) $order->fresh()->paid_amount)->toBe($paid)
        ->and($order->fresh()->payment_status)->toBe($status);

    if ($mode === 'edge') {
        $operation = BranchSyncOperation::query()->where('operation_type', 'sale.upsert')->sole();
        expect($operation->payload['order']['type'])->toBe('wholesale')
            ->and((float) $operation->payload['order']['paid_amount'])->toBe($paid);
        if ($paid === 0.0) {
            expect($operation->payload['payment'])->toBeNull();
        }
    }
})->with([
    'unpaid on server' => [0.0, 'unpaid', 'central'],
    'partial on server' => [4.0, 'partial', 'central'],
    'paid on server' => [10.0, 'paid', 'central'],
    'unpaid offline' => [0.0, 'unpaid', 'edge'],
    'partial offline' => [4.0, 'partial', 'edge'],
    'partial card payment' => [4.0, 'partial', 'central', 'card'],
]);
