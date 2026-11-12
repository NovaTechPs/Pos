<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class EmployeeRecurringTask extends Model
{
    use BelongsToTenant;

    /** @var array<string, string> */
    public const FREQUENCIES = [
        'daily' => 'يومياً',
        'weekly' => 'أسبوعياً',
        'monthly' => 'شهرياً',
    ];

    /** Weekday keys follow the employee shift `work_days` format. */
    /** @var array<string, string> */
    public const WEEKDAYS = [
        'sat' => 'السبت',
        'sun' => 'الأحد',
        'mon' => 'الاثنين',
        'tue' => 'الثلاثاء',
        'wed' => 'الأربعاء',
        'thu' => 'الخميس',
        'fri' => 'الجمعة',
    ];

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true, 'priority' => 'normal'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'steps' => 'array',
            'starts_on' => 'date',
            'last_generated_on' => 'date',
            'is_active' => 'boolean',
            'day_of_month' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (EmployeeRecurringTask $recurringTask): void {
            $employee = Employee::withoutGlobalScopes()->find($recurringTask->employee_id);
            $tenantId = auth()->user()?->tenant_id;
            if (! $employee || ($tenantId && (int) $employee->tenant_id !== (int) $tenantId)) {
                throw ValidationException::withMessages(['employee_id' => 'الموظف يجب أن يتبع المنشأة نفسها.']);
            }
            $recurringTask->tenant_id = $employee->tenant_id;
        });
    }

    /** Whether a task from this template is due on the given day. */
    public function isDueOn(CarbonInterface $day): bool
    {
        if (! $this->is_active || $day->lt($this->starts_on->copy()->startOfDay())) {
            return false;
        }

        return match ($this->frequency) {
            'daily' => true,
            'weekly' => in_array(strtolower($day->format('D')), $this->weekdays ?? [], true),
            'monthly' => $day->day === min((int) $this->day_of_month, $day->daysInMonth),
            default => false,
        };
    }

    public function scheduleLabel(): string
    {
        return match ($this->frequency) {
            'weekly' => 'أسبوعياً: '.collect($this->weekdays ?? [])->map(fn (string $day): string => self::WEEKDAYS[$day] ?? $day)->join('، '),
            'monthly' => 'شهرياً يوم '.$this->day_of_month,
            default => 'يومياً',
        };
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<EmployeeTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(EmployeeTask::class, 'recurring_task_id');
    }
}
