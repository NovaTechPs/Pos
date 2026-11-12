<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTaskStep extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('employee_task_tenant', function (Builder $query): void {
            $tenantId = auth()->user()?->tenant_id;
            if ($tenantId) {
                $query->whereHas('task', fn (Builder $task) => $task->where('employee_tasks.tenant_id', $tenantId));
            }
        });
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** @return BelongsTo<EmployeeTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(EmployeeTask::class, 'employee_task_id');
    }
}
