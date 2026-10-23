<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property array<string, mixed> $payload */
class BranchSyncOperation extends Model
{
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'device_id',
        'operation_uuid',
        'operation_type',
        'status',
        'payload',
        'attempts',
        'received_at',
        'processed_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BranchSyncDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(BranchSyncDevice::class, 'device_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
