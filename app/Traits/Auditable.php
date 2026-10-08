<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->writeAuditLog('created'));
        static::updated(fn ($model) => $model->writeAuditLog('updated'));
        static::deleted(fn ($model) => $model->writeAuditLog('deleted'));
    }

    public function writeAuditLog(string $action, ?string $description = null): void
    {
        $user = Auth::user();

        AuditLog::create([
            'tenant_id' => session('active_tenant_id') ?: $user?->tenant_id,
            'branch_id' => session('active_branch_id') ?: $user?->branch_id,
            'user_id' => $user?->id,
            'action' => $action,
            'module' => class_basename($this),
            'description' => $description,
            'subject_type' => get_class($this),
            'subject_id' => $this->getKey(),
            'old_values' => $action === 'created' ? null : $this->getOriginal(),
            'new_values' => $action === 'deleted' ? null : $this->getAttributes(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
