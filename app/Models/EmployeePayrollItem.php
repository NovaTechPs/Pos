<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayrollItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    /** @return BelongsTo<EmployeePayroll, $this> */
    public function payroll(): BelongsTo
    {
        return $this->belongsTo(
            EmployeePayroll::class,
            'employee_payroll_id'
        );
    }

    /** @return BelongsTo<EmployeeAdvance, $this> */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(
            EmployeeAdvance::class,
            'employee_advance_id'
        );
    }

    /** @return BelongsTo<EmployeePayrollCarryover, $this> */
    public function carryover(): BelongsTo
    {
        return $this->belongsTo(
            EmployeePayrollCarryover::class,
            'employee_payroll_carryover_id'
        );
    }
}
