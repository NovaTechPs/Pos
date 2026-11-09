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
use App\Services\ReportingService;
use Livewire\Livewire;

test('cost preview excludes delivery fees from expected profit', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر عرض التكلفة']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    Shift::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'opening_cash' => 0, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $user->id]);
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'صنف التكلفة', 'cost_price' => 4]);
    BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock_quantity' => 5, 'retail_price' => 10]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);

    Livewire::test('pages::tenant.pos.sales')
        ->call('selectInlineProduct', $product->id)
        ->call('openCostModal')
        ->assertSet('showCostModal', true)
        ->assertSet('expected_profit', 6.0)
        ->set('delivery_fee', 5)
        ->assertSet('total', 15.0)
        ->assertSet('expected_profit', 6.0)
        ->set('discount_amount', 2)
        ->assertSet('total', 13.0)
        ->assertSet('expected_profit', 4.0)
        ->set('custom_final_total', 8)
        ->assertSet('expected_profit', -1.0)
        ->set('delivery_fee', 10)
        ->assertSet('total', 13.0)
        ->assertSet('expected_profit', -1.0)
        ->set('cart', [])
        ->assertSet('expected_profit', 0.0);
});

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
        ->set('selectedCustomerId', $customer->id)
        ->set('payment_method', $paymentMethod)
        ->set('selectedCustomerId', $customer->id)
        ->call('checkout')
        ->assertSet('showCustomerPaymentModal', true)
        ->set('customerPaymentAmount', $paid)
        ->call('confirmCustomerPayment')
        ->assertSet('errorMessage', null)
        ->assertSet('invoiceEditMode', false)
        ->assertSet('currentInvoiceId', null)
        ->assertSet('cart', [])
        ->assertSet('selectedCustomerId', null)
        ->assertSee('وفتح فاتورة جديدة');

    $order = Order::query()->sole();
    $component->assertSet('currentInvoiceId', null)
        ->call('loadInvoice', $order->id)
        ->assertSet('currentInvoiceId', $order->id);

    expect($order->type)->toBe('pos')
        ->and((int) $order->shift_id)->toBe($shift->id)
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
    Livewire::test('pages::tenant.pos.sales')
        ->set('cart', [])
        ->set('currentInvoiceId', null)
        ->set('editingInvoiceId', null)
        ->set('invoiceEditMode', false)
        ->set('selectedCustomerId', null)
        ->call('prepareCloseShift')
        ->assertSet('shift_total_sales', 10.0)
        ->assertSet('shift_cash_receipts', $cashPaid)
        ->assertSet('shift_expected_cash', 50 + $cashPaid);

    $component
        ->call('editLoadedInvoice')
        ->assertSet('invoiceEditMode', true);

    Livewire::test('pages::tenant.customers.index')->call('openStatementModal', $customer->id)
        ->assertSee('فاتورة مبيعات #'.$order->invoice_number);

    Livewire::test('pages::tenant.customers.index')
        ->call('openStatementModal', $customer->id)
        ->call('openStatementDocument', 'order', $order->id)
        ->assertDispatched('open-statement-document', url: route('tenant.pos', ['invoice' => $order->id, 'customer_copy' => 1]));

    expect((float) $order->fresh()->paid_amount)->toBe($paid)
        ->and($order->fresh()->payment_status)->toBe($status);

    if ($mode === 'edge') {
        $operation = BranchSyncOperation::query()->where('operation_type', 'sale.upsert')->sole();
        expect($operation->payload['order']['type'])->toBe('pos')
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

test('delivery notes are saved on the invoice while the delivery fee stays out of the shift', function (): void {
    config(['branch-sync.mode' => 'central']);
    $tenant = Tenant::query()->create(['name' => 'متجر التوصيل']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $customer = Party::query()->create(['tenant_id' => $tenant->id, 'name' => 'زبون التوصيل', 'type' => 'customer', 'is_active' => true]);
    $deliveryCompany = Party::query()->create(['tenant_id' => $tenant->id, 'name' => 'شركة التوصيل', 'type' => 'supplier', 'is_active' => true]);
    $shift = Shift::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'opening_cash' => 50, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $user->id]);
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'صنف توصيل', 'cost_price' => 4]);
    BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock_quantity' => 5, 'retail_price' => 10]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);

    Livewire::test('pages::tenant.pos.sales')
        ->call('selectInlineProduct', $product->id)
        ->set('selectedCustomerId', $customer->id)
        ->set('deliveryCompanyId', $deliveryCompany->id)
        ->set('delivery_fee', 5)
        ->set('deliveryCustomerName', 'عميل التوصيل')
        ->set('deliveryCustomerPhone', '0599999999')
        ->set('deliveryCustomerAddress', 'عنوان التوصيل')
        ->set('deliveryNotes', 'اتصل قبل الوصول')
        ->set('paid_amount', 15)
        ->call('checkout')
        ->set('customerPaymentAmount', 15)
        ->call('confirmCustomerPayment')
        ->assertSet('errorMessage', null);

    $order = Order::query()->sole();
    $payment = Payment::query()->sole();

    expect((float) $order->total)->toBe(15.0)
        ->and((int) $order->customer_id)->toBe($customer->id)
        ->and((float) $order->delivery_fee)->toBe(5.0)
        ->and((float) $order->paid_amount)->toBe(15.0)
        ->and($order->notes)->toContain('ملاحظة التوصيل: اتصل قبل الوصول')
        ->and((float) $payment->amount)->toBe(10.0)
        ->and($payment->notes)->toContain('لا يشمل رسوم التوصيل: 5.00');

    $statement = (new ReportingService($user))->prepare('statement', [
        'from' => now()->subYear()->toDateString(),
        'to' => now()->addYear()->toDateString(),
        'party_id' => $customer->id,
        'product_id' => null,
        'search' => '',
        'party_type' => 'all',
        'balance_side' => 'all',
        'stock_filter' => 'all',
        'source' => '',
        'sale_kind' => '',
    ]);
    expect((float) $statement['summary']['إجمالي المدين'])->toBe(10.0);

    $deliveryStatement = (new ReportingService($user))->prepare('statement', [
        'from' => now()->subYear()->toDateString(),
        'to' => now()->addYear()->toDateString(),
        'party_id' => $deliveryCompany->id,
        'product_id' => null,
        'search' => '',
        'party_type' => 'all',
        'balance_side' => 'all',
        'stock_filter' => 'all',
        'source' => '',
        'sale_kind' => '',
    ]);
    expect((float) $deliveryStatement['summary']['إجمالي الدائن'])->toBe(5.0);

    $customerCopy = Livewire::withQueryParams(['customer_copy' => 1])
        ->test('pages::tenant.pos.sales')
        ->call('loadInvoice', $order->id)
        ->call('printReceipt')
        ->assertDispatched('print-receipt')
        ->get('receipt');
    expect($customerCopy['delivery_fee'])->toBe('0.00')
        ->and($customerCopy['total_amount'])->toBe('10.00');

    Livewire::test('pages::tenant.pos.sales')
        ->set('cart', [])
        ->call('prepareCloseShift')
        ->assertSet('shift_cash_receipts', 10.0)
        ->assertSet('shift_expected_cash', 60.0);

    expect((int) $payment->shift_id)->toBe($shift->id);
});
