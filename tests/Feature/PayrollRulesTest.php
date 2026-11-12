<?php

use App\Models\Branch;
use App\Models\CasualJob;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeLeave;
use App\Models\EmployeePayroll;
use App\Models\EmployeeShift;
use App\Models\Payment;
use App\Models\PayrollHoliday;
use App\Models\PayrollPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmployeeEarningsService;
use App\Services\PayrollCalculatorService;
use App\Services\PayrollSettings;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'HR rules']);
    $this->branch = Branch::query()->create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id, 'active_branch_id' => $this->branch->id]);
    // October 2026: Friday is the weekly rest day.
    $this->period = PayrollPeriod::query()->create(['title' => 'October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft']);
});

function hrEmployee(object $context, string $salaryType, float $salary, float $hourlyRate = 0): Employee
{
    $employee = Employee::query()->create(['branch_id' => $context->branch->id, 'employee_code' => 'E'.random_int(100, 999),
        'first_name' => 'Sami', 'last_name' => 'Ali', 'hire_date' => '2026-01-01', 'salary_type' => $salaryType,
        'basic_salary' => $salary, 'hourly_rate' => $hourlyRate, 'is_active' => true]);
    EmployeeShift::query()->create(['employee_id' => $employee->id, 'effective_from' => '2026-01-01', 'shift_name' => 'Main',
        'start_time' => '09:00', 'end_time' => '17:00', 'work_days' => ['sat', 'sun', 'mon', 'tue', 'wed', 'thu'], 'grace_period_minutes' => 15]);

    return $employee;
}

test('overtime is paid at its own rate on working days, the weekly rest day and public holidays', function (): void {
    app(PayrollSettings::class)->save($this->tenant->id, ['overtime_multiplier' => 1.5, 'rest_day_multiplier' => 2, 'holiday_multiplier' => 3]);
    $employee = hrEmployee($this, 'hourly', 0, 12);
    PayrollHoliday::query()->create(['date' => '2026-10-07', 'name' => 'عيد', 'is_paid' => true]);
    foreach ([
        ['2026-10-05', '09:00', '18:00', 540, 60],  // Monday: one approved overtime hour
        ['2026-10-09', '09:00', '11:00', 120, 120], // Friday rest day: two hours
        ['2026-10-07', '09:00', '10:00', 60, 60],   // Holiday: one hour
    ] as [$date, $in, $out, $worked, $overtime]) {
        EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => $date, 'check_in' => $in, 'check_out' => $out,
            'status' => 'present', 'worked_minutes' => $worked, 'overtime_minutes' => $overtime, 'overtime_approved_minutes' => $overtime]);
    }

    $earnings = app(EmployeeEarningsService::class)->calculate($employee, $this->period);

    // 1h × 12 × 1.5 + 2h × 12 × 2 + 1h × 12 × 3
    expect($earnings['overtime_pay'])->toBe(102.0)
        ->and($earnings['holiday_days'])->toBe(1)
        // Worked Monday (8h) and the paid holiday (8h); work on the rest day and holiday is overtime only.
        ->and($earnings['basic_salary'])->toBe(192.0);
});

test('a public holiday is never deducted as an absence from a monthly salary', function (): void {
    $employee = hrEmployee($this, 'monthly', 3100);
    PayrollHoliday::query()->create(['date' => '2026-10-07', 'name' => 'عيد', 'is_paid' => true]);
    EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-10-07', 'status' => 'absent']);
    EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-10-08', 'status' => 'absent']);

    $earnings = app(EmployeeEarningsService::class)->calculate($employee, $this->period);

    expect($earnings['unpaid_absent_days'])->toBe(1)
        ->and($earnings['attendance_deductions'])->toBe(100.0);
});

test('annual and sick leave cannot exceed the yearly balance', function (): void {
    $employee = hrEmployee($this, 'monthly', 3000);
    EmployeeLeave::query()->create(['employee_id' => $employee->id, 'type' => 'annual', 'start_date' => '2026-03-01', 'end_date' => '2026-03-10',
        'total_days' => 10, 'status' => 'approved']);

    Livewire::test('pages::tenant.hr.payroll-manager')
        ->set('leave_employee_id', $employee->id)->set('leave_type', 'annual')
        ->set('leave_start_date', '2026-10-11')->set('leave_end_date', '2026-10-15')
        ->call('saveLeave')->assertHasErrors('leave_type');
    expect(EmployeeLeave::query()->count())->toBe(1)
        ->and(app(PayrollSettings::class)->remainingDays($employee, 'annual', '2026-10-11'))->toBe(4.0);

    $employee->update(['annual_leave_days' => 20]);
    Livewire::test('pages::tenant.hr.payroll-manager')
        ->set('leave_employee_id', $employee->id)->set('leave_type', 'annual')
        ->set('leave_start_date', '2026-10-11')->set('leave_end_date', '2026-10-15')
        ->call('saveLeave')->assertHasNoErrors();
    expect(EmployeeLeave::query()->count())->toBe(2);
});

