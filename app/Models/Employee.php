<?php

namespace App\Models;

use App\Services\EmployeeTermsService;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Employee extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function save(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn (): bool => parent::save($options));
    }

    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            $employee->tenant_id ??= auth()->user()?->tenant_id;
            if ($employee->user_id) {
                $user = User::withoutGlobalScopes()->find($employee->user_id);
                $employee->tenant_id ??= $user?->tenant_id;
                if (! $employee->branch_id) {
                    $employee->setAttribute('branch_id', $user?->branch_id);
                }

                if (! $user || (int) $user->tenant_id !== (int) $employee->tenant_id) {
                    throw ValidationException::withMessages(['user_id' => 'حساب الدخول يجب أن يتبع منشأة الموظف.']);
                }
            }

            if ($employee->branch_id && ! Branch::withoutGlobalScopes()->whereKey($employee->branch_id)->where('tenant_id', $employee->tenant_id)->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'الفرع يجب أن يتبع منشأة الموظف.']);
            }
        });
        static::created(function (Employee $employee): void {
            $employee->salaryHistories()->create([
                'effective_from' => $employee->hire_date,
                'salary_type' => $employee->salary_type ?? 'monthly',
                'basic_salary' => $employee->basic_salary ?? 0,
                'hourly_rate' => $employee->hourly_rate ?? 0,
                'created_by' => auth()->id(),
                'reason' => 'الراتب عند التعيين',
            ]);
        });
        static::updated(function (Employee $employee): void {
            if ($employee->wasChanged(['salary_type', 'basic_salary', 'hourly_rate'])) {
                app(EmployeeTermsService::class)->salary($employee, [
                    'salary_type' => $employee->salary_type ?? 'monthly',
                    'basic_salary' => $employee->basic_salary ?? 0,
                    'hourly_rate' => $employee->hourly_rate ?? 0,
                ], today()->toDateString(), 'تعديل بيانات الراتب');
            }
        });
    }

    /** @return HasMany<EmployeeSalaryHistory, $this> */
    public function salaryHistories(): HasMany
    {
        return $this->hasMany(EmployeeSalaryHistory::class);
    }

    /** @return HasMany<EmployeeAttendanceSession, $this> */
    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(EmployeeAttendanceSession::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<EmployeeAttendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    /** @return HasMany<EmployeeShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(EmployeeShift::class);
    }

    /** @return HasMany<EmployeeLeave, $this> */
    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    /** @return HasMany<EmployeeDeparture, $this> */
    public function departures(): HasMany
    {
        return $this->hasMany(EmployeeDeparture::class);
    }

    /** @return HasMany<EmployeeAdvance, $this> */
    public function advances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class);
    }

    /** @return HasMany<EmployeeBonus, $this> */
    public function bonuses(): HasMany
    {
        return $this->hasMany(EmployeeBonus::class);
    }

    /** @return HasMany<EmployeeDeduction, $this> */
    public function deductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    /** @return HasMany<EmployeePayroll, $this> */
    public function payrolls(): HasMany
    {
        return $this->hasMany(EmployeePayroll::class);
    }

    /** @return HasMany<EmployeePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(EmployeePayment::class);
    }
}
