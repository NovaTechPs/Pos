<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\WebCategory;
use App\Services\OnlineShop;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر البيت العصري', 'is_active' => true, 'phone' => '0591234567']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'type' => 'tenant_user', 'is_owner' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الرئيسي', 'domain' => 'home-store']);
    TenantSetting::setBool($this->tenant->id, OnlineShop::SETTING, true);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->internal = Category::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'تصنيف داخلي للمخزن', 'is_active' => true]);
    $this->category = WebCategory::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'أواني المطبخ']);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'طقم أواني مطبخ', 'cost_price' => 50, 'category_id' => $this->internal->id, 'web_category_id' => $this->category->id, 'show_in_website' => true]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'retail_price' => 150, 'wholesale_price' => 120, 'stock_quantity' => 10]);
});

test('store branding uploads are local scoped and shared by home login and registration', function (): void {
    Storage::fake('public');
    Livewire::test('pages::settings.shop-appearance')->set('logo', UploadedFile::fake()->image('logo.png', 160, 160))
        ->set('banner', UploadedFile::fake()->image('banner.jpg', 1200, 400))->set('tagline', 'للأدوات المنزلية والمستلزمات')->set('heroTitle', 'كل ما تحتاجه لمنزلك في مكان واحد')->call('saveAppearance')->assertHasNoErrors();
    $logo = $this->branch->fresh()->shop_branding['shop_logo'];
    $banner = $this->branch->fresh()->shop_branding['shop_banner'];
    expect($logo)->toStartWith('tenants/'.$this->tenant->id.'/branches/'.$this->branch->id.'/shop/');
    Storage::disk('public')->assertExists([$logo, $banner]);
    foreach (['shop.home', 'shop.login', 'shop.register'] as $route) {
        $this->get(route($route, ['shop' => 'home-store']))->assertOk()->assertSee('متجر البيت العصري')->assertSee(Storage::disk('public')->url($logo), false);
    }
    Livewire::test('pages::settings.shop-appearance')->call('removeImage', 'logo')->assertHasNoErrors();
    expect($this->branch->fresh()->shop_branding['shop_logo'])->toBeNull();
});

test('shop identity rejects non image uploads and unauthorized staff', function (): void {
    Storage::fake('public');
    Livewire::test('pages::settings.shop-appearance')->set('logo', UploadedFile::fake()->create('payload.txt', 1, 'text/plain'))->call('saveAppearance')->assertHasErrors('logo');
    $staff = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => false]);
    $this->actingAs($staff);
    Livewire::test('pages::settings.shop-appearance')->assertForbidden();
    Livewire::test('pages::settings.web-categories')->assertForbidden();
});

test('web categories are independent and hidden sections cannot be bypassed in checkout', function (): void {
    $this->get(route('shop.home', ['shop' => 'home-store']))->assertOk()->assertSee('أواني المطبخ')->assertDontSee('تصنيف داخلي للمخزن');
    Livewire::test('pages::shop.index', ['shop' => 'home-store'])->set('categoryId', $this->category->id)->assertSee('طقم أواني مطبخ');
    $this->category->update(['is_active' => false]);
    expect(app(OnlineShop::class)->catalog($this->branch, 'retail'))->toBeEmpty()
        ->and(app(OnlineShop::class)->cartLines($this->branch, 'retail', [$this->product->id => 1]))->toBeEmpty();
    expect(fn () => app(OnlineShop::class)->placeOrder($this->branch, [$this->product->id => 1], ['name' => 'اختبار', 'phone' => '0591234567']))->toThrow(ValidationException::class);
});

test('category editor saves images ordering and edits without changing internal categories', function (): void {
    Storage::fake('public');
    $page = Livewire::test('pages::settings.web-categories')->set('name', 'أطقم الطعام')->set('sortOrder', 2)->set('image', UploadedFile::fake()->image('category.png'))->call('saveCategory')->assertHasNoErrors();
    $category = WebCategory::query()->where('name', 'أطقم الطعام')->sole();
    Storage::disk('public')->assertExists($category->image);
    $page->call('edit', $category->id)->set('name', 'أطقم المائدة')->call('saveCategory')->assertHasNoErrors();
    expect($category->fresh()->name)->toBe('أطقم المائدة');
    $page->call('delete', $this->category->id)->assertHasNoErrors();
    expect($this->product->fresh()->web_category_id)->toBeNull()->and($this->product->fresh()->category_id)->toBe($this->internal->id)
        ->and(app(OnlineShop::class)->catalog($this->branch, 'retail'))->toHaveCount(1);
});

test('product editor persists web classification without altering the internal one', function (): void {
    $other = WebCategory::factory()->create(['tenant_id' => $this->tenant->id]);
    Livewire::test('pages::tenant.products.index')->call('edit', $this->product->id)->assertSet('web_category_id', $this->category->id)
        ->set('web_category_id', $other->id)->call('save')->assertHasNoErrors();
    expect($this->product->fresh()->web_category_id)->toBe($other->id)->and($this->product->fresh()->category_id)->toBe($this->internal->id);
});

test('cross tenant categories are rejected by the editor public catalog and database', function (): void {
    $other = WebCategory::factory()->create();
    Livewire::test('pages::settings.web-categories')->call('edit', $other->id)->assertNotFound();
    Livewire::test('pages::settings.web-categories')->call('delete', $other->id)->assertNotFound();
    Livewire::test('pages::tenant.products.index')->call('edit', $this->product->id)->set('web_category_id', $other->id)->call('save')->assertHasErrors('web_category_id');
    expect(app(OnlineShop::class)->catalog($this->branch, 'retail', categoryId: $other->id))->toBeEmpty();
    expect(fn () => DB::table('products')->where('id', $this->product->id)->update(['web_category_id' => $other->id]))->toThrow(QueryException::class);
});

test('storefront renders working navigation search category cards and branded authentication', function (): void {
    WebCategory::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'أطقم الطعام']);
    WebCategory::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'تنظيم وتخزين']);
    $home = $this->get(route('shop.home', ['shop' => 'home-store']))->assertOk()->assertSee('shop-hero', false)->assertSee('shop-categories', false)->assertSee('shop-cart', false);
    $login = $this->get(route('shop.login', ['shop' => 'home-store']))->assertOk()->assertSee('shop-auth-card', false)->assertSee('إنشاء حساب جديد')->assertDontSee('Google')->assertDontSee('Apple');
    Livewire::withQueryParams(['search' => 'غير موجود'])->test('pages::shop.index', ['shop' => 'home-store'])->assertSee('لا توجد منتجات مطابقة');
});

test('web classification migration can roll back without losing products or internal classification', function (): void {
    $migration = require database_path('migrations/2026_11_14_210831_add_web_category_id_to_products_table.php');
    $migration->down();
    expect(Schema::hasColumn('products', 'web_category_id'))->toBeFalse()
        ->and($this->product->fresh()->category_id)->toBe($this->internal->id);
    $migration->up();
    expect(Schema::hasColumn('products', 'web_category_id'))->toBeTrue();
    $this->product->refresh()->update(['web_category_id' => $this->category->id]);
    expect($this->product->fresh()->web_category_id)->toBe($this->category->id);
    $other = WebCategory::factory()->create();
    expect(fn () => DB::table('products')->where('id', $this->product->id)->update(['web_category_id' => $other->id]))->toThrow(QueryException::class);
});
