<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Employee $employee): void {
            if (! $employee->tenant_id && $employee->user_id) {
                $user = User::query()->find($employee->user_id);
                $employee->tenant()->associate($user?->tenant);
                if (! $employee->branch_id) {
                    $employee->setAttribute('branch_id', $user?->branch_id);
                }
            }
        });
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
