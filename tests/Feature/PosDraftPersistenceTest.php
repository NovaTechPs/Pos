<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر المسودات']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الرئيسي']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'status' => 'open', 'opened_at' => now(), 'opened_by' => $this->user->id, 'opening_cash' => 0]);
    $this->product = Product::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'صنف المسودة', 'cost_price' => 2]);
    BranchProduct::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 30, 'retail_price' => 12, 'offer_quantity' => 2, 'offer_price' => 10]);
});

test('refresh restores separate cart rows and checkout fields without creating a sale', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')->set('mergeSimilarProducts', false)
        ->call('selectInlineProduct', $this->product->id)->call('selectInlineProduct', $this->product->id)
        ->set('notes', 'ملاحظة محفوظة')->set('paid_amount', 8)->set('payment_method', 'card')->set('discount_amount', 1);
    $cart = $component->get('cart');
    $restored = Livewire::test('pages::tenant.pos.sales')->assertSet('cart', $cart)
        ->assertSet('notes', 'ملاحظة محفوظة')->assertSet('paid_amount', 8)
        ->assertSet('payment_method', 'card')->assertSet('discount_amount', 1);
    expect(array_sum(array_column($restored->get('cart'), 'subtotal')))->toBe(10.0)
        ->and(Order::query()->count())->toBe(0);
});

test('clearing or saving an invoice prevents its draft from returning on refresh', function (string $action): void {
    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)->set('paid_amount', 12);
    $component->call($action);
    if ($action === 'clearCart') {
        $component->assertSet('showClearInvoiceModal', true)->call('confirmClearCart');
    }
    Livewire::test('pages::tenant.pos.sales')->assertSet('cart', [])->assertSet('notes', '');
    expect(Order::query()->count())->toBe($action === 'checkoutAndPrint' ? 1 : 0);
})->with(['clearCart', 'checkoutAndPrint']);

test('starting a new invoice uses the toolbar status without a duplicate notice', function (): void {
    Livewire::test('pages::tenant.pos.sales')
        ->call('selectInlineProduct', $this->product->id)
        ->call('startNewInvoice')
        ->assertSet('cart', [])
        ->assertSet('successMessage', null)
        ->assertSee('فاتورة جديدة جاهزة')
        ->assertDontSee('تم فتح فاتورة جديدة.')
        ->assertDontSee('data-pos-notice-strip', false);
});

test('drafts are isolated between users and branches', function (): void {
    Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $other = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($other);
    Livewire::test('pages::tenant.pos.sales')->assertSet('cart', []);
    $this->actingAs($this->user);
    $otherBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الثاني']);
    $this->user->update(['branch_id' => $otherBranch->id]);
    Livewire::test('pages::tenant.pos.sales')->assertSet('cart', []);
    $this->user->update(['branch_id' => $this->branch->id]);
    $restored = Livewire::test('pages::tenant.pos.sales');
    expect($restored->get('cart'))->toHaveCount(1);
});
