<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Role;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Count shop']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->product = function (string $name, float $stock, string $barcode, float $cost = 4): Product {
        $product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => $name, 'cost_price' => $cost]);
        ProductBarcode::query()->create(['tenant_id' => $this->tenant->id, 'product_id' => $product->id, 'barcode' => $barcode]);
        BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $product->id, 'stock_quantity' => $stock, 'retail_price' => 10]);

        return $product;
    };
});

test('a stocktake counts scanned items and on approval sets only their stock, recording the variance', function (): void {
    $sugar = ($this->product)('سكر', 10, '111');
    $rice = ($this->product)('أرز', 2, '222');
    $oil = ($this->product)('زيت', 7, '333');

    $page = Livewire::test('pages::tenant.stock-counts.index')
        ->assertSee('بدء جرد جديد')
        ->call('startCount')
        ->call('scanBarcode', '111')->call('scanBarcode', '111')->call('scanBarcode', '6*111')
        ->call('scanBarcode', '222')
        ->set('scanInput', 'أرز')->assertSee('+1');
    expect($page->call('scanBarcode', 'غير موجود')->get('scanInput'))->toBe('أرز');

    $count = StockCount::query()->sole();
    expect($count->status)->toBe('draft')
        ->and((float) $count->items()->where('product_id', $sugar->id)->value('counted_quantity'))->toBe(8.0);

    $riceLine = $count->items()->where('product_id', $rice->id)->sole();
    $page->call('setCounted', $riceLine->id, '5')
        ->assertSee('النظام: 10')->assertSee('-2')->assertSee('+3')
        ->call('approve')->assertHasNoErrors()
        ->assertSee('تم اعتماد الجرد');

    $stock = fn (Product $product): float => (float) BranchProduct::query()->where('product_id', $product->id)->value('stock_quantity');
    expect($stock($sugar))->toBe(8.0)->and($stock($rice))->toBe(5.0)->and($stock($oil))->toBe(7.0)
        ->and($count->fresh()->status)->toBe('approved')
        ->and((float) $count->items()->where('product_id', $sugar->id)->value('system_quantity'))->toBe(10.0);

    $movements = StockMovement::query()->where('source_type', 'stock_count')->orderBy('product_id')->get();
    expect($movements)->toHaveCount(2)
        ->and($movements->pluck('quantity')->map(fn ($quantity): float => (float) $quantity)->all())->toBe([-2.0, 3.0])
        ->and($movements->pluck('source_reference')->unique()->all())->toBe([$count->count_number]);

    // An approved count is read-only.
    $page->call('scanBarcode', '333')->assertHasErrors('count');
    expect($stock($oil))->toBe(7.0);
});

test('an unfinished count is resumed, can be deleted, and needs counted items before approval', function (): void {
    ($this->product)('سكر', 10, '111');
    Livewire::test('pages::tenant.stock-counts.index')->call('startCount')->call('approve')
        ->assertSee('أضف الأصناف المعدودة قبل اعتماد الجرد.');
    Livewire::test('pages::tenant.stock-counts.index')->call('scanBarcode', '111');

    $resumed = Livewire::test('pages::tenant.stock-counts.index')->assertSet('countId', StockCount::query()->sole()->id)->assertSee('سكر');
    $resumed->call('deleteDraft');
    expect(StockCount::query()->count())->toBe(0)
        ->and((float) BranchProduct::query()->value('stock_quantity'))->toBe(10.0);
});

test('only users who may adjust stock can count, and counts stay inside their branch', function (): void {
    $otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
    $foreign = StockCount::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $otherBranch->id, 'count_number' => 'CNT-OTHER', 'status' => 'draft']);
    Livewire::test('pages::tenant.stock-counts.index')->call('openCount', $foreign->id)->assertNotFound();

    $cashier = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => false,
        'role_id' => Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier'])->id]);
    $this->actingAs($cashier)->get(route('tenant.stock-counts'))->assertForbidden();
});
