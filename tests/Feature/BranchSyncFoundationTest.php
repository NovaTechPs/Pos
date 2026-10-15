<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncDevice;
use App\Models\BranchSyncOperation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function branchSyncFixtures(): array
{
    $tenant = Tenant::query()->create(['name' => 'متجر المزامنة']);
    $branch = Branch::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'فرع المزامنة',
    ]);

    return [$tenant, $branch];
}

function branchSyncSalePayload(Tenant $tenant, Branch $branch, string $invoiceNumber = 'B-100'): array
{
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);
    $product = Product::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'منتج المزامنة',
        'cost_price' => 5,
    ]);
    BranchProduct::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'product_id' => $product->id,
        'stock_quantity' => 10,
        'retail_price' => 12.5,
    ]);

    return [
        'order' => [
            'sync_uuid' => (string) Str::uuid(),
            'invoice_number' => $invoiceNumber,
            'created_at' => now()->toISOString(),
            'created_by' => $user->id,
            'customer_id' => null,
            'customer_name' => 'زبون عام',
            'customer_phone' => null,
            'customer_address' => null,
            'type' => 'pos',
            'subtotal' => 25,
            'tax_amount' => 0,
            'discount_type' => 'fixed',
            'discount_rate' => 0,
            'discount' => 0,
            'delivery_fee' => 0,
            'total' => 25,
            'total_cost' => 10,
            'total_profit' => 15,
            'paid_amount' => 25,
            'payment_status' => 'paid',
            'notes' => null,
        ],
        'shift' => [
            'sync_uuid' => (string) Str::uuid(),
            'opening_cash' => 100,
            'opened_at' => now()->subHour()->toISOString(),
            'opened_by' => $user->id,
            'notes' => null,
        ],
        'items' => [[
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 12.5,
            'total_price' => 25,
            'cost_price' => 5,
            'total_cost' => 10,
            'discount' => 0,
        ]],
        'payment' => [
            'amount' => 25,
            'type' => 'receipt',
            'voucher_number' => 'PAY-'.$invoiceNumber,
            'payment_method' => 'cash',
            'payment_date' => now()->toISOString(),
            'notes' => 'سند قبض',
        ],
    ];
}

test('sync devices are tied to a branch and store only a token hash', function (): void {
    [$tenant, $branch] = branchSyncFixtures();
    $tokenHash = hash('sha256', 'branch-device-secret');

    $device = BranchSyncDevice::query()->create([
        'device_uuid' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'name' => 'خادم الفرع',
        'token_hash' => $tokenHash,
    ]);

    expect($device->branch->is($branch))->toBeTrue()
        ->and($device->tenant->is($tenant))->toBeTrue()
        ->and($device->fresh()->is_active)->toBeTrue()
        ->and($device->fresh()->getAttributes()['token_hash'])->toBe($tokenHash);
});

test('branch sync operations are idempotent by branch and operation uuid', function (): void {
    [$tenant, $branch] = branchSyncFixtures();
    $operationUuid = (string) Str::uuid();
    $payload = ['invoice' => ['total' => 25.5]];

    BranchSyncOperation::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'operation_uuid' => $operationUuid,
        'operation_type' => 'sale.upsert',
        'payload' => $payload,
    ]);

    expect(fn () => BranchSyncOperation::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'operation_uuid' => $operationUuid,
        'operation_type' => 'sale.upsert',
        'payload' => $payload,
    ]))->toThrow(QueryException::class);

    expect(BranchSyncOperation::query()->count())->toBe(1)
        ->and(BranchSyncOperation::query()->first()->payload)->toBe($payload);
});

test('new orders and shifts receive stable sync uuids', function (): void {
    [$tenant, $branch] = branchSyncFixtures();

    $shift = Shift::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'opening_cash' => 0,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    $order = Order::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'shift_id' => $shift->id,
        'invoice_number' => 'SYNC-TEST-1',
        'subtotal' => 25.5,
        'total' => 25.5,
    ]);

    expect(Str::isUuid($shift->sync_uuid))->toBeTrue()
        ->and(Str::isUuid($order->sync_uuid))->toBeTrue()
        ->and($order->fresh()->sync_uuid)->toBe($order->sync_uuid);
});

