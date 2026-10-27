<?php

use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('product numbers increase independently per tenant and include deleted products', function (): void {
    $tenant = Tenant::create(['name' => 'First']);
    $otherTenant = Tenant::create(['name' => 'Other']);
    $first = Product::create(['tenant_id' => $tenant->id, 'name' => 'First']);
    expect($first->product_number)->toBe('i00000001');
    $first->delete();
    $next = Product::create(['tenant_id' => $tenant->id, 'name' => 'Next']);
    expect($next->product_number)->toBe('i00000002');
    $next->update(['name' => 'Renamed']);
    expect($next->fresh()->product_number)->toBe('i00000002')
        ->and(Product::create(['tenant_id' => $otherTenant->id, 'name' => 'Other'])->product_number)->toBe('i00000001');
});

test('automatic product numbering preserves legacy codes and continues imported sequences', function (): void {
    $tenant = Tenant::create(['name' => 'Imported']);
    foreach (['I00000009', 'P999', '12345'] as $number) {
        $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'Imported', 'product_number' => $number]);
        expect($product->product_number)->toBe($number);
    }
    expect(Product::create(['tenant_id' => $tenant->id, 'name' => 'Next', 'product_number' => ''])->product_number)
        ->toBe('i00000010');
});

test('automatic numbering uses the authenticated tenant when omitted', function (): void {
    $tenant = Tenant::create(['name' => 'Authenticated']);
    $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id]));
    $product = Product::create(['name' => 'Implicit tenant']);
    expect($product->tenant_id)->toBe($tenant->id)
        ->and($product->product_number)->toBe('i00000001');
});
