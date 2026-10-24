<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Deletion store']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->shift = Shift::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'opening_cash' => 0, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $this->user->id,
    ]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Deletion product', 'cost_price' => 4]);
    $this->stock = BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id,
        'retail_price' => 10, 'stock_quantity' => 5,
    ]);
    $this->pos = Livewire::test('pages::tenant.pos.sales')
        ->call('selectInlineProduct', $this->product->id)->set('paid_amount', 10)->call('checkoutAndPrint');
    $this->order = Order::query()->sole();
});

test('POS soft deletes saved invoice and payment while restoring stock and hiding it from navigation and search', function (): void {
    $payment = Payment::query()->where('order_id', $this->order->id)->sole();
    $this->pos->call('loadInvoice', $this->order->id)->assertSee('حذف الفاتورة')
        ->call('deleteInvoice')->assertSet('errorMessage', null)->assertSet('currentInvoiceId', null)->assertSet('cart', []);
    $this->assertSoftDeleted($this->order);
    $this->assertSoftDeleted($payment);
    expect($this->order->items()->count())->toBe(1)
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(5.0)
        ->and(Order::query()->count())->toBe(0);
    $this->pos->call('previousInvoice')->assertSet('currentInvoiceId', null)
        ->set('searchInvoiceQuery', $this->order->invoice_number)->call('searchInvoice')->assertSet('currentInvoiceId', null)
        ->set('currentInvoiceId', $this->order->id)->call('deleteInvoice');
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(5.0);
});

test('POS refuses invoice deletion without permission', function (): void {
    $this->user->update(['is_owner' => false]);
    Livewire::test('pages::tenant.pos.sales')->set('currentInvoiceId', $this->order->id)
        ->call('deleteInvoice')->assertForbidden();
    expect($this->order->fresh()->trashed())->toBeFalse()
        ->and((float) $this->stock->fresh()->stock_quantity)->toBe(4.0);
});

test('POS refuses deleting invoices outside the current branch or tenant', function (string $scope): void {
    if ($scope === 'branch') {
        $other = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Other']);
        $this->order->update(['branch_id' => $other->id]);
    } else {
        $other = Tenant::query()->create(['name' => 'Other tenant']);
        $this->order->update(['tenant_id' => $other->id]);
    }
    $this->pos->set('currentInvoiceId', $this->order->id)->call('deleteInvoice')
        ->assertSet('errorMessage', 'اختر فاتورة محفوظة وغير محذوفة أولاً.');
    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'deleted_at' => null]);
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(4.0);
})->with(['branch', 'tenant']);

test('POS protects closed shifts returns and local sync invoices from deletion', function (string $condition): void {
    if ($condition === 'closed shift') {
        $this->shift->update(['status' => 'closed']);
    } elseif ($condition === 'linked return') {
        $return = $this->order->replicate(['sync_uuid']);
        $return->fill(['invoice_number' => 'RETURN-1', 'type' => 'return', 'original_order_id' => $this->order->id])->save();
    } elseif ($condition === 'return invoice') {
        $this->order->update(['type' => 'return']);
    } else {
        config(['branch-sync.mode' => 'edge']);
    }
    $this->pos->set('currentInvoiceId', $this->order->id)->call('deleteInvoice');
    expect($this->pos->get('errorMessage'))->not->toBeNull();
    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'deleted_at' => null]);
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(4.0);
})->with(['closed shift', 'linked return', 'return invoice', 'edge']);

test('deleting held invoice does not increase stock', function (): void {
    $this->order->update(['status' => 'pending']);
    $this->stock->refresh()->update(['stock_quantity' => 5]);
    $this->pos->set('currentInvoiceId', $this->order->id)->call('deleteInvoice')->assertSet('errorMessage', null);
    $this->assertSoftDeleted($this->order);
    expect((float) $this->stock->fresh()->stock_quantity)->toBe(5.0);
});

test('missing stock rolls back invoice and payment deletion', function (): void {
    $this->stock->delete();
    $this->pos->set('currentInvoiceId', $this->order->id)->call('deleteInvoice');
    expect($this->pos->get('errorMessage'))->not->toBeNull();
    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'deleted_at' => null]);
    expect(Payment::query()->where('order_id', $this->order->id)->count())->toBe(1);
});

test('deleted POS invoices are excluded from report revenue and top products', function (): void {
    $this->pos->set('currentInvoiceId', $this->order->id)->call('deleteInvoice');
    $this->getJson('/api/reports/summary?branch_id='.$this->branch->id)
        ->assertSuccessful()->assertJsonPath('summary.total_sales_count', 0)
        ->assertJsonPath('summary.total_sales_revenue', 0)->assertJsonCount(0, 'top_products');
    Livewire::test('pages::tenant.reports.analytics')
        ->assertViewHas('topProducts', fn ($products): bool => $products->isEmpty())
        ->assertViewHas('stats', fn ($stats): bool => (float) $stats['total_units'] === 0.0);
});

test('delete button stays visible disabled on a new invoice and becomes available for a saved invoice', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->assertSee('حذف الفاتورة')->assertSee('اختر فاتورة محفوظة');
    $readButton = function (string $html): DOMElement {
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return (new DOMXPath($dom))->query('//*[@data-pos-delete-invoice]')->item(0);
    };
    expect($readButton($component->html())->hasAttribute('disabled'))->toBeTrue();
    $component->call('loadInvoice', $this->order->id);
    expect($readButton($component->html())->hasAttribute('disabled'))->toBeFalse();
    $this->user->update(['is_owner' => false]);
    $restricted = Livewire::test('pages::tenant.pos.sales')->assertSee('بدون صلاحية الحذف');
    expect($readButton($restricted->html())->hasAttribute('disabled'))->toBeTrue();
});
