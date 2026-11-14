<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'متجر الترقيم']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    Shift::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opened_by' => $this->user->id, 'opened_at' => now(), 'status' => 'open', 'opening_cash' => 0]);
    $this->product = Product::create(['tenant_id' => $this->tenant->id, 'name' => 'صنف', 'cost_price' => 2]);
    BranchProduct::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'product_id' => $this->product->id, 'stock_quantity' => 50, 'retail_price' => 10, 'wholesale_price' => 10]);

    $this->sell = function (): string {
        Livewire::test('pages::tenant.pos.sales')->call('selectInlineProduct', $this->product->id)->call('checkout')->assertHasNoErrors();

        return (string) Order::query()->latest('id')->value('invoice_number');
    };
});

test('point of sale invoices get a short running number per branch', function (): void {
    $prefix = 'A'.$this->branch->id.'-';
    // An invoice saved before the change keeps its nine random digits and does not move the sequence.
    Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => $prefix.'483920174',
        'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10, 'payment_status' => 'paid']);

    expect(($this->sell)())->toBe($prefix.'1')
        ->and(($this->sell)())->toBe($prefix.'2');
});

test('the number of a deleted invoice is not used again', function (): void {
    $prefix = 'A'.$this->branch->id.'-';
    ($this->sell)();
    ($this->sell)();
    Order::query()->where('invoice_number', $prefix.'2')->firstOrFail()->delete();

    expect(($this->sell)())->toBe($prefix.'3');
});

test('the sequence keeps counting past nine', function (): void {
    $prefix = 'A'.$this->branch->id.'-';
    foreach (['9', '10'] as $sequence) {
        Order::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'invoice_number' => $prefix.$sequence,
            'type' => 'pos', 'status' => 'completed', 'subtotal' => 10, 'total' => 10, 'paid_amount' => 10, 'payment_status' => 'paid']);
    }

    expect(($this->sell)())->toBe($prefix.'11');
});
