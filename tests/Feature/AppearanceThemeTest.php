<?php

use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('store owner can save a store-wide theme applied across pages', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'متجر الثيم',
        'is_active' => true,
    ]);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_owner' => true,
        'type' => 'tenant_user',
    ]);
    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::actingAs($owner)
        ->test('pages::settings.store')
        ->set('uiTheme', 'orange')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('store-theme-updated', theme: 'orange');

    expect(TenantSetting::get($tenant->id, 'ui_theme'))->toBe('orange');

    $this->actingAs($owner)
        ->get('/settings/appearance')
        ->assertOk()
        ->assertSee('data-theme="orange"', false);
});

test('store theme setting rejects unsupported colors', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'متجر الثيم',
        'is_active' => true,
    ]);
    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'is_owner' => true,
        'type' => 'tenant_user',
    ]);
    $this->withSession(['active_tenant_id' => $tenant->id]);

    Livewire::actingAs($owner)
        ->test('pages::settings.store')
        ->set('uiTheme', 'purple')
        ->call('save')
        ->assertHasErrors(['uiTheme']);

    expect(TenantSetting::get($tenant->id, 'ui_theme'))->toBeNull();
});
