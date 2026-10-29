<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function captureResponsivePreview(string $key, string $html): void
{
    if (! $directory = getenv('CODEX_RESPONSIVE_PREVIEWS')) {
        return;
    }
    $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
    $css = file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));
    $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);
    $html = preg_replace('/<link[^>]+(?:stylesheet|modulepreload)[^>]*>/i', '', $html);
    $html = str_replace('x-cloak', '', $html);
    $html = str_replace('<body', '<body data-preview-page="'.$key.'"', $html);
    $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
    file_put_contents($directory.'/'.$key.'.html', $html);
}

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر تجريبي باسم طويل لاختبار العرض على الجوال', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الرئيسي', 'domain' => 'responsive-test']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'type' => 'tenant_user', 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id, 'auth.password_confirmed_at' => time()]);
    $category = Category::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'تصنيف تجريبي', 'is_active' => true]);
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف تجريبي باسم طويل للتأكد من عدم خروج البيانات عن حدود الشاشة', 'product_number' => 'I00000001', 'category_id' => $category->id, 'cost_price' => 5, 'show_in_website' => true]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'stock_quantity' => 20, 'retail_price' => 10, 'wholesale_price' => 8]);
    ProductBarcode::query()->create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'barcode' => '1234567890123']);
    Party::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'عميل تجريبي باسم طويل لاختبار القوائم والجداول', 'type' => 'both', 'current_balance' => 50, 'is_active' => true]);
    Employee::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'employee_code' => 'E001', 'first_name' => 'موظف', 'last_name' => 'تجريبي', 'is_active' => true, 'hire_date' => today()]);
});

test('tenant and settings pages render their responsive layouts with usable table regions', function (string $path, string $key): void {
    $response = $this->get($path)->assertSuccessful()->assertSee('app-responsive');
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " app-table-scroll ")]') as $region) {
        expect($region->getAttribute('tabindex'))->toBe('0')
            ->and($region->getAttribute('aria-label'))->not->toBe('')
            ->and($xpath->query('./table', $region)->length)->toBe(1);
    }
    if (in_array($key, ['pos', 'sales', 'purchases', 'wholesale', 'wholesale-invoices'], true)) {
        $response->assertSee('data-invoice-copyright', false)->assertSee('WaslaPOS');
    }
    captureResponsivePreview($key, $response->getContent());
})->with([
    ['/dashboard', 'dashboard'], ['/dashboard/branch', 'branches'], ['/dashboard/role', 'roles'],
    ['/dashboard/employees', 'employees'], ['/dashboard/customer', 'customers'], ['/dashboard/category', 'categories'],
    ['/dashboard/product', 'products'], ['/dashboard/barcode/printer', 'barcode'], ['/dashboard/pos', 'pos'],
    ['/dashboard/sale-invoices', 'sales'], ['/dashboard/purchase-invoices', 'purchases'],
    ['/dashboard/wholesale-sales', 'wholesale'], ['/dashboard/wholesale-invoices', 'wholesale-invoices'],
    ['/dashboard/stock-transfers', 'transfers'], ['/dashboard/returns', 'returns'],
    ['/dashboard/online-orders', 'online-orders'], ['/dashboard/store/responsive-test', 'storefront'],
    ['/dashboard/analytics', 'analytics'], ['/dashboard/daily-settlement', 'settlement'],
    ['/dashboard/payment', 'payments'], ['/dashboard/receipts', 'receipts'], ['/dashboard/exchange-rates', 'currency'],
    ['/dashboard/excel', 'excel'], ['/dashboard/logs', 'logs'], ['/dashboard/payroll-manager', 'payroll'],
    ['/settings/profile', 'profile'], ['/settings/appearance', 'appearance'], ['/settings/store', 'store-settings'],
    ['/settings/security', 'security'],
]);

test('point of sale keeps notes outside payment and offers one copy action for a saved invoice', function (): void {
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opening_cash' => 0, 'status' => 'open', 'opened_at' => now()]);
    $product = Product::query()->firstOrFail();
    $component = Livewire::test('pages::tenant.pos.sales')->set('mergeSimilarProducts', false);
    for ($index = 0; $index < 5; $index++) {
        $component->call('selectInlineProduct', $product->id);
    }
    $component->set('notes', 'ملاحظة العميل');
    captureResponsivePreview('pos-filled', $this->get('/dashboard/pos')->assertOk()->getContent());
    $component->set('paid_amount', 50)->call('checkoutAndPrint');
    $component->call('loadInvoice', Order::query()->sole()->id)->assertSet('notes', 'ملاحظة العميل')
        ->assertDontSee('data-pos-mobile-section-nav', false)
        ->assertSet('successMessage', null)
        ->assertSee('عرض فاتورة محفوظة — للقراءة فقط')
        ->assertDontSee('data-pos-notice-strip', false);
    captureResponsivePreview('pos-saved', $this->get('/dashboard/pos')->assertOk()->getContent());
    $dom = new DOMDocument;
    $dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//*[@id="pos-products-panel"]/following-sibling::*[@id="pos-cart-panel"]')->length)->toBe(1);
    expect($xpath->query('//*[@*[name()="wire:click"]="copyLoadedInvoice"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-notes-input]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-pos-notice-strip]')->length)->toBe(0)
        ->and($xpath->query('//*[@data-pos-customer-notes]//*[@data-pos-notes-input]')->length)->toBe(0);
});

test('administrator pages render responsive layouts', function (string $path, string $key): void {
    $this->user->update(['type' => 'saas_admin']);
    $response = $this->get($path)->assertSuccessful()->assertSee('app-responsive');
    captureResponsivePreview($key, $response->getContent());
})->with([['/admin/dashboard', 'admin-dashboard'], ['/admin/user', 'admin-users'], ['/admin/plan', 'admin-plans'], ['/dashboard/backup', 'backup']]);

test('guest pages render responsive layouts', function (string $path, string $key): void {
    auth()->logout();
    $response = $this->get($path)->assertSuccessful()->assertSee('app-responsive');
    captureResponsivePreview($key, $response->getContent());
})->with([['/', 'home'], ['/login', 'login'], ['/register', 'register'], ['/forgot-password', 'forgot-password']]);
