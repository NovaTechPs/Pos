<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\CasualJobFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A one-off job paid to a casual worker, such as unloading a container, arranging stock or cleaning. */
class CasualJob extends Model
{
    /** @use HasFactory<CasualJobFactory> */
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected $casts = [
        'job_date' => 'date',
        'amount' => 'decimal:2',
    ];

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
