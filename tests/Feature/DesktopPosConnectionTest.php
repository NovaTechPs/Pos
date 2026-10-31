<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncDevice;
use App\Models\BranchSyncOperation;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\DesktopPosConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Desktop shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Desktop branch']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cached coffee', 'cost_price' => 4]);
    $this->stock = BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'retail_price' => 10, 'stock_quantity' => 5]);
    ProductBarcode::query()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'barcode' => '123456']);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    config(['app.desktop' => true, 'branch-sync.mode' => 'edge', 'branch-sync.server_url' => 'https://central.example',
        'branch-sync.token' => 'device-secret', 'branch-sync.tenant_id' => $this->tenant->id, 'branch-sync.branch_id' => $this->branch->id]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    Http::preventStrayRequests();
});

/** @return array<string, mixed> */
function desktopCatalogPage(object $context, float $price = 12, float $quantity = 25): array
{
    return [
        'version' => 1, 'entity' => 'products', 'tenant_id' => $context->tenant->id, 'branch_id' => $context->branch->id,
        'rows' => [['id' => $context->product->id, 'tenant_id' => $context->tenant->id, 'name' => 'Central coffee', 'cost_price' => 4]],
        'settings' => ['allow_negative_stock' => false, 'unified_stock' => false, 'merge_similar_products' => false],
        'categories' => [], 'offers' => [],
        'barcodes' => [['id' => 500, 'tenant_id' => $context->tenant->id, 'product_id' => $context->product->id, 'barcode' => '123456']],
        'prices' => [['id' => $context->stock->id, 'tenant_id' => $context->tenant->id, 'branch_id' => $context->branch->id,
            'product_id' => $context->product->id, 'retail_price' => $price, 'stock_quantity' => $quantity]],
        'available_stock' => [$context->product->id => $quantity],
    ];
}

test('desktop reads central prices stock and names into its offline cache', function (): void {
    Http::fake(fn () => Http::response(desktopCatalogPage($this)));
    $connection = app(DesktopPosConnection::class);

    expect($connection->read($this->tenant->id, $this->branch->id))->toBe([$this->product->id])
        ->and($connection->state)->toBe('online')
        ->and($this->product->fresh()->name)->toBe('Central coffee')
        ->and((float) $this->stock->fresh()->retail_price)->toBe(12.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(25.0);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer device-secret'));
});

test('barcode addition checks the server once and checkout avoids reloading the entire catalog', function (): void {
    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            return Http::response(['accepted' => collect($request['operations'])->pluck('operation_uuid')
                ->mapWithKeys(fn (string $uuid): array => [$uuid => 'synced'])->all()]);
        }

        return Http::response(desktopCatalogPage($this, 10));
    });
    $component = Livewire::test('pages::tenant.pos.sales');
    $beforeScan = count(Http::recorded());
    $component->set('barcode', '123456')->call('scanBarcode');
    expect(count(Http::recorded()) - $beforeScan)->toBe(1)
        ->and($component->get('cart'))->toHaveCount(1);
    $beforeCheckout = count(Http::recorded());
    $component->set('paid_amount', 10)->call('checkout')->assertSet('errorMessage', null);
    expect(count(Http::recorded()) - $beforeCheckout)->toBe(2)
        ->and(BranchSyncOperation::query()->where('operation_type', 'sale.upsert')->first()->status)->toBe('synced');
});

test('catalog schema inspection is bounded by tables instead of product count', function (): void {
    $page = desktopCatalogPage($this);
    foreach (range(1, 30) as $index) {
        $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Coffee '.$index]);
        $stock = BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'product_id' => $product->id, 'retail_price' => 10, 'stock_quantity' => 5]);
        $page['rows'][] = ['id' => $product->id, 'tenant_id' => $this->tenant->id, 'name' => $product->name];
        $page['prices'][] = ['id' => $stock->id, 'tenant_id' => $this->tenant->id,
            'branch_id' => $this->branch->id, 'product_id' => $product->id, 'retail_price' => 10, 'stock_quantity' => 5];
    }
    Http::fake(fn () => Http::response($page));
    DB::enableQueryLog();
    try {
        expect(app(DesktopPosConnection::class)->read($this->tenant->id, $this->branch->id))->toHaveCount(31);
        $queries = collect(DB::getQueryLog())->pluck('query');
        expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'pragma_table_xinfo'))->count())->toBeLessThanOrEqual(3)
            ->and($queries->filter(fn (string $sql): bool => str_starts_with($sql, 'update "branch_products"'))->count())->toBe(1);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

