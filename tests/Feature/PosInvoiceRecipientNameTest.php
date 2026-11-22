<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر اسم المستلم']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الفرع']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    Shift::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'opening_cash' => 0, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $user->id]);
    $this->product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'صنف المستلم', 'cost_price' => 4]);
    BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 20, 'retail_price' => 10]);
    $this->customer = Party::query()->create(['tenant_id' => $tenant->id, 'name' => 'صاحب الحساب', 'type' => 'customer', 'is_active' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
});

test('a walk-in invoice saves the typed recipient name and keeps it editable after saving', function (): void {
    $pos = Livewire::test('pages::tenant.pos.sales')
        ->assertSeeHtml('data-pos-invoice-recipient')
        ->call('selectInlineProduct', $this->product->id)
        ->set('invoiceRecipientName', 'نعمة البدوي')
        ->call('checkout')
        ->assertSet('errorMessage', null)
        ->assertSet('invoiceRecipientName', '');

    $order = Order::query()->sole();
    expect($order->customer_name)->toBe('نعمة البدوي')->and($order->customer_id)->toBeNull();

    $pos->call('loadInvoice', $order->id)->assertSet('invoiceRecipientName', 'نعمة البدوي')
        ->call('editLoadedInvoice')->set('invoiceRecipientName', 'نعمة البدوي - الزرقاء')
        ->call('checkout')->assertSet('errorMessage', null);

    expect($order->fresh()->customer_name)->toBe('نعمة البدوي - الزرقاء')
        ->and(Order::query()->count())->toBe(1);
});

test('a customer invoice keeps the account but prints the recipient name, and an empty name falls back to the customer', function (): void {
    $pos = Livewire::test('pages::tenant.pos.sales')
        ->call('selectInlineProduct', $this->product->id)
        ->set('selectedCustomerId', $this->customer->id)
        ->set('invoiceRecipientName', 'ابن صاحب الحساب')
        ->call('checkout')
        ->set('customerPaymentAmount', 10)
        ->call('confirmCustomerPayment')
        ->assertSet('errorMessage', null);

    $order = Order::query()->sole();
    expect($order->customer_id)->toBe($this->customer->id)->and($order->customer_name)->toBe('ابن صاحب الحساب');

    $pos->call('loadInvoice', $order->id)->assertSet('invoiceRecipientName', 'ابن صاحب الحساب')
        ->call('editLoadedInvoice')->set('invoiceRecipientName', '')
        ->call('checkout')->set('customerPaymentAmount', 10)->call('confirmCustomerPayment')
        ->assertSet('errorMessage', null);

    expect($order->fresh()->customer_name)->toBe('صاحب الحساب');

    $pos->call('loadInvoice', $order->id)->assertSet('invoiceRecipientName', '');
});
