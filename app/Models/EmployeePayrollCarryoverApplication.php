<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollCarryoverApplication extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'employee_payroll_carryover_id',
        'employee_payroll_id',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /** @return BelongsTo<EmployeePayrollCarryover, $this> */
    public function carryover(): BelongsTo
    {
        return $this->belongsTo(
            EmployeePayrollCarryover::class,
            'employee_payroll_carryover_id'
        );
    }

    /** @return BelongsTo<EmployeePayroll, $this> */
    public function payroll(): BelongsTo
    {
        return $this->belongsTo(
            EmployeePayroll::class,
            'employee_payroll_id'
        );
    }
}
