<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeBonus extends Model
{
    protected $guarded = [];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function payrollPeriod()
    {
        return $this->belongsTo(PayrollPeriod::class);
    }
}
