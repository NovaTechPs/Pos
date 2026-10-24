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

test('responsive POS keeps a single editable cart and checkout with products before the cart on narrow screens', function (): void {
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
    expect($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " pos-page ")]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-barcode-input]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="pos-cart-panel"]')->length)->toBe(1)
        ->and($xpath->query('//*[@id="pos-products-panel"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-cart-line]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-field="quantity"]')->length)->toBe(1)
        ->and($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " pos-payment-actions ")]/button')->length)->toBe(6)
        ->and($xpath->query('//*[@data-pos-invoice-info]//div[contains(concat(" ", normalize-space(@class), " "), " pos-invoice-actions ")]')->length)->toBe(1)
        ->and($xpath->query('//*[@role="tab"]')->length)->toBe(0)
        ->and(strpos($html, 'id="pos-products-panel"'))->toBeLessThan(strpos($html, 'id="pos-cart-panel"'));

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
