<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RenumberLegacySalesInvoices;
use App\Services\SalesInvoiceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('old sales invoices join the branch sequence oldest first and stay findable by their old number', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Numbering store']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $other = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Second']);
    $order = fn (string $number, string $type, string $createdAt, ?int $branchId = null, string $status = 'completed'): Order => Order::query()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branchId ?? $branch->id, 'invoice_number' => $number, 'type' => $type, 'status' => $status,
        'subtotal' => 10, 'total' => 10, 'paid_amount' => 10, 'created_at' => $createdAt,
    ]);

    $unified = $order("A{$branch->id}-1", 'pos', '2026-11-16 10:00:00');
    $newer = $order('SAL-20261109094155-805', 'wholesale', '2026-11-09 09:41:55');
    $older = $order('W-20261102150423-VAR8', 'wholesale', '2026-11-02 15:04:23');
    $random = $order("A{$branch->id}-123456789", 'pos', '2026-11-05 12:00:00');
    $elsewhere = $order('INV-20261101-77', 'pos', '2026-11-01 08:00:00', $other->id);
    $held = $order('HOLD-abc', 'pos', '2026-11-03 08:00:00', status: 'pending');
    $return = $order('RET-20261104-ABCDE', 'return', '2026-11-04 08:00:00');
    $online = $order('WEB-1001', 'online', '2026-11-04 09:00:00');
    $receipt = Payment::query()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'type' => 'receipt', 'voucher_number' => 'RCV-1',
        'amount' => 5, 'payment_date' => '2026-11-09', 'order_id' => $newer->id, 'notes' => 'دفعة على الفاتورة رقم: SAL-20261109094155-805',
    ]);

    expect(app(RenumberLegacySalesInvoices::class)->run())->toBe(4);

    expect($unified->fresh()->invoice_number)->toBe("A{$branch->id}-1")
        ->and($older->fresh()->invoice_number)->toBe("A{$branch->id}-2")
        ->and($random->fresh()->invoice_number)->toBe("A{$branch->id}-3")
        ->and($newer->fresh()->invoice_number)->toBe("A{$branch->id}-4")
        ->and($newer->fresh()->previous_invoice_number)->toBe('SAL-20261109094155-805')
        ->and($elsewhere->fresh()->invoice_number)->toBe("A{$other->id}-1")
        ->and($held->fresh()->invoice_number)->toBe('HOLD-abc')
        ->and($return->fresh()->invoice_number)->toBe('RET-20261104-ABCDE')
        ->and($online->fresh()->invoice_number)->toBe('WEB-1001')
        ->and($receipt->fresh()->notes)->toBe("دفعة على الفاتورة رقم: A{$branch->id}-4")
        ->and(SalesInvoiceNumber::next($tenant->id, $branch->id))->toBe("A{$branch->id}-5");

    // Old printed receipts still lead to the invoice.
    expect(Order::query()->whereInvoiceNumber('SAL-20261109094155-805')->value('id'))->toBe($newer->id)
        ->and(Order::query()->whereInvoiceNumber('094155', partial: true)->value('id'))->toBe($newer->id)
        ->and(Order::query()->whereInvoiceNumber("A{$branch->id}-4")->value('id'))->toBe($newer->id);

    // Running it again changes nothing.
    expect(app(RenumberLegacySalesInvoices::class)->run())->toBe(0);
});

test('scanning an old receipt number opens the renumbered invoice for a return', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Returns store', 'is_active' => true]);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'type' => 'tenant_user', 'is_owner' => true, 'is_active' => true]);
    Order::query()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'invoice_number' => 'SAL-20261109094155-805', 'type' => 'pos',
        'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10,
    ]);
    app(RenumberLegacySalesInvoices::class)->run();

    $this->actingAs($user)->getJson('/api/orders/invoice/SAL-20261109094155-805')
        ->assertOk()
        ->assertJsonPath('order.invoice_number', "A{$branch->id}-1");
});
