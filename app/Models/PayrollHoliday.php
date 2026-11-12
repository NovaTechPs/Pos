<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\PayrollHolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A public holiday of the store: no absence is deducted, and work on it is paid at the holiday rate. */
class PayrollHoliday extends Model
{
    /** @use HasFactory<PayrollHolidayFactory> */
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'is_paid' => 'boolean',
    ];
}
