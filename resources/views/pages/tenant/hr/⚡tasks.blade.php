<?php

use App\Models\Employee;
use App\Models\EmployeeRecurringTask;
use App\Models\EmployeeTask;
use App\Models\EmployeeTaskStep;
use App\Services\RecurringTaskGenerator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Employee Tasks')] class extends Component
{
    use WithPagination;

    public string $view = 'tasks';

    public string $search = '';

    public string $statusFilter = 'open';

    public ?int $employeeFilter = null;

    public bool $showTaskModal = false;

    public ?int $editingTaskId = null;

    public ?int $editingRecurringId = null;

    public ?int $employee_id = null;

    public string $title = '';

    public string $description = '';

    public string $priority = 'normal';

    public string $due_date = '';

    /** One checklist step per line. */
    public string $stepsText = '';

    public string $frequency = 'none';

    /** @var array<int, string> */
    public array $weekdays = [];

    public int $day_of_month = 1;

    public function mount(): void
    {
        $tenantId = (int) auth()->user()?->tenant_id;
        if ($tenantId && Cache::add("recurring-employee-tasks:{$tenantId}:".today()->toDateString(), true, now()->endOfDay())) {
            app(RecurringTaskGenerator::class)->generate($tenantId);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'statusFilter', 'employeeFilter', 'view'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return (bool) auth()->user()?->hasPermission('tasks.manage');
    }

    #[Computed]
    public function ownEmployeeId(): ?int
    {
        return auth()->user()?->employee?->id;
    }

    /** @return Collection<int, Employee> */
    #[Computed]
    public function employees(): Collection
    {
        if (! $this->canManage) {
            return collect();
        }

        return Employee::query()->where('is_active', true)
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_code']);
    }

    /** @return LengthAwarePaginator<int, EmployeeTask> */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        return $this->visibleTasks()
            ->with(['employee:id,first_name,last_name,employee_code', 'steps'])
            ->when($this->statusFilter === 'open', fn (Builder $query) => $query->open())
            ->when($this->statusFilter === 'overdue', fn (Builder $query) => $query->open()->whereDate('due_date', '<', today()))
            ->when(array_key_exists($this->statusFilter, EmployeeTask::STATUSES), fn (Builder $query) => $query->where('status', $this->statusFilter))
            ->when($this->canManage && $this->employeeFilter, fn (Builder $query) => $query->where('employee_id', $this->employeeFilter))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                ->where('title', 'like', '%'.trim($this->search).'%')
                ->orWhere('description', 'like', '%'.trim($this->search).'%')))
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
            ->latest('id')
            ->paginate(20);
    }

    /** @return Collection<int, EmployeeRecurringTask> */
    #[Computed]
    public function recurringTasks(): Collection
    {
        if (! $this->canManage) {
            return collect();
        }

        return EmployeeRecurringTask::query()
            ->with('employee:id,first_name,last_name,employee_code')
            ->when($this->employeeFilter, fn (Builder $query) => $query->where('employee_id', $this->employeeFilter))
            ->when(trim($this->search) !== '', fn (Builder $query) => $query->where('title', 'like', '%'.trim($this->search).'%'))
            ->orderByDesc('is_active')
            ->orderBy('title')
            ->get();
    }

    /** @return array{open: int, overdue: int, today: int, completed_week: int} */
    #[Computed]
    public function summary(): array
    {
        return [
            'open' => $this->visibleTasks()->open()->count(),
            'overdue' => $this->visibleTasks()->open()->whereDate('due_date', '<', today())->count(),
            'today' => $this->visibleTasks()->open()->whereDate('due_date', today())->count(),
            'completed_week' => $this->visibleTasks()->where('status', 'completed')->where('completed_at', '>=', now()->subDays(7))->count(),
        ];
    }

    public function openCreate(): void
    {
        $this->authorize('tasks.manage');
        $this->resetForm();
        $this->employee_id = $this->employeeFilter;
        $this->due_date = today()->toDateString();
        $this->frequency = $this->view === 'recurring' ? 'daily' : 'none';
        $this->showTaskModal = true;
    }

    public function edit(int $taskId): void
    {
        $this->authorize('tasks.manage');
        $task = EmployeeTask::query()->with('steps')->findOrFail($taskId);
        $this->resetForm();
        $this->editingTaskId = $task->id;
        $this->fillCommonFields($task->employee_id, $task->title, $task->description, $task->priority, $task->steps->pluck('title')->all());
        $this->due_date = $task->due_date?->toDateString() ?? '';
        $this->showTaskModal = true;
    }

    public function editRecurring(int $recurringTaskId): void
    {
        $this->authorize('tasks.manage');
        $recurringTask = EmployeeRecurringTask::query()->findOrFail($recurringTaskId);
        $this->resetForm();
        $this->editingRecurringId = $recurringTask->id;
        $this->fillCommonFields($recurringTask->employee_id, $recurringTask->title, $recurringTask->description, $recurringTask->priority, $recurringTask->steps ?? []);
        $this->frequency = $recurringTask->frequency;
        $this->weekdays = $recurringTask->weekdays ?? [];
        $this->day_of_month = (int) ($recurringTask->day_of_month ?: 1);
        $this->showTaskModal = true;
    }

    public function save(): void
    {
        $this->authorize('tasks.manage');
        $isRecurring = $this->editingRecurringId !== null || ($this->editingTaskId === null && $this->frequency !== 'none');

        $validated = $this->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', auth()->user()->tenant_id)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(array_keys(EmployeeTask::PRIORITIES))],
            'due_date' => ['nullable', 'date'],
            'stepsText' => ['nullable', 'string', 'max:5000'],
            'frequency' => [$isRecurring ? Rule::in(array_keys(EmployeeRecurringTask::FREQUENCIES)) : Rule::in(['none'])],
            'weekdays' => [Rule::requiredIf($isRecurring && $this->frequency === 'weekly'), 'array'],
            'weekdays.*' => [Rule::in(array_keys(EmployeeRecurringTask::WEEKDAYS))],
            'day_of_month' => [Rule::requiredIf($isRecurring && $this->frequency === 'monthly'), 'integer', 'between:1,31'],
        ], [
            'weekdays.required' => 'اختر يوماً واحداً على الأقل.',
        ], [
            'employee_id' => 'الموظف',
            'title' => 'عنوان المهمة',
            'description' => 'التفاصيل',
            'priority' => 'الأولوية',
            'due_date' => 'تاريخ الاستحقاق',
            'stepsText' => 'الخطوات',
            'frequency' => 'التكرار',
            'day_of_month' => 'يوم الشهر',
        ]);

        $steps = $this->parsedSteps();
        if (count($steps) > 30) {
            throw ValidationException::withMessages(['stepsText' => 'الحد الأقصى 30 خطوة للمهمة.']);
        }

        $attributes = [
            'employee_id' => $validated['employee_id'],
            'title' => trim($validated['title']),
            'description' => trim((string) $validated['description']) ?: null,
            'priority' => $validated['priority'],
        ];

        DB::transaction(function () use ($isRecurring, $attributes, $validated, $steps): void {
            if ($isRecurring) {
                $recurringAttributes = [
                    ...$attributes,
                    'frequency' => $this->frequency,
                    'weekdays' => $this->frequency === 'weekly' ? array_values(array_intersect(array_keys(EmployeeRecurringTask::WEEKDAYS), $this->weekdays)) : null,
                    'day_of_month' => $this->frequency === 'monthly' ? $this->day_of_month : null,
                    'steps' => $steps,
                ];

                if ($this->editingRecurringId) {
                    EmployeeRecurringTask::query()->findOrFail($this->editingRecurringId)->update($recurringAttributes);
                    session()->flash('message', 'تم تعديل المهمة المتكررة. التعديل يسري على المهام القادمة.');
                } else {
                    EmployeeRecurringTask::query()->create([...$recurringAttributes, 'starts_on' => today()->toDateString(), 'created_by' => auth()->id()]);
                    app(RecurringTaskGenerator::class)->generate((int) auth()->user()->tenant_id);
                    session()->flash('message', 'تمت إضافة المهمة المتكررة. ستظهر للموظف تلقائياً في أيامها.');
                }

                return;
            }

            $taskAttributes = [...$attributes, 'due_date' => $validated['due_date'] ?: null];
            if ($this->editingTaskId) {
                $task = EmployeeTask::query()->findOrFail($this->editingTaskId);
                $task->update($taskAttributes);
                session()->flash('message', 'تم تعديل المهمة.');
            } else {
                $task = EmployeeTask::query()->create([...$taskAttributes, 'created_by' => auth()->id()]);
                session()->flash('message', 'تمت إضافة المهمة.');
            }
            $task->syncSteps($steps);
        });

        $this->showTaskModal = false;
        $this->resetForm();
    }

    public function setStatus(int $taskId, string $status): void
    {
        abort_unless(array_key_exists($status, EmployeeTask::STATUSES), 422);
        $task = $this->visibleTasks()->findOrFail($taskId);

        if (! $this->canManage) {
            abort_unless(in_array($status, ['pending', 'in_progress', 'completed'], true) && $task->status !== 'cancelled', 403);
        }

        if ($status === 'completed' && $task->hasUnfinishedSteps()) {
            throw ValidationException::withMessages(["task.{$task->id}" => 'أكمل كل خطوات المهمة قبل إنهائها.']);
        }

        $task->update(['status' => $status]);
    }

    public function toggleStep(int $stepId): void
    {
        $step = EmployeeTaskStep::query()->with('task')->findOrFail($stepId);
        $task = $this->visibleTasks()->findOrFail($step->employee_task_id);
        abort_if(in_array($task->status, ['completed', 'cancelled'], true), 403);

        DB::transaction(function () use ($step, $task): void {
            $step->update($step->isCompleted()
                ? ['completed_at' => null, 'completed_by' => null]
                : ['completed_at' => now(), 'completed_by' => auth()->id()]);

            if ($task->status === 'pending' && $step->isCompleted()) {
                $task->update(['status' => 'in_progress']);
            }
        });
    }

    public function delete(int $taskId): void
    {
        $this->authorize('tasks.manage');
        EmployeeTask::query()->findOrFail($taskId)->delete();
        session()->flash('message', 'تم حذف المهمة.');
    }

    public function toggleRecurring(int $recurringTaskId): void
    {
        $this->authorize('tasks.manage');
        $recurringTask = EmployeeRecurringTask::query()->findOrFail($recurringTaskId);
        $recurringTask->update(['is_active' => ! $recurringTask->is_active]);
        if ($recurringTask->is_active) {
            app(RecurringTaskGenerator::class)->generate((int) $recurringTask->tenant_id);
        }
    }

    public function deleteRecurring(int $recurringTaskId): void
    {
        $this->authorize('tasks.manage');
        EmployeeRecurringTask::query()->findOrFail($recurringTaskId)->delete();
        session()->flash('message', 'تم حذف المهمة المتكررة. المهام التي أُنشئت منها سابقاً بقيت كما هي.');
    }

    /** @return Builder<EmployeeTask> */
    private function visibleTasks(): Builder
    {
        $query = EmployeeTask::query();
        if ($this->canManage) {
            return $query;
        }

        abort_unless($this->ownEmployeeId !== null, 403);

        return $query->where('employee_id', $this->ownEmployeeId);
    }

    /** @return array<int, string> */
    private function parsedSteps(): array
    {
        return collect(preg_split('/\R/u', $this->stepsText) ?: [])
            ->map(fn (string $line): string => trim(mb_substr(trim($line), 0, 255)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<int, string> $steps */
    private function fillCommonFields(int $employeeId, string $title, ?string $description, string $priority, array $steps): void
    {
        $this->employee_id = $employeeId;
        $this->title = $title;
        $this->description = (string) $description;
        $this->priority = $priority;
        $this->stepsText = implode("\n", $steps);
    }

    private function resetForm(): void
    {
        $this->reset(['editingTaskId', 'editingRecurringId', 'employee_id', 'title', 'description', 'priority', 'due_date', 'stepsText', 'frequency', 'weekdays', 'day_of_month']);
        $this->resetValidation();
    }
};
?>

<div class="mx-auto max-w-6xl space-y-6" dir="rtl">
    <header class="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-start gap-4">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                <flux:icon name="clipboard-document-check" class="size-6" />
            </span>
            <div>
                <flux:heading size="xl">{{ $this->canManage ? 'مهام الموظفين' : 'مهامي' }}</flux:heading>
                <flux:subheading class="mt-1">
                    {{ $this->canManage ? 'وزّع المهام على الموظفين وتابع ما أُنجز منها.' : 'المهام المسندة إليك. أشّر على الخطوات وحدّث حالة المهمة عند الإنجاز.' }}
                </flux:subheading>
            </div>
        </div>
        @if ($this->canManage)
            <flux:button variant="primary" icon="plus" wire:click="openCreate">
                {{ $view === 'recurring' ? 'مهمة متكررة جديدة' : 'مهمة جديدة' }}
            </flux:button>
        @endif
    </header>

    @if (session('message'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
            {{ session('message') }}
        </div>
    @endif

    @if ($this->canManage)
        <div class="inline-flex rounded-xl border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            @foreach (['tasks' => 'المهام', 'recurring' => 'المهام المتكررة'] as $value => $label)
                <button type="button" wire:click="$set('view', '{{ $value }}')" @class([
                    'rounded-lg px-4 py-2 text-sm font-bold transition',
                    'bg-indigo-600 text-white' => $view === $value,
                    'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800' => $view !== $value,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    @endif

    @if ($view === 'recurring' && $this->canManage)
        <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input type="search" icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="ابحث في عنوان المهمة" />
                <flux:select wire:model.live="employeeFilter">
                    <option value="">كل الموظفين</option>
                    @foreach ($this->employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }} ({{ $employee->employee_code }})</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->recurringTasks as $recurringTask)
                    <article wire:key="recurring-{{ $recurringTask->id }}" class="flex flex-wrap items-start justify-between gap-3 py-4">
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 @class(['text-base font-black text-zinc-900 dark:text-white', 'opacity-50' => ! $recurringTask->is_active])>{{ $recurringTask->title }}</h3>
                                <flux:badge size="sm" color="indigo" icon="arrow-path">{{ $recurringTask->scheduleLabel() }}</flux:badge>
                                @unless ($recurringTask->is_active)
                                    <flux:badge size="sm" color="zinc">موقوفة</flux:badge>
                                @endunless
                            </div>
                            <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
                                <span>الموظف: {{ $recurringTask->employee?->first_name }} {{ $recurringTask->employee?->last_name }}</span>
                                <span>أولوية {{ \App\Models\EmployeeTask::PRIORITIES[$recurringTask->priority] }}</span>
                                @if (count($recurringTask->steps ?? []))
                                    <span>{{ count($recurringTask->steps) }} خطوات</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1">
                            <flux:button size="sm" variant="ghost" wire:click="toggleRecurring({{ $recurringTask->id }})">
                                {{ $recurringTask->is_active ? 'إيقاف' : 'تفعيل' }}
                            </flux:button>
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editRecurring({{ $recurringTask->id }})" aria-label="تعديل المهمة المتكررة" />
                            <flux:button size="sm" variant="ghost" icon="trash" class="text-rose-500!" wire:click="deleteRecurring({{ $recurringTask->id }})" wire:confirm="حذف هذه المهمة المتكررة؟ المهام التي أُنشئت منها سابقاً تبقى." aria-label="حذف المهمة المتكررة" />
                        </div>
                    </article>
                @empty
                    <div class="py-10 text-center text-sm text-zinc-500">
                        لا توجد مهام متكررة بعد. أضف مهمة مثل «تنظيف الثلاجات» لتظهر للموظف تلقائياً كل يوم أو أسبوع أو شهر.
                    </div>
                @endforelse
            </div>
        </section>
    @else
        @php
            $summary = $this->summary;
        @endphp
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach ([
                ['key' => 'open', 'label' => 'مهام مفتوحة', 'value' => $summary['open'], 'class' => 'text-indigo-700 dark:text-indigo-300'],
                ['key' => 'overdue', 'label' => 'متأخرة', 'value' => $summary['overdue'], 'class' => 'text-rose-600 dark:text-rose-400'],
                ['key' => 'open', 'label' => 'مستحقة اليوم', 'value' => $summary['today'], 'class' => 'text-amber-600 dark:text-amber-400'],
                ['key' => 'completed', 'label' => 'منجزة آخر 7 أيام', 'value' => $summary['completed_week'], 'class' => 'text-emerald-600 dark:text-emerald-400'],
            ] as $card)
                <button type="button" wire:click="$set('statusFilter', '{{ $card['key'] }}')" class="rounded-2xl border border-zinc-200 bg-white p-4 text-start shadow-sm hover:border-indigo-300 dark:border-zinc-700 dark:bg-zinc-900">
                    <span class="block text-xs font-bold text-zinc-500">{{ $card['label'] }}</span>
                    <span class="mt-1 block font-mono text-2xl font-black {{ $card['class'] }}">{{ $card['value'] }}</span>
                </button>
            @endforeach
        </div>

        <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <flux:input type="search" icon="magnifying-glass" wire:model.live.debounce.300ms="search" placeholder="ابحث في عنوان المهمة أو تفاصيلها" />
                <flux:select wire:model.live="statusFilter">
                    <option value="open">المفتوحة</option>
                    <option value="overdue">المتأخرة</option>
                    @foreach (\App\Models\EmployeeTask::STATUSES as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                    <option value="all">الكل</option>
                </flux:select>
                @if ($this->canManage)
                    <flux:select wire:model.live="employeeFilter">
                        <option value="">كل الموظفين</option>
                        @foreach ($this->employees as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }} ({{ $employee->employee_code }})</option>
                        @endforeach
                    </flux:select>
                @endif
            </div>

            <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @forelse ($this->tasks as $task)
                    @php
                        $priorityColor = match ($task->priority) {
                            'high' => 'rose',
                            'low' => 'zinc',
                            default => 'sky',
                        };
                        $statusColor = match ($task->status) {
                            'completed' => 'emerald',
                            'in_progress' => 'amber',
                            'cancelled' => 'zinc',
                            default => 'indigo',
                        };
                        $isClosed = in_array($task->status, ['completed', 'cancelled'], true);
                        $doneSteps = $task->steps->filter->isCompleted()->count();
                    @endphp
                    <article wire:key="task-{{ $task->id }}" class="flex flex-wrap items-start justify-between gap-3 py-4">
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 @class(['text-base font-black text-zinc-900 dark:text-white', 'line-through opacity-60' => $isClosed])>{{ $task->title }}</h3>
                                <flux:badge size="sm" :color="$statusColor">{{ \App\Models\EmployeeTask::STATUSES[$task->status] }}</flux:badge>
                                <flux:badge size="sm" :color="$priorityColor">أولوية {{ \App\Models\EmployeeTask::PRIORITIES[$task->priority] }}</flux:badge>
                                @if ($task->recurring_task_id)
                                    <flux:badge size="sm" color="indigo" icon="arrow-path">متكررة</flux:badge>
                                @endif
                            </div>
                            @if ($task->description)
                                <p class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300">{{ $task->description }}</p>
                            @endif

                            @if ($task->steps->isNotEmpty())
                                <div class="space-y-1.5 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                                    <div class="flex items-center justify-between gap-3 text-xs font-bold text-zinc-500">
                                        <span>الخطوات</span>
                                        <span>{{ $doneSteps }} / {{ $task->steps->count() }}</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                        <div class="h-full rounded-full bg-emerald-500 transition-all" style="width: {{ round($doneSteps / $task->steps->count() * 100) }}%"></div>
                                    </div>
                                    <ul class="space-y-1 pt-1">
                                        @foreach ($task->steps as $step)
                                            <li wire:key="step-{{ $step->id }}">
                                                <button type="button" wire:click="toggleStep({{ $step->id }})" @disabled($isClosed) class="flex w-full items-center gap-2 rounded-lg px-1.5 py-1 text-start text-sm hover:bg-white disabled:cursor-default disabled:hover:bg-transparent dark:hover:bg-zinc-900">
                                                    <span @class([
                                                        'flex size-5 shrink-0 items-center justify-center rounded-md border',
                                                        'border-emerald-500 bg-emerald-500 text-white' => $step->isCompleted(),
                                                        'border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-900' => ! $step->isCompleted(),
                                                    ])>
                                                        @if ($step->isCompleted())
                                                            <flux:icon name="check" variant="micro" class="size-3.5" />
                                                        @endif
                                                    </span>
                                                    <span @class(['text-zinc-700 dark:text-zinc-200', 'line-through opacity-60' => $step->isCompleted()])>{{ $step->title }}</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @error("task.{$task->id}")
                                <p class="text-xs font-bold text-rose-600">{{ $message }}</p>
                            @enderror

                            <p class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
                                @if ($this->canManage)
                                    <span>الموظف: {{ $task->employee?->first_name }} {{ $task->employee?->last_name }}</span>
                                @endif
                                @if ($task->due_date)
                                    <span @class(['font-bold text-rose-600 dark:text-rose-400' => $task->isOverdue()])>
                                        الاستحقاق: {{ $task->due_date->format('Y-m-d') }}@if ($task->isOverdue()) (متأخرة) @endif
                                    </span>
                                @endif
                                @if ($task->completed_at)
                                    <span>أُنجزت: {{ $task->completed_at->format('Y-m-d H:i') }}</span>
                                @endif
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5">
                            @if ($task->status === 'pending')
                                <flux:button size="sm" wire:click="setStatus({{ $task->id }}, 'in_progress')">بدء التنفيذ</flux:button>
                            @endif
                            @if (! $isClosed)
                                <flux:button size="sm" variant="primary" color="emerald" icon="check" wire:click="setStatus({{ $task->id }}, 'completed')">تم الإنجاز</flux:button>
                            @elseif ($task->status === 'completed' || $this->canManage)
                                <flux:button size="sm" variant="ghost" icon="arrow-uturn-right" wire:click="setStatus({{ $task->id }}, 'pending')">إعادة فتح</flux:button>
                            @endif
                            @if ($this->canManage)
                                @if (! $isClosed)
                                    <flux:button size="sm" variant="ghost" wire:click="setStatus({{ $task->id }}, 'cancelled')">إلغاء</flux:button>
                                @endif
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $task->id }})" aria-label="تعديل المهمة" />
                                <flux:button size="sm" variant="ghost" icon="trash" class="text-rose-500!" wire:click="delete({{ $task->id }})" wire:confirm="حذف هذه المهمة نهائيًا؟" aria-label="حذف المهمة" />
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="py-10 text-center text-sm text-zinc-500">لا توجد مهام في هذا العرض.</p>
                @endforelse
            </div>

            {{ $this->tasks->links() }}
        </section>
    @endif

    @if ($this->canManage)
        @php
            $formIsRecurring = $editingRecurringId !== null || ($editingTaskId === null && $frequency !== 'none');
        @endphp
        <flux:modal wire:model.self="showTaskModal" class="w-full md:w-[36rem]">
            <form wire:submit="save" class="space-y-5" dir="rtl">
                <div>
                    <flux:heading size="lg">
                        @if ($editingRecurringId)
                            تعديل المهمة المتكررة
                        @elseif ($editingTaskId)
                            تعديل المهمة
                        @else
                            مهمة جديدة
                        @endif
                    </flux:heading>
                    <flux:subheading>حدّد الموظف وما المطلوب منه وموعد الإنجاز.</flux:subheading>
                </div>

                <flux:select label="الموظف" wire:model="employee_id">
                    <option value="">اختر الموظف</option>
                    @foreach ($this->employees as $employee)
                        <option value="{{ $employee->id }}">{{ $employee->first_name }} {{ $employee->last_name }} ({{ $employee->employee_code }})</option>
                    @endforeach
                </flux:select>

                <flux:input label="عنوان المهمة" wire:model="title" maxlength="255" placeholder="مثال: إقفال المحل" />

                <flux:textarea label="التفاصيل" badge="اختياري" wire:model="description" rows="2" placeholder="أي ملاحظات تساعد الموظف على إنجاز المهمة" />

                <flux:textarea label="الخطوات" badge="اختياري" wire:model="stepsText" rows="4" description="اكتب كل خطوة في سطر. لا تُعتبر المهمة منجزة حتى تكتمل كل خطواتها." placeholder="إطفاء الأجهزة&#10;عدّ الصندوق&#10;قفل المخزن" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select label="الأولوية" wire:model="priority">
                        @foreach (\App\Models\EmployeeTask::PRIORITIES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    @if ($editingTaskId === null)
                        <flux:select label="التكرار" wire:model.live="frequency">
                            @unless ($editingRecurringId)
                                <option value="none">مرة واحدة</option>
                            @endunless
                            @foreach (\App\Models\EmployeeRecurringTask::FREQUENCIES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    @endif
                    @unless ($formIsRecurring)
                        <flux:input type="date" label="تاريخ الاستحقاق" wire:model="due_date" />
                    @endunless
                    @if ($formIsRecurring && $frequency === 'monthly')
                        <flux:input type="number" min="1" max="31" label="يوم الشهر" wire:model="day_of_month" description="إن كان الشهر أقصر، تُنشأ في آخر يوم منه." />
                    @endif
                </div>

                @if ($formIsRecurring && $frequency === 'weekly')
                    <flux:field>
                        <flux:label>أيام التكرار</flux:label>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Models\EmployeeRecurringTask::WEEKDAYS as $value => $label)
                                <label wire:key="weekday-{{ $value }}" class="cursor-pointer">
                                    <input type="checkbox" wire:model.live="weekdays" value="{{ $value }}" class="peer sr-only">
                                    <span class="inline-block rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-bold text-zinc-600 peer-checked:border-indigo-600 peer-checked:bg-indigo-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-400 dark:border-zinc-600 dark:text-zinc-300">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <flux:error name="weekdays" />
                    </flux:field>
                @endif

                @if ($formIsRecurring)
                    <p class="rounded-xl bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200">
                        تُنشأ المهمة تلقائياً للموظف في كل يوم مستحق، بتاريخ استحقاق ذلك اليوم.
                    </p>
                @endif

                <div class="flex justify-end gap-2 pt-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">إلغاء</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" icon="check">حفظ المهمة</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
