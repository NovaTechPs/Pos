<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الحذف']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف الحذف', 'cost_price' => 4]);
    $this->stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);
});

test('deleting a sales invoice returns its stock, removes its receipts and clears what the customer owed', function (): void {
    $customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون الحذف', 'type' => 'customer', 'current_balance' => 0]);

    Livewire::test('pages::tenant.sales.invoices')
        ->call('selectCustomer', $customer->id)
        ->call('selectProduct', $this->product->id)
        ->set('selectedQuantity', 5)
        ->set('selectedBonus', 1)
        ->call('commitRowToCart')
        ->set('paidAmount', 20)
        ->call('saveInvoice');

    $order = Order::query()->sole();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(44.0)
        ->and((float) $customer->fresh()->current_balance)->toBe(30.0)
        ->and(Payment::query()->count())->toBe(1);

    Livewire::test('pages::tenant.sales.invoices')
        ->call('loadOrder', $order)
        ->assertSeeHtml('data-delete-invoice')
        ->call('deleteInvoice')
        ->assertSet('currentOrderId', null)
        ->assertSet('errorMessage', null);

    expect(Order::query()->count())->toBe(0)
        ->and(Order::withTrashed()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(50.0)
        ->and((float) $customer->fresh()->current_balance)->toBe(0.0);
});

test('a sales invoice with returns cannot be deleted', function (): void {
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'DEL-1', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 0, 'created_by' => $this->user->id]);
    Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'DEL-R', 'type' => 'return', 'original_order_id' => $order->id, 'status' => 'completed', 'subtotal' => -10, 'total' => -10, 'paid_amount' => 0]);

    Livewire::test('pages::tenant.sales.invoices')->set('currentOrderId', $order->id)->call('deleteInvoice')
        ->assertSet('errorMessage', 'لا يمكن حذف فاتورة مرتبطة بمرتجعات.');

    expect($order->fresh()->trashed())->toBeFalse();
});

test('deleting a purchase invoice takes its stock back, removes its payments and clears what we owed', function (): void {
    $supplier = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'مورد الحذف', 'type' => 'supplier', 'current_balance' => 0]);

    Livewire::test('pages::tenant.purchases.invoices')
        ->set('selectedSupplierId', $supplier->id)
        ->call('selectProduct', $this->product->id)
        ->call('commitRowWithValues', '10', '6', '0', '2')
        ->set('paidAmount', 15)
        ->call('saveInvoice')
        ->assertSet('errorMessage', null);

    $purchase = Purchase::query()->sole();
    $balanceAfterPurchase = (float) $supplier->fresh()->current_balance;
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(62.0)
        ->and(abs($balanceAfterPurchase))->toBe(45.0);

    Livewire::test('pages::tenant.purchases.invoices')
        ->call('loadPurchase', $purchase->id)
        ->assertSeeHtml('data-delete-invoice')
        ->call('deleteInvoice')
        ->assertSet('currentPurchaseId', null)
        ->assertSet('errorMessage', null);

    expect(Purchase::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(50.0)
        ->and((float) $supplier->fresh()->current_balance)->toBe(0.0);
});

test('staff without the delete permission cannot delete invoices', function (): void {
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => false, 'type' => 'tenant_user']);
    $order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => 'DEL-2', 'type' => 'wholesale', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 0, 'created_by' => $staff->id]);
    $this->actingAs($staff);

    Livewire::test('pages::tenant.sales.invoices')->set('currentOrderId', $order->id)->call('deleteInvoice')
        ->assertSet('errorMessage', 'لا تملك صلاحية حذف فواتير المبيعات.');

    expect($order->fresh()->trashed())->toBeFalse();
});
