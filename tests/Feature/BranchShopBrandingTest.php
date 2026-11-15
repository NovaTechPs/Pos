<?php

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\OnlineShop;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'الهوية الأصلية', 'is_active' => true]);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true]);
    $this->first = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الأول', 'domain' => 'first-shop']);
    $this->second = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الثاني', 'domain' => 'second-shop']);
    TenantSetting::setBool($this->tenant->id, OnlineShop::SETTING, true);
    TenantSetting::set($this->tenant->id, 'shop_logo', 'legacy/logo.png');
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->first->id]);
});

test('each branch saves its own identity without changing sibling or tenant settings', function (): void {
    Storage::fake('public');
    $page = Livewire::test('pages::settings.shop-appearance')->assertSet('branchId', $this->first->id)
        ->set('storeName', 'متجر الألعاب')->set('phone', '0591234567')->set('primaryColor', '#6d28d9')->set('accentColor', '#fbbf24')
        ->set('logo', UploadedFile::fake()->image('logo.png'))->set('banner', UploadedFile::fake()->image('banner.png'))
        ->call('saveAppearance')->assertHasNoErrors();
    $identity = $this->first->fresh()->shop_branding;
    Storage::disk('public')->assertExists([$identity['shop_logo'], $identity['shop_banner']]);
    expect($identity['shop_name'])->toBe('متجر الألعاب')->and($this->second->fresh()->shop_branding)->toBeNull()
        ->and(TenantSetting::get($this->tenant->id, 'shop_logo'))->toBe('legacy/logo.png');
    foreach (['shop.home', 'shop.login', 'shop.register'] as $route) {
        $this->get(route($route, ['shop' => 'first-shop']))->assertOk()->assertSee('متجر الألعاب')
            ->assertSee('--shop-blue: #6d28d9', false)->assertSee('--shop-yellow: #fbbf24', false)
            ->assertSee(Storage::disk('public')->url($identity['shop_logo']), false);
        $this->get(route($route, ['shop' => 'second-shop']))->assertOk()->assertSee('الهوية الأصلية')->assertDontSee('متجر الألعاب');
    }
    $page->set('branchId', $this->second->id)->assertSet('storeName', 'الهوية الأصلية')
        ->set('storeName', 'متجر المنزل')->call('saveAppearance')->assertHasNoErrors();
    expect($this->second->fresh()->shop_branding['shop_name'])->toBe('متجر المنزل')
        ->and($this->first->fresh()->shop_branding['shop_name'])->toBe('متجر الألعاب');
});

test('removing inherited image affects one branch and reset restores defaults', function (): void {
    $page = Livewire::test('pages::settings.shop-appearance')->call('removeImage', 'logo')->assertHasNoErrors();
    $shop = app(OnlineShop::class);
    expect($shop->branding($this->first->fresh())['logo'])->toBeNull()
        ->and($shop->branding($this->second->fresh())['logo'])->toContain('legacy/logo.png');
    $page->call('resetAppearance')->assertHasNoErrors();
    expect($this->first->fresh()->shop_branding)->toBeNull()
        ->and($shop->branding($this->first->fresh())['logo'])->toContain('legacy/logo.png');
});

test('switching branches clears unsaved uploads and validation', function (): void {
    Storage::fake('public');
    Livewire::test('pages::settings.shop-appearance')->set('logo', UploadedFile::fake()->image('unsaved.png'))
        ->set('storeName', '')->call('saveAppearance')->assertHasErrors('storeName')
        ->set('branchId', $this->second->id)->assertSet('logo', null)->assertSet('storeName', 'الهوية الأصلية')->assertHasNoErrors();
});

test('foreign and deleted branches cannot be selected or changed', function (): void {
    $tenant = Tenant::query()->create(['name' => 'آخر']);
    $foreign = Branch::query()->create(['tenant_id' => $tenant->id, 'name' => 'خاص']);
    Livewire::test('pages::settings.shop-appearance')->set('branchId', $foreign->id)->assertNotFound();
    $this->second->delete();
    Livewire::test('pages::settings.shop-appearance')->set('branchId', $this->second->id)->assertNotFound();
    expect($foreign->fresh()->shop_branding)->toBeNull();
});

test('identity validates colors and permissions', function (): void {
    Livewire::test('pages::settings.shop-appearance')->set('primaryColor', 'red; background:url(x)')->set('accentColor', '#xyz')
        ->call('saveAppearance')->assertHasErrors(['primaryColor', 'accentColor']);
    expect($this->first->fresh()->shop_branding)->toBeNull();
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => false]);
    $this->actingAs($staff);
    Livewire::test('pages::settings.shop-appearance')->assertForbidden();
});

test('unsafe stored colors fall back to safe defaults', function (): void {
    $this->first->update(['shop_branding' => ['shop_primary_color' => 'red; color:transparent', 'shop_accent_color' => '#fff']]);
    $branding = app(OnlineShop::class)->branding($this->first->fresh());
    expect($branding['primary_color'])->toBe('#064b9b')->and($branding['accent_color'])->toBe('#ffd336');
});

test('settings handle a tenant without branches', function (): void {
    $this->first->delete();
    $this->second->delete();
    Livewire::test('pages::settings.shop-appearance')->assertSet('branchId', null)->assertSee('لا توجد فروع');
});
