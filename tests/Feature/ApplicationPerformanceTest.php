<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر الأداء', 'is_active' => true]);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الرئيسي']);
});

test('staff page resolves all repeated permission checks with one permission query', function (): void {
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'موظف']);
    $permission = Permission::query()->create(['name' => 'products.view', 'display_name' => 'عرض المنتجات', 'group' => 'الأصناف']);
    $role->permissions()->attach($permission);
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'role_id' => $role->id, 'is_owner' => false]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'permission_role')) {
            $queries[] = $query->sql;
        }
    });
    $this->get('/dashboard/product')->assertSuccessful();
    expect(count($queries))->toBeLessThanOrEqual(1);
    $role->permissions()->detach($permission);
    $this->get('/dashboard/product')->assertForbidden();
});

test('tenant settings are read once per request and invalidate immediately after changes', function (): void {
    request()->setLaravelSession(app('session.store'));
    TenantSetting::set($this->tenant->id, 'currency', 'ILS');
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'tenant_settings')) {
            $queries[] = $query->sql;
        }
    });
    expect(TenantSetting::get($this->tenant->id, 'currency'))->toBe('ILS');
    TenantSetting::get($this->tenant->id, 'ui_theme', 'blue');
    TenantSetting::getBool($this->tenant->id, 'allow_negative_stock');
    TenantSetting::get($this->tenant->id, 'currency');
    expect(count($queries))->toBe(1);
    TenantSetting::set($this->tenant->id, 'currency', 'USD');
    expect(TenantSetting::get($this->tenant->id, 'currency'))->toBe('USD');
    $other = Tenant::query()->create(['name' => 'متجر مستقل']);
    expect(TenantSetting::get($other->id, 'currency', 'JOD'))->toBe('JOD');
});

test('repricing twenty separate cart lines uses bounded offer price and remaining stock queries', function (): void {
    $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $user->id, 'opening_cash' => 0]);
    $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف العرض', 'cost_price' => 2]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'retail_price' => 12, 'stock_quantity' => 100, 'offer_quantity' => 2, 'offer_price' => 10]);
    $component = Livewire::test('pages::tenant.pos.sales')->set('mergeSimilarProducts', false)->call('selectInlineProduct', $product->id);
    $line = array_values($component->get('cart'))[0];
    $cart = [];
    for ($i = 0; $i < 20; $i++) {
        $cart['line_'.$i] = $line;
    }
    $component->set('cart', $cart);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_starts_with(strtolower($query->sql), 'select') && (str_contains($query->sql, 'product_offers') || str_contains($query->sql, 'branch_products'))) {
            $queries[] = $query->sql;
        }
    });
    $component->call('updateQuantity', 'line_0', 2);
    expect(count($queries))->toBeLessThanOrEqual(3)
        ->and(array_sum(array_column($component->get('cart'), 'subtotal')))->toBe(112.0);
});

test('application boot avoids eagerly loading every model for audit registration', function (): void {
    $script = <<<'PHP'
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo count(array_filter(get_declared_classes(), fn (string $class): bool => str_starts_with($class, 'App\\Models\\')));
    PHP;
    $process = new Process([PHP_BINARY, '-r', $script], base_path());
    $process->run();
    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
        ->and((int) $process->getOutput())->toBeLessThanOrEqual(5);
});
