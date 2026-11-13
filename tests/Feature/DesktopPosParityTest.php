<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncOperation;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BranchSyncOperationProcessor;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Parity shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Parity branch']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'قهوة عربية', 'cost_price' => 4]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'product_id' => $this->product->id, 'retail_price' => 10, 'stock_quantity' => 5]);
    ProductBarcode::query()->create(['tenant_id' => $this->tenant->id, 'product_id' => $this->product->id, 'barcode' => '123456']);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    Http::preventStrayRequests();
});

function useDesktopEdgeMode(object $context): void
{
    config(['app.desktop' => true, 'branch-sync.mode' => 'edge', 'branch-sync.server_url' => 'https://central.example',
        'branch-sync.token' => 'device-secret', 'branch-sync.tenant_id' => $context->tenant->id,
        'branch-sync.branch_id' => $context->branch->id]);
}

/**
 * The keyboard handlers, POS hooks and script bundle a rendered POS page relies on.
 *
 * @return array<int, string>
 */
function posInteractionHooks(string $html): array
{
    preg_match_all('/(?:x-on:keydown[\w.:-]*|wire:keydown[\w.:-]*|data-pos-[\w-]+|build\/assets\/app-[\w-]+\.js)/', $html, $matches);

    return collect($matches[0])->unique()->sort()->values()->all();
}

test('the desktop point of sale offers every keyboard shortcut, search and cart hook of the web point of sale', function (): void {
    $web = posInteractionHooks($this->get('/dashboard/pos')->assertOk()->getContent());

    useDesktopEdgeMode($this);
    Http::fake(fn () => Http::failedConnection());
    $desktop = posInteractionHooks($this->get('/dashboard/pos')->assertOk()
        ->assertDontSee('data-desktop-central-page', false)->getContent());

    expect($web)->toContain('x-on:keydown.window.f2.prevent', 'x-on:keydown.window.f6.prevent',
        'x-on:keydown.window.f10.prevent', 'x-on:keydown.arrow-down.prevent', 'data-pos-barcode-input',
        'data-pos-product-panel-search', 'data-pos-field')
        ->and(array_values(array_diff($web, $desktop)))->toBe([]);
});

test('offline desktop search, scanning, line total edits and checkout behave like the web point of sale', function (): void {
    useDesktopEdgeMode($this);
    Http::fake(fn () => Http::failedConnection());

    // A single Arabic-normalized match is added straight away, as on the web.
    $component = Livewire::test('pages::tenant.pos.sales')->call('submitProductSearch', 'قهوه');
    expect($component->get('cart'))->toHaveCount(1);

    $lineKey = array_key_first($component->get('cart'));
    expect($component->get('cart.'.$lineKey.'.id'))->toBe($this->product->id);
    $component->call('scanBarcode', '123456');
    expect(collect($component->get('cart'))->sum('quantity'))->toEqual(2);

    $component->call('updateLineTotal', $lineKey, 8);
    expect($component->get('total'))->toEqual(18.0);
    $component->set('paid_amount', 18)->call('checkout')->assertSet('errorMessage', null);

    expect(BranchSyncOperation::query()->where('operation_type', 'sale.upsert')->sole()->status)->toBe('pending');
});

test('a customer phone saved at the desktop point of sale is queued and saved on the central customer', function (): void {
    useDesktopEdgeMode($this);
    Http::fake(fn () => Http::failedConnection());
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'زبون الهاتف', 'type' => 'customer']);

    Livewire::test('pages::tenant.pos.sales')->call('selectCustomer', $customer->id)
        ->set('customerPhoneInput', '0599123456')->call('saveCustomerPhone')
        ->assertHasNoErrors()->assertSet('pendingSyncOperations', 1);

    $operation = BranchSyncOperation::query()->sole();
    expect($operation->operation_type)->toBe('customer.phone_updated')
        ->and($operation->payload['customer'])->toBe(['id' => $customer->id, 'phone' => '0599123456']);

    $customer->forceFill(['phone' => null])->save();
    app(BranchSyncOperationProcessor::class)->process($operation);
    expect($customer->fresh()->phone)->toBe('0599123456');
});

test('the web point of sale does not queue customer phone changes for sync', function (): void {
    $customer = Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'زبون الويب', 'type' => 'customer']);

    Livewire::test('pages::tenant.pos.sales')->call('selectCustomer', $customer->id)
        ->set('customerPhoneInput', '0599000000')->call('saveCustomerPhone');

    expect($customer->fresh()->phone)->toBe('0599000000')
        ->and(BranchSyncOperation::query()->count())->toBe(0);
});