test('desktop switches to cached products without losing the current cart and reconnects automatically', function (): void {
    $online = true;
    Http::fake(function () use (&$online) {
        return $online ? Http::response(desktopCatalogPage($this)) : Http::failedConnection();
    });
    $component = Livewire::test('pages::tenant.pos.sales')->assertSet('connectionState', 'online')
        ->call('selectInlineProduct', $this->product->id);
    $cart = $component->get('cart');
    $online = false;
    $component->call('refreshLiveData')->assertSet('connectionState', 'offline')->assertSet('cart', $cart);
    expect(data_get($component->get('quickProducts'), '0.retail_price'))->toBe(12.0);
    $online = true;
    $component->call('refreshLiveData')->assertSet('connectionState', 'online')->assertSet('cart', $cart);
});

test('pending uploads do not add a blocking upload request to every catalog lookup', function (): void {
    BranchSyncOperation::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'operation_uuid' => (string) Str::uuid(), 'operation_type' => 'sale.upsert', 'status' => 'pending', 'payload' => []]);
    Http::fake(fn () => Http::response(desktopCatalogPage($this)));

    $connection = app(DesktopPosConnection::class);
    expect($connection->read($this->tenant->id, $this->branch->id, filters: ['ids' => [$this->product->id]]))
        ->toBe([$this->product->id])->and($connection->state)->toBe('pending');
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(5.0);
});

test('desktop sends sales immediately and records their server acknowledgment', function (): void {
    $accepted = [];
    Http::fake(function ($request) use (&$accepted) {
        if ($request->method() === 'POST') {
            foreach ($request['operations'] as $operation) {
                $accepted[$operation['operation_uuid']] = 'synced';
            }

            return Http::response(['accepted' => $accepted]);
        }

        return Http::response(desktopCatalogPage($this, 10));
    });
    Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->set('paid_amount', 10)->call('checkout')->assertSet('errorMessage', null)->assertSet('pendingSyncOperations', 0);

    expect(Order::query()->count())->toBe(1)
        ->and(BranchSyncOperation::query()->where('operation_type', 'sale.upsert')->first()->status)->toBe('synced')
        ->and($accepted)->toHaveCount(1);
});

test('checkout prioritizes the current sale while preserving earlier stock returns', function (bool $hasEarlierReturn): void {
    $old = BranchSyncOperation::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'operation_uuid' => (string) Str::uuid(), 'operation_type' => 'sale.upsert', 'status' => 'pending',
        'payload' => $hasEarlierReturn ? ['order' => ['type' => 'return']] : []]);
    $sent = [];
    Http::fake(function ($request) use (&$sent) {
        if ($request->method() === 'POST') {
            $sent = collect($request['operations'])->pluck('operation_uuid')->all();

            return Http::response(['accepted' => array_fill_keys($sent, 'synced')]);
        }

        return Http::response(desktopCatalogPage($this, 10));
    });
    Livewire::test('pages::tenant.pos.sales')->assertSee('data-pos-save-overlay', false)
        ->call('selectInlineProduct', $this->product->id)->set('paid_amount', 10)
        ->call('checkout')->assertSet('errorMessage', null);
    $current = BranchSyncOperation::query()->whereKeyNot($old->id)->where('operation_type', 'sale.upsert')->firstOrFail();
    expect($sent)->toBe($hasEarlierReturn ? [$old->operation_uuid, $current->operation_uuid] : [$current->operation_uuid])
        ->and($current->status)->toBe('synced')
        ->and($old->fresh()->status)->toBe($hasEarlierReturn ? 'synced' : 'pending')
        ->and($old->fresh()->attempts)->toBe($hasEarlierReturn ? 1 : 0);
})->with([false, true]);

