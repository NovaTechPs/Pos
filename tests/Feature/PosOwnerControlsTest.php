<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر التحكم']);
    $this->firstBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الأول']);
    $this->secondBranch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع الثاني']);
    $this->owner = User::factory()->create([
        'type' => 'tenant_user',
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->firstBranch->id,
        'is_owner' => true,
    ]);
    $this->product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'صنف نقطة البيع',
        'cost_price' => 5,
    ]);
    BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->firstBranch->id,
        'product_id' => $this->product->id,
        'retail_price' => 10,
        'stock_quantity' => 8,
    ]);
    BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->secondBranch->id,
        'product_id' => $this->product->id,
        'retail_price' => 20,
        'stock_quantity' => 8,
    ]);
    Shift::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->secondBranch->id,
        'opened_by' => $this->owner->id,
        'opened_at' => now(),
        'status' => 'open',
        'opening_cash' => 0,
    ]);

    $this->actingAs($this->owner)->withSession([
        'active_tenant_id' => $this->tenant->id,
        'active_branch_id' => $this->secondBranch->id,
    ]);
});

test('store owner uses the globally selected branch and can sell below cost at a custom price', function (): void {
    $component = Livewire::test('pages::tenant.pos.sales')
        ->assertSet('selectedBranchId', $this->secondBranch->id)
        ->call('selectInlineProduct', $this->product->id);

    $lineKey = array_key_first($component->get('cart'));
    $component->call('updateCartField', $lineKey, 'price', 3)
        ->assertSet("cart.{$lineKey}.price", 3)
        ->set('paid_amount', 3)
        ->call('checkout')
        ->assertSet('showBelowCostModal', true)
        ->assertSee('aria-labelledby="below-cost-title"', false)
        ->assertSee('data-pos-dialog-action', false)
        ->assertSee('x-trap.inert.noscroll', false)
        ->call('confirmBelowCostCheckout');

    $orderItem = OrderItem::query()->sole();
    $order = Order::query()->sole();

    expect((float) $orderItem->unit_price)->toBe(3.0)
        ->and((int) $order->branch_id)->toBe($this->secondBranch->id);
});

test('cashier without price permission cannot edit the POS selling price', function (): void {
    $permission = Permission::query()->create([
        'name' => 'pos.sale.checkout',
        'display_name' => 'إتمام البيع',
        'group' => 'نقطة البيع',
    ]);
    $role = Role::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'كاشير بدون تعديل سعر']);
    $role->permissions()->attach($permission);
    $cashier = User::factory()->create([
        'type' => 'tenant_user',
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->secondBranch->id,
        'role_id' => $role->id,
        'is_owner' => false,
    ]);
    Shift::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->secondBranch->id,
        'opened_by' => $cashier->id,
        'opened_at' => now(),
        'status' => 'open',
        'opening_cash' => 0,
    ]);
    $this->actingAs($cashier)->withSession([
        'active_tenant_id' => $this->tenant->id,
        'active_branch_id' => $this->secondBranch->id,
    ]);

    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $lineKey = array_key_first($component->get('cart'));
    $component->call('updateUnitPrice', $lineKey, 1)->assertForbidden();

    $component = Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id);
    $lineKey = array_key_first($component->get('cart'));
    $component->set("cart.{$lineKey}.price", 8)
        ->set("cart.{$lineKey}.price_manual", true)
        ->set("cart.{$lineKey}.subtotal", 8)
        ->set('paid_amount', 20)
        ->call('checkout');

    expect(OrderItem::query()->where('product_id', $this->product->id)->pluck('unit_price')->map(fn ($price): float => (float) $price)->all())
        ->toBe([20.0, 20.0]);
});
