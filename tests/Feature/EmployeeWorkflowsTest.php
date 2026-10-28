<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeDeparture;
use App\Models\EmployeePayroll;
use App\Models\PayrollPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\EmployeeAttendanceService;
use App\Services\EmployeeEarningsService;
use App\Services\EmployeeTermsService;
use App\Services\PayrollCalculatorService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-28 09:00:00'));
    $this->tenant = Tenant::create(['name' => 'موارد بشرية']);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'الفرع']);
    $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->actingAs($this->user)->withSession(['active_tenant_id' => $this->tenant->id]);
    $this->employee = Employee::create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id,
        'user_id' => $this->user->id, 'employee_code' => 'HR-1', 'first_name' => 'موظف', 'last_name' => 'الاختبار',
        'hire_date' => '2026-01-01', 'salary_type' => 'monthly', 'basic_salary' => 3100]);
});

function workPeriod(Employee $employee, string $from, string $to): PayrollPeriod
{
    return PayrollPeriod::create(['tenant_id' => $employee->tenant_id, 'title' => 'فترة اختبار', 'start_date' => $from, 'end_date' => $to]);
}

test('multiple attendance sessions preserve the actual hours and enforce duplicate clock protection', function (): void {
    $clock = app(EmployeeAttendanceService::class);
    $this->travelTo(Carbon::parse('2026-10-05 09:00'));
    $clock->clock($this->employee, 'check_in');
    $this->postJson('/api/employee/attendance/clock', ['type' => 'check_in'])->assertConflict();
    $this->travelTo(Carbon::parse('2026-10-05 12:00'));
    $clock->clock($this->employee, 'check_out');
    $this->travelTo(Carbon::parse('2026-10-05 13:00'));
    $this->postJson('/api/employee/attendance/clock', ['type' => 'check_in'])->assertOk();
    $this->travelTo(Carbon::parse('2026-10-05 17:00'));
    $summary = $clock->clock($this->employee, 'check_out');
    expect($summary->sessions()->count())->toBe(2)->and((int) $summary->worked_minutes)->toBe(420)
        ->and($summary->check_in)->toBe('09:00:00')->and($summary->check_out)->toBe('17:00:00');
});

