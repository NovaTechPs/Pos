<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'المتجر']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
});

test('invoice printing includes outstanding amount and the current signed account balance', function (string $page, ?float $balance, float $paid): void {
    $party = $balance !== null ? Party::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'الحساب',
        'type' => $page === 'sales' ? 'customer' : 'supplier',
        'current_balance' => $balance,
    ]) : null;
    $attributes = [
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'total' => 100,
        'paid_amount' => $paid,
        'payment_status' => $paid >= 100 ? 'paid' : 'partial',
    ];
    $invoice = $page === 'sales'
        ? Order::create($attributes + ['subtotal' => 100, 'customer_id' => $party?->id, 'invoice_number' => 'PRINT-1', 'type' => 'wholesale', 'status' => 'completed'])
        : Purchase::create($attributes + ['supplier_id' => $party?->id, 'reference_number' => 'PRINT-1']);
    $eventName = $page === 'sales' ? 'print-sales-invoice' : 'print-purchase-invoice';

    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->set($page === 'sales' ? 'currentOrderId' : 'currentPurchaseId', $invoice->id)
        ->call('printCurrentInvoice')
        ->assertSet('errorMessage', null)
        ->assertDispatched($eventName, function (string $event, array $params) use ($balance, $paid): bool {
            $data = $params['invoice'];

            return (float) $data['remaining'] === max(0.0, 100 - $paid)
                && (float) $data['paid'] === $paid
                && ($balance === null ? $data['account_balance'] === null : (float) $data['account_balance'] === $balance);
        });

    if ($party) {
        $party->update(['current_balance' => 25]);
        $component->call('printCurrentInvoice')
            ->assertDispatched($eventName, fn (string $event, array $params): bool => (float) $params['invoice']['account_balance'] === 25.0);
    }
})->with(['sales', 'purchases'])->with([150.0, 0.0, -20.0, null])->with([40.0, 100.0]);

test('save and print reads the account balance after saving the invoice', function (string $page): void {
    $party = Party::create([
        'tenant_id' => $this->tenant->id, 'name' => 'الحساب',
        'type' => $page === 'sales' ? 'customer' : 'supplier',
        'opening_balance' => 50, 'current_balance' => 50,
    ]);
    $product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'الصنف', 'cost_price' => 10]);
    BranchProduct::create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $product->id, 'stock_quantity' => 10, 'retail_price' => 100,
    ]);

    $component = Livewire::test('pages::tenant.'.$page.'.invoices')
        ->set($page === 'sales' ? 'selectedCustomerId' : 'selectedSupplierId', $party->id)
        ->call('selectProduct', $product->id)
        ->set($page === 'sales' ? 'selectedPrice' : 'selectedPurchasePrice', 100)
        ->call('commitRowToCart');

    $component->set('paidAmount', 40);

    $component->call('saveAndPrint')->assertHasNoErrors()->assertSet('errorMessage', null)
        ->assertDispatched($page === 'sales' ? 'print-sales-invoice' : 'print-purchase-invoice', function (string $event, array $params) use ($party): bool {
            return (float) $params['invoice']['account_balance'] === (float) $party->fresh()->current_balance
                && (float) $params['invoice']['account_balance'] === 110.0;
        });
})->with(['sales', 'purchases']);
