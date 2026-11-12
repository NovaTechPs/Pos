<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Database\Factories\EmployeeTaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class EmployeeTask extends Model
{
    /** @use HasFactory<EmployeeTaskFactory> */
    use BelongsToTenant, HasFactory;

    /** @var array<string, string> */
    public const STATUSES = [
        'pending' => 'جديدة',
        'in_progress' => 'قيد التنفيذ',
        'completed' => 'منجزة',
        'cancelled' => 'ملغاة',
    ];

    /** @var array<string, string> */
    public const PRIORITIES = [
        'low' => 'منخفضة',
        'normal' => 'عادية',
        'high' => 'عالية',
    ];

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending', 'priority' => 'normal'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (EmployeeTask $task): void {
            $employee = Employee::withoutGlobalScopes()->find($task->employee_id);
            $tenantId = auth()->user()?->tenant_id;
            if (! $employee || ($tenantId && (int) $employee->tenant_id !== (int) $tenantId)) {
                throw ValidationException::withMessages(['employee_id' => 'الموظف يجب أن يتبع المنشأة نفسها.']);
            }
            if ($task->tenant_id && (int) $task->tenant_id !== (int) $employee->tenant_id) {
                throw ValidationException::withMessages(['employee_id' => 'الموظف يجب أن يتبع منشأة المهمة.']);
            }
            $task->tenant_id = $employee->tenant_id;

            if ($task->status === 'completed') {
                $task->completed_at ??= now();
                $task->completed_by ??= auth()->id();
            } else {
                $task->completed_at = null;
                $task->completed_by = null;
            }
        });
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->lt(today())
            && in_array($this->status, ['pending', 'in_progress'], true);
    }

    /** @param Builder<EmployeeTask> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', ['pending', 'in_progress']);
    }

    /**
     * Replace the checklist with the given step titles. Steps whose title is kept also keep
     * their ticked state, so editing a task does not undo work already done.
     *
     * @param  array<int, string>  $titles
     */
    public function syncSteps(array $titles): void
    {
        $titles = collect($titles)->map(fn (string $title): string => trim($title))->filter()->unique()->values();
        $existing = $this->steps()->get()->keyBy('title');

        $this->steps()->whereNotIn('title', $titles->all())->delete();
        $titles->each(function (string $title, int $position) use ($existing): void {
            $step = $existing->get($title);
            if ($step) {
                $step->update(['position' => $position]);
            } else {
                $this->steps()->create(['title' => $title, 'position' => $position]);
            }
        });
    }

    public function hasUnfinishedSteps(): bool
    {
        return $this->relationLoaded('steps')
            ? $this->steps->contains(fn (EmployeeTaskStep $step): bool => ! $step->isCompleted())
            : $this->steps()->whereNull('completed_at')->exists();
    }

    /** @return HasMany<EmployeeTaskStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(EmployeeTaskStep::class)->orderBy('position');
    }

    /** @return BelongsTo<EmployeeRecurringTask, $this> */
    public function recurringTask(): BelongsTo
    {
        return $this->belongsTo(EmployeeRecurringTask::class, 'recurring_task_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
