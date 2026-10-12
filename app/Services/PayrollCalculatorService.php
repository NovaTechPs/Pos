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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollCalculatorService
{
    /**
     * Calculate draft payrolls. Approved/paid periods and payrolls with actual
     * payments are deliberately immutable through this method.
     */
    public function calculateForPeriod(PayrollPeriod $period): void
    {
        DB::transaction(function () use ($period) {
            $period = PayrollPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if (in_array($period->status, ['approved', 'paid'], true)) {
                throw ValidationException::withMessages([
                    'selectedPeriodId' => 'لا يمكن إعادة احتساب مسير معتمد أو مدفوع. أنشئ تسوية مستقلة للتصحيح.',
                ]);
            }

            $employees = Employee::query()
                ->where('is_active', true)
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

                $basicSalary = $this->calculateBasicSalaryForPeriod($employee, $period);

                // SQL OR returns each matching row once; period-linked rows are not duplicated.
                $bonuses = (float) EmployeeBonus::query()
                    ->where('employee_id', $employee->id)
                    ->where(function ($query) use ($period) {
                        $query->where('payroll_period_id', $period->id)
                            ->orWhere(function ($fallback) use ($period) {
                                $fallback->whereNull('payroll_period_id')
                                    ->whereBetween('date', [$period->start_date, $period->end_date]);
                            });
                    })->sum('amount');

                $directDeductions = (float) EmployeeDeduction::query()
                    ->where('employee_id', $employee->id)
                    ->where(function ($query) use ($period) {
                        $query->where('payroll_period_id', $period->id)
                            ->orWhere(function ($fallback) use ($period) {
                                $fallback->whereNull('payroll_period_id')
                                    ->whereBetween('date', [$period->start_date, $period->end_date]);
                            });
                    })->sum('amount');

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
                    ->where('status', 'pending')
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
                    $carryover->target_period_id = $period->id;
                    $carryover->save();
                }

                $totalDeductions = round($directDeductions + $carryoverDeduction, 2);
                $netSalary = max(0, round($basicSalary + $bonuses - $totalDeductions - $advanceDeduction, 2));

                if (! $payroll) {
                    $payroll = new EmployeePayroll;
                    $payroll->payroll_period_id = $period->id;
                    $payroll->employee_id = $employee->id;
                    $payroll->paid_amount = 0;
                    $payroll->payment_status = 'unpaid';
                }

                $payroll->basic_salary = $basicSalary;
                $payroll->total_allowances = round($bonuses, 2);
                $payroll->total_deductions = $totalDeductions;
                $payroll->advance_deduction = round($advanceDeduction, 2);
                $payroll->net_salary = $netSalary;
                $payroll->save();

                $payroll->items()->delete();

                if ($bonuses > 0) {
                    EmployeePayrollItem::create([
                        'employee_payroll_id' => $payroll->id,
                        'type' => 'allowance',
                        'title' => 'مكافآت وبدلات الفترة',
                        'amount' => round($bonuses, 2),
                    ]);
                }

                if ($directDeductions > 0) {
                    EmployeePayrollItem::create([
                        'employee_payroll_id' => $payroll->id,
                        'type' => 'deduction',
                        'title' => 'الخصومات المباشرة للفترة',
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

    private function calculateBasicSalaryForPeriod(Employee $employee, PayrollPeriod $period): float
    {
        $monthlySalary = round((float) $employee->basic_salary, 2);

        if ($employee->salary_type !== 'monthly') {
            return $monthlySalary;
        }

        $periodStart = Carbon::parse($period->start_date)->startOfDay();
        $periodEnd = Carbon::parse($period->end_date)->startOfDay();
        $hireDate = Carbon::parse($employee->hire_date)->startOfDay();

        if ($hireDate->greaterThan($periodEnd)) {
            return 0.0;
        }

        $proratedSalary = 0.0;
        $monthStart = $periodStart->copy()->startOfMonth();

        while ($monthStart->lessThanOrEqualTo($periodEnd)) {
            $monthEnd = $monthStart->copy()->endOfMonth();
            $earningStart = $periodStart->greaterThan($monthStart) ? $periodStart : $monthStart;

            if ($hireDate->greaterThan($earningStart)) {
                $earningStart = $hireDate;
            }

            $earningEnd = $periodEnd->lessThan($monthEnd) ? $periodEnd : $monthEnd;

            if ($earningStart->lessThanOrEqualTo($earningEnd)) {
                $eligibleDays = $earningStart->diffInDays($earningEnd) + 1;
                $proratedSalary += $monthlySalary * ($eligibleDays / $monthStart->daysInMonth);
            }

            $monthStart->addMonth()->startOfMonth();
        }

        return round($proratedSalary, 2);
    }
}
