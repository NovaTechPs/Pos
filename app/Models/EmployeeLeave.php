<?php

namespace App\Models;

use App\Traits\BelongsToEmployeeTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class EmployeeLeave extends Model
{
    use BelongsToEmployeeTenant;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $leave): void {
            $employee = Employee::query()->findOrFail($leave->employee_id);
            $start = Carbon::parse($leave->start_date)->startOfDay();
            $end = Carbon::parse($leave->end_date)->startOfDay();
            if ($end->lt($start)) {
                throw ValidationException::withMessages(['leave_end_date' => 'نهاية الإجازة لا تسبق بدايتها.']);
            }
            if ($employee->payrolls()->whereHas('payrollPeriod', fn ($q) => $q->whereIn('status', ['approved', 'paid'])->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start))->exists()) {
                throw ValidationException::withMessages(['leave_start_date' => 'الإجازة تتداخل مع فترة رواتب مقفلة.']);
            }
            $overlap = ! in_array($leave->status, ['rejected', 'cancelled'], true) && self::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
                ->when($leave->exists, fn ($q) => $q->whereKeyNot($leave->id))->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['leave_start_date' => 'توجد إجازة متداخلة في الفترة نفسها.']);
            }
            if ($leave->status === 'approved' && $employee->attendances()->whereBetween('date', [$start->toDateString(), $end->toDateString()])->whereNotNull('check_in')->exists()) {
                throw ValidationException::withMessages(['leave_start_date' => 'يوجد حضور مسجّل. استخدم مغادرة ساعية بدل إجازة يوم كامل.']);
            }
            $leave->total_days = (int) $start->diffInDays($end, true) + 1;
            if ($leave->status === 'approved' && ! $leave->approved_by) {
                $leave->approved_by = auth()->id();
            }
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