test('penalties and bonuses enter the payroll and lock once it is approved', function (): void {
    $employee = hrEmployee($this, 'monthly', 3000);

    Livewire::test('pages::tenant.hr.payroll-adjustments')
        ->set('kind', 'deduction')->set('employeeId', $employee->id)->set('category', 'damage')
        ->set('amount', '75')->set('date', '2026-10-12')->set('reason', 'كسر رف زجاج')->call('save')->assertHasNoErrors()
        ->set('kind', 'bonus')->set('employeeId', $employee->id)
        ->set('amount', '50')->set('date', '2026-10-20')->set('reason', 'جرد ممتاز')->call('save')->assertHasNoErrors()
        ->assertSee('كسر أو إتلاف')->assertSee('كسر رف زجاج');

    expect(EmployeeDeduction::query()->sole()->category)->toBe('damage');
    app(PayrollCalculatorService::class)->calculateForPeriod($this->period);
    $payroll = EmployeePayroll::query()->sole();
    expect((float) $payroll->total_deductions)->toBe(75.0)
        ->and((float) $payroll->net_salary)->toBe(2975.0);

    $this->period->update(['status' => 'approved']);
    Livewire::test('pages::tenant.hr.payroll-adjustments')
        ->call('delete', 'deduction', EmployeeDeduction::query()->sole()->id)->assertHasErrors('date');
    expect(EmployeeDeduction::query()->count())->toBe(1);
});

test('a casual worker job is paid at once with a voucher that counts as an operating expense', function (): void {
    Livewire::test('pages::tenant.hr.casual-jobs')
        ->set('workerName', 'أبو أحمد')->set('workerPhone', '0599000000')->set('title', 'تحميل كونتينر')
        ->set('jobDate', '2026-10-14')->set('amount', '200')->set('paymentMethod', 'cash')
        ->call('save')->assertHasNoErrors()->assertSee('تحميل كونتينر');

    $job = CasualJob::query()->sole();
    $voucher = Payment::query()->sole();
    $report = fn (string $name): array => (new ReportingService($this->owner, null, false))->prepare($name, [
        'from' => '2026-10-01', 'to' => '2026-10-31', 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all']);
    expect($job->payment_id)->toBe($voucher->id)
        ->and($voucher->voucher_number)->toBe('JOB-'.str_pad((string) $job->id, 6, '0', STR_PAD_LEFT))
        ->and((float) $voucher->amount)->toBe(200.0)
        ->and((float) $report('expenses')['summary']['إجمالي المصروفات'])->toBe(200.0)
        ->and((float) $report('daily-cash')['summary']['مدفوعات نقدية'])->toBe(200.0);

    Livewire::test('pages::tenant.accounting.payment')->call('edit', $voucher->id)->assertSet('paymentId', null);

    Livewire::test('pages::tenant.hr.casual-jobs')->call('delete', $job->id);
    expect(CasualJob::query()->count())->toBe(0)->and(Payment::query()->count())->toBe(0);
});

test('payroll settings and holidays are saved from their tab', function (): void {
    Livewire::test('pages::tenant.hr.payroll-manager')->set('activeTab', 'settings')->assertSee('data-payroll-settings', false);

    Livewire::test('pages::tenant.hr.payroll-settings')
        ->set('overtime_multiplier', 1.5)->set('rest_day_multiplier', 2)->set('holiday_multiplier', 2.5)
        ->set('annual_leave_days', 21)->set('sick_leave_days', 10)->call('saveSettings')->assertHasNoErrors()
        ->set('holidayDate', '2026-12-25')->set('holidayName', 'عطلة')->call('addHoliday')->assertHasNoErrors()
        ->set('overtime_multiplier', 0.5)->call('saveSettings')->assertHasErrors('overtime_multiplier');

    expect(app(PayrollSettings::class)->all($this->tenant->id))->toMatchArray([
        'overtime_multiplier' => 1.5, 'rest_day_multiplier' => 2.0, 'holiday_multiplier' => 2.5, 'annual_leave_days' => 21.0, 'sick_leave_days' => 10.0])
        ->and(PayrollHoliday::query()->sole()->name)->toBe('عطلة');
});
