<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Tenant;
use App\Services\SalesInvoiceNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('every sales screen shares one running number per branch and returns keep their own', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Numbering store']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Main']);
    $other = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'Second']);
    $order = fn (string $number, string $type): Order => Order::query()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'invoice_number' => $number, 'type' => $type, 'status' => 'completed',
        'subtotal' => 10, 'total' => 10, 'paid_amount' => 10,
    ]);

    expect(SalesInvoiceNumber::next($tenant->id, $branch->id))->toBe("A{$branch->id}-1");

    $order("A{$branch->id}-1", 'pos');
    $order("A{$branch->id}-2", 'wholesale');
    $order('W-20261102150423-VAR8', 'wholesale');
    $order("A{$branch->id}-123456789", 'pos');

    expect(SalesInvoiceNumber::next($tenant->id, $branch->id))->toBe("A{$branch->id}-3")
        ->and(SalesInvoiceNumber::next($tenant->id, $other->id))->toBe("A{$other->id}-1")
        ->and(SalesInvoiceNumber::next($tenant->id, $branch->id, isReturn: true))->toBe("R{$branch->id}-1");
});
