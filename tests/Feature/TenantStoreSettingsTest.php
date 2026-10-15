<?php

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createSettingsTenant(array $overrides = []): Tenant
{
    return Tenant::query()->create(array_merge([
        'name' => 'متجر الاختبار',
        'phone' => '0590000000',
        'is_active' => true,
    ], $overrides));
}

test('store owner can save store profile, inventory and receipt preferences for their own tenant', function (): void {
    $tenant = createSettingsTenant();
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_owner' => true,
        'type' => 'tenant_user',
    ]);

    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::actingAs($owner)
        ->test('pages::settings.store')
        ->set('storeName', 'متجر زيد')
        ->set('storePhone', '0591234567')
        ->set('storeAddress', 'رام الله')
        ->set('currency', 'USD')
        ->set('receiptFooter', 'أهلاً بكم')
        ->set('unifiedStock', true)
        ->set('allowNegativeStock', false)
        ->set('mergeSimilarProducts', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Store settings saved');

    expect($tenant->fresh()->name)->toBe('متجر زيد')
        ->and($tenant->fresh()->phone)->toBe('0591234567')
        ->and(TenantSetting::get($tenant->id, 'store_address'))->toBe('رام الله')
        ->and(TenantSetting::get($tenant->id, 'currency'))->toBe('USD')
        ->and(TenantSetting::get($tenant->id, 'receipt_footer'))->toBe('أهلاً بكم')
        ->and(TenantSetting::getBool($tenant->id, 'unified_stock'))->toBeTrue()
        ->and(TenantSetting::getBool($tenant->id, 'allow_negative_stock'))->toBeFalse()
        ->and(TenantSetting::getBool($tenant->id, 'merge_similar_products'))->toBeTrue();
});

test('store settings page denies employees without the store settings permission', function (): void {
    $tenant = createSettingsTenant();
    $employee = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_owner' => false,
        'type' => 'tenant_user',
    ]);

    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::actingAs($employee)
        ->test('pages::settings.store')
        ->assertForbidden();
});

test('POS loads persisted store preferences for inventory and item merging', function (): void {
    $tenant = createSettingsTenant();
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_owner' => true,
        'type' => 'tenant_user',
    ]);
    TenantSetting::setBool($tenant->id, 'unified_stock', true);
    TenantSetting::setBool($tenant->id, 'allow_negative_stock', true);
    TenantSetting::setBool($tenant->id, 'merge_similar_products', true);

    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::actingAs($user)
        ->test('pages::tenant.pos.sales')
        ->assertSet('unifiedStock', true)
        ->assertSet('allowNegativeStock', true)
        ->assertSet('mergeSimilarProducts', true);
});

test('store owner cannot load another tenant settings by changing the active tenant id', function (): void {
    $ownedTenant = createSettingsTenant(['name' => 'متجري']);
    $otherTenant = createSettingsTenant(['name' => 'متجر آخر']);
    $owner = User::factory()->create([
        'tenant_id' => $ownedTenant->id,
        'is_owner' => true,
        'type' => 'tenant_user',
    ]);

    $this->withSession(['active_tenant_id' => $otherTenant->id]);

    $this->expectException(ModelNotFoundException::class);

    Livewire::actingAs($owner)
        ->test('pages::settings.store');
});
