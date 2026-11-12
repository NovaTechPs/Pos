<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeRecurringTask;
use App\Models\EmployeeTask;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RecurringTaskGenerator;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Tasks tenant', 'is_active' => true]);
    $this->branch = Branch::create(['tenant_id' => $this->tenant->id, 'name' => 'Main']);
    $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'branch_id' => $this->branch->id, 'is_owner' => true]);
    $this->cashier = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'type' => 'tenant_user',
        'is_owner' => false,
        'role_id' => Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Cashier'])->id,
    ]);
    $this->employee = Employee::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'user_id' => $this->cashier->id,
        'employee_code' => 'EMP-0001',
        'first_name' => 'سامي',
        'last_name' => 'خليل',
        'hire_date' => today(),
    ]);
    $this->colleague = Employee::create([
        'tenant_id' => $this->tenant->id,
        'branch_id' => $this->branch->id,
        'employee_code' => 'EMP-0002',
        'first_name' => 'منى',
        'last_name' => 'يوسف',
        'hire_date' => today(),
    ]);
});

test('a manager assigns a task to an employee', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);

    $this->get(route('tenant.employee-tasks'))->assertOk()->assertSee('مهام الموظفين');

    Livewire::test('pages::tenant.hr.tasks')
        ->call('openCreate')
        ->set('employee_id', $this->employee->id)
        ->set('title', 'جرد رف المنظفات')
        ->set('priority', 'high')
        ->set('due_date', today()->addDay()->toDateString())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('جرد رف المنظفات');

    $task = EmployeeTask::query()->sole();
    expect($task->employee_id)->toBe($this->employee->id)
        ->and($task->tenant_id)->toBe($this->tenant->id)
        ->and($task->created_by)->toBe($this->owner->id)
        ->and($task->status)->toBe('pending')
        ->and($task->priority)->toBe('high');
});

test('a task cannot be assigned to an employee of another tenant', function (): void {
    $otherTenant = Tenant::create(['name' => 'Other tenant', 'is_active' => true]);
    $stranger = Employee::create([
        'tenant_id' => $otherTenant->id,
        'employee_code' => 'EMP-9999',
        'first_name' => 'غريب',
        'last_name' => 'آخر',
        'hire_date' => today(),
    ]);
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.hr.tasks')
        ->set('employee_id', $stranger->id)
        ->set('title', 'مهمة')
        ->call('save')
        ->assertHasErrors(['employee_id']);

    expect(EmployeeTask::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('an employee sees only their own tasks and can complete them', function (): void {
    $ownTask = EmployeeTask::factory()->forEmployee($this->employee)->create(['title' => 'ترتيب المعرض']);
    EmployeeTask::factory()->forEmployee($this->colleague)->create(['title' => 'استلام البضاعة']);
    $this->actingAs($this->cashier)->withSession(['active_tenant_id' => $this->tenant->id]);

    $this->get(route('tenant.employee-tasks'))->assertOk()->assertSee('مهامي');

    Livewire::test('pages::tenant.hr.tasks')
        ->assertSee('ترتيب المعرض')
        ->assertDontSee('استلام البضاعة')
        ->assertDontSee('مهمة جديدة')
        ->call('setStatus', $ownTask->id, 'completed');

    $ownTask->refresh();
    expect($ownTask->status)->toBe('completed')
        ->and($ownTask->completed_by)->toBe($this->cashier->id)
        ->and($ownTask->completed_at)->not->toBeNull();
});

test('an employee cannot change or manage tasks of others', function (): void {
    $colleagueTask = EmployeeTask::factory()->forEmployee($this->colleague)->create();
    $ownTask = EmployeeTask::factory()->forEmployee($this->employee)->create();
    $this->actingAs($this->cashier)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.hr.tasks')
        ->call('setStatus', $colleagueTask->id, 'completed')
        ->assertNotFound();
    Livewire::test('pages::tenant.hr.tasks')
        ->call('setStatus', $ownTask->id, 'cancelled')
        ->assertForbidden();
    Livewire::test('pages::tenant.hr.tasks')
        ->call('delete', $ownTask->id)
        ->assertForbidden();
    Livewire::test('pages::tenant.hr.tasks')
        ->call('openCreate')
        ->assertForbidden();

    expect($colleagueTask->fresh()->status)->toBe('pending')
        ->and($ownTask->fresh()->status)->toBe('pending');
});

test('a user without an employee profile or permission cannot open tasks', function (): void {
    $outsider = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => 'tenant_user',
        'is_owner' => false,
    ]);

    $this->actingAs($outsider)->withSession(['active_tenant_id' => $this->tenant->id])
        ->get(route('tenant.employee-tasks'))
        ->assertForbidden();
});

test('a role with the manage permission sees all tasks', function (): void {
    $supervisorRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Supervisor']);
    $supervisorRole->permissions()->attach(Permission::create(['name' => 'tasks.manage', 'display_name' => 'tasks', 'group' => 'hr'])->id);
    $supervisor = User::factory()->create([
        'tenant_id' => $this->tenant->id,
        'type' => 'tenant_user',
        'is_owner' => false,
        'role_id' => $supervisorRole->id,
    ]);
    EmployeeTask::factory()->forEmployee($this->employee)->create(['title' => 'ترتيب المعرض']);
    EmployeeTask::factory()->forEmployee($this->colleague)->create(['title' => 'استلام البضاعة']);
    $this->actingAs($supervisor)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.hr.tasks')
        ->assertSee('ترتيب المعرض')
        ->assertSee('استلام البضاعة')
        ->set('employeeFilter', $this->colleague->id)
        ->assertDontSee('ترتيب المعرض');
});

test('reopening a completed task clears its completion and overdue tasks are counted', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);
    $task = EmployeeTask::factory()->forEmployee($this->employee)->completed()->create();
    EmployeeTask::factory()->forEmployee($this->employee)->overdue()->create(['title' => 'مهمة متأخرة']);

    expect($task->completed_at)->not->toBeNull();

    $component = Livewire::test('pages::tenant.hr.tasks')
        ->call('setStatus', $task->id, 'pending');

    expect($task->fresh()->completed_at)->toBeNull()
        ->and($task->fresh()->completed_by)->toBeNull()
        ->and($component->instance()->summary['overdue'])->toBe(1)
        ->and($component->instance()->summary['open'])->toBe(2);

    $component->set('statusFilter', 'overdue')->assertSee('مهمة متأخرة');
});

