<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchStockAllocator;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر تعديل الفواتير']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->shift = Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف الزبون', 'cost_price' => 2]);
    $this->stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);
    $this->customer = Party::create(['tenant_id' => $this->tenant->id, 'name' => 'زبون نقطة البيع', 'type' => 'customer', 'current_balance' => 30]);
    $this->order = Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'shift_id' => $this->shift->id, 'customer_id' => $this->customer->id,
        'customer_name' => $this->customer->name, 'invoice_number' => 'POS-CUSTOMER-EDIT', 'type' => 'pos', 'source' => 'pos', 'status' => 'completed',
        'subtotal' => 30, 'total' => 30, 'total_cost' => 6, 'paid_amount' => 0, 'payment_status' => 'unpaid']);
    $this->order->items()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 10, 'total_price' => 30, 'cost_price' => 2, 'total_cost' => 6]);
    app(BranchStockAllocator::class)->deductMany($this->order, [$this->product->id => 3], false, false);
});

test('a POS invoice issued to a customer can be edited from the sales invoices page and stays a POS invoice', function (): void {
    $component = Livewire::test('pages::tenant.sales.invoices')
        ->call('openInvoiceFromSearch', $this->order->id)
        ->assertSet('currentOrderId', $this->order->id)
        ->assertSet('selectedCustomerId', $this->customer->id)
        ->call('editLoadedInvoice');
    $key = array_key_first($component->get('cart'));

    Exceptions::fake();
    $component->call('updateItemQuantity', $key, 5)->call('saveInvoice')->assertSet('errorMessage', null);
    Exceptions::assertNothingReported();

    $order = $this->order->fresh();
    expect($order->type)->toBe('pos')
        ->and($order->source)->toBe('pos')
        ->and($order->invoice_number)->toBe('POS-CUSTOMER-EDIT')
        ->and((float) $order->total)->toBe(50.0)
        ->and(Order::query()->count())->toBe(1)
        ->and((float) $this->customer->fresh()->current_balance)->toBe(50.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(45.0);

    Livewire::test('pages::tenant.pos.sales')->call('loadInvoice', $order->id)->assertSet('currentInvoiceId', $order->id);
});

test('walk-in POS invoices are not opened from the sales invoices page', function (): void {
    $this->order->update(['customer_id' => null]);

    Livewire::test('pages::tenant.sales.invoices')
        ->call('openInvoiceFromSearch', $this->order->id)
        ->assertSet('currentOrderId', null)
        ->assertSet('errorMessage', 'الفاتورة غير موجودة أو ليست ضمن نطاقك.');
});
