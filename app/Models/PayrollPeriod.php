<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    protected $guarded = [];

    public function employeePayrolls()
    {
        return $this->hasMany(EmployeePayroll::class);
    }

    public function bonuses()
    {
        return $this->hasMany(EmployeeBonus::class);
    }

    public function deductions()
    {
        return $this->hasMany(EmployeeDeduction::class);
    }
}