test('a task with steps can only be completed once every step is ticked', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.hr.tasks')
        ->call('openCreate')
        ->set('employee_id', $this->employee->id)
        ->set('title', 'إقفال المحل')
        ->set('stepsText', "إطفاء الأجهزة\n\n  عدّ الصندوق  \nإطفاء الأجهزة")
        ->call('save')
        ->assertHasNoErrors();

    $task = EmployeeTask::query()->with('steps')->sole();
    expect($task->steps->pluck('title')->all())->toBe(['إطفاء الأجهزة', 'عدّ الصندوق']);

    $this->actingAs($this->cashier);
    $component = Livewire::test('pages::tenant.hr.tasks')
        ->assertSee('عدّ الصندوق')
        ->call('setStatus', $task->id, 'completed')
        ->assertHasErrors(["task.{$task->id}"]);
    expect($task->fresh()->status)->toBe('pending');

    $component->call('toggleStep', $task->steps[0]->id);
    expect($task->fresh()->status)->toBe('in_progress')
        ->and($task->steps[0]->fresh()->completed_by)->toBe($this->cashier->id);

    $component->call('toggleStep', $task->steps[1]->id)
        ->call('setStatus', $task->id, 'completed')
        ->assertHasNoErrors();
    expect($task->fresh()->status)->toBe('completed');
});

test('editing a task keeps the ticked state of steps that stay', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);
    $task = EmployeeTask::factory()->forEmployee($this->employee)->create();
    $task->syncSteps(['أولى', 'ثانية']);
    $task->steps()->where('title', 'أولى')->update(['completed_at' => now()]);

    Livewire::test('pages::tenant.hr.tasks')
        ->call('edit', $task->id)
        ->assertSet('stepsText', "أولى\nثانية")
        ->set('stepsText', "جديدة\nأولى")
        ->call('save')
        ->assertHasNoErrors();

    $steps = $task->steps()->get();
    expect($steps->pluck('title')->all())->toBe(['جديدة', 'أولى'])
        ->and($steps->firstWhere('title', 'أولى')->completed_at)->not->toBeNull()
        ->and($steps->firstWhere('title', 'جديدة')->completed_at)->toBeNull();
});

