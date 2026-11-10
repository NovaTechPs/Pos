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
use Illuminate\Support\Facades\DB;
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
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', $search)
        ->assertSee('data-pos-product-grid', false)
        ->assertSee('الكمية');
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

test('both POS search controls find products from the first typed letter', function (string $search): void {
    $product = searchablePosProduct($this, 'غسالة صغيرة');
    searchablePosProduct($this, 'صحن كبير');

    $component = Livewire::test('pages::tenant.pos.sales')
        ->set('productSearchQuery', $search)
        ->assertDontSee('لم نجد صنفاً مطابقاً');

    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$product->id]);

    $component->set('inlineSearchQuery', $search);

    expect(array_column($component->get('inlineSearchResults'), 'id'))->toBe([$product->id]);
})->with(['غ', 'غس', 'غسا', 'غسال']);

test('POS product results fit the panel and add by selecting a wrapped result row', function (): void {
    $template = file_get_contents(resource_path('views/pages/tenant/pos/partials/quick-product-results.blade.php'));

    expect($template)->toContain('table-fixed')
        ->and($template)->toContain('data-pos-product data-product-index')
        ->and($template)->toContain('break-words')
        ->and($template)->toContain('break-all')
        ->and($template)->not->toContain('min-w-[920px]')
        ->and($template)->not->toContain('quickProductQuantities')
        ->and($template)->not->toContain('wire:click="addSearchedProduct');
});

test('F10 and product search reset keyboard navigation to the first result', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales');
    $cartKeyboard = file_get_contents(resource_path('js/pos-cart-keyboard.js'));

    expect($component->html())->toContain("panel.dataset.selectedProductIndex = '0'")
        ->and($component->html())->toContain("dataset.selectedProductIndex = '0'")
        ->and($cartKeyboard)->toContain('[data-pos-products-panel], [data-pos-product-panel-search], [data-pos-product]');
});
test('Enter does not implicitly submit forms anywhere in the app', function (): void {
    $script = file_get_contents(resource_path('js/prevent-enter-submit.js'));

    expect($script)->toContain("event.key !== 'Enter'")
        ->and($script)->toContain("event.target.closest('form')")
        ->and($script)->toContain('event.preventDefault()')
        ->and($script)->toContain('/^(search|applyFilters|filter|preview|calculate|lookup)/i');
});
test('cart rows with equivalent product names share the same background color', function (): void {
    $first = searchablePosProduct($this, 'إبريق شفاف');
    $second = searchablePosProduct($this, 'ابريق شفاف');

    $html = Livewire::test('pages::tenant.pos.sales')->set('mergeSimilarProducts', false)
        ->call('selectInlineProduct', $first->id)->call('selectInlineProduct', $second->id)->html();
    preg_match_all('/data-pos-product-color="(\\d+)" style="--pos-cart-hue: ([^"]+)"/', $html, $matches, PREG_SET_ORDER);
    $hues = [];
    foreach ($matches as [, $productId, $hue]) {
        $hues[(int) $productId] = $hue;
    }

    expect($hues[$first->id] ?? null)->toBe($hues[$second->id] ?? null);
});
test('POS text search returns up to twenty-four relevant matches', function (): void {
    for ($index = 1; $index <= 28; $index++) {
        searchablePosProduct($this, 'Coffee type '.$index);
    }

    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', 'coffee');

    expect($component->get('quickProducts'))->toHaveCount(24);
});
test('product list search includes one-character product name queries', function (): void {
    $product = searchablePosProduct($this, 'إبريق زجاج');

    Livewire::test('pages::tenant.products.index')->set('search', 'ب')
        ->assertViewHas('products', fn ($products): bool => $products->contains('id', $product->id));
});
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
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', $search)
        ->assertSee('data-pos-product-grid', false)
        ->assertSee('الكمية');
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

test('POS search ranks a whole-word match inside the name above a partial-word match', function (): void {
    $partial = searchablePosProduct($this, 'وعاء زجاجي جاطي');
    $word = searchablePosProduct($this, 'وعاء زجاجي جاط قهوة');
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', 'جاط');

    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$word->id, $partial->id]);
});

