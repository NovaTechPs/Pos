<?php

namespace App\Models;

use App\Traits\BelongsToEmployeeTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAdvance extends Model
{
    use BelongsToEmployeeTenant;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'installment_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'start_installment_date' => 'date',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<EmployeePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(
            EmployeePayment::class,
            'employee_advance_id'
        );
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(
            0,
            (float) $this->amount - (float) $this->paid_amount
        );
    }
}
