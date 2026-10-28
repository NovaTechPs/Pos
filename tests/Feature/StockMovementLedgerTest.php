<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\StockMovementContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    $tenant = Tenant::create(['name' => 'مخزون']);
    $branch = Branch::create(['tenant_id' => $tenant->id, 'name' => 'فرع']);
    $product = Product::create(['tenant_id' => $tenant->id, 'name' => 'صنف']);
    $this->stock = BranchProduct::create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'product_id' => $product->id, 'stock_quantity' => 10, 'retail_price' => 5]);
});

test('stock ledger records opening and document changes and permits negative inventory', function (): void {
    StockMovementContext::reference('order', 123, fn () => BranchProduct::whereKey($this->stock->id)->decrement('stock_quantity', 12), 'INV-123');
    $rows = DB::table('stock_movements')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and((float) $rows[0]->quantity)->toBe(10.0)
        ->and($rows[1]->source_type)->toBe('order')
        ->and((int) $rows[1]->source_id)->toBe(123)
        ->and((float) $rows[1]->quantity_after)->toBe(-2.0);
});

test('raw inventory writes are captured without inheriting a previous document', function (): void {
    StockMovementContext::reference('purchase', 42, fn () => BranchProduct::whereKey($this->stock->id)->increment('stock_quantity', 5));
    DB::table('branch_products')->where('id', $this->stock->id)->update(['stock_quantity' => 12]);
    $row = DB::table('stock_movements')->orderByDesc('id')->first();
    expect($row->source_type)->toBe('manual')->and($row->source_id)->toBeNull()
        ->and((float) $row->quantity)->toBe(-3.0);
});

test('inventory and movement roll back together', function (): void {
    try {
        DB::transaction(function (): void {
            BranchProduct::whereKey($this->stock->id)->increment('stock_quantity', 3);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(10.0)
        ->and(DB::table('stock_movements')->count())->toBe(1);
});

test('ledger cannot be edited or deleted by direct SQL', function (string $operation): void {
    expect(fn () => $operation === 'delete' ? DB::table('stock_movements')->delete() : DB::table('stock_movements')->update(['source_type' => 'tampered']))->toThrow(QueryException::class);
})->with(['delete', 'update']);

test('stock movement screen filters by product number and isolates tenants', function (): void {
    $tenant = Tenant::findOrFail($this->stock->tenant_id);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $this->stock->product->update(['product_number' => 'LEDGER-100']);
    $other = Tenant::create(['name' => 'منشأة أخرى']);
    $otherBranch = Branch::create(['tenant_id' => $other->id, 'name' => 'فرع آخر']);
    $otherProduct = Product::create(['tenant_id' => $other->id, 'name' => 'صنف سري']);
    BranchProduct::create(['tenant_id' => $other->id, 'branch_id' => $otherBranch->id, 'product_id' => $otherProduct->id, 'stock_quantity' => 2]);
    $this->actingAs($user)->withSession(['active_tenant_id' => $tenant->id]);
    $this->get(route('tenant.stock-movements'))->assertOk()->assertSee('سجل حركات المخزون');
    Livewire::test('pages::tenant.stock-movements.index')->set('search', 'LEDGER-100')
        ->assertViewHas('movements', fn ($rows): bool => $rows->total() === 1)
        ->assertDontSee('صنف سري');
});
