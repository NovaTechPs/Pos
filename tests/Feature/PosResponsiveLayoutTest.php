<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('responsive POS keeps one cart with mobile search and save below totals', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر التجربة']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الرئيسي']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    Shift::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $user->id, 'opening_cash' => 0]);
    $product = null;
    for ($index = 1; $index <= 8; $index++) {
        $item = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'إبريق بلاستيك شفاف مع غطاء ومقبض للاستخدام المنزلي '.$index, 'cost_price' => 4]);
        BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $item->id, 'retail_price' => 10, 'stock_quantity' => 20]);
        $product ??= $item;
    }
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $product->id);
    $html = $component->html();
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $styles = file_get_contents(resource_path('css/pos.css'));
    preg_match('/\.pos-products \[data-pos-product-panel-search\]\s*\{([^}]+)\}/', $styles, $searchRule);
    expect($searchRule[1])->toContain('width: 100%')->not->toContain('width: 0', 'flex: 1');
    expect($styles)->not->toContain('.pos-products > div > div:first-child');
    expect($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " pos-page ")]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-barcode-input]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-product-search-header]/label[@data-pos-search-field]/input[@data-pos-product-panel-search]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-product-search-header]/*[@data-pos-price-filter]//input[@data-pos-product-price-search]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-product-price-search]')->item(0)->getAttribute('inputmode'))->toBe('decimal')
        ->and(strpos($html, 'data-pos-product-panel-search'))->toBeLessThan(strpos($html, 'data-pos-product-price-search'))
        ->and($xpath->query('//*[@data-pos-product-search-header]/*[@id="pos-search-help"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="pos-cart-panel"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="pos-products-panel"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-cart-line]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-field="quantity"]')->length)->toBe(1)
        ->and($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " pos-payment-actions ")]/button')->length)->toBe(6)
        ->and($xpath->query('//*[@data-pos-invoice-info]//div[contains(concat(" ", normalize-space(@class), " "), " pos-invoice-actions ")]')->length)->toBe(1)
        ->and($xpath->query('//*[@role="tab"]')->length)->toBe(0)
        ->and(strpos($html, 'id="pos-products-panel"'))->toBeLessThan(strpos($html, 'id="pos-cart-panel"'));

    $methods = $xpath->query('//*[@data-pos-payment-methods]');
    expect($xpath->query('//*[@data-pos-mobile-save]//button')->length)->toBe(2)
        ->and($xpath->query('//*[@data-pos-mobile-search-results]')->length)->toBe(1)
        ->and($styles)->toContain('.pos-content-grid > .pos-products { display: none; }')
        ->and(strpos($html, 'pos-payment-totals'))->toBeLessThan(strpos($html, 'data-pos-mobile-save'));
    $customerNotes = $xpath->query('//*[@data-pos-customer-notes]');
    expect($methods->length)->toBe(1)
        ->and($customerNotes->length)->toBe(1)
        ->and($methods->item(0)->getAttribute('class'))->toContain('lg:order-2')
        ->and($customerNotes->item(0)->getAttribute('class'))->toContain('lg:order-1')
        ->and($xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " pos-payment-totals ")]')->item(0)->getAttribute('class'))->toContain('lg:order-3');

    if ($previewPath = getenv('CODEX_POS_PREVIEW')) {
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $css = file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));
        $markup = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);
        $markup = str_replace('x-cloak', '', $markup);
        $document = '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>'.$css.'</style><body>'.$markup.'</body></html>';
        file_put_contents($previewPath, $document);
    }

    $lineKey = array_key_first($component->get('cart'));
    $component->call('updateCartField', (string) $lineKey, 'quantity', 3)->set('paid_amount', 30)
        ->call('checkoutAndPrint')->assertDispatched('print-receipt');
    expect((float) Order::query()->sole()->total)->toBe(30.0)
        ->and((float) BranchProduct::query()->where('product_id', $product->id)->sole()->stock_quantity)->toBe(17.0);
});

test('phone POS shows the app bar, invoice card, item stepper, sticky save bar and payment sheet', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر الجوال']);
    $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'الرئيسي']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id, 'active_branch_id' => $branch->id]);
    Shift::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $user->id, 'opening_cash' => 0]);
    $product = Product::query()->create(['tenant_id' => $tenant->id, 'name' => 'كوب زجاجي', 'cost_price' => 4]);
    BranchProduct::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'retail_price' => 7.5, 'stock_quantity' => 20]);

    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $product->id);
    $lineKey = (string) array_key_first($component->get('cart'));
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $text = fn (string $query): string => trim(preg_replace('/\s+/u', ' ', $xpath->query($query)->item(0)?->textContent ?? ''));

    expect($xpath->query('//*[@data-pos-mobile-header]//*[@data-pos-mobile-section-pills]/*')->length)->toBe(3)
        ->and($text('//*[@data-pos-mobile-section-pills]/*[@aria-current="page"]'))->toBe('نقطة البيع')
        ->and($xpath->query('//*[@data-pos-mobile-header]//*[@data-pos-mobile-held]')->length)->toBe(1)
        ->and($text('//*[@data-pos-mobile-invoice-title]'))->toBe('فاتورة بيع جديدة')
        ->and($xpath->query('//*[@data-pos-barcode-entry]/*[@data-pos-add-button]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-barcode-entry]/*[@data-pos-scanner-button]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-price-dropdown]//input[@aria-label="البحث بسعر البيع على الجوال"]')->length)->toBe(1)
        ->and($text('//*[@data-pos-quantity-chip]'))->toBe('الكمية: 1')
        ->and($text('//*[@data-pos-mobile-cart-head]//h3'))->toBe('الأصناف (1)')
        ->and($xpath->query('//*[@data-pos-quantity-step="up"]')->item(0)->getAttribute('wire:click'))->toBe("updateCartField('{$lineKey}', 'quantity', '2')")
        ->and($xpath->query('//*[@data-pos-quantity-step="down"]')->item(0)->hasAttribute('disabled'))->toBeTrue();

    $sheet = '//*[@data-pos-payment-sheet]';
    expect($xpath->query($sheet.'/*[@data-pos-payment-methods]//button')->length)->toBe(4)
        ->and($xpath->query($sheet.'/*[contains(@class, "pos-payment-totals")]/*[@data-pos-total-tile]')->length)->toBe(6)
        ->and($xpath->query($sheet.'//*[@data-pos-payment-apply]')->length)->toBe(1)
        ->and($xpath->query($sheet.'//*[@data-pos-customer-notes]')->length)->toBe(0)
        ->and($xpath->query('//*[@data-pos-customer-notes]//*[@data-pos-add-customer]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-mobile-bar]/*[@data-pos-mobile-bar-save][@*[name()="wire:click"]="saveOrEditInvoice"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-mobile-bar]/*[@data-pos-payment-details]')->length)->toBe(1)
        ->and($text('//*[@data-pos-mobile-net]'))->toBe('الصافي للدفع ₪ 7.50');

    $styles = file_get_contents(resource_path('css/pos.css'));
    expect($styles)->toContain("@media screen and (min-width: 1024px) {\n    .pos-payment-sheet { display: contents; }\n}")
        ->and($styles)->toContain('.pos-payment-sheet[data-open="true"]');

    $component->call('updateCartField', $lineKey, 'quantity', '2');
    expect((float) $component->get('cart')[$lineKey]['quantity'])->toBe(2.0);
});