test('lost sale responses retain the same operation uuid and never create a second local sale', function (): void {
    $online = false;
    $received = [];
    Http::fake(function ($request) use (&$online, &$received) {
        if ($request->method() === 'POST') {
            foreach ($request['operations'] as $operation) {
                $received[] = $operation['operation_uuid'];
            }
            if (! $online) {
                return Http::failedConnection();
            }

            return Http::response(['accepted' => array_fill_keys($received, 'synced')]);
        }

        return $online ? Http::response(desktopCatalogPage($this, 10, 4)) : Http::failedConnection();
    });
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->set('paid_amount', 10)->call('checkout')->assertSet('errorMessage', null)->assertSet('pendingSyncOperations', 1);
    $operation = BranchSyncOperation::query()->where('operation_type', 'sale.upsert')->firstOrFail();
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(4.0);
    $online = true;
    $component->call('refreshLiveData')->assertSet('connectionState', 'online')->assertSet('pendingSyncOperations', 0);

    expect(Order::query()->count())->toBe(1)->and($operation->fresh()->status)->toBe('synced')
        ->and(array_unique($received))->toBe([$operation->operation_uuid])
        ->and(count($received))->toBeGreaterThanOrEqual(2)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(4.0);
});

test('unconfirmed and conflicting sales protect local quantities from central snapshots', function (string $status): void {
    BranchSyncOperation::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'operation_uuid' => (string) Str::uuid(), 'operation_type' => 'sale.upsert', 'status' => $status, 'payload' => []]);
    Http::fake(fn ($request) => $request->method() === 'POST'
        ? Http::response(['accepted' => []]) : Http::response(desktopCatalogPage($this)));
    $connection = app(DesktopPosConnection::class);
    $connection->read($this->tenant->id, $this->branch->id);

    expect($connection->state)->toBe('pending')->and((float) $this->stock->fresh()->stock_quantity)->toBe(5.0)
        ->and((float) $this->stock->fresh()->retail_price)->toBe(12.0);
})->with(['pending', 'conflict']);

test('server price changes retain the cart for review before completing checkout', function (): void {
    $price = 10;
    Http::fake(function () use (&$price) {
        return Http::response(desktopCatalogPage($this, $price));
    });
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)->set('paid_amount', 10);
    $price = 15;
    $component->call('checkout');

    expect($component->get('errorMessage'))->toContain('تغير سعر')
        ->and(data_get(array_values($component->get('cart')), '0.price'))->toBe(15.0)
        ->and(Order::query()->count())->toBe(0);
});

test('desktop clearly identifies a server that needs upgrading or a revoked token', function (int $status, string $state): void {
    Http::fake(fn () => Http::response([], $status));
    Livewire::test('pages::tenant.pos.sales')->assertSet('connectionState', $state);
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(5.0);
})->with([[404, 'update_required'], [401, 'unauthorized'], [503, 'unavailable']]);

test('live catalog rejects data belonging to another tenant without altering the cache', function (): void {
    $page = desktopCatalogPage($this);
    $page['prices'][0]['tenant_id'] = $this->tenant->id + 1;
    Http::fake(fn () => Http::response($page));
    $connection = app(DesktopPosConnection::class);

    expect($connection->read($this->tenant->id, $this->branch->id))->toBeNull()
        ->and($connection->state)->toBe('unavailable')
        ->and($this->product->fresh()->name)->toBe('Cached coffee')
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(5.0);
});

test('live catalog endpoint enforces branch scope and excludes deleted products', function (): void {
    config(['branch-sync.mode' => 'central']);
    BranchSyncDevice::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'device_uuid' => (string) Str::uuid(), 'name' => 'Desktop', 'token_hash' => hash('sha256', 'device-secret'), 'is_active' => true]);
    Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Unassigned product']);
    $deleted = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Deleted coffee']);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $deleted->id]);
    $deleted->delete();
    $this->getJson('/api/branch-sync/pos-catalog?entity=products')->assertUnauthorized();
    $this->withToken('device-secret')->getJson('/api/branch-sync/pos-catalog?entity=products&barcode=123456')
        ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.id', $this->product->id)
        ->assertJsonPath('prices.0.stock_quantity', 5);
    $this->withToken('device-secret')->getJson('/api/branch-sync/pos-catalog?entity=products&search=Deleted')
        ->assertOk()->assertJsonCount(0, 'rows');
    $this->withToken('device-secret')->getJson('/api/branch-sync/pos-catalog?entity=products&search=Missing')
        ->assertOk()->assertJsonCount(0, 'rows');
});