test('POS numeric search words do not match longer numbers inside product names', function (): void {
    $matching = searchablePosProduct($this, 'كاسات كرتون 4 اوز 40 قطعة');
    searchablePosProduct($this, 'كاسات كرتون 7 اوز 40 قطعة');
    searchablePosProduct($this, 'كاسات كرتون 14 اوز 40 قطعة');

    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', '4 اوز');

    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$matching->id]);

    $component->set('inlineSearchQuery', '4 اوز');

    expect(array_column($component->get('inlineSearchResults'), 'id'))->toBe([$matching->id]);

    config(['branch-sync.mode' => 'central']);
    BranchSyncDevice::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'device_uuid' => (string) Str::uuid(),
        'name' => 'Numeric search desktop',
        'token_hash' => hash('sha256', 'numeric-search-device'),
        'is_active' => true,
    ]);

    $response = $this->withToken('numeric-search-device')->getJson('/api/branch-sync/pos-catalog?'.http_build_query([
        'entity' => 'products',
        'search' => '4 اوز',
    ]))->assertOk();

    expect(array_column($response->json('rows'), 'id'))->toBe([$matching->id]);
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

test('products store their normalized search name when saved', function (): void {
    $product = searchablePosProduct($this, 'إِبْرِيـق زجاجي');

    expect($product->fresh()->search_name)->toBe('ابريق زجاجي');
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

test('selecting a searched product clears both search controls and restores the catalog', function (): void {
    $coffee = searchablePosProduct($this, 'Coffee');
    $tea = searchablePosProduct($this, 'Tea');
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $component = Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', 'coffee');
    expect(array_column($component->get('quickProducts'), 'id'))->toBe([$coffee->id]);
    $component->call('selectInlineProduct', $coffee->id)
        ->assertSet('productSearchQuery', '')->assertSet('inlineSearchQuery', '')
        ->assertDispatched('pos-search-cleared')->assertDispatched('pos-focus-barcode');
    expect(array_column($component->get('quickProducts'), 'id'))->toContain($coffee->id, $tea->id)
        ->and(array_column($component->get('cart'), 'id'))->toBe([$coffee->id]);
});

test('cart item rows can be focused and navigated without focusing the price field', function (): void {
    $product = searchablePosProduct($this, 'Cart navigation item');
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $product->id);
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $row = (new DOMXPath($dom))->query('//*[@data-pos-cart-line]')->item(0);
    $keyboard = file_get_contents(resource_path('js/pos-cart-keyboard.js'));
    $styles = file_get_contents(resource_path('css/pos.css'));

    expect($row)->not->toBeNull()
        ->and($row->getAttribute('tabindex'))->toBe('0')
        ->and($keyboard)->toContain("cartRow && !current && ['ArrowUp', 'ArrowDown'].includes(key)")
        ->and($keyboard)->toContain('focusCartRow(targetRow)')
        ->and($styles)->toContain('pos-cart-row-focused > td');
});

test('pos search shows product rows and adds the requested quantity', function (): void {
    $coffee = searchablePosProduct($this, 'Coffee beans', 'COFFEE-10', '1234567890123');
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);

    $component = Livewire::test('pages::tenant.pos.sales')
        ->set('productSearchQuery', 'coffee')
        ->assertSee('data-pos-product-results', false)
        ->assertSee('رقم الصنف')
        ->assertSee('سعر البيع')
        ->assertSee('الباركود')
        ->assertSee('المتوفر')
        ->assertSee('الكمية')
        ->set('quickProductQuantities.'.$coffee->id, 3)
        ->call('addSearchedProduct', $coffee->id)
        ->assertSet('productSearchQuery', '');

    $item = array_values($component->get('cart'))[0];
    expect($item['id'])->toBe($coffee->id)
        ->and((float) $item['quantity'])->toBe(3.0)
        ->and((float) $item['subtotal'])->toBe(45.0);
});

test('failed addition preserves search and does not dispatch a successful selection', function (): void {
    $coffee = searchablePosProduct($this, 'Coffee');
    Livewire::test('pages::tenant.pos.sales')->set('productSearchQuery', 'coffee')
        ->call('selectInlineProduct', $coffee->id)->assertSet('productSearchQuery', 'coffee')
        ->assertSet('cart', [])->assertNotDispatched('pos-search-cleared');
});

test('repeated search in one request reuses results while explicit refresh reads again', function (): void {
    searchablePosProduct($this, 'Coffee small');
    searchablePosProduct($this, 'Coffee large');
    $instance = Livewire::test('pages::tenant.pos.sales')->instance();
    $instance->productSearchQuery = 'coffee';
    $reads = 0;
    DB::listen(function ($query) use (&$reads): void {
        if (preg_match('/^select .*from ["`]products["`]/i', $query->sql)) {
            $reads++;
        }
    });
    $instance->updatedProductSearchQuery();
    $instance->submitProductSearch('coffee');
    expect($reads)->toBe(2);
    $instance->loadQuickProducts(true);
    expect($reads)->toBe(3);
});
