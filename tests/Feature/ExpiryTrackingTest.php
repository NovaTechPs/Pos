<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\ExpiryWarnings;
use App\Services\ReportingService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Grocery']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'حليب طويل الأجل', 'cost_price' => 3]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 0, 'retail_price' => 5]);
    $this->buy = fn (?string $expiry) => tap(Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)->set('selectedQuantity', 12)->set('selectedPurchasePrice', 3)->call('commitRowToCart'),
        fn ($page) => $expiry === null ? null : $page->call('updateItemExpiry', array_key_first($page->get('cart')), $expiry))
        ->call('saveInvoice')->assertSet('errorMessage', null);
    $this->filters = ['from' => today()->toDateString(), 'to' => today()->addDays(30)->toDateString(), 'party_id' => null, 'product_id' => null, 'search' => '',
        'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all', 'source' => '', 'sale_kind' => ''];
});

test('expiry dates stay hidden until the store turns tracking on', function (): void {
    Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)->call('commitRowToCart')
        ->assertDontSee('data-purchase-expiry', false);

    TenantSetting::setBool($this->tenant->id, 'track_expiry', true);
    request()->attributes->remove('tenant_settings_values_'.$this->tenant->id);

    Livewire::test('pages::tenant.purchases.invoices')
        ->call('selectProduct', $this->product->id)->call('commitRowToCart')
        ->assertSee('data-purchase-expiry', false)->assertSee('الصلاحية');
});

test('a purchase line keeps its expiry date, which survives editing the invoice', function (): void {
    TenantSetting::setBool($this->tenant->id, 'track_expiry', true);
    ($this->buy)(today()->addDays(10)->toDateString());

    expect((string) DB::table('purchase_items')->value('expiry_date'))->toStartWith(today()->addDays(10)->toDateString());

    $page = Livewire::test('pages::tenant.purchases.invoices')->call('loadPurchase', Purchase::query()->sole()->id);
    $key = array_key_first($page->get('cart'));
    expect($page->get('cart')[$key]['expiry_date'])->toBe(today()->addDays(10)->toDateString());

    $page->call('editLoadedInvoice')->call('updateItemExpiry', $key, 'not-a-date')->assertSet("cart.{$key}.expiry_date", null)
        ->call('updateItemExpiry', $key, today()->addDays(20)->toDateString())->call('saveInvoice');
    expect((string) DB::table('purchase_items')->value('expiry_date'))->toStartWith(today()->addDays(20)->toDateString());
});

test('the expiry report and menu alert list batches that expired or expire soon while the product is in stock', function (): void {
    TenantSetting::setBool($this->tenant->id, 'track_expiry', true);
    ($this->buy)(today()->subDays(2)->toDateString());
    ($this->buy)(today()->addDays(15)->toDateString());
    ($this->buy)(today()->addDays(90)->toDateString());
    ($this->buy)(null);

    $definition = (new ReportingService($this->owner, $this->branch->id))->prepare('expiring', $this->filters);
    $rows = $definition['query']->get();
    expect($rows->pluck('expiry_status')->all())->toBe(['منتهية', 'تنتهي قريباً'])
        ->and($definition['summary'])->toBe(['دفعات منتهية' => 1, 'دفعات تنتهي حتى تاريخ النهاية' => 1]);

    Livewire::test('stock-alerts')->assertSee('صلاحية منتهية أو قريبة')->assertSee(route('tenant.reports', ['report' => 'expiring']), false);
    Livewire::withQueryParams(['report' => 'expiring'])->test('pages::tenant.reports.center')
        ->assertSet('to', today()->addDays(30)->toDateString());

    // Once the product is sold out the batches no longer need attention.
    BranchProduct::query()->update(['stock_quantity' => 0]);
    expect((new ReportingService($this->owner, $this->branch->id))->prepare('expiring', $this->filters)['query']->count())->toBe(0);
});

test('the store settings turn expiry tracking on', function (): void {
    Livewire::test('pages::settings.store')->assertSet('trackExpiry', false)
        ->set('trackExpiry', true)->call('save')->assertHasNoErrors();

    expect(TenantSetting::getBool($this->tenant->id, 'track_expiry'))->toBeTrue();
});

test('one product can carry several expiry dates, one per purchase line, and replacing a line keeps its date', function (): void {
    TenantSetting::setBool($this->tenant->id, 'track_expiry', true);
    $page = Livewire::test('pages::tenant.purchases.invoices');
    foreach ([today()->addDays(5), today()->addDays(25)] as $expiry) {
        $page->call('selectProduct', $this->product->id)->set('selectedQuantity', 6)->set('selectedPurchasePrice', 3)->call('commitRowToCart');
        $page->call('updateItemExpiry', array_key_last($page->get('cart')), $expiry->toDateString());
    }
    $firstLine = array_key_first($page->get('cart'));
    $page->call('editCartItem', $firstLine)->set('selectedQuantity', 8)->call('commitRowToCart')
        ->assertSet("cart.{$firstLine}.expiry_date", today()->addDays(5)->toDateString());
    $page->call('saveInvoice')->assertSet('errorMessage', null);

    expect(DB::table('purchase_items')->orderBy('id')->pluck('expiry_date')->map(fn ($date): string => substr((string) $date, 0, 10))->all())
        ->toBe([today()->addDays(5)->toDateString(), today()->addDays(25)->toDateString()])
        ->and((new ReportingService($this->owner, $this->branch->id))->prepare('expiring', $this->filters)['query']->count())->toBe(2);
});

test('the point of sale flags products with an expired or soon-expiring batch in search results and the cart', function (): void {
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->owner->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $fresh = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'حليب طازج', 'cost_price' => 3]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $fresh->id, 'stock_quantity' => 0, 'retail_price' => 5]);
    TenantSetting::setBool($this->tenant->id, 'track_expiry', true);
    ($this->buy)(today()->subDay()->toDateString());
    $this->product = $fresh;
    ($this->buy)(today()->addDays(3)->toDateString());
    $expiredProductId = Product::query()->where('name', 'حليب طويل الأجل')->value('id');

    $warnings = app(ExpiryWarnings::class)->forProducts($this->tenant->id, $this->branch->id, [$expiredProductId, $fresh->id]);
    expect($warnings[$expiredProductId]['status'])->toBe('expired')
        ->and($warnings[$fresh->id])->toBe(['status' => 'soon', 'date' => today()->addDays(3)->toDateString()]);

    Livewire::test('pages::tenant.pos.sales')
        ->set('productSearchQuery', 'حليب')
        ->assertSee('data-pos-expiry="expired"', false)
        ->assertSee('دفعة منتهية')
        ->assertSee('تنتهي '.today()->addDays(3)->format('d/m'))
        ->call('selectInlineProduct', $expiredProductId)
        ->assertSee('تأكد من القطعة قبل البيع');

    TenantSetting::setBool($this->tenant->id, 'track_expiry', false);
    request()->attributes->remove('tenant_settings_values_'.$this->tenant->id);
    expect(app(ExpiryWarnings::class)->forProducts($this->tenant->id, $this->branch->id, [$expiredProductId]))->toBe([]);
});
