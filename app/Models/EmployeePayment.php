<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePayment extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payroll(): BelongsTo
    {
        return $this->belongsTo(
            EmployeePayroll::class,
            'employee_payroll_id'
        );
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(
            EmployeeAdvance::class,
            'employee_advance_id'
        );
    }
}
