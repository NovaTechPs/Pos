<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $guarded = [];

    public function user() { return $this->belongsTo(User::class); }
    public function attendances() { return $this->hasMany(EmployeeAttendance::class); }
    public function shifts() { return $this->hasMany(EmployeeShift::class); }
    public function leaves() { return $this->hasMany(EmployeeLeave::class); }
    public function departures() { return $this->hasMany(EmployeeDeparture::class); }
    public function advances() { return $this->hasMany(EmployeeAdvance::class); }
    public function bonuses() { return $this->hasMany(EmployeeBonus::class); }
    public function deductions() { return $this->hasMany(EmployeeDeduction::class); }
    public function payrolls() { return $this->hasMany(EmployeePayroll::class); }
    public function payments() { return $this->hasMany(EmployeePayment::class); }
}