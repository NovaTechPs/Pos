<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeePayroll;
use App\Models\EmployeePayrollCarryover;
use App\Models\PayrollPeriod;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PayrollCalculatorService;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

function payrollTenantFixture(string $name): array
{
    $tenant = Tenant::query()->create(['name' => $name]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'is_owner' => true]);
    $employee = Employee::query()->create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'employee_code' => $name, 'first_name' => $name, 'last_name' => 'Employee', 'hire_date' => '2026-01-01', 'basic_salary' => 1000, 'salary_type' => 'monthly', 'is_active' => true]);

    return [$tenant, $user, $employee];
}

test('payroll and employee records are isolated between tenants', function (): void {
    [$first, $user, $employee] = payrollTenantFixture('First');
    [$second, $otherUser, $otherEmployee] = payrollTenantFixture('Second');
    EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => '2026-10-01', 'status' => 'present']);
    EmployeeAttendance::query()->create(['employee_id' => $otherEmployee->id, 'date' => '2026-10-01', 'status' => 'present']);
    $period = PayrollPeriod::query()->create(['tenant_id' => $first->id, 'title' => 'First October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft']);
    $otherPeriod = PayrollPeriod::query()->create(['tenant_id' => $second->id, 'title' => 'Second October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft']);
    $this->actingAs($user);
    expect(Employee::query()->pluck('id')->all())->toBe([$employee->id])
        ->and(EmployeeAttendance::query()->count())->toBe(1)
        ->and(PayrollPeriod::query()->pluck('id')->all())->toBe([$period->id])
        ->and(PayrollPeriod::query()->find($otherPeriod->id))->toBeNull();
    app(PayrollCalculatorService::class)->calculateForPeriod($period);
    expect(EmployeePayroll::query()->count())->toBe(1)
        ->and(EmployeePayroll::query()->sole()->employee_id)->toBe($employee->id);
});

test('creating payroll data for another tenant is rejected', function (): void {
    [, $user] = payrollTenantFixture('First');
    [, , $otherEmployee] = payrollTenantFixture('Second');
    $this->actingAs($user);
    expect(fn () => EmployeeAttendance::query()->create(['employee_id' => $otherEmployee->id, 'date' => '2026-10-01', 'status' => 'present']))
        ->toThrow(HttpException::class);
    expect(EmployeeAttendance::withoutGlobalScopes()->count())->toBe(0);
});

test('payroll overpayments create a deductible carryover and apply it only to a later period', function (): void {
    [$tenant, $user, $employee] = payrollTenantFixture('Carryover');
    $period = PayrollPeriod::query()->create(['tenant_id' => $tenant->id, 'title' => 'September', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'approved']);
    $payroll = EmployeePayroll::query()->create(['payroll_period_id' => $period->id, 'employee_id' => $employee->id, 'basic_salary' => 1000, 'net_salary' => 1000, 'paid_amount' => 0]);
    $this->withSession(['active_tenant_id' => $tenant->id]);
    Livewire::actingAs($user)->test('pages::tenant.hr.payroll-manager')
        ->set('payment_employee_id', $employee->id)->set('payment_period_id', $period->id)
        ->set('payment_amount', 1100)->set('payment_date', '2026-09-30')
        ->call('savePayment')->assertHasNoErrors();
    $carryover = EmployeePayrollCarryover::query()->sole();
    expect($carryover->type)->toBe('deduction')->and((float) $carryover->remaining_amount)->toBe(100.0);
    $next = PayrollPeriod::query()->create(['title' => 'October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft']);
    app(PayrollCalculatorService::class)->calculateForPeriod($next);
    expect((float) EmployeePayroll::query()->where('payroll_period_id', $next->id)->sole()->net_salary)->toBe(900.0);
    Livewire::actingAs($user)->test('pages::tenant.hr.payroll-manager')
        ->set('selectedPeriodId', $next->id)->call('approvePayroll')->assertHasNoErrors();
    expect((float) $carryover->fresh()->remaining_amount)->toBe(0.0)
        ->and((float) $carryover->fresh()->applied_amount)->toBe(100.0)
        ->and($carryover->fresh()->status)->toBe('applied');
});

test('payroll calculation is tenant scoped even outside an authenticated request', function (): void {
    [$tenant, , $employee] = payrollTenantFixture('Console first');
    payrollTenantFixture('Console second');
    $period = PayrollPeriod::query()->create(['tenant_id' => $tenant->id, 'title' => 'October', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'status' => 'draft']);
    app(PayrollCalculatorService::class)->calculateForPeriod($period);
    expect(EmployeePayroll::query()->count())->toBe(1)
        ->and(EmployeePayroll::query()->sole()->employee_id)->toBe($employee->id);
});
