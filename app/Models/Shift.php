<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Shift extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $shift): void {
            $shift->sync_uuid ??= (string) Str::uuid();
        });
    }

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'sync_uuid',
        'user_id',
        'opening_cash',
        'total_sales',
        'total_returns',
        'expected_cash',
        'actual_cash',
        'difference',
        'status',
        'notes',
        'opened_at',
        'closed_at',
        'opened_by',
        'closed_by',
    ];

    protected $casts = [
        'opening_cash' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'total_returns' => 'decimal:2',
        'expected_cash' => 'decimal:2',
        'actual_cash' => 'decimal:2',
        'difference' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
