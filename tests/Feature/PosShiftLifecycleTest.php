<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\BranchSyncOperation;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'متجر الاختبار']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    $this->branch = Branch::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'الفرع الرئيسي',
    ]);
    $this->user->update(['branch_id' => $this->branch->id]);

    $this->actingAs($this->user);
    $this->withSession(['active_tenant_id' => $this->tenant->id]);
});

function posShiftComponent(): Testable
{
    return Livewire::test('pages::tenant.pos.sales');
}

test('the POS displays a live online or offline connectivity status without a simulation control', function (): void {
    $component = posShiftComponent();

    $component->assertSee('أونلاين / Online')
        ->assertSee('أوفلاين / Offline')
        ->assertDontSee('Simulate offline');
});

test('opening a shift validates the opening amount and prevents duplicate open shifts', function (): void {
    $component = posShiftComponent();

    $component->call('openShift')
        ->assertSet('errorMessage', 'افتح نافذة بدء الشيفت أولاً.')
        ->call('triggerOpenShiftModal')
        ->set('opening_cash', -5)
        ->call('openShift')
        ->assertHasErrors(['opening_cash' => 'min']);

    expect(Shift::query()->count())->toBe(0);

    $concurrentComponent = posShiftComponent()->call('triggerOpenShiftModal');

    $component->set('opening_cash', 100)
        ->set('shift_notes', 'رصيد بداية اليوم')
        ->call('openShift')
        ->assertSet('showOpenShiftModal', false);

    $shift = Shift::query()->sole();
    expect((float) $shift->opening_cash)->toBe(100.0)
        ->and($shift->status)->toBe('open')
        ->and($shift->opened_by)->toBe($this->user->id)
        ->and($shift->branch_id)->toBe($this->branch->id);

    $concurrentComponent->set('opening_cash', 500)->call('openShift');

    expect(Shift::query()->count())->toBe(1)
        ->and((float) $shift->fresh()->opening_cash)->toBe(100.0);
});

test('closing a shift uses the latest cash activity and preserves opening and closing notes', function (): void {
    $shift = Shift::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'opening_cash' => 100,
        'status' => 'open',
        'opened_at' => now(),
        'opened_by' => $this->user->id,
        'notes' => 'ملاحظة الفتح',
    ]);

    Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'shift_id' => $shift->id,
        'invoice_number' => 'POS-TEST-1',
        'type' => 'pos',
        'status' => 'completed',
        'subtotal' => 15,
        'total' => 15,
    ]);
    Order::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'shift_id' => $shift->id,
        'invoice_number' => 'RET-TEST-1',
        'type' => 'return',
        'status' => 'completed',
        'subtotal' => -3,
        'total' => -3,
    ]);

    Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'shift_id' => $shift->id,
        'type' => 'receipt',
        'voucher_number' => 'PAY-TEST-1',
        'amount' => 15,
        'payment_method' => 'cash',
        'payment_date' => now(),
    ]);
    Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'shift_id' => $shift->id,
        'type' => 'payment',
        'voucher_number' => 'PAY-TEST-2',
        'amount' => 3,
        'payment_method' => 'cash',
        'payment_date' => now(),
    ]);

    $component = posShiftComponent()->call('prepareCloseShift')
        ->assertSet('shift_expected_cash', 112.0)
        ->assertSet('shift_total_sales', 15.0)
        ->assertSet('shift_total_returns', 3.0);

    Payment::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'shift_id' => $shift->id,
        'type' => 'receipt',
        'voucher_number' => 'PAY-TEST-3',
        'amount' => 10,
        'payment_method' => 'cash',
        'payment_date' => now(),
    ]);

    $component->set('actual_cash', 121)
        ->set('shift_notes', 'ملاحظة الإغلاق')
        ->call('closeShift')
        ->assertSet('showCloseShiftModal', true)
        ->assertSet('shift_expected_cash', 122.0);

    expect($shift->fresh()->status)->toBe('open');

    $component->set('actual_cash', 121)->call('closeShift')
        ->assertSet('showCloseShiftModal', false);

    $closedShift = $shift->fresh();
    expect($closedShift->status)->toBe('closed')
        ->and((float) $closedShift->expected_cash)->toBe(122.0)
        ->and((float) $closedShift->actual_cash)->toBe(121.0)
        ->and((float) $closedShift->difference)->toBe(-1.0)
        ->and((float) $closedShift->total_sales)->toBe(15.0)
        ->and((float) $closedShift->total_returns)->toBe(3.0)
        ->and($closedShift->notes)->toContain('ملاحظة الفتح', 'ملاحظة الإغلاق');
});

