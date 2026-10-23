<?php

namespace App\Models;

use App\Traits\BelongsToEmployeeTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeePayrollCarryover extends Model
{
    use BelongsToEmployeeTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PARTIALLY_APPLIED = 'partially_applied';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_ALLOWANCE = 'allowance';

    public const TYPE_DEDUCTION = 'deduction';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    /** @return BelongsTo<EmployeePayroll, $this> */
    public function sourcePayroll(): BelongsTo
    {
        return $this->belongsTo(EmployeePayroll::class, 'source_payroll_id');
    }

    /** @return BelongsTo<PayrollPeriod, $this> */
    public function targetPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class, 'target_period_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<EmployeePayrollCarryoverApplication, $this> */
    public function applications(): HasMany
    {
        return $this->hasMany(
            EmployeePayrollCarryoverApplication::class,
            'employee_payroll_carryover_id'
        );
    }
}
