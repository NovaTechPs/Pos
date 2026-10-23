<?php

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('store owner can save JOD and USD rates and use the shekel converter', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر التحويلات']);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_owner' => true,
        'type' => 'tenant_user',
    ]);

    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::actingAs($owner)
        ->test('pages::tenant.accounting.exchange-rates')
        ->set('jodToIlsRate', '5.10')
        ->set('usdToIlsRate', '3.70')
        ->set('amountToConvert', '100')
        ->call('saveRates')
        ->assertHasNoErrors()
        ->assertSee('Exchange rates saved')
        ->assertSee('₪ 510.00');

    expect(TenantSetting::get($tenant->id, 'currency_rate_jod_to_ils'))->toBe('5.1000')
        ->and(TenantSetting::get($tenant->id, 'currency_rate_usd_to_ils'))->toBe('3.7000');
});

test('employees without exchange rate permission are denied', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجري']);
    $employee = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_owner' => false,
        'type' => 'tenant_user',
    ]);
    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::actingAs($employee)
        ->test('pages::tenant.accounting.exchange-rates')
        ->assertForbidden();
});

test('store owner cannot load exchange rates from another tenant', function (): void {
    $ownedTenant = Tenant::query()->create(['name' => 'متجري']);
    $otherTenant = Tenant::query()->create(['name' => 'متجر آخر']);
    $owner = User::factory()->create([
        'tenant_id' => $ownedTenant->id,
        'is_owner' => true,
        'type' => 'tenant_user',
    ]);
    $this->withSession(['active_tenant_id' => $otherTenant->id]);

    Livewire::actingAs($owner)
        ->test('pages::tenant.accounting.exchange-rates')
        ->assertStatus(404);
});

test('store owner can refresh rates from Open Exchange Rates and cached rates are saved', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر الصرف']);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_owner' => true,
        'type' => 'tenant_user',
    ]);
    $this->withSession(['active_tenant_id' => $tenant->id]);
    config()->set('services.open_exchange_rates.app_id', 'test-app-key');

    Http::fake([
        'openexchangerates.org/api/latest.json*' => Http::response([
            'timestamp' => 1792022400,
            'base' => 'USD',
            'rates' => ['ILS' => 3.7, 'JOD' => 0.71],
        ]),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::tenant.accounting.exchange-rates')
        ->call('updateRatesFromApi')
        ->assertHasNoErrors()
        ->assertSee('Rates updated from Open Exchange Rates');

    expect(TenantSetting::get($tenant->id, 'currency_rate_usd_to_ils'))->toBe('3.7000')
        ->and(TenantSetting::get($tenant->id, 'currency_rate_jod_to_ils'))->toBe('5.2113')
        ->and(TenantSetting::get($tenant->id, 'currency_rates_provider'))->toBe('open_exchange_rates')
        ->and(TenantSetting::get($tenant->id, 'currency_rates_updated_at'))->not->toBeNull();

    Http::assertSent(function ($request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://openexchangerates.org/api/latest.json?')
            && ($query['app_id'] ?? null) === 'test-app-key'
            && ($query['symbols'] ?? null) === 'ILS,JOD';
    });
});

test('api failure leaves the previously saved rates unchanged', function (): void {
    $tenant = Tenant::query()->create(['name' => 'متجر الصرف']);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_owner' => true,
        'type' => 'tenant_user',
    ]);
    $this->withSession(['active_tenant_id' => $tenant->id]);
    config()->set('services.open_exchange_rates.app_id', 'test-app-key');
    TenantSetting::set($tenant->id, 'currency_rate_jod_to_ils', '5.2500');
    TenantSetting::set($tenant->id, 'currency_rate_usd_to_ils', '3.7500');

    Http::fake(['openexchangerates.org/*' => Http::response([], 503)]);

    Livewire::actingAs($owner)
        ->test('pages::tenant.accounting.exchange-rates')
        ->call('updateRatesFromApi')
        ->assertSee('Could not fetch rates');

    expect(TenantSetting::get($tenant->id, 'currency_rate_jod_to_ils'))->toBe('5.2500')
        ->and(TenantSetting::get($tenant->id, 'currency_rate_usd_to_ils'))->toBe('3.7500');
});
