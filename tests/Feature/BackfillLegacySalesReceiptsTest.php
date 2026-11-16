<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Legacy receipt tenant']);
    $this->user = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'is_owner' => true,
    ]);
    $this->branch = Branch::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Legacy receipt branch',
    ]);
    $this->customer = Party::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Legacy receipt customer',
        'type' => 'customer',
        'is_active' => true,
        'current_balance' => 40,
    ]);
});

test('backfills only an unvouchered legacy sales payment without changing the party balance or statement balance', function (): void {
    $order = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'created_by' => $this->user->id,
        'customer_id' => $this->customer->id,
        'invoice_number' => 'SAL-LEGACY-6000',
        'invoice_date' => '2026-11-04',
        'type' => 'wholesale',
        'status' => 'completed',
        'subtotal' => 100,
        'total' => 100,
        'paid_amount' => 60,
        'payment_status' => 'partial',
    ]);
    $ledgerBefore = (new ReportingService($this->user, null, false))->partyLedgerBalance($this->customer->id);

    $this->artisan('sales:backfill-legacy-receipts', ['--tenant' => $this->tenant->id])
        ->assertExitCode(0);

    expect(Payment::query()->count())->toBe(0);

    $this->artisan('sales:backfill-legacy-receipts', ['--tenant' => $this->tenant->id, '--apply' => true])
        ->assertExitCode(0);

    $receipt = Payment::query()->sole();

    expect($receipt->type)->toBe('receipt')
        ->and((int) $receipt->order_id)->toBe($order->id)
        ->and((int) $receipt->party_id)->toBe($this->customer->id)
        ->and((float) $receipt->amount)->toBe(60.0)
        ->and($receipt->payment_method)->toBe('cash')
        ->and($receipt->payment_date->toDateString())->toBe('2026-11-04')
        ->and($receipt->notes)->toContain('تمت معالجته تلقائياً')
        ->and((float) $order->fresh()->paid_amount)->toBe(60.0)
        ->and((float) $this->customer->fresh()->current_balance)->toBe(40.0)
        ->and((new ReportingService($this->user, null, false))->partyLedgerBalance($this->customer->id))->toBe($ledgerBefore);

    $this->artisan('sales:backfill-legacy-receipts', ['--tenant' => $this->tenant->id, '--apply' => true])
        ->assertExitCode(0);

    expect(Payment::query()->count())->toBe(1);
});

test('does not duplicate a legacy sales payment that already has a linked receipt', function (): void {
    $order = Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'customer_id' => $this->customer->id,
        'invoice_number' => 'SAL-ALREADY-VOUCHERED',
        'invoice_date' => '2026-11-04',
        'type' => 'wholesale',
        'status' => 'completed',
        'subtotal' => 100,
        'total' => 100,
        'paid_amount' => 60,
        'payment_status' => 'partial',
    ]);
    $receipt = Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'party_id' => $this->customer->id,
        'order_id' => $order->id,
        'type' => 'receipt',
        'voucher_number' => 'RCV-ALREADY-EXISTS',
        'payment_date' => '2026-11-04',
        'payment_method' => 'cash',
        'amount' => 60,
    ]);

    $this->artisan('sales:backfill-legacy-receipts', ['--tenant' => $this->tenant->id, '--apply' => true])
        ->assertExitCode(0);

    expect(Payment::query()->sole()->id)->toBe($receipt->id);
});
