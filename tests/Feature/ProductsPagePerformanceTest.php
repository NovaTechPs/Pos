<?php

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('products table displays product numbers in a separate column', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر أرقام المنتجات']);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'tenant_user',
        'is_owner' => true,
    ]);
    $this->withSession(['active_tenant_id' => $tenant->id]);

    foreach (['000123', null] as $number) {
        $product = Product::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'منتج اختبار',
            'product_number' => $number,
        ]);
        if ($number === null) {
            DB::table('products')->where('id', $product->id)->update(['product_number' => null]);
        }
    }

    $component = Livewire::actingAs($owner)
        ->test('pages::tenant.products.index')
        ->assertSee('رقم المنتج');

    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$component->html());
    $cells = (new DOMXPath($document))->query('//td[@data-product-number]');

    expect($cells)->toHaveCount(2);
    expect(trim($cells->item(0)->textContent))->toBe('000123');
    expect(trim($cells->item(1)->textContent))->toBe('—');
});

test('product page renders its summary with a single aggregate statistics query', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر اختبار الأداء']);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'type' => 'tenant_user',
        'is_owner' => true,
    ]);
    $this->withSession(['active_tenant_id' => $tenant->id]);

    Product::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'منتج ظاهر',
        'show_in_website' => true,
    ]);
    Product::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'منتج مخفي',
        'show_in_website' => false,
    ]);

    $productCountQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$productCountQueries): void {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'products') && str_contains($sql, 'count(')) {
            $productCountQueries[] = $sql;
        }
    });

    Livewire::actingAs($owner)
        ->test('pages::tenant.products.index')
        ->assertSee('إجمالي المنتجات')
        ->assertSee('معروض في الموقع')
        ->assertSee('مخفي من الموقع');

    expect($productCountQueries)->toHaveCount(2);
});
