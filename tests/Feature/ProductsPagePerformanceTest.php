<?php

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
