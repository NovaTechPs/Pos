<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeePayroll extends Model
{
    protected $guarded = [];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'total_allowances' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'advance_deduction' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(
            EmployeePayrollItem::class,
            'employee_payroll_id'
        );
    }

    public function payments(): HasMany
    {
        return $this->hasMany(
            EmployeePayment::class,
            'employee_payroll_id'
        );
    }

    public function carryoverApplications(): HasMany
    {
        return $this->hasMany(
            EmployeePayrollCarryoverApplication::class,
            'employee_payroll_id'
        );
    }

    public function getRemainingSalaryAttribute(): float
    {
        return max(
            0,
            (float) $this->net_salary - (float) $this->paid_amount
        );
    }
}