test('live customer lookup refreshes balances and excludes other branches', function (): void {
    config(['branch-sync.mode' => 'central']);
    BranchSyncDevice::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'device_uuid' => (string) Str::uuid(), 'name' => 'Desktop', 'token_hash' => hash('sha256', 'device-secret'), 'is_active' => true]);
    Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Shared customer', 'type' => 'customer', 'current_balance' => 50]);
    $other = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
    Party::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $other->id, 'name' => 'Private customer', 'type' => 'customer']);
    $this->withToken('device-secret')->getJson('/api/branch-sync/pos-catalog?entity=customers&search=customer')
        ->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.name', 'Shared customer');
});

test('central offers and shared stock remain usable after disconnecting', function (): void {
    $online = true;
    Http::fake(function () use (&$online) {
        if (! $online) {
            return Http::failedConnection();
        }
        $page = desktopCatalogPage($this, 10, 0);
        $page['settings']['unified_stock'] = true;
        $page['available_stock'] = [$this->product->id => 20];
        $page['offers'] = [['id' => 600, 'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'product_id' => $this->product->id, 'offer_quantity' => 2, 'offer_price' => 15,
            'start_at' => now()->subDay()->toDateTimeString(), 'is_active' => true]];

        return Http::response($page);
    });
    $component = Livewire::test('pages::tenant.pos.sales')->assertSet('connectionState', 'online')
        ->call('selectInlineProduct', $this->product->id)->call('selectInlineProduct', $this->product->id);
    expect(data_get($component->get('quickProducts'), '0.has_offer'))->toBeTrue();
    $online = false;
    $component->set('paid_amount', 15)->call('checkout')->assertSet('errorMessage', null);

    expect((float) Order::query()->firstOrFail()->total)->toBe(15.0)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(-2.0)
        ->and(json_decode(TenantSetting::get($this->tenant->id, 'desktop_shared_stock_'.$this->branch->id), true)[$this->product->id])->toBe(18);
});

test('online empty results exclude stale local products and do not permit selling removed products', function (): void {
    Http::fake(function () {
        $page = desktopCatalogPage($this);
        $page['rows'] = $page['prices'] = $page['barcodes'] = $page['available_stock'] = [];

        return Http::response($page);
    });
    $component = Livewire::test('pages::tenant.pos.sales')->assertSet('quickProducts', [])
        ->call('selectInlineProduct', $this->product->id);

    expect($component->get('cart'))->toBe([])
        ->and($component->get('errorMessage'))->toContain('لم يعد متاحاً');
});

test('server rejects a sale without claiming that it was confirmed online', function (): void {
    Http::fake(function ($request) {
        if ($request->method() === 'POST') {
            $operation = $request['operations'][0];

            return Http::response(['accepted' => [$operation['operation_uuid'] => 'conflict'],
                'messages' => [$operation['operation_uuid'] => 'Stock changed on server']]);
        }

        return Http::response(desktopCatalogPage($this, 10));
    });
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)
        ->set('paid_amount', 10)->call('checkout')->assertSet('conflictedSyncOperations', 1);

    expect(Order::query()->count())->toBe(1)->and($component->get('errorMessage'))->toContain('رفض مزامنتها')
        ->and($component->get('successMessage'))->not->toContain('على السيرفر')
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(24.0);
});

test('central customer data is cached and refreshed without overwriting pending balances', function (): void {
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'name' => 'Cached customer', 'type' => 'customer', 'current_balance' => 15]);
    Http::fake(function () use ($customer) {
        $page = desktopCatalogPage($this);
        $page['entity'] = 'customers';
        $page['rows'] = [['id' => $customer->id, 'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
            'name' => 'Central customer', 'type' => 'customer', 'current_balance' => 50]];
        $page['prices'] = $page['barcodes'] = $page['available_stock'] = [];

        return Http::response($page);
    });
    $connection = app(DesktopPosConnection::class);
    expect($connection->read($this->tenant->id, $this->branch->id, 'customers'))->toBe([$customer->id])
        ->and((float) $customer->fresh()->current_balance)->toBe(50.0);
    $customer->update(['current_balance' => 60]);
    BranchSyncOperation::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'operation_uuid' => (string) Str::uuid(), 'operation_type' => 'sale.upsert', 'status' => 'conflict', 'payload' => []]);
    $connection->read($this->tenant->id, $this->branch->id, 'customers');

    expect((float) $customer->fresh()->current_balance)->toBe(60.0);
});
