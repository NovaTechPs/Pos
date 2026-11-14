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
    $this->tenant = Tenant::query()->create(['name' => 'WhatsApp store']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'كاشير']);
    foreach (['pos.view', 'pos.invoice.search', 'pos.sale.checkout'] as $name) {
        $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'test']));
    }
    $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $this->colleague = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'أبو أحمد', 'type' => 'customer', 'phone' => '0599123456']);
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Item']);
    $invoice = function (User $creator, string $number) use ($customer, $product): Order {
        $order = Order::query()->create([
            'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'created_by' => $creator->id,
            'customer_id' => $customer->id, 'customer_name' => 'أبو أحمد', 'invoice_number' => $number, 'type' => 'pos',
            'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10, 'payment_status' => 'paid',
        ]);
        OrderItem::query()->create(['tenant_id' => $this->tenant->id, 'order_id' => $order->id, 'product_id' => $product->id,
            'quantity' => 1, 'unit_price' => 10, 'total_price' => 10, 'cost_price' => 4, 'total_cost' => 4, 'discount' => 0]);

        return $order;
    };
    $this->own = $invoice($this->cashier, 'POS-OWN-1');
    $this->others = $invoice($this->colleague, 'POS-OTHER-1');
});

test('sending a point of sale invoice by WhatsApp hands the browser the invoice PDF', function (): void {
    $this->actingAs($this->cashier)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.pos.sales')
        ->call('previousInvoice')->assertSet('currentInvoiceId', $this->own->id)
        ->call('sendInvoiceWhatsApp')
        ->assertDispatched('whatsapp-invoice',
            phone: '972599123456',
            pdfUrl: route('tenant.sale-invoices.pdf', ['orderId' => $this->own->id]),
            filename: 'أبو أحمد - POS-OWN-1.pdf')
        ->assertSeeHtml('window.posSharesInvoiceFile?.() ? null : window.open');

    expect(file_get_contents(resource_path('views/pages/tenant/pos/⚡sales.blade.php')))
        ->toContain('navigator.share({ files: [file], text: message, title: filename })')
        ->toContain('link.download = filename;');
});

test('a cashier downloads the PDF of their own point of sale invoices only', function (): void {
    $this->actingAs($this->cashier)->withSession(['active_tenant_id' => $this->tenant->id]);

    $this->get(route('tenant.sale-invoices.pdf', $this->own->id))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get(route('tenant.sale-invoices.pdf', $this->others->id))->assertNotFound();
});

test('a user without sales or point of sale access cannot download an invoice PDF', function (): void {
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'مخزن']);
    $storekeeper = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $this->actingAs($storekeeper)->withSession(['active_tenant_id' => $this->tenant->id]);

    $this->get(route('tenant.sale-invoices.pdf', $this->own->id))->assertForbidden();
});
