<?php

namespace App\Models;

use App\Traits\BelongsToEmployeeTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmployeeAttendanceSession extends Model
{
    use BelongsToEmployeeTenant;

    protected $guarded = [];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected $casts = ['checked_in_at' => 'datetime', 'checked_out_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            $session->sync_uuid ??= (string) Str::uuid();
        });
        static::saving(function (self $session): void {
            $attendance = EmployeeAttendance::withoutGlobalScopes()->find($session->employee_attendance_id);
            if (! $attendance || (int) $attendance->employee_id !== (int) $session->employee_id) {
                throw ValidationException::withMessages(['employee_id' => 'جلسة الدوام يجب أن تتبع الموظف نفسه.']);
            }
            $overlap = self::withoutGlobalScopes()->where('employee_id', $session->employee_id)
                ->when($session->exists, fn ($q) => $q->whereKeyNot($session->id))
                ->where(function ($q) use ($session): void {
                    $q->whereNull('checked_out_at')->orWhere('checked_out_at', '>', $session->checked_in_at);
                })
                ->when($session->checked_out_at, fn ($q) => $q->where('checked_in_at', '<', $session->checked_out_at))
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['check_in' => 'توجد جلسة دوام مفتوحة أو متداخلة مع هذه الفترة.']);
            }
        });
    }
}
