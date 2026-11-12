<?php

namespace App\Services;

use App\Models\EmployeeRecurringTask;
use App\Models\EmployeeTask;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class RecurringTaskGenerator
{
    /**
     * Create today's task for every active recurring task of the tenant that is due today.
     * Missed past days are not back-filled, and running it twice on the same day creates nothing new.
     *
     * @return int The number of tasks created.
     */
    public function generate(int $tenantId, ?CarbonInterface $day = null): int
    {
        $day = ($day ?? today())->copy()->startOfDay();
        $created = 0;

        EmployeeRecurringTask::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $day)
            ->where(fn ($query) => $query->whereNull('last_generated_on')->orWhereDate('last_generated_on', '<', $day))
            ->whereHas('employee', fn ($query) => $query->withoutGlobalScopes()->where('is_active', true)->whereNull('terminated_at'))
            ->get()
            ->each(function (EmployeeRecurringTask $recurringTask) use ($day, &$created): void {
                if ($recurringTask->isDueOn($day) && $this->createTask($recurringTask, $day)) {
                    $created++;
                }
                $recurringTask->forceFill(['last_generated_on' => $day->toDateString()])->saveQuietly();
            });

        return $created;
    }

    private function createTask(EmployeeRecurringTask $recurringTask, CarbonInterface $day): bool
    {
        if (EmployeeTask::withoutGlobalScopes()->where('recurring_task_id', $recurringTask->id)->whereDate('due_date', $day)->exists()) {
            return false;
        }

        try {
            return DB::transaction(function () use ($recurringTask, $day): bool {
                $task = new EmployeeTask([
                    'tenant_id' => $recurringTask->tenant_id,
                    'employee_id' => $recurringTask->employee_id,
                    'recurring_task_id' => $recurringTask->id,
                    'title' => $recurringTask->title,
                    'description' => $recurringTask->description,
                    'priority' => $recurringTask->priority,
                    'status' => 'pending',
                    'due_date' => $day->toDateString(),
                    'created_by' => $recurringTask->created_by,
                ]);
                $task->save();
                $task->syncSteps($recurringTask->steps ?? []);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
