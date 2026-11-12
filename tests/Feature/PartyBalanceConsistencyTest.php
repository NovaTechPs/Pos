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
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Balance store']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opening_cash' => 0,
        'status' => 'open', 'opened_at' => now(), 'opened_by' => $this->owner->id]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Item', 'cost_price' => 40]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'retail_price' => 100, 'stock_quantity' => 10]);
    $this->customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Customer', 'type' => 'customer', 'opening_balance' => 0, 'current_balance' => 0]);
});

function ledgerBalance(object $context): float
{
    return (new ReportingService($context->owner, null, false))->partyLedgerBalance($context->customer->id);
}

test('a POS credit sale adds the unpaid part to the customer and deleting it removes it again', function (): void {
    $pos = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->call('selectCustomer', $this->customer->id)->set('paid_amount', 30)->call('checkout');
    if ($pos->get('showCustomerPaymentModal')) {
        $pos->call('setCustomerPaymentAmount', 30)->call('confirmCustomerPayment');
    }
    $order = Order::query()->sole();

    expect((float) $order->paid_amount)->toBe(30.0)
        ->and((float) $this->customer->fresh()->current_balance)->toBe(70.0)
        ->and(ledgerBalance($this))->toBe(70.0);

    $pos->call('loadInvoice', $order->id)->call('deleteInvoice')->assertSet('errorMessage', null);

    expect((float) $this->customer->fresh()->current_balance)->toBe(0.0)
        ->and(ledgerBalance($this))->toBe(0.0);
});

test('balance reconciliation lists differences and corrects them to the statement', function (): void {
    $this->customer->update(['current_balance' => 55]);

    $this->artisan('parties:reconcile-balances', ['--tenant' => $this->tenant->id])
        ->expectsOutputToContain('difference -55.00')->assertSuccessful();
    expect((float) $this->customer->fresh()->current_balance)->toBe(55.0);

    $this->artisan('parties:reconcile-balances', ['--tenant' => $this->tenant->id, '--fix' => true])->assertSuccessful();
    expect((float) $this->customer->fresh()->current_balance)->toBe(0.0);
    $this->artisan('parties:reconcile-balances', ['--tenant' => $this->tenant->id])
        ->expectsOutputToContain('All balances match')->assertSuccessful();
});

test('the reconcile button uses the same ledger as the statement report', function (): void {
    $this->customer->update(['current_balance' => 12, 'opening_balance' => 40]);

    Livewire::test('pages::tenant.customers.index')->call('reconcilePartyBalance', $this->customer->id);

    expect((float) $this->customer->fresh()->current_balance)->toBe(40.0)->and(ledgerBalance($this))->toBe(40.0);
});

test('a sale paid on the invoice without a receipt voucher is settled in the statement', function (): void {
    $sale = fn (string $number, float $total, float $paid) => Order::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $this->customer->id,
        'customer_name' => 'Customer', 'invoice_number' => $number, 'type' => 'wholesale', 'status' => 'completed',
        'subtotal' => $total, 'total' => $total, 'paid_amount' => $paid, 'payment_status' => $paid >= $total ? 'paid' : 'partial']);
    $sale('OLD-PAID', 630, 630);
    $linked = $sale('LINKED', 200, 200);
    Payment::query()->create(['tenant_id' => $this->tenant->id, 'party_id' => $this->customer->id, 'order_id' => $linked->id,
        'type' => 'receipt', 'voucher_number' => 'REC-LINKED', 'amount' => 200, 'payment_method' => 'cash', 'payment_date' => now()]);
    $sale('PART', 100, 40);

    expect(ledgerBalance($this))->toBe(60.0);
});

test('balance reconciliation can correct chosen parties only', function (): void {
    $other = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'type' => 'customer', 'current_balance' => 9]);
    $this->customer->update(['current_balance' => 55]);

    $this->artisan('parties:reconcile-balances', ['--party' => [$this->customer->id], '--fix' => true])->assertSuccessful();

    expect((float) $this->customer->fresh()->current_balance)->toBe(0.0)->and((float) $other->fresh()->current_balance)->toBe(9.0);
});

test('balance reconciliation skips excluded names and shared delivery companies and logs every correction', function (): void {
    $party = fn (string $name, float $stored) => Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => $name, 'type' => 'customer', 'current_balance' => $stored]);
    $azm = $party('مؤسسة العزم / ابو ديس', 7);
    $hook = $party('شركة توصيل هوك', -1019);
    $courier = $party('شركة توصيل أخرى', 0);
    Order::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'customer_id' => $courier->id,
        'delivery_party_id' => $courier->id, 'customer_name' => 'x', 'invoice_number' => 'SHARED-1', 'type' => 'pos', 'status' => 'completed',
        'subtotal' => 100, 'total' => 100, 'delivery_fee' => 20, 'paid_amount' => 0, 'payment_status' => 'unpaid']);
    $this->customer->update(['current_balance' => 55]);

    $this->artisan('parties:reconcile-balances', ['--fix' => true, '--except-name' => ['العزم', 'هوك'], '--reason' => 'pos-credit-sale-fix-2026-11-12'])->assertSuccessful();

    expect((float) $this->customer->fresh()->current_balance)->toBe(0.0)
        ->and((float) $azm->fresh()->current_balance)->toBe(7.0)
        ->and((float) $hook->fresh()->current_balance)->toBe(-1019.0)
        ->and((float) $courier->fresh()->current_balance)->toBe(0.0);
    $log = DB::table('party_balance_corrections')->sole();
    expect($log->party_id)->toBe($this->customer->id)
        ->and((float) $log->previous_balance)->toBe(55.0)
        ->and((float) $log->corrected_balance)->toBe(0.0)
        ->and($log->reason)->toBe('pos-credit-sale-fix-2026-11-12');
});
