<?php

namespace App\Models;

use App\Traits\BelongsToEmployeeTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeAttendance extends Model
{
    use BelongsToEmployeeTenant;

    protected $guarded = [];

    /** @return HasMany<EmployeeAttendanceSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(EmployeeAttendanceSession::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
