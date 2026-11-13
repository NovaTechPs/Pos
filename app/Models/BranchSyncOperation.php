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

    /**
     * The desktop app checks this file every few seconds and starts an upload process only when it exists,
     * instead of booting PHP every ten seconds to find an empty queue.
     */
    public static function pendingUploadMarker(): string
    {
        return storage_path('app/branch-sync-pending');
    }

    protected static function booted(): void
    {
        static::saved(function (BranchSyncOperation $operation): void {
            if ($operation->status === 'pending' && config('branch-sync.mode') === 'edge') {
                @touch(self::pendingUploadMarker());
            }
        });
    }

    /** Removes the desktop upload marker once nothing waits to be uploaded. */
    public static function clearPendingUploadMarkerWhenIdle(): void
    {
        if (! self::query()->where('status', 'pending')->exists()) {
            @unlink(self::pendingUploadMarker());
        }
    }

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