test('overnight attendance remains associated with the work day it started', function (): void {
    app(EmployeeTermsService::class)->schedule($this->employee, ['shift_name' => 'ليلي', 'start_time' => '22:00', 'end_time' => '06:00',
        'work_days' => ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'], 'grace_period_minutes' => 15], '2026-10-01');
    $this->travelTo(Carbon::parse('2026-10-05 22:00'));
    app(EmployeeAttendanceService::class)->clock($this->employee, 'check_in');
    $this->travelTo(Carbon::parse('2026-10-06 06:00'));
    $summary = app(EmployeeAttendanceService::class)->clock($this->employee, 'check_out');
    expect($summary->date)->toBe('2026-10-05')->and((int) $summary->worked_minutes)->toBe(480)->and((int) $summary->late_minutes)->toBe(0);
});

test('salary and work schedule changes retain historical values and payroll uses the effective salary', function (): void {
    $terms = app(EmployeeTermsService::class);
    $terms->salary($this->employee, ['salary_type' => 'monthly', 'basic_salary' => 6200, 'hourly_rate' => 0], '2026-10-16');
    expect($terms->ratesOn($this->employee, '2026-10-15')['basic_salary'])->toBe(3100.0)
        ->and($terms->ratesOn($this->employee, '2026-10-16')['basic_salary'])->toBe(6200.0);
    $schedule = ['shift_name' => 'صباحي', 'start_time' => '09:00', 'end_time' => '17:00', 'work_days' => ['mon'], 'grace_period_minutes' => 10];
    $terms->schedule($this->employee, $schedule, '2026-10-01');
    $terms->schedule($this->employee, array_merge($schedule, ['start_time' => '10:00', 'end_time' => '18:00']), '2026-10-16');
    expect($terms->scheduleOn($this->employee, '2026-10-15')->start_time)->toStartWith('09:00')
        ->and($terms->scheduleOn($this->employee, '2026-10-16')->start_time)->toStartWith('10:00');
    $period = workPeriod($this->employee, '2026-10-01', '2026-10-31');
    app(PayrollCalculatorService::class)->calculateForPeriod($period);
    expect((float) EmployeePayroll::sole()->net_salary)->toBe(4700.0);
    $period->update(['status' => 'approved']);
    expect(fn () => $terms->salary($this->employee, ['salary_type' => 'monthly', 'basic_salary' => 1, 'hourly_rate' => 0], '2026-10-20'))
        ->toThrow(ValidationException::class);
});

test('hourly daily and production earnings are calculated from attendance rather than a flat monthly value', function (string $type, float $salary, float $hour, float $expected): void {
    app(EmployeeTermsService::class)->salary($this->employee, ['salary_type' => $type, 'basic_salary' => $salary, 'hourly_rate' => $hour], '2026-01-01');
    $attendance = app(EmployeeAttendanceService::class)->manual($this->employee, '2026-10-05', '09:00', '17:00', 'present');
    $attendance->update(['production_units' => 5]);
    $earnings = app(EmployeeEarningsService::class)->calculate($this->employee, workPeriod($this->employee, '2026-10-05', '2026-10-05'));
    expect($earnings['basic_salary'])->toBe($expected);
})->with([['hourly', 0.0, 20.0, 160.0], ['daily', 80.0, 0.0, 80.0], ['production', 2.0, 0.0, 10.0]]);

test('late time and overlapping deductible departures are charged once and paid departures cover gaps', function (): void {
    app(EmployeeTermsService::class)->schedule($this->employee, ['shift_name' => 'دوام', 'start_time' => '09:00', 'end_time' => '17:00', 'work_days' => ['mon'], 'grace_period_minutes' => 0], '2026-10-01');
    app(EmployeeAttendanceService::class)->manual($this->employee, '2026-10-05', '09:30', '17:00', 'present');
    $departure = EmployeeDeparture::create(['employee_id' => $this->employee->id, 'date' => '2026-10-05', 'start_time' => '09:00', 'end_time' => '10:00', 'minutes' => 900, 'status' => 'approved', 'is_deductible' => true]);
    $period = workPeriod($this->employee, '2026-10-05', '2026-10-05');
    $earnings = app(EmployeeEarningsService::class)->calculate($this->employee, $period);
    expect((int) $departure->minutes)->toBe(60)->and($earnings['attendance_deductions'])->toBe(12.5);
    $departure->update(['is_deductible' => false]);
    expect(app(EmployeeEarningsService::class)->calculate($this->employee, $period)['attendance_deductions'])->toBe(0.0);
});

test('only approved overtime is paid and preview matches the payroll snapshot', function (): void {
    app(EmployeeTermsService::class)->salary($this->employee, ['salary_type' => 'hourly', 'basic_salary' => 0, 'hourly_rate' => 20], '2026-01-01');
    $attendance = app(EmployeeAttendanceService::class)->manual($this->employee, '2026-10-05', '09:00', '19:00', 'present');
    $period = workPeriod($this->employee, '2026-10-05', '2026-10-05');
    $calculator = app(PayrollCalculatorService::class);
    expect($calculator->preview($this->employee, $period)['net_due'])->toBe(160.0);
    $attendance->update(['overtime_approved_minutes' => 120]);
    $preview = $calculator->preview($this->employee, $period);
    $calculator->calculateForPeriod($period);
    expect($preview['net_due'])->toBe(200.0)->and((float) EmployeePayroll::sole()->net_salary)->toBe($preview['net_due']);
});

test('ending service disables access but retains history and pays the final partial period', function (): void {
    $period = workPeriod($this->employee, '2026-10-01', '2026-10-31');
    app(EmployeeTermsService::class)->terminate($this->employee, '2026-10-15', 'انتهاء العقد');
    expect($this->employee->fresh()->is_active)->toBeFalse()->and($this->user->fresh()->is_active)->toBeFalse()
        ->and($this->employee->salaryHistories()->count())->toBe(1);
    app(PayrollCalculatorService::class)->calculateForPeriod($period);
    expect((float) EmployeePayroll::sole()->net_salary)->toBe(1500.0);
});

test('employee terms and approvals are available through the HR screen', function (): void {
    Livewire::test('pages::tenant.hr.payroll-manager')->call('openEmployeeTerms', $this->employee->id)
        ->assertSet('showTermsModal', true)->assertSee('تاريخ الرواتب والجداول')
        ->set('termsEffectiveFrom', '2026-10-16')->set('termsBasicSalary', 6200)->call('saveSalaryTerms')->assertHasNoErrors()
        ->call('saveScheduleTerms')->assertHasNoErrors();
    expect($this->employee->salaryHistories()->count())->toBe(2)->and($this->employee->shifts()->count())->toBe(1);
});

test('approved payroll blocks later attendance edits and unresolved sessions block payroll calculation', function (): void {
    app(EmployeeAttendanceService::class)->clock($this->employee, 'check_in');
    $period = workPeriod($this->employee, '2026-10-01', '2026-10-31');
    expect(fn () => app(PayrollCalculatorService::class)->calculateForPeriod($period))->toThrow(ValidationException::class);
    $this->travelTo(Carbon::parse('2026-10-28 17:00'));
    app(EmployeeAttendanceService::class)->clock($this->employee, 'check_out');
    app(PayrollCalculatorService::class)->calculateForPeriod($period);
    $period->update(['status' => 'approved']);
    expect(fn () => app(EmployeeAttendanceService::class)->manual($this->employee, '2026-10-28', '10:00', '16:00', 'present'))
        ->toThrow(ValidationException::class);
});

test('overnight departures detect next day overlap and reject invalid duration', function (): void {
    EmployeeDeparture::create(['employee_id' => $this->employee->id, 'date' => '2026-10-05',
        'start_time' => '23:00', 'end_time' => '02:00', 'ends_next_day' => true, 'status' => 'approved', 'is_deductible' => true]);
    expect(fn () => EmployeeDeparture::create(['employee_id' => $this->employee->id, 'date' => '2026-10-06',
        'start_time' => '01:00', 'end_time' => '03:00', 'status' => 'pending', 'is_deductible' => true]))->toThrow(ValidationException::class);
});

test('invalid overtime approval rolls back an attendance edit', function (): void {
    $attendance = app(EmployeeAttendanceService::class)->manual($this->employee, '2026-10-05', '09:00', '17:00', 'present');
    Livewire::test('pages::tenant.hr.payroll-manager')->call('editAttendance', $attendance->id)
        ->set('edit_check_out', '18:00')->set('editApprovedOvertime', 500)->call('updateAttendance')
        ->assertHasErrors('editApprovedOvertime');
    expect($attendance->fresh()->sessions()->first()->checked_out_at->format('H:i'))->toBe('17:00');
});

test('approved payroll cannot be deleted even before its first payment', function (): void {
    $period = workPeriod($this->employee, '2026-10-01', '2026-10-31');
    app(PayrollCalculatorService::class)->calculateForPeriod($period);
    $period->update(['status' => 'approved']);
    expect(fn () => DB::table('employee_payrolls')->delete())->toThrow(QueryException::class);
    expect(EmployeePayroll::count())->toBe(1);
});
