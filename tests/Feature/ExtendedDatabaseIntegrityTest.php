<?php

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('database rejects cross tenant category links even through raw writes', function (): void {
    $tenant = Tenant::create(['name' => 'أول']);
    $other = Tenant::create(['name' => 'ثان']);
    $category = DB::table('categories')->insertGetId(['tenant_id' => $other->id, 'name' => 'تصنيف']);
    expect(fn () => DB::table('products')->insert(['tenant_id' => $tenant->id, 'name' => 'خطأ', 'category_id' => $category]))->toThrow(QueryException::class);
});

test('native checks reject invalid money and reversed payroll periods', function (string $table, array $attributes): void {
    $tenant = Tenant::create(['name' => 'اختبار']);
    expect(fn () => DB::table($table)->insert(['tenant_id' => $tenant->id] + $attributes))->toThrow(QueryException::class);
})->with([
    ['products', ['name' => 'سعر سالب', 'cost_price' => -1]],
    ['payroll_periods', ['title' => 'فترة', 'start_date' => '2026-10-31', 'end_date' => '2026-10-01']],
]);

test('raw changes cannot move a product into a different tenant category', function (): void {
    $tenant = Tenant::create(['name' => 'أول']);
    $other = Tenant::create(['name' => 'ثان']);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'صنف']);
    $category = DB::table('categories')->insertGetId(['tenant_id' => $other->id, 'name' => 'تصنيف']);
    expect(fn () => DB::table('products')->where('id', $product->id)->update(['category_id' => $category]))->toThrow(QueryException::class);
});

test('existing business records cannot be reassigned to another tenant', function (): void {
    $tenant = Tenant::create(['name' => 'أول']);
    $other = Tenant::create(['name' => 'ثان']);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'صنف']);
    expect(fn () => DB::table('products')->where('id', $product->id)->update(['tenant_id' => $other->id]))->toThrow(QueryException::class);
});
