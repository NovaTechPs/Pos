<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeBonus;
use App\Models\EmployeeDeduction;
use App\Models\EmployeePayroll;
use App\Models\EmployeePayrollCarryover;
use App\Models\EmployeePayrollItem;
use App\Models\PayrollPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollCalculatorService
{
    public function __construct(private EmployeeEarningsService $earnings) {}

    /**
     * Calculate draft payrolls. Approved/paid periods and payrolls with actual
     * payments are deliberately immutable through this method.
     */
    public function calculateForPeriod(PayrollPeriod $period): void
    {
        DB::transaction(function () use ($period) {
            $period = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if (! $period->tenant_id) {
                throw ValidationException::withMessages([
                    'selectedPeriodId' => 'يجب ربط فترة الرواتب بمنشأة قبل احتسابها.',
                ]);
            }

            if (in_array($period->status, ['approved', 'paid'], true)) {
                throw ValidationException::withMessages([
                    'selectedPeriodId' => 'لا يمكن إعادة احتساب مسير معتمد أو مدفوع. أنشئ تسوية مستقلة للتصحيح.',
                ]);
            }

            $employees = Employee::query()
                ->where('tenant_id', $period->tenant_id)
                ->where(fn ($q) => $q->where('is_active', true)->orWhereDate('terminated_at', '>=', $period->start_date))
                ->whereDate('hire_date', '<=', $period->end_date)
                ->orderBy('id')
                ->get();

            foreach ($employees as $employee) {
                $payroll = EmployeePayroll::query()
                    ->where('payroll_period_id', $period->id)
                    ->where('employee_id', $employee->id)
                    ->lockForUpdate()
                    ->first();

                if ($payroll && ((float) $payroll->paid_amount > 0 || $payroll->payments()->exists())) {
                    throw ValidationException::withMessages([
                        'selectedPeriodId' => "لا يمكن إعادة احتساب راتب {$employee->first_name} {$employee->last_name} بعد تسجيل دفعة فعلية.",
                    ]);
                }

                $earnings = $this->earnings->calculate($employee, $period);
                if ($earnings['open_sessions'] > 0) {
                    throw ValidationException::withMessages(['selectedPeriodId' => 'توجد جلسات دوام مفتوحة للموظف '.$employee->first_name.'. أغلقها قبل احتساب المسير.']);
                }
                $basicSalary = $earnings['basic_salary'];

                // SQL OR returns each matching row once; period-linked rows are not duplicated.
                $bonuses = (float) EmployeeBonus::query()
                    ->where('employee_id', $employee->id)
                    ->where(function ($query) use ($period) {
                        $query->where('payroll_period_id', $period->id)
                            ->orWhere(function ($fallback) use ($period) {
                                $fallback->whereNull('payroll_period_id')
                                    ->whereBetween('date', [$period->start_date, $period->end_date]);
                            });
                    })->sum('amount') + $earnings['overtime_pay'];

                $directDeductions = (float) EmployeeDeduction::query()
                    ->where('employee_id', $employee->id)
                    ->where(function ($query) use ($period) {
                        $query->where('payroll_period_id', $period->id)
                            ->orWhere(function ($fallback) use ($period) {
                                $fallback->whereNull('payroll_period_id')
                                    ->whereBetween('date', [$period->start_date, $period->end_date]);
                            });
                    })->sum('amount') + $earnings['attendance_deductions'];

                // All eligible active advances, not only the first one.
                $advanceRows = EmployeeAdvance::query()
                    ->where('employee_id', $employee->id)
                    ->where('status', 'active')
                    ->whereDate('start_installment_date', '<=', $period->end_date)
                    ->whereColumn('paid_amount', '<', 'amount')
                    ->orderBy('start_installment_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $advanceDeduction = 0.0;
                $advancePlans = [];
                foreach ($advanceRows as $advance) {
                    $remaining = max(0, round((float) $advance->amount - (float) $advance->paid_amount, 2));
                    $installment = min(round((float) $advance->installment_amount, 2), $remaining);
                    if ($installment <= 0) {
                        continue;
                    }
                    $advanceDeduction += $installment;
                    $advancePlans[] = ['advance' => $advance, 'amount' => $installment];
                }

                // Reserve pending payroll-overpayment carryovers for this period.
                $carryovers = EmployeePayrollCarryover::query()
                    ->where('employee_id', $employee->id)
                    ->whereIn('status', ['pending', 'partially_applied'])
                    ->where('remaining_amount', '>', 0)
                    ->whereHas('sourcePayroll.payrollPeriod', function ($query) use ($period) {
                        $query->whereDate('end_date', '<', $period->start_date);
                    })
                    ->where(function ($query) use ($period) {
                        $query->whereNull('target_period_id')
                            ->orWhere('target_period_id', $period->id);
                    })
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $netBeforeCarryover = max(0, round($basicSalary + $bonuses - $directDeductions - $advanceDeduction, 2));
                $carryoverBudget = $netBeforeCarryover;
                $carryoverDeduction = 0.0;
                $carryoverPlans = [];

                foreach ($carryovers as $carryover) {
                    if ($carryoverBudget <= 0) {
                        break;
                    }
                    $amount = min(round((float) $carryover->remaining_amount, 2), $carryoverBudget);
                    if ($amount <= 0) {
                        continue;
                    }
                    $carryoverDeduction += $amount;
                    $carryoverBudget -= $amount;
                    $carryoverPlans[] = ['carryover' => $carryover, 'amount' => $amount];
                    // Reserve this carryover so another draft period cannot claim it.
                    $carryover->targetPeriod()->associate($period);
                    $carryover->save();
                }

                $totalDeductions = round($directDeductions + $carryoverDeduction, 2);
                $netSalary = max(0, round($basicSalary + $bonuses - $totalDeductions - $advanceDeduction, 2));

                if (! $payroll) {
                    $payroll = new EmployeePayroll;
                    $payroll->payrollPeriod()->associate($period);
                    $payroll->employee()->associate($employee);
                    $payroll->paid_amount = 0;
                    $payroll->payment_status = 'unpaid';
                }

                $payroll->basic_salary = $basicSalary;
                $payroll->total_allowances = round($bonuses, 2);
                $payroll->total_deductions = $totalDeductions;
                $payroll->advance_deduction = round($advanceDeduction, 2);
                $payroll->net_salary = $netSalary;
                $payroll->earnings_breakdown = $earnings + ['bonuses' => round($bonuses, 2), 'deductions' => round($directDeductions, 2), 'advances' => round($advanceDeduction, 2), 'carryovers' => round($carryoverDeduction, 2)];
                $payroll->save();

                $payroll->items()->delete();

                if ($bonuses > 0) {
                    EmployeePayrollItem::create([
                        'employee_payroll_id' => $payroll->id,
                        'type' => 'addition',
                        'title' => 'مكافآت وبدلات الفترة',
                        'amount' => round($bonuses, 2),
                    ]);
                }

                if ($directDeductions > 0) {
                    EmployeePayrollItem::create([
                        'employee_payroll_id' => $payroll->id,
                        'type' => 'deduction',
                        'title' => 'الخصومات المباشرة وخصومات الدوام للفترة',
                        'amount' => round($directDeductions, 2),
                    ]);
                }

                foreach ($advancePlans as $plan) {
                    EmployeePayrollItem::create([
                        'employee_payroll_id' => $payroll->id,
                        'employee_advance_id' => $plan['advance']->id,
                        'type' => 'deduction',
                        'title' => 'قسط سلفة: '.($plan['advance']->notes ?: ('سلفة #'.$plan['advance']->id)),
                        'amount' => $plan['amount'],
                    ]);
                }

                foreach ($carryoverPlans as $plan) {
                    EmployeePayrollItem::create([
                        'employee_payroll_id' => $payroll->id,
                        'employee_payroll_carryover_id' => $plan['carryover']->id,
                        'type' => 'deduction',
                        'title' => 'دفعة راتب مقدمة - مكسور من الشهر السابق',
                        'amount' => $plan['amount'],
                    ]);
                }
            }

            if ($period->status === 'draft') {
                $period->status = 'processing';
                $period->save();
            }
        });
    }

    /** @return array<string, mixed> */
    public function preview(Employee $employee, PayrollPeriod $period): array
    {
        $saved = $period->exists ? EmployeePayroll::query()->where('employee_id', $employee->id)->where('payroll_period_id', $period->id)->first() : null;
        if ($saved && (in_array($period->status, ['approved', 'paid'], true) || (float) $saved->paid_amount > 0)) {
            return ($saved->earnings_breakdown ?? []) + [
                'basic_salary' => (float) $saved->basic_salary, 'bonuses' => (float) $saved->total_allowances,
                'deductions' => (float) $saved->total_deductions, 'advances' => (float) $saved->advance_deduction,
                'net_due' => (float) $saved->net_salary, 'actual_worked_days' => 0, 'paid_leave_days' => 0, 'unpaid_absent_days' => 0, 'daily_rate' => 0,
            ];
        }
        $earnings = $this->earnings->calculate($employee, $period);
        $bonuses = (float) EmployeeBonus::query()->where('employee_id', $employee->id)->where(function ($q) use ($period): void {
            if ($period->exists) {
                $q->where('payroll_period_id', $period->id)->orWhere(fn ($d) => $d->whereNull('payroll_period_id')->whereBetween('date', [$period->start_date, $period->end_date]));
            } else {
                $q->whereBetween('date', [$period->start_date, $period->end_date]);
            }
        })->sum('amount') + $earnings['overtime_pay'];
        $deductions = (float) EmployeeDeduction::query()->where('employee_id', $employee->id)->where(function ($q) use ($period): void {
            if ($period->exists) {
                $q->where('payroll_period_id', $period->id)->orWhere(fn ($d) => $d->whereNull('payroll_period_id')->whereBetween('date', [$period->start_date, $period->end_date]));
            } else {
                $q->whereBetween('date', [$period->start_date, $period->end_date]);
            }
        })->sum('amount') + $earnings['attendance_deductions'];
        $advances = $employee->advances()->where('status', 'active')->whereDate('start_installment_date', '<=', $period->end_date)->get()
            ->sum(fn ($a): float => min((float) $a->installment_amount, max(0, (float) $a->amount - (float) $a->paid_amount)));
        $beforeCarryover = max(0, $earnings['basic_salary'] + $bonuses - $deductions - $advances);
        $carryover = ! $employee->payrolls()->exists() ? 0 : (float) EmployeePayrollCarryover::query()->where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'partially_applied'])->where('remaining_amount', '>', 0)
            ->whereHas('sourcePayroll.payrollPeriod', fn ($q) => $q->whereDate('end_date', '<', $period->start_date))
            ->where(fn ($q) => $q->whereNull('target_period_id')->when($period->exists, fn ($q) => $q->orWhere('target_period_id', $period->id)))->sum('remaining_amount');
        $carryover = min($beforeCarryover, $carryover);

        return $earnings + ['bonuses' => round($bonuses, 2), 'deductions' => round($deductions + $carryover, 2),
            'advances' => round($advances, 2), 'carryovers' => round($carryover, 2), 'net_due' => round($beforeCarryover - $carryover, 2)];
    }
}
