<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
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
        ->set('lines', [['product_id' => (string) $this->product->id, 'quantity' => 5]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('تم حفظ سند الإدخال بنجاح');

    $in = StockVoucher::query()->where('tenant_id', $this->tenant->id)->firstOrFail();
    expect($in->type)->toBe('in')->and($in->notes)->toBe('بضاعة وصلت دون فاتورة')
        ->and($in->items)->toHaveCount(1)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(15.0);
    $movement = DB::table('stock_movements')->where('source_id', $in->id)->latest('id')->first();
    expect($movement->source_type)->toBe('stock_in')->and($movement->source_reference)->toBe($in->voucher_number)
        ->and((float) $movement->quantity)->toBe(5.0);

    Livewire::test('pages::tenant.stock-vouchers.index')
        ->set('branchId', $this->branch->id)
        ->set('type', 'out')
        ->set('lines', [['product_id' => (string) $this->product->id, 'quantity' => 4]])
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $this->stock->fresh()->stock_quantity)->toBe(11.0)
        ->and((float) DB::table('stock_movements')->where('source_type', 'stock_out')->value('quantity'))->toBe(-4.0);
});

test('stock out voucher refuses a quantity larger than the branch balance', function (): void {
    Livewire::test('pages::tenant.stock-vouchers.index')
        ->set('branchId', $this->branch->id)
        ->set('type', 'out')
        ->set('lines', [['product_id' => (string) $this->product->id, 'quantity' => 11]])
        ->call('save')
        ->assertHasErrors(['lines']);

    expect(StockVoucher::count())->toBe(0)->and((float) $this->stock->fresh()->stock_quantity)->toBe(10.0);
});
