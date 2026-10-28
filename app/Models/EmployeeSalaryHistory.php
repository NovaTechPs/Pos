<?php

namespace App\Models;

use App\Traits\BelongsToEmployeeTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryHistory extends Model
{
    use BelongsToEmployeeTenant;

    protected $guarded = [];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected $casts = ['effective_from' => 'date:Y-m-d', 'effective_until' => 'date:Y-m-d', 'basic_salary' => 'decimal:2', 'hourly_rate' => 'decimal:2'];
}
