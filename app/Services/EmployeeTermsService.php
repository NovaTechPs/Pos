<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeSalaryHistory;
use App\Models\EmployeeShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeTermsService
{
    /** @param array<string, mixed> $rates */
    public function salary(Employee $employee, array $rates, string $effectiveFrom, ?string $reason = null): EmployeeSalaryHistory
    {
        return DB::transaction(function () use ($employee, $rates, $effectiveFrom, $reason): EmployeeSalaryHistory {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $date = Carbon::parse($effectiveFrom)->startOfDay();
            $this->assertEditable($employee, $date);
            validator($rates, ['salary_type' => 'required|in:monthly,hourly,daily,production', 'basic_salary' => 'required|numeric|min:0', 'hourly_rate' => 'required|numeric|min:0'])->validate();
            $previous = EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->whereDate('effective_from', '<', $date)->latest('effective_from')->first();
            $next = EmployeeSalaryHistory::query()->where('employee_id', $employee->id)->whereDate('effective_from', '>', $date)->oldest('effective_from')->first();
            $previous?->update(['effective_until' => $date->copy()->subDay()->toDateString()]);
            $history = EmployeeSalaryHistory::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'effective_from' => $date->toDateString()],
                $rates + ['effective_until' => $next ? Carbon::parse($next->effective_from)->subDay()->toDateString() : null, 'created_by' => auth()->id(), 'reason' => $reason],
            );
            if ($date->lte(today()) && (! $next || Carbon::parse($next->effective_from)->gt(today()))) {
                $employee->fill($rates)->saveQuietly();
            }

            return $history;
        });
    }

    /** @param array<string, mixed> $values */
    public function schedule(Employee $employee, array $values, string $effectiveFrom): EmployeeShift
    {
        return DB::transaction(function () use ($employee, $values, $effectiveFrom): EmployeeShift {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $date = Carbon::parse($effectiveFrom)->startOfDay();
            $this->assertEditable($employee, $date);
            validator($values, ['shift_name' => 'required|string|max:191', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|different:start_time', 'work_days' => 'required|array|min:1', 'work_days.*' => 'in:sat,sun,mon,tue,wed,thu,fri', 'grace_period_minutes' => 'required|integer|min:0|max:240'])->validate();
            $previous = EmployeeShift::query()->where('employee_id', $employee->id)->whereDate('effective_from', '<', $date)->latest('effective_from')->first();
            $next = EmployeeShift::query()->where('employee_id', $employee->id)->whereDate('effective_from', '>', $date)->oldest('effective_from')->first();
            $previous?->update(['effective_until' => $date->copy()->subDay()->toDateString()]);

            return EmployeeShift::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'effective_from' => $date->toDateString()],
                $values + ['effective_until' => $next ? Carbon::parse($next->effective_from)->subDay()->toDateString() : null],
            );
        });
    }

    public function terminate(Employee $employee, string $date, string $reason): void
    {
        validator(['date' => $date, 'reason' => $reason], ['date' => 'required|date|before_or_equal:today|after_or_equal:'.$employee->hire_date, 'reason' => 'required|string|max:2000'])->validate();
        DB::transaction(function () use ($employee, $date, $reason): void {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($employee->attendanceSessions()->whereNull('checked_out_at')->exists()) {
                throw ValidationException::withMessages(['date' => 'أغلق جلسة الدوام المفتوحة قبل إنهاء الخدمة.']);
            }
            $this->assertEditable($employee, Carbon::parse($date)->startOfDay());
            if ($employee->attendances()->whereDate('date', '>', $date)->exists()) {
                throw ValidationException::withMessages(['date' => 'يوجد حضور بعد تاريخ إنهاء الخدمة. اختر تاريخًا يشمل آخر دوام مسجّل.']);
            }
            $employee->update(['terminated_at' => $date, 'termination_reason' => $reason, 'terminated_by' => auth()->id(), 'is_active' => false]);
            if ($employee->user_id) {
                $employee->user()->update(['is_active' => false]);
            }
        });
    }

    public function assertEditable(Employee $employee, Carbon $date): void
    {
        if ($date->lt(Carbon::parse($employee->hire_date)->startOfDay())) {
            throw ValidationException::withMessages(['effective_from' => 'تاريخ السريان لا يسبق تاريخ التعيين.']);
        }
        if ($employee->payrolls()->whereHas('payrollPeriod', fn ($q) => $q->whereIn('status', ['approved', 'paid'])->whereDate('end_date', '>=', $date))->exists()) {
            throw ValidationException::withMessages(['effective_from' => 'الفترة معتمدة. سجّل تسوية مستقلة بدل تغيير تاريخها.']);
        }
    }

    public function scheduleOn(Employee $employee, string $date): ?EmployeeShift
    {
        return $employee->shifts()->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->latest('effective_from')->first();
    }

    /** @return array{salary_type: string, basic_salary: float, hourly_rate: float} */
    public function ratesOn(Employee $employee, string $date): array
    {
        $history = $employee->salaryHistories()->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date))
            ->latest('effective_from')->first();

        return ['salary_type' => $history->salary_type ?? $employee->salary_type,
            'basic_salary' => (float) ($history->basic_salary ?? $employee->basic_salary),
            'hourly_rate' => (float) ($history->hourly_rate ?? $employee->hourly_rate)];
    }
}
