<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Party;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Visibility store']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'كاشير']);
    foreach (['pos.view', 'pos.invoice.search', 'pos.sale.checkout', 'sales_invoices.view', 'sales_invoices.print'] as $name) {
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'test']));
    }
    $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $this->colleague = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Customer', 'type' => 'customer']);
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Item']);
    $invoice = function (User $creator, string $number) use ($customer, $product): Order {
        $order = Order::query()->create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'created_by' => $creator->id,
            'customer_id' => $customer->id, 'customer_name' => 'Customer', 'invoice_number' => $number, 'type' => 'pos',
            'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10, 'payment_status' => 'paid',
        ]);
        OrderItem::query()->create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'cost_price' => 4, 'total_cost' => 4, 'discount' => 0]);

        return $order;
    };
    $this->own = $invoice($this->cashier, 'POS-OWN-1');
    $this->others = $invoice($this->colleague, 'POS-OTHER-1');
    $this->newer = $invoice($this->colleague, 'POS-OTHER-2');
});

test('employees browse, search and reopen only their own invoices at the point of sale', function (): void {
    $this->actingAs($this->cashier)->withSession(['active_tenant_id' => $this->tenant->id]);

    $pos = Livewire::test('pages::tenant.pos.sales')
        ->call('previousInvoice')->assertSet('currentInvoiceId', $this->own->id)
        ->call('previousInvoice')->assertSet('currentInvoiceId', $this->own->id)
        // Past the cashier's newest invoice, "next" opens a new invoice instead of a colleague's.
        ->call('nextInvoice')->assertSet('currentInvoiceId', null)
        ->call('previousInvoice')->assertSet('currentInvoiceId', $this->own->id)
        ->set('searchInvoiceQuery', 'POS-OTHER-1')->call('searchInvoice')->assertSet('currentInvoiceId', $this->own->id)
        ->call('loadInvoice', $this->others->id)->assertSet('currentInvoiceId', $this->own->id)
        ->call('scanBarcode', 'POS-OTHER-2')->assertSet('currentInvoiceId', $this->own->id)
        ->assertSet('errorMessage', 'هذه الفاتورة أنشأها مستخدم آخر؛ يمكنك فتح فواتيرك فقط.');

    $pos->set('currentInvoiceId', $this->others->id)->call('printReceipt')->assertNotDispatched('print-receipt');
    $this->get(route('tenant.sale-invoices.pdf', $this->others->id))->assertNotFound();
    $this->get(route('tenant.sale-invoices.pdf', $this->own->id))->assertOk();
});

test('employees open only their own invoices in the sales invoices log', function (): void {
    $this->actingAs($this->cashier)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.sales.invoices')
        ->call('previousInvoice')->assertSet('currentOrderId', $this->own->id)
        ->call('openInvoiceFromSearch', $this->others->id)->assertSet('currentOrderId', $this->own->id)
        ->set('showInvoiceSearch', true)->assertSee('POS-OWN-1')->assertDontSee('POS-OTHER-1');
});

test('the store owner still sees every invoice', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.pos.sales')->call('previousInvoice')->assertSet('currentInvoiceId', $this->newer->id)
        ->call('loadInvoice', $this->others->id)->assertSet('currentInvoiceId', $this->others->id);
    $this->get(route('tenant.sale-invoices.pdf', $this->others->id))->assertOk();
});

test('invoice search accepts arabic digits', function (): void {
    $this->actingAs($this->cashier)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.pos.sales')->set('searchInvoiceQuery', 'POS-OWN-١')->call('searchInvoice')
        ->assertSet('currentInvoiceId', $this->own->id);
});
