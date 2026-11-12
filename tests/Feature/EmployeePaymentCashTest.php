<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeePayment;
use App\Models\EmployeePayroll;
use App\Models\Payment;
use App\Models\PayrollPeriod;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Payroll cash']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    $this->employee = Employee::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'employee_code' => 'E1',
        'first_name' => 'Sami', 'last_name' => 'Ali', 'hire_date' => '2026-01-01', 'basic_salary' => 1000, 'salary_type' => 'monthly', 'is_active' => true]);
    $this->period = PayrollPeriod::query()->create(['tenant_id' => $this->tenant->id, 'title' => 'October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'approved']);
    EmployeePayroll::query()->create(['payroll_period_id' => $this->period->id, 'employee_id' => $this->employee->id, 'basic_salary' => 1000, 'net_salary' => 1000, 'paid_amount' => 0]);
});

/** @return array<string, mixed> */
function payrollCashReport(object $context, string $report): array
{
    return (new ReportingService($context->owner, null, false))->prepare($report, [
        'from' => '2026-10-01', 'to' => '2026-10-31', 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all',
    ]);
}

function paySalary(object $context, float $amount, string $method = 'cash'): void
{
    Livewire::test('pages::tenant.hr.payroll-manager')
        ->set('payment_employee_id', $context->employee->id)->set('payment_period_id', $context->period->id)
        ->set('payment_amount', $amount)->set('payment_date', '2026-10-31')->set('payment_method', $method)
        ->call('savePayment')->assertHasNoErrors();
}

test('a cash salary paid during an open shift leaves that drawer and appears in the cash reports', function (): void {
    $shift = Shift::query()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'opening_cash' => 0,
        'status' => 'open', 'opened_at' => now(), 'opened_by' => $this->owner->id]);

    paySalary($this, 400);

    $voucher = Payment::query()->sole();
    expect($voucher->employee_payment_id)->toBe(EmployeePayment::query()->sole()->id)
        ->and($voucher->type)->toBe('payment')
        ->and($voucher->voucher_number)->toStartWith('SAL-')
        ->and((float) $voucher->amount)->toBe(400.0)
        ->and($voucher->payment_method)->toBe('cash')
        ->and($voucher->shift_id)->toBe($shift->id)
        ->and($voucher->branch_id)->toBe($this->branch->id)
        ->and($voucher->notes)->toContain('Sami Ali', 'October')
        ->and((float) payrollCashReport($this, 'daily-cash')['summary']['مدفوعات نقدية'])->toBe(400.0)
        ->and((float) payrollCashReport($this, 'expenses')['summary']['إجمالي المصروفات'])->toBe(0.0);

    // The profit report subtracts the salary once, in its own line.
    $profit = collect(payrollCashReport($this, 'profit')['query']->get())->pluck('amount', 'label');
    expect((float) $profit['المصروفات التشغيلية المسجلة'])->toBe(0.0)
        ->and((float) $profit['رواتب مصروفة خلال الفترة (دون السلف)'])->toBe(400.0);
});

test('a salary paid outside a shift or by bank transfer does not touch a drawer', function (): void {
    paySalary($this, 300, 'bank_transfer');
    paySalary($this, 200, 'check');

    $vouchers = Payment::query()->orderBy('id')->get();
    expect($vouchers->pluck('shift_id')->all())->toBe([null, null])
        ->and($vouchers->pluck('payment_method')->all())->toBe(['bank_transfer', 'cheque']);
});

test('salary vouchers are managed from payroll and cannot be edited or deleted as vouchers', function (): void {
    paySalary($this, 250);
    $voucher = Payment::query()->sole();

    Livewire::test('pages::tenant.accounting.payment')->call('edit', $voucher->id)->assertSet('paymentId', null)
        ->call('confirmDelete', $voucher->id)->assertSet('deleteId', null)
        ->call('openCreate')->call('previousVoucher')->assertSet('paymentId', null);
    expect(Payment::query()->find($voucher->id))->not->toBeNull();
});

test('an advance paid through the API is recorded as a payment voucher', function (): void {
    $advance = EmployeeAdvance::query()->create(['employee_id' => $this->employee->id, 'amount' => 500, 'installment_amount' => 100,
        'start_installment_date' => '2026-11-01', 'status' => 'active']);

    $this->postJson('/api/employee/advances/'.$advance->id.'/pay', ['amount' => 500, 'payment_method' => 'cash', 'payment_date' => '2026-10-15'])
        ->assertCreated();

    $voucher = Payment::query()->sole();
    expect($voucher->voucher_number)->toStartWith('ADV-')
        ->and((float) $voucher->amount)->toBe(500.0)
        ->and($voucher->notes)->toContain('سلفة')
        ->and((float) payrollCashReport($this, 'daily-cash')['summary']['مدفوعات نقدية'])->toBe(500.0)
        ->and((float) payrollCashReport($this, 'expenses')['summary']['إجمالي المصروفات'])->toBe(0.0);
});
