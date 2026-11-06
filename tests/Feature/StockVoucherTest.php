<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\StockVoucher;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'سندات مخزون']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'المخزن الرئيسي']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'is_owner' => true]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف سند', 'product_number' => 'VOUCHER-1']);
    $this->stock = BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 10]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
});

test('stock in and out vouchers update inventory and leave auditable ledger entries', function (): void {
    Livewire::test('pages::tenant.stock-vouchers.index')
        ->set('branchId', $this->branch->id)
        ->set('type', 'in')
        ->set('notes', 'بضاعة وصلت دون فاتورة')
        ->set('lines', [['product_id' => (string) $this->product->id, 'quantity' => 5, 'unit_cost' => 7.5]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('تم حفظ سند الإدخال بنجاح');

    $in = StockVoucher::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($in->type)->toBe('in')->and($in->notes)->toBe('بضاعة وصلت دون فاتورة')
        ->and($in->items)->toHaveCount(1)
        ->and((float) $in->items->first()->unit_cost)->toBe(7.5)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(15.0);
    $movement = DB::table('stock_movements')->where('source_id', $in->id)->latest('id')->first();
    expect($movement->source_type)->toBe('stock_in')->and($movement->source_reference)->toBe($in->voucher_number)
        ->and((float) $movement->quantity)->toBe(5.0);

    Livewire::test('pages::tenant.stock-vouchers.index')
        ->set('branchId', $this->branch->id)
        ->set('type', 'out')
        ->set('lines', [['product_id' => (string) $this->product->id, 'quantity' => 4, 'unit_cost' => 7.5]])
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $this->stock->fresh()->stock_quantity)->toBe(11.0)
        ->and((float) DB::table('stock_movements')->where('source_type', 'stock_out')->value('quantity'))->toBe(-4.0);
});

test('stock out voucher refuses a quantity larger than the branch balance', function (): void {
    Livewire::test('pages::tenant.stock-vouchers.index')
        ->set('branchId', $this->branch->id)
        ->set('type', 'out')
        ->set('lines', [['product_id' => (string) $this->product->id, 'quantity' => 11, 'unit_cost' => 0]])
        ->call('save')
        ->assertHasErrors(['lines']);

    expect(StockVoucher::count())->toBe(0)->and((float) $this->stock->fresh()->stock_quantity)->toBe(10.0);
});

test('stock vouchers find a product by barcode and add it to the voucher line', function (): void {
    ProductBarcode::create([
        'tenant_id' => $this->tenant->id,
        'product_id' => $this->product->id,
        'barcode' => '9876543210123',
    ]);

    Livewire::test('pages::tenant.stock-vouchers.index')
        ->set('branchId', $this->branch->id)
        ->set('productSearch', '9876543210123')
        ->assertViewHas('products', fn ($products): bool => $products->pluck('id')->all() === [$this->product->id])
        ->call('addProduct', $this->product->id)
        ->assertSet('lines.0.product_id', (string) $this->product->id)
        ->assertSet('lines.0.quantity', 1)
        ->assertSet('lines.0.unit_cost', 0.0)
        ->assertSet('productSearch', '')
        ->call('addProduct', $this->product->id)
        ->assertSet('errorMessage', 'الصنف موجود بالفعل في السند. عدّل كميته من الجدول.');
});

test('stock voucher line defaults to the product cost and stores an edited cost with quantity', function (): void {
    $this->product->update(['cost_price' => 8.25]);
    $component = Livewire::test('pages::tenant.stock-vouchers.index')
        ->set('branchId', $this->branch->id)
        ->call('addProduct', $this->product->id)
        ->assertSet('lines.0.unit_cost', 8.25)
        ->set('lines.0.quantity', 3.5)
        ->set('lines.0.unit_cost', 9.75)
        ->call('save')
        ->assertHasNoErrors();

    $item = StockVoucher::query()->with('items')->sole()->items->sole();
    expect((float) $item->quantity)->toBe(3.5)
        ->and((float) $item->unit_cost)->toBe(9.75)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(13.5);
});