test('branch sync endpoint rejects missing and invalid device credentials', function (): void {
    $this->postJson('/api/branch-sync/operations', [
        'operations' => [[
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'sale.upsert',
            'payload' => ['invoice' => ['total' => 5]],
        ]],
    ])->assertUnauthorized();

    [$tenant, $branch] = branchSyncFixtures();
    BranchSyncDevice::query()->create([
        'device_uuid' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'name' => 'خادم غير نشط',
        'token_hash' => hash('sha256', 'disabled-token'),
        'is_active' => false,
    ]);

    $this->withToken('disabled-token')->postJson('/api/branch-sync/operations', [
        'operations' => [[
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'sale.upsert',
            'payload' => ['invoice' => ['total' => 5]],
        ]],
    ])->assertUnauthorized();
});

test('branch sync endpoint stores retries once and rejects altered duplicate payloads', function (): void {
    [$tenant, $branch] = branchSyncFixtures();
    $token = 'active-branch-token';
    BranchSyncDevice::query()->create([
        'device_uuid' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'name' => 'خادم الفرع',
        'token_hash' => hash('sha256', $token),
    ]);

    $operationUuid = (string) Str::uuid();
    $payload = branchSyncSalePayload($tenant, $branch);
    $operation = [
        'operation_uuid' => $operationUuid,
        'operation_type' => 'sale.upsert',
        'payload' => $payload,
    ];

    $this->withToken($token)->postJson('/api/branch-sync/operations', ['operations' => [$operation]])
        ->assertOk()
        ->assertJsonPath("accepted.{$operationUuid}", 'synced');

    $this->withToken($token)->postJson('/api/branch-sync/operations', ['operations' => [$operation]])
        ->assertOk()
        ->assertJsonPath("accepted.{$operationUuid}", 'synced');

    $this->withToken($token)->postJson('/api/branch-sync/operations', ['operations' => [[
        ...$operation,
        'payload' => ['order' => ['total' => 900]],
    ]]])->assertOk()->assertJsonPath("accepted.{$operationUuid}", 'conflict');

    expect(BranchSyncOperation::query()->count())->toBe(1)
        ->and(BranchSyncOperation::query()->where('operation_uuid', $operationUuid)->sole()->tenant_id)->toBe($tenant->id)
        ->and(BranchSyncOperation::query()->where('operation_uuid', $operationUuid)->sole()->branch_id)->toBe($branch->id)
        ->and(Order::query()->count())->toBe(1)
        ->and((float) BranchProduct::query()->value('stock_quantity'))->toBe(8.0)
        ->and(Payment::query()->count())->toBe(1);
});

test('branch sync stock conflicts do not create a central invoice or change stock', function (): void {
    [$tenant, $branch] = branchSyncFixtures();
    $token = 'stock-conflict-token';
    BranchSyncDevice::query()->create([
        'device_uuid' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'name' => 'خادم الفرع',
        'token_hash' => hash('sha256', $token),
    ]);

    $payload = branchSyncSalePayload($tenant, $branch);
    BranchProduct::query()->update(['stock_quantity' => 1]);
    $operationUuid = (string) Str::uuid();

    $this->withToken($token)->postJson('/api/branch-sync/operations', [
        'operations' => [[
            'operation_uuid' => $operationUuid,
            'operation_type' => 'sale.upsert',
            'payload' => $payload,
        ]],
    ])->assertOk()->assertJsonPath("accepted.{$operationUuid}", 'conflict');

    expect(Order::query()->count())->toBe(0)
        ->and((float) BranchProduct::query()->value('stock_quantity'))->toBe(1.0)
        ->and(BranchSyncOperation::query()->sole()->status)->toBe('conflict');
});