test('a shift cannot be closed while the cart contains an unsaved invoice', function (): void {
    $shift = Shift::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'opening_cash' => 0,
        'status' => 'open',
        'opened_at' => now(),
        'opened_by' => $this->user->id,
    ]);

    posShiftComponent()
        ->set('cart', ['line_1' => ['id' => 1, 'name' => 'منتج اختبار', 'quantity' => 1, 'price' => 5, 'subtotal' => 5]])
        ->call('prepareCloseShift')
        ->assertSet('showCloseShiftModal', false)
        ->assertSet('errorMessage', 'احفظ الفاتورة أو ألغها قبل إغلاق الشيفت حتى لا تفقد البيانات.');

    expect($shift->fresh()->status)->toBe('open');
});

test('closing requires opening the cash reconciliation modal first', function (): void {
    $shift = Shift::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'opening_cash' => 50,
        'status' => 'open',
        'opened_at' => now(),
        'opened_by' => $this->user->id,
    ]);

    posShiftComponent()
        ->call('closeShift')
        ->assertSet('showCloseShiftModal', false)
        ->assertSet('errorMessage', 'افتح نافذة التسوية وراجع الرصيد قبل تأكيد الإغلاق.');

    expect($shift->fresh()->status)->toBe('open');
});

test('edge mode queues the closed shift snapshot in the same transaction', function (): void {
    config(['branch-sync.mode' => 'edge']);

    $shift = Shift::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'opening_cash' => 50,
        'status' => 'open',
        'opened_at' => now(),
        'opened_by' => $this->user->id,
    ]);

    posShiftComponent()
        ->call('prepareCloseShift')
        ->set('actual_cash', 50)
        ->call('closeShift')
        ->assertSet('showCloseShiftModal', false);

    $operation = BranchSyncOperation::query()->sole();
    expect($operation->operation_type)->toBe('shift.closed')
        ->and($operation->status)->toBe('pending')
        ->and($operation->payload['shift']['sync_uuid'])->toBe($shift->sync_uuid)
        ->and($operation->payload['shift']['status'])->toBe('closed');
});

test('edge mode queues a shift opening in the same transaction', function (): void {
    config(['branch-sync.mode' => 'edge']);

    posShiftComponent()
        ->call('triggerOpenShiftModal')
        ->set('opening_cash', 75)
        ->set('shift_notes', 'بداية الدوام')
        ->call('openShift');

    $shift = Shift::query()->sole();
    $operation = BranchSyncOperation::query()->sole();
    expect($operation->operation_type)->toBe('shift.opened')
        ->and($operation->status)->toBe('pending')
        ->and($operation->payload['shift']['sync_uuid'])->toBe($shift->sync_uuid)
        ->and((float) $operation->payload['shift']['opening_cash'])->toBe(75.0);
});

test('edge mode queues a complete sale snapshot in the checkout transaction', function (): void {
    config(['branch-sync.mode' => 'edge']);

    Shift::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'opening_cash' => 50,
        'status' => 'open',
        'opened_at' => now(),
        'opened_by' => $this->user->id,
    ]);
    $product = Product::query()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'منتج نقطة البيع',
        'cost_price' => 4,
    ]);
    BranchProduct::query()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'product_id' => $product->id,
        'stock_quantity' => 5,
        'retail_price' => 10,
    ]);

    $component = posShiftComponent()
        ->set('cart', [
            'item_'.$product->id => [
                'id' => $product->id,
                'name' => $product->name,
                'quantity' => 2,
                'price' => 10,
                'cost_price' => 4,
                'subtotal' => 20,
            ],
        ])
        ->set('paid_amount', 20);
    $component->call('checkout');

    expect($component->get('successMessage'))->toContain('تم حفظ الفاتورة');

    $operation = BranchSyncOperation::query()->sole();
    expect($operation->operation_type)->toBe('sale.upsert')
        ->and($operation->status)->toBe('pending')
        ->and($operation->payload['items'][0]['product_id'])->toBe($product->id)
        ->and($operation->payload['order']['sync_uuid'])->toBe(Order::query()->sole()->sync_uuid)
        ->and((float) BranchProduct::query()->value('stock_quantity'))->toBe(3.0);
});
