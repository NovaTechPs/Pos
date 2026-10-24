<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('printed invoices use the current store name and reflect a renamed store', function (string $kind): void {
    Tenant::query()->create(['name' => 'متجر آخر']);
    $tenant = Tenant::query()->create(['name' => 'متجر ياسمين']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'اسم الفرع', 'domain' => 'invoice-test']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'type' => 'tenant_user', 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);

    if ($kind === 'purchases') {
        $id = DB::table('purchases')->insertGetId([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'reference_number' => 'PUR-STORE', 'total' => 20, 'paid_amount' => 20,
            'payment_status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $component = Livewire::test('pages::tenant.purchases.invoices')->set('currentPurchaseId', $id);
        $event = 'print-purchase-invoice';
    } else {
        $order = Order::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'invoice_number' => 'INV-STORE', 'type' => $kind === 'wholesale' ? 'wholesale' : 'pos',
            'status' => 'completed', 'subtotal' => 20, 'discount' => 0, 'total' => 20, 'paid_amount' => 20,
        ]);
        $id = $order->id;
        $component = Livewire::test('pages::tenant.'.$kind.'.invoices');
        if ($kind === 'sales') {
            $component->set('currentOrderId', $id);
        }
        $event = $kind === 'sales' ? 'print-sales-invoice' : 'print-wholesale-invoice';
    }

    foreach (['متجر ياسمين', 'متجر ياسمين الجديد'] as $storeName) {
        $tenant->update(['name' => $storeName]);
        if ($kind === 'wholesale') {
            $component->call('printOrder', $id);
        } else {
            $component->call('printCurrentInvoice');
        }
        $component->assertDispatched($event, function (string $name, array $params) use ($kind, $storeName): bool {
            $invoice = $params[$kind === 'wholesale' ? 'data' : 'invoice'];

            return $invoice['store_name'] === $storeName;
        });
    }
})->with(['sales', 'purchases', 'wholesale']);
