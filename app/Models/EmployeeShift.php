<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeShift extends Model
{
    protected $guarded = [];

    protected $casts = [
        'work_days' => 'array',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