test('branch sync applies a closed shift by its stable uuid', function (): void {
    [$tenant, $branch] = branchSyncFixtures();
    $token = 'shift-close-token';
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);
    BranchSyncDevice::query()->create([
        'device_uuid' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'name' => 'خادم الفرع',
        'token_hash' => hash('sha256', $token),
    ]);
    $shiftUuid = (string) Str::uuid();
    $operationUuid = (string) Str::uuid();

    $this->withToken($token)->postJson('/api/branch-sync/operations', [
        'operations' => [[
            'operation_uuid' => $operationUuid,
            'operation_type' => 'shift.closed',
            'payload' => ['shift' => [
                'sync_uuid' => $shiftUuid,
                'opening_cash' => 100,
                'expected_cash' => 125,
                'actual_cash' => 120,
                'difference' => -5,
                'total_sales' => 25,
                'total_returns' => 0,
                'opened_at' => now()->subHour()->toISOString(),
                'opened_by' => $user->id,
                'closed_at' => now()->toISOString(),
                'closed_by' => $user->id,
                'notes' => 'إغلاق الفرع',
            ]],
        ]],
    ])->assertOk()->assertJsonPath("accepted.{$operationUuid}", 'synced');

    $shift = Shift::query()->where('sync_uuid', $shiftUuid)->sole();
    expect($shift->branch_id)->toBe($branch->id)
        ->and($shift->status)->toBe('closed')
        ->and((float) $shift->difference)->toBe(-5.0);
});

test('branch sync device command prints a one-time token and persists only its hash', function (): void {
    [$tenant, $branch] = branchSyncFixtures();

    $this->artisan('branch-sync:create-device', [
        'branch' => $branch->id,
        'name' => 'خادم الفرع',
    ])->assertSuccessful();

    $device = BranchSyncDevice::query()->sole();
    expect($device->tenant_id)->toBe($tenant->id)
        ->and($device->branch_id)->toBe($branch->id)
        ->and($device->token_hash)->toMatch('/^[a-f0-9]{64}$/');
});

test('branch sync payloads above one megabyte are rejected', function (): void {
    [$tenant, $branch] = branchSyncFixtures();
    $token = 'bounded-branch-token';
    BranchSyncDevice::query()->create([
        'device_uuid' => (string) Str::uuid(),
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'name' => 'خادم الفرع',
        'token_hash' => hash('sha256', $token),
    ]);

    $this->withToken($token)->postJson('/api/branch-sync/operations', [
        'operations' => [[
            'operation_uuid' => (string) Str::uuid(),
            'operation_type' => 'sale.upsert',
            'payload' => ['oversized' => str_repeat('x', 1_000_001)],
        ]],
    ])->assertStatus(413);
});

test('edge synchronization command marks operations complete only after central acknowledgement', function (): void {
    config([
        'branch-sync.mode' => 'edge',
        'branch-sync.server_url' => 'https://central.example',
        'branch-sync.token' => 'branch-secret-token',
    ]);
    [$tenant, $branch] = branchSyncFixtures();
    $operationUuid = (string) Str::uuid();
    $operation = BranchSyncOperation::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'operation_uuid' => $operationUuid,
        'operation_type' => 'sale.upsert',
        'status' => 'pending',
        'payload' => ['order' => ['invoice_number' => 'A-1']],
    ]);

    Http::fake([
        'central.example/api/branch-sync/operations' => Http::response([
            'accepted' => [$operationUuid => 'synced'],
        ]),
    ]);

    $this->artisan('branch-sync:push')->assertSuccessful();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer branch-secret-token')
        && $request['operations'][0]['operation_uuid'] === $operationUuid);
    expect($operation->fresh()->status)->toBe('synced')
        ->and($operation->fresh()->attempts)->toBe(1)
        ->and($operation->fresh()->processed_at)->not->toBeNull();
});

test('edge synchronization retry returns a conflict to the pending outbox', function (): void {
    config(['branch-sync.mode' => 'edge']);
    [$tenant, $branch] = branchSyncFixtures();
    $operation = BranchSyncOperation::query()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'operation_uuid' => (string) Str::uuid(),
        'operation_type' => 'sale.upsert',
        'status' => 'conflict',
        'payload' => ['order' => ['invoice_number' => 'A-2']],
        'last_error' => 'stock conflict',
    ]);

    $this->artisan('branch-sync:retry', ['operation_uuid' => $operation->operation_uuid])
        ->assertSuccessful();

    expect($operation->fresh()->status)->toBe('pending')
        ->and($operation->fresh()->last_error)->toBeNull();
});
