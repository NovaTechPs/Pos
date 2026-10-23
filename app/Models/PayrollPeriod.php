<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollPeriod extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** @return HasMany<EmployeePayroll, $this> */
    public function employeePayrolls(): HasMany
    {
        return $this->hasMany(EmployeePayroll::class);
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
}
