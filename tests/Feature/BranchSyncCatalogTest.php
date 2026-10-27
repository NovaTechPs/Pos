<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncDevice;
use App\Models\BranchSyncOperation;
use App\Models\Party;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('catalog synchronization applies central sales policy changes and preserves unrelated settings', function (): void {
    $enabled = true;
    Http::fake(function () use (&$enabled) {
        $page = catalogPage($this->tenant->id, $this->branch->id);
        $page['settings'] = ['allow_negative_stock' => $enabled, 'unified_stock' => $enabled, 'merge_similar_products' => false];

        return Http::response($page);
    });
    TenantSetting::set($this->tenant->id, 'unrelated_setting', 'preserved');
    $this->artisan('branch-sync:pull')->assertSuccessful();
    expect(TenantSetting::getBool($this->tenant->id, 'allow_negative_stock'))->toBeTrue()
        ->and(TenantSetting::getBool($this->tenant->id, 'unified_stock'))->toBeTrue();
    $enabled = false;
    $this->artisan('branch-sync:pull')->assertSuccessful();
    expect(TenantSetting::getBool($this->tenant->id, 'allow_negative_stock'))->toBeFalse()
        ->and(TenantSetting::get($this->tenant->id, 'unrelated_setting'))->toBe('preserved');
});

test('an older desktop database upgrades before importing customer codes without losing local customers', function (): void {
    Schema::table('parties', function ($table): void {
        $table->dropUnique(['tenant_id', 'code']);
        $table->dropColumn('code');
    });
    DB::table('parties')->insert(['tenant_id' => $this->tenant->id, 'name' => 'Existing local customer', 'type' => 'customer']);
    $upgrade = require database_path('migrations/2026_10_26_213937_add_code_to_existing_parties_table.php');
    $upgrade->up();
    $upgrade->up();
    $backfill = require database_path('migrations/2026_10_26_213940_backfill_party_codes.php');
    $backfill->up();
    Http::fake(fn ($request) => Http::response(catalogPage($this->tenant->id, $this->branch->id,
        $request['entity'] === 'customers' ? [[
            'id' => 100, 'tenant_id' => $this->tenant->id, 'name' => 'Central customer', 'type' => 'customer', 'code' => 'C00000001',
        ]] : [])));

    $this->artisan('branch-sync:pull')->assertSuccessful();
    expect(Party::query()->count())->toBe(2)
        ->and(Party::query()->where('name', 'Existing local customer')->value('code'))->toBe('1')
        ->and(Party::query()->find(100)->code)->toBe('C00000001');
});

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Offline shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Offline branch']);
    config([
        'branch-sync.mode' => 'edge',
        'branch-sync.server_url' => 'https://central.example',
        'branch-sync.token' => 'secret',
        'branch-sync.tenant_id' => $this->tenant->id,
        'branch-sync.branch_id' => $this->branch->id,
    ]);
    Http::preventStrayRequests();
});

function catalogPage(int $tenantId, int $branchId, array $rows = []): array
{
    return ['tenant_id' => $tenantId, 'branch_id' => $branchId, 'rows' => $rows,
        'next' => null, 'categories' => [], 'barcodes' => [], 'prices' => []];
}

test('catalog endpoint authenticates devices and isolates tenants and branches', function (): void {
    config(['branch-sync.mode' => 'central']);
    TenantSetting::setBool($this->tenant->id, 'allow_negative_stock', true);
    TenantSetting::setBool($this->tenant->id, 'unified_stock', true);
    TenantSetting::set($this->tenant->id, 'private_setting', 'not-for-catalog');
    BranchSyncDevice::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'device_uuid' => (string) Str::uuid(), 'name' => 'Branch',
        'token_hash' => hash('sha256', 'secret'), 'is_active' => true,
    ]);
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Visible']);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id]);
    Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other branch']);
    $otherTenant = Tenant::query()->create(['name' => 'Other tenant']);
    Product::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Private']);
    Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Customer', 'type' => 'customer']);
    Party::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Private customer', 'type' => 'customer']);

    $this->getJson('/api/branch-sync/catalog?entity=products')->assertUnauthorized();
    $this->withToken('secret')->getJson('/api/branch-sync/catalog?entity=products')
        ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', $product->id)
        ->assertJsonPath('settings.allow_negative_stock', true)->assertJsonPath('settings.unified_stock', true)
        ->assertJsonCount(3, 'settings')->assertJsonMissingPath('settings.private_setting');
    $this->withToken('secret')->getJson('/api/branch-sync/catalog?entity=customers')
        ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.name', 'Customer');
    $this->withToken('secret')->getJson('/api/branch-sync/catalog?entity=users')->assertUnprocessable();
});

