<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
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