test('an employee cannot tick steps of a colleague task', function (): void {
    $colleagueTask = EmployeeTask::factory()->forEmployee($this->colleague)->create();
    $colleagueTask->syncSteps(['خطوة']);
    $this->actingAs($this->cashier)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.hr.tasks')
        ->call('toggleStep', $colleagueTask->steps()->sole()->id)
        ->assertNotFound();

    expect($colleagueTask->steps()->sole()->completed_at)->toBeNull();
});

test('a daily recurring task creates today task with its steps once', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);

    Livewire::test('pages::tenant.hr.tasks')
        ->set('view', 'recurring')
        ->call('openCreate')
        ->assertSet('frequency', 'daily')
        ->set('employee_id', $this->employee->id)
        ->set('title', 'تنظيف الثلاجات')
        ->set('stepsText', "تفريغ\nتنظيف")
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('تنظيف الثلاجات');

    $recurring = EmployeeRecurringTask::query()->sole();
    $task = EmployeeTask::query()->with('steps')->sole();
    expect($task->recurring_task_id)->toBe($recurring->id)
        ->and($task->due_date->isToday())->toBeTrue()
        ->and($task->steps->pluck('title')->all())->toBe(['تفريغ', 'تنظيف']);

    expect(app(RecurringTaskGenerator::class)->generate($this->tenant->id))->toBe(0)
        ->and(app(RecurringTaskGenerator::class)->generate($this->tenant->id, today()->addDay()))->toBe(1)
        ->and(EmployeeTask::query()->count())->toBe(2);
});

test('weekly and monthly recurring tasks are created only on their days', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);
    $monday = today()->next(Carbon::MONDAY);
    $weekly = EmployeeRecurringTask::create([
        'employee_id' => $this->employee->id,
        'title' => 'جرد أسبوعي',
        'frequency' => 'weekly',
        'weekdays' => ['mon'],
        'starts_on' => today()->subMonth(),
    ]);
    $monthly = EmployeeRecurringTask::create([
        'employee_id' => $this->employee->id,
        'title' => 'جرد شهري',
        'frequency' => 'monthly',
        'day_of_month' => 31,
        'starts_on' => today()->subYear(),
    ]);

    expect($weekly->isDueOn($monday))->toBeTrue()
        ->and($weekly->isDueOn($monday->copy()->addDay()))->toBeFalse()
        ->and($monthly->isDueOn(Carbon::parse('2027-02-28')))->toBeTrue()
        ->and($monthly->isDueOn(Carbon::parse('2027-02-27')))->toBeFalse()
        ->and($monthly->isDueOn(Carbon::parse('2027-03-31')))->toBeTrue();

    Livewire::test('pages::tenant.hr.tasks')
        ->set('view', 'recurring')
        ->call('openCreate')
        ->set('employee_id', $this->employee->id)
        ->set('title', 'بلا أيام')
        ->set('frequency', 'weekly')
        ->call('save')
        ->assertHasErrors(['weekdays']);
});

test('paused recurring tasks and inactive employees get no new tasks', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);
    $recurring = EmployeeRecurringTask::create([
        'employee_id' => $this->employee->id,
        'title' => 'يومية',
        'frequency' => 'daily',
        'starts_on' => today(),
    ]);

    Livewire::test('pages::tenant.hr.tasks')->call('toggleRecurring', $recurring->id);
    expect($recurring->fresh()->is_active)->toBeFalse()
        ->and(app(RecurringTaskGenerator::class)->generate($this->tenant->id))->toBe(0);

    $recurring->update(['is_active' => true]);
    $this->employee->update(['is_active' => false]);
    expect(app(RecurringTaskGenerator::class)->generate($this->tenant->id, today()->addDay()))->toBe(0)
        ->and(EmployeeTask::query()->whereDate('due_date', today()->addDay())->exists())->toBeFalse();
});

test('deleting a recurring task keeps the tasks it already created', function (): void {
    $this->actingAs($this->owner)->withSession(['active_tenant_id' => $this->tenant->id]);
    $recurring = EmployeeRecurringTask::create([
        'employee_id' => $this->employee->id,
        'title' => 'يومية',
        'frequency' => 'daily',
        'starts_on' => today(),
    ]);
    app(RecurringTaskGenerator::class)->generate($this->tenant->id);

    Livewire::test('pages::tenant.hr.tasks')->call('deleteRecurring', $recurring->id);

    expect(EmployeeRecurringTask::query()->count())->toBe(0)
        ->and(EmployeeTask::query()->sole()->recurring_task_id)->toBeNull();
});
