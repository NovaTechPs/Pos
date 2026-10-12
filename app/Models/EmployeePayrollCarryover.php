<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeePayrollCarryover extends Model
{
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(
            EmployeePayrollCarryoverApplication::class,
            'employee_payroll_carryover_id'
        );
    }
}