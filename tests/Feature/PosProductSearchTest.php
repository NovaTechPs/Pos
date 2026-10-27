<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncDevice;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PosProductSearch;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Search shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Search branch']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
});

function searchablePosProduct(object $context, string $name, ?string $number = null, ?string $barcode = null): Product
{
    $product = Product::query()->create(['tenant_id' => $context->tenant->id, 'name' => $name, 'product_number' => $number]);
    BranchProduct::query()->create(['tenant_id' => $context->tenant->id, 'branch_id' => $context->branch->id,
        'product_id' => $product->id, 'retail_price' => 15, 'stock_quantity' => 20]);
    if ($barcode !== null) {
        ProductBarcode::query()->create(['tenant_id' => $context->tenant->id, 'product_id' => $product->id, 'barcode' => $barcode]);
    }

    return $product;
}

test('pos search understands Arabic spelling and unordered words in both search controls', function (string $name, string $search): void {
    $product = searchablePosProduct($this, $name);
    searchablePosProduct($this, 'صنف غير مطابق');
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', $search);
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$product->id]);
    $component->set('inlineSearchQuery', $search);
    expect(array_column($component->get('inlineSearchResults'), 'id'))->toBe([$product->id]);
})->with([
    ['قَهْوَة تُرْكِيَّة أصلية', 'قهوه تركيه'],
    ['إبريق زجاج كبير', 'كبير ابريق'],
    ['أكواب زجاج 5 قطع', 'قطع اكواب'],
    ['وعاء على الطاولة', 'علي طاوله'],
    ['ســكر أبيض ناعم', 'ناعم سكر'],
    ['Premium Long Grain Rice', 'rice PREMIUM'],
]);

test('exact barcode and product number outrank name matches', function (): void {
    $name = searchablePosProduct($this, '123456 coffee');
    $number = searchablePosProduct($this, 'Coffee by code', '123456');
    $barcode = searchablePosProduct($this, 'Z coffee by barcode', null, '123456');
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', '١٢٣٤٥٦');
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$barcode->id, $number->id, $name->id]);
});

test('both pos search controls find full and partial product numbers', function (string $search): void {
    $product = searchablePosProduct($this, 'صنف تجريبي', 'ITEM-12345');
    searchablePosProduct($this, 'صنف آخر', 'OTHER-987');
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', $search);
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$product->id]);
    $component->set('inlineSearchQuery', $search);
    expect(array_column($component->get('inlineSearchResults'), 'id'))->toBe([$product->id]);
})->with(['ITEM-12345', 'EM-123', '٢٣٤']);

test('exact names outrank prefixes which outrank word matches', function (): void {
    $word = searchablePosProduct($this, 'علبة قهوة');
    $prefix = searchablePosProduct($this, 'قهوة عربية');
    $exact = searchablePosProduct($this, 'قهوة');
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', 'قهوه');
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$exact->id, $prefix->id, $word->id]);
});

test('search wildcards are literal and cannot expand results', function (): void {
    $literal = searchablePosProduct($this, 'Discount 50%', 'AB_12');
    searchablePosProduct($this, 'Discount 500', 'ABX12');
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', '50%');
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$literal->id]);
    $component->set('productSearchQuery', 'AB_1');
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$literal->id]);
    $component->set('productSearchQuery', "' OR 1=1 --")->assertSet('quickProducts', []);
});

test('search never exposes unassigned products deleted products or another tenant', function (): void {
    $visible = searchablePosProduct($this, 'Visible coffee');
    Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Unassigned coffee']);
    searchablePosProduct($this, 'Deleted coffee')->delete();
    $otherTenant = Tenant::query()->create(['name' => 'Other shop']);
    Product::query()->create(['tenant_id' => $otherTenant->id, 'name' => 'Private coffee']);
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', 'coffee');
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$visible->id]);
});

test('central live search matches the local search relevance and Arabic rules', function (): void {
    config(['branch-sync.mode' => 'central']);
    $inside = searchablePosProduct($this, 'علبة قَهْوَة تركية');
    $prefix = searchablePosProduct($this, 'قهوة تركية');
    BranchSyncDevice::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'device_uuid' => (string) Str::uuid(), 'name' => 'Search desktop', 'token_hash' => hash('sha256', 'search-device'), 'is_active' => true]);
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', 'قهوه تركيه');
    $response = $this->withToken('search-device')->getJson('/api/branch-sync/pos-catalog?'.http_build_query([
        'entity' => 'products', 'search' => 'قهوه تركيه',
    ]))->assertOk();
    expect(array_column($response->json('rows'), 'id'))->toBe([$prefix->id, $inside->id])
        ->and(array_column($component->get('quickProducts'), 'id'))->toBe(array_column($response->json('rows'), 'id'));
});

test('normalization preserves codes and translates Arabic and Persian digits', function (): void {
    expect(app(PosProductSearch::class)->normalize('  إِبْرِيـق   ۱۲٣  '))->toBe('ابريق 123');
});

test('Enter searches the current input before adding a unique result instead of a stale result', function (): void {
    $old = searchablePosProduct($this, 'Old coffee');
    $current = searchablePosProduct($this, 'New tea');
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', 'coffee');
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$old->id]);
    $component->call('submitProductSearch', 'tea');
    expect(array_column($component->get('cart'), 'id'))->toBe([$current->id]);
});

test('Enter with multiple matches focuses results without choosing a product automatically', function (): void {
    searchablePosProduct($this, 'Coffee small');
    searchablePosProduct($this, 'Coffee large');
    Livewire::test('pages::tenant.pos.sales')->call('submitProductSearch', 'coffee')
        ->assertSet('cart', [])->assertDispatched('pos-search-results-ready');
});