test('catalog pull imports products prices barcodes and customers idempotently', function (): void {
    $page = catalogPage($this->tenant->id, $this->branch->id, [[
        'id' => 100, 'tenant_id' => $this->tenant->id, 'name' => 'Coffee', 'cost_price' => 3,
    ]]);
    $page['prices'] = [['id' => 100, 'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => 100, 'retail_price' => 5, 'stock_quantity' => 40]];
    $page['barcodes'] = [['id' => 100, 'tenant_id' => $this->tenant->id, 'product_id' => 100, 'barcode' => '123456']];
    $customers = catalogPage($this->tenant->id, $this->branch->id, [[
        'id' => 100, 'tenant_id' => $this->tenant->id, 'name' => 'Customer', 'type' => 'customer', 'current_balance' => 10,
    ]]);
    Http::fake(fn ($request) => Http::response($request['entity'] === 'products' ? $page : $customers));

    $this->artisan('branch-sync:pull')->assertSuccessful();
    DB::table('branch_products')->where('id', 100)->update(['stock_quantity' => 38]);
    DB::table('parties')->where('id', 100)->update(['current_balance' => 15]);
    $this->artisan('branch-sync:pull')->assertSuccessful();

    expect(Product::query()->count())->toBe(1)
        ->and(Product::query()->find(100)->name)->toBe('Coffee')
        ->and((float) BranchProduct::query()->find(100)->stock_quantity)->toBe(38.0)
        ->and((float) Party::query()->find(100)->current_balance)->toBe(15.0)
        ->and(DB::table('product_barcodes')->count())->toBe(1);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret'));
});

test('offline upload is retried using the same operation identifier after reconnecting', function (): void {
    $operation = BranchSyncOperation::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'operation_uuid' => (string) Str::uuid(), 'operation_type' => 'sale.upsert',
        'status' => 'pending', 'payload' => ['order' => ['invoice_number' => 'OFF-1']],
    ]);
    Http::fake(['central.example/*' => Http::failedConnection()]);
    $this->artisan('branch-sync:push')->assertSuccessful();
    expect($operation->fresh()->status)->toBe('pending');
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['central.example/*' => Http::response(['accepted' => [$operation->operation_uuid => 'synced']])]);
    $this->artisan('branch-sync:push')->assertSuccessful();
    expect($operation->fresh()->status)->toBe('synced')->and($operation->fresh()->attempts)->toBe(2);
});

test('failed catalog download retains local products', function (): void {
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Local']);
    Http::fake(['central.example/*' => Http::failedConnection()]);
    $this->artisan('branch-sync:pull')->assertFailed();
    expect($product->fresh()->name)->toBe('Local');
});

test('catalog pull rejects a response for another branch before writing', function (): void {
    Http::fake(['central.example/*' => Http::response(catalogPage($this->tenant->id, $this->branch->id + 1, [[
        'id' => 100, 'tenant_id' => $this->tenant->id, 'name' => 'Wrong branch',
    ]]))]);
    $this->artisan('branch-sync:pull')->assertFailed();
    expect(Product::query()->count())->toBe(0);
});

test('catalog pull rejects central mode and insecure connections', function (): void {
    config(['branch-sync.mode' => 'central']);
    $this->artisan('branch-sync:pull')->assertFailed();
    config(['branch-sync.mode' => 'edge', 'branch-sync.server_url' => 'http://central.example']);
    $this->artisan('branch-sync:pull')->assertFailed();
    Http::assertNothingSent();
});

test('catalog pull rolls back a page when an identifier belongs to another tenant', function (): void {
    $otherTenant = Tenant::query()->create(['name' => 'Private tenant']);
    $private = Product::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Private product']);
    $page = catalogPage($this->tenant->id, $this->branch->id, [
        ['id' => 100, 'tenant_id' => $this->tenant->id, 'name' => 'New product'],
        ['id' => $private->id, 'tenant_id' => $this->tenant->id, 'name' => 'Invalid replacement'],
    ]);
    Http::fake(['central.example/*' => Http::response($page)]);
    $this->artisan('branch-sync:pull')->assertFailed();
    expect(Product::query()->count())->toBe(1)->and($private->fresh()->name)->toBe('Private product');
});

test('catalog pull updates prices and retains central soft deletions', function (): void {
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Old name']);
    $price = BranchProduct::query()->create(['tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id, 'product_id' => $product->id, 'retail_price' => 5, 'stock_quantity' => 8]);
    $page = catalogPage($this->tenant->id, $this->branch->id, [[
        'id' => $product->id, 'tenant_id' => $this->tenant->id, 'name' => 'New name', 'deleted_at' => now()->toDateTimeString(),
    ]]);
    $page['prices'] = [['id' => $price->id, 'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id, 'product_id' => $product->id, 'retail_price' => 7, 'stock_quantity' => 20]];
    Http::fake(fn ($request) => Http::response($request['entity'] === 'products'
        ? $page : catalogPage($this->tenant->id, $this->branch->id)));
    $this->artisan('branch-sync:pull')->assertSuccessful();
    expect(Product::query()->find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id)->name)->toBe('New name')
        ->and((float) $price->fresh()->retail_price)->toBe(7.0)
        ->and((float) $price->fresh()->stock_quantity)->toBe(8.0);
});

test('catalog pull follows pages without skipping product identifiers', function (): void {
    $first = catalogPage($this->tenant->id, $this->branch->id, [['id' => 100, 'tenant_id' => $this->tenant->id, 'name' => 'First']]);
    $first['next'] = 100;
    $last = catalogPage($this->tenant->id, $this->branch->id, [['id' => 101, 'tenant_id' => $this->tenant->id, 'name' => 'Last']]);
    Http::fake(fn ($request) => Http::response($request['entity'] === 'customers'
        ? catalogPage($this->tenant->id, $this->branch->id)
        : ((int) $request['after'] === 0 ? $first : $last)));
    $this->artisan('branch-sync:pull')->assertSuccessful();
    expect(Product::query()->count())->toBe(2);
    Http::assertSentCount(3);
});
