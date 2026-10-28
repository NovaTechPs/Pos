<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeAttendanceSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeAttendanceService
{
    public function __construct(private EmployeeTermsService $terms) {}

    public function clock(Employee $employee, string $type, string $source = 'self', ?string $device = null): EmployeeAttendance
    {
        return DB::transaction(function () use ($employee, $type, $source, $device): EmployeeAttendance {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_unless($employee->is_active && ! $employee->terminated_at, 422, 'خدمة الموظف غير نشطة.');
            $open = $employee->attendanceSessions()->whereNull('checked_out_at')->lockForUpdate()->first();
            $now = now();
            if ($type === 'check_in') {
                abort_if($open !== null, 409, 'لديك جلسة حضور مفتوحة بالفعل.');
                $workDate = $now->toDateString();
                $yesterday = $now->copy()->subDay()->toDateString();
                $shift = $this->terms->scheduleOn($employee, $yesterday);
                if ($shift && $shift->end_time < $shift->start_time && $now->format('H:i:s') < $shift->end_time) {
                    $workDate = $yesterday;
                }
                $this->assertOpenPeriod($employee, $workDate);
                $attendance = EmployeeAttendance::query()->firstOrCreate(['employee_id' => $employee->id, 'date' => $workDate], ['status' => 'present']);
                EmployeeAttendanceSession::query()->create([
                    'employee_id' => $employee->id, 'employee_attendance_id' => $attendance->id,
                    'checked_in_at' => $now, 'source' => $source, 'recorded_by' => auth()->id(), 'device_name' => $device,
                ]);
            } else {
                abort_unless($open !== null, 422, 'يجب تسجيل الحضور قبل الانصراف.');
                $attendance = EmployeeAttendance::query()->findOrFail($open->employee_attendance_id);
                $this->assertOpenPeriod($employee, $attendance->date);
                abort_unless($now->gt($open->checked_in_at), 422, 'وقت الانصراف يجب أن يكون بعد الحضور.');
                $open->update(['checked_out_at' => $now]);
            }

            return $this->summarize($attendance);
        }, 3);
    }

    public function manual(Employee $employee, string $date, ?string $in, ?string $out, string $status, ?int $sessionId = null, ?string $notes = null): EmployeeAttendance
    {
        return DB::transaction(function () use ($employee, $date, $in, $out, $status, $sessionId, $notes): EmployeeAttendance {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->assertOpenPeriod($employee, $date);
            $attendance = EmployeeAttendance::query()->firstOrCreate(['employee_id' => $employee->id, 'date' => $date], ['status' => $status]);
            if ($in) {
                $start = Carbon::parse($date.' '.$in);
                $end = $out ? Carbon::parse($date.' '.$out) : null;
                if ($end && $end->lte($start)) {
                    $end->addDay();
                }
                $attributes = ['checked_in_at' => $start, 'checked_out_at' => $end, 'source' => 'manager', 'recorded_by' => auth()->id()];
                if ($sessionId) {
                    $session = $attendance->sessions()->whereKey($sessionId)->firstOrFail();
                    $session->update($attributes);
                } else {
                    $attendance->sessions()->create($attributes + ['employee_id' => $employee->id]);
                }
                $attendance = $this->summarize($attendance);
            } else {
                if ($attendance->sessions()->exists()) {
                    throw ValidationException::withMessages(['attendance_status' => 'لا يمكن تحويل يوم به جلسات حضور إلى غياب أو إجازة.']);
                }
                $attendance->update(['status' => $status, 'check_in' => null, 'check_out' => null, 'worked_minutes' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0]);
            }
            if ($notes !== null) {
                $attendance->update(['notes' => $notes]);
            }

            return $attendance->fresh();
        });
    }

    public function summarize(EmployeeAttendance $attendance): EmployeeAttendance
    {
        $sessions = $attendance->sessions()->orderBy('checked_in_at')->get();
        if ($sessions->isEmpty()) {
            return $attendance;
        }
        $worked = (int) $sessions->sum(fn ($session): int => $session->checked_out_at ? (int) $session->checked_in_at->diffInMinutes($session->checked_out_at, true) : 0);
        $employee = Employee::query()->findOrFail($attendance->employee_id);
        $shift = $this->terms->scheduleOn($employee, $attendance->date);
        $first = $sessions->first()->checked_in_at;
        $late = 0;
        $scheduled = 480;
        if ($shift) {
            $start = Carbon::parse($attendance->date.' '.$shift->start_time);
            $end = Carbon::parse($attendance->date.' '.$shift->end_time);
            if ($end->lte($start)) {
                $end->addDay();
            }
            $scheduled = (int) $start->diffInMinutes($end, true);
            $late = $first->gt($start->copy()->addMinutes($shift->grace_period_minutes)) ? (int) $start->diffInMinutes($first, true) : 0;
            if (! in_array(strtolower(Carbon::parse($attendance->date)->format('D')), $shift->work_days, true)) {
                $scheduled = 0;
                $late = 0;
            }
        }
        $attendance->update([
            'check_in' => $first->format('H:i:s'),
            'check_out' => $sessions->contains(fn ($s): bool => $s->checked_out_at === null) ? null : $sessions->last()->checked_out_at->format('H:i:s'),
            'worked_minutes' => $worked, 'late_minutes' => $late,
            'overtime_minutes' => max(0, $worked - $scheduled),
            'overtime_approved_minutes' => min((int) $attendance->overtime_approved_minutes, max(0, $worked - $scheduled)),
            'status' => $late > 0 ? 'late' : 'present',
        ]);

        return $attendance->fresh();
    }

    public function assertOpenPeriod(Employee $employee, string $date): void
    {
        if ($employee->payrolls()->whereHas('payrollPeriod', fn ($q) => $q->whereIn('status', ['approved', 'paid'])->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date))->exists()) {
            throw ValidationException::withMessages(['attendance_date' => 'دوام هذه الفترة مقفل بعد اعتماد الراتب. سجّل تسوية مستقلة.']);
        }
    }
}
