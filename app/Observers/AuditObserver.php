<?php

namespace App\Observers;

use App\Models\AuditLog;

class AuditObserver
{
    public function created($model): void { $this->log($model, 'created', null, $model->getAttributes()); }
    public function updated($model): void { $this->log($model, 'updated', $model->getOriginal(), $model->getAttributes()); }
    public function deleted($model): void { $this->log($model, 'deleted', $model->getOriginal(), null); }

    private function log($model, string $action, ?array $old, ?array $new): void
    {
        AuditLog::create([
            'tenant_id' => session('active_tenant_id') ?: auth()->user()?->tenant_id,
            'branch_id' => session('active_branch_id') ?: auth()->user()?->branch_id,
            'user_id' => auth()->id(),
            'action' => $action,
            'module' => class_basename($model),
            'description' => match ($action) { 'created' => 'تم إنشاء سجل جديد', 'updated' => 'تم تعديل سجل', default => 'تم حذف سجل' },
            'subject_type' => get_class($model),
            'subject_id' => $model->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
