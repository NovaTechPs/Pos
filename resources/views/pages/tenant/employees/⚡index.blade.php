<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\User;
use App\Models\Role;
use App\Models\Branch;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

new class extends Component
{
    /*
    |--------------------------------------------------------------------------
    | Data
    |--------------------------------------------------------------------------
    */

    public $employees = [];
    public $roles = [];
    public $branches = [];

    /*
    |--------------------------------------------------------------------------
    | Form
    |--------------------------------------------------------------------------
    */

    public $user_id = null;

    public string $name = '';
    public string $email = '';
    public string $password = '';

    public $branch_id = null;
    public $role_id = null;

    public bool $is_active = true;

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    */

    public bool $showModal = false;
    public bool $isEditing = false;

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public string $search = '';
    public string $statusFilter = 'all';
    public string $branchFilter = 'all';

    /*
    |--------------------------------------------------------------------------
    | Lifecycle
    |--------------------------------------------------------------------------
    */

    public function mount(): void
    {
        $this->loadData();
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */

    private function getTenantId(): ?int
    {
        $tenantId = session('active_tenant_id');

        return $tenantId ? (int) $tenantId : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected function rules(): array
    {
        $tenantId = $this->getTenantId();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user_id),
            ],

            'password' => [
                $this->isEditing ? 'nullable' : 'required',
                'string',
                'min:8',
            ],

            'branch_id' => [
                'required',
                'integer',
                Rule::exists('branches', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],

            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],

            'is_active' => [
                'boolean',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Load Data
    |--------------------------------------------------------------------------
    */

    #[On('tenant-changed')]
    public function loadData(): void
    {
        $this->resetValidation();

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->employees = collect();
            $this->roles = collect();
            $this->branches = collect();

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Employees
        |--------------------------------------------------------------------------
        */

        $this->employees = User::query()
            ->with([
                'role:id,name',
                'branch:id,name',
            ])
            ->where('tenant_id', $tenantId)
            ->where('is_owner', false)

            ->when(
                filled(trim($this->search)),
                function ($query) {
                    $search = trim($this->search);

                    $query->where(function ($query) use ($search) {
                        $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhereHas('role', function ($roleQuery) use ($search) {
                                $roleQuery->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                );
                            })
                            ->orWhereHas('branch', function ($branchQuery) use ($search) {
                                $branchQuery->where(
                                    'name',
                                    'like',
                                    "%{$search}%"
                                );
                            });
                    });
                }
            )

            ->when(
                $this->statusFilter === 'active',
                fn ($query) => $query->where('is_active', true)
            )

            ->when(
                $this->statusFilter === 'inactive',
                fn ($query) => $query->where('is_active', false)
            )

            ->when(
                $this->branchFilter !== 'all',
                fn ($query) => $query->where(
                    'branch_id',
                    $this->branchFilter
                )
            )

            ->orderBy('name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        */

        $this->roles = Role::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Branches
        |--------------------------------------------------------------------------
        */

        $this->branches = Branch::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public function updatedSearch(): void
    {
        $this->loadData();
    }

    public function updatedStatusFilter(): void
    {
        $this->loadData();
    }

    public function updatedBranchFilter(): void
    {
        $this->loadData();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->branchFilter = 'all';

        $this->loadData();
    }

    /*
    |--------------------------------------------------------------------------
    | Modal
    |--------------------------------------------------------------------------
    */

    public function openCreateModal(): void
    {
        $this->resetInputFields();

        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->flashError('يرجى اختيار المتجر أولاً.');

            return;
        }

        $employee = User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_owner', false)
            ->findOrFail($id);

        $this->user_id = $employee->id;
        $this->name = $employee->name;
        $this->email = $employee->email;
        $this->branch_id = $employee->branch_id;
        $this->role_id = $employee->role_id;
        $this->is_active = (bool) $employee->is_active;

        $this->password = '';

        $this->resetValidation();

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;

        $this->resetInputFields();
    }

    /*
    |--------------------------------------------------------------------------
    | Save
    |--------------------------------------------------------------------------
    */

    public function save(): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->flashError(
                'يرجى اختيار متجر أولاً لتتمكن من إدارة الموظفين.'
            );

            return;
        }

        $validated = $this->validate();

        /*
        |--------------------------------------------------------------------------
        | Create
        |--------------------------------------------------------------------------
        */

        if (!$this->isEditing) {
            $employee = new User();

            $employee->tenant_id = $tenantId;
            $employee->type = 'tenant_user';
            $employee->is_owner = false;
        }

        /*
        |--------------------------------------------------------------------------
        | Update
        |--------------------------------------------------------------------------
        */

        if ($this->isEditing) {
            $employee = User::query()
                ->where('tenant_id', $tenantId)
                ->where('is_owner', false)
                ->findOrFail($this->user_id);
        }

        $employee->name = $validated['name'];
        $employee->email = $validated['email'];
        $employee->branch_id = $validated['branch_id'];
        $employee->role_id = $validated['role_id'];
        $employee->is_active = $validated['is_active'];

        if (filled($validated['password'] ?? null)) {
            $employee->password = Hash::make(
                $validated['password']
            );
        }

        $employee->save();

        $message = $this->isEditing
            ? 'تم تحديث بيانات الموظف بنجاح.'
            : 'تم إضافة الموظف بنجاح.';

        $this->closeModal();
        $this->loadData();

        session()->flash('message', $message);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function delete(int $id): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            $this->flashError('يرجى اختيار المتجر أولاً.');

            return;
        }

        $employee = User::query()
            ->where('tenant_id', $tenantId)
            ->where('is_owner', false)
            ->findOrFail($id);

        $employee->delete();

        $this->loadData();

        session()->flash(
            'message',
            'تم إزالة حساب الموظف بنجاح.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function resetInputFields(): void
    {
        $this->user_id = null;

        $this->name = '';
        $this->email = '';
        $this->password = '';

        $this->branch_id = null;
        $this->role_id = null;

        $this->is_active = true;

        $this->resetValidation();
    }

    private function flashError(string $message): void
    {
        session()->flash('error', $message);
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        return $this->view()->layout('layouts::tenant');
    }
};

?>

<flux:main class="space-y-6">

    {{-- ============================================================
        HEADER
    ============================================================= --}}

    <div class="space-y-5">

        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">

            <div class="min-w-0">

                <div class="flex items-center gap-3">

                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-zinc-900"
                    >
                        <flux:icon.users class="size-5" />
                    </div>

                    <div class="min-w-0">

                        <flux:heading
                            size="xl"
                            level="1"
                            class="truncate"
                        >
                            إدارة الموظفين
                        </flux:heading>

                        <flux:subheading class="mt-1">
                            إدارة حسابات الموظفين وتعيين الفروع والأدوار والصلاحيات.
                        </flux:subheading>

                    </div>

                </div>

            </div>

            <flux:button
                variant="primary"
                icon="user-plus"
                wire:click="openCreateModal"
                wire:loading.attr="disabled"
                wire:target="openCreateModal"
                class="shrink-0"
            >
                إضافة موظف جديد
            </flux:button>

        </div>

        {{-- ========================================================
            FLASH MESSAGES
        ========================================================= --}}

        @if (session()->has('message'))

            <div
                class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300"
            >
                <div class="flex items-center gap-2">

                    <flux:icon.check-circle class="size-5 shrink-0" />

                    <span>
                        {{ session('message') }}
                    </span>

                </div>
            </div>

        @endif

        @if (session()->has('error'))

            <div
                class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300"
            >
                <div class="flex items-center gap-2">

                    <flux:icon.exclamation-triangle class="size-5 shrink-0" />

                    <span>
                        {{ session('error') }}
                    </span>

                </div>
            </div>

        @endif

    </div>


    {{-- ============================================================
        STATISTICS
    ============================================================= --}}

    @php
        $totalEmployees = $employees->count();
        $activeEmployees = $employees->where('is_active', true)->count();
        $inactiveEmployees = $employees->where('is_active', false)->count();
    @endphp

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">

        {{-- Total --}}

        <div
            class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div class="flex items-center justify-between">

                <div>

                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">
                        إجمالي الموظفين
                    </p>

                    <p class="mt-2 text-2xl font-semibold text-zinc-900 dark:text-white">
                        {{ $totalEmployees }}
                    </p>

                </div>

                <div
                    class="flex h-11 w-11 items-center justify-center rounded-xl bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                >
                    <flux:icon.users class="size-5" />
                </div>

            </div>
        </div>

        {{-- Active --}}

        <div
            class="rounded-2xl border border-emerald-200/70 bg-emerald-50/60 p-5 shadow-sm dark:border-emerald-900/50 dark:bg-emerald-950/20"
        >
            <div class="flex items-center justify-between">

                <div>

                    <p class="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                        الموظفون النشطون
                    </p>

                    <p class="mt-2 text-2xl font-semibold text-emerald-800 dark:text-emerald-300">
                        {{ $activeEmployees }}
                    </p>

                </div>

                <div
                    class="flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300"
                >
                    <flux:icon.check-circle class="size-5" />
                </div>

            </div>
        </div>

        {{-- Inactive --}}

        <div
            class="rounded-2xl border border-zinc-200 bg-zinc-50 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
        >
            <div class="flex items-center justify-between">

                <div>

                    <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">
                        الحسابات المعطلة
                    </p>

                    <p class="mt-2 text-2xl font-semibold text-zinc-700 dark:text-zinc-300">
                        {{ $inactiveEmployees }}
                    </p>

                </div>

                <div
                    class="flex h-11 w-11 items-center justify-center rounded-xl bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400"
                >
                    <flux:icon.user-minus class="size-5" />
                </div>

            </div>
        </div>

    </div>


    {{-- ============================================================
        FILTERS
    ============================================================= --}}

    <div
        class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
    >

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center">

            {{-- Search --}}

            <div class="min-w-0 flex-1">

                <div class="relative">

                    <flux:input
                        wire:model.live.debounce.350ms="search"
                        icon="magnifying-glass"
                        placeholder="ابحث بالاسم أو البريد أو الفرع أو الدور..."
                    />

                </div>

            </div>


            {{-- Status --}}

            <div class="w-full lg:w-44">

                <flux:select wire:model.live="statusFilter">

                    <option value="all">
                        كل الحالات
                    </option>

                    <option value="active">
                        نشط فقط
                    </option>

                    <option value="inactive">
                        معطل فقط
                    </option>

                </flux:select>

            </div>


            {{-- Branch --}}

            <div class="w-full lg:w-52">

                <flux:select wire:model.live="branchFilter">

                    <option value="all">
                        كل الفروع
                    </option>

                    @foreach ($branches as $branch)

                        <option value="{{ $branch->id }}">
                            {{ $branch->name }}
                        </option>

                    @endforeach

                </flux:select>

            </div>


            {{-- Clear --}}

            @if (
                filled($search) ||
                $statusFilter !== 'all' ||
                $branchFilter !== 'all'
            )

                <flux:button
                    variant="ghost"
                    icon="x-mark"
                    wire:click="clearFilters"
                    wire:loading.attr="disabled"
                    wire:target="clearFilters"
                    class="shrink-0"
                >
                    مسح
                </flux:button>

            @endif

        </div>

        <div class="mt-3 flex items-center justify-between gap-3 text-xs text-zinc-500">

            <span>
                عرض {{ $employees->count() }} موظف
            </span>

            <span
                wire:loading
                wire:target="search,statusFilter,branchFilter,clearFilters"
                class="inline-flex items-center gap-2"
            >
                <flux:icon.arrow-path class="size-3.5 animate-spin" />
                جاري تحديث النتائج...
            </span>

        </div>

    </div>


    {{-- ============================================================
        EMPLOYEES TABLE
    ============================================================= --}}

    <div
        class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
    >

        <flux:table>

            <flux:table.columns>

                <flux:table.column>
                    الموظف
                </flux:table.column>

                <flux:table.column>
                    البريد الإلكتروني
                </flux:table.column>

                <flux:table.column>
                    الفرع
                </flux:table.column>

                <flux:table.column>
                    الدور الوظيفي
                </flux:table.column>

                <flux:table.column align="center">
                    الحالة
                </flux:table.column>

                <flux:table.column align="end">
                    الإجراءات
                </flux:table.column>

            </flux:table.columns>


            <flux:table.rows>

                @forelse ($employees as $employee)

                    <flux:table.row
                        wire:key="employee-{{ $employee->id }}"
                    >

                        {{-- Employee --}}

                        <flux:table.cell>

                            <div class="flex items-center gap-3">

                                <div
                                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
                                >
                                    {{ mb_strtoupper(mb_substr($employee->name, 0, 1)) }}
                                </div>

                                <div class="min-w-0">

                                    <div class="font-medium text-zinc-900 dark:text-white">
                                        {{ $employee->name }}
                                    </div>

                                    <div class="mt-0.5 text-xs text-zinc-400">
                                        #{{ $employee->id }}
                                    </div>

                                </div>

                            </div>

                        </flux:table.cell>


                        {{-- Email --}}

                        <flux:table.cell>

                            <span class="text-sm text-zinc-600 dark:text-zinc-300">
                                {{ $employee->email }}
                            </span>

                        </flux:table.cell>


                        {{-- Branch --}}

                        <flux:table.cell>

                            @if ($employee->branch)

                                <flux:badge
                                    size="sm"
                                    variant="subtle"
                                    color="zinc"
                                >
                                    {{ $employee->branch->name }}
                                </flux:badge>

                            @else

                                <span class="text-xs text-zinc-400">
                                    غير محدد
                                </span>

                            @endif

                        </flux:table.cell>


                        {{-- Role --}}

                        <flux:table.cell>

                            @if ($employee->role)

                                <flux:badge
                                    size="sm"
                                    variant="subtle"
                                    color="indigo"
                                >
                                    {{ $employee->role->name }}
                                </flux:badge>

                            @else

                                <span class="text-xs text-zinc-400">
                                    بدون دور
                                </span>

                            @endif

                        </flux:table.cell>


                        {{-- Status --}}

                        <flux:table.cell align="center">

                            @if ($employee->is_active)

                                <flux:badge
                                    size="sm"
                                    color="emerald"
                                    variant="solid"
                                >
                                    <span class="mr-1 inline-block size-1.5 rounded-full bg-white"></span>
                                    نشط
                                </flux:badge>

                            @else

                                <flux:badge
                                    size="sm"
                                    color="zinc"
                                    variant="solid"
                                >
                                    معطل
                                </flux:badge>

                            @endif

                        </flux:table.cell>


                        {{-- Actions --}}

                        <flux:table.cell align="end">

                            <div class="flex items-center justify-end gap-1">

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="pencil-square"
                                    wire:click="edit({{ $employee->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="edit({{ $employee->id }})"
                                    title="تعديل الموظف"
                                />

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"
                                    wire:click="delete({{ $employee->id }})"
                                    wire:confirm="هل أنت متأكد من إزالة حساب هذا الموظف؟"
                                    wire:loading.attr="disabled"
                                    wire:target="delete({{ $employee->id }})"
                                    title="حذف الموظف"
                                />

                            </div>

                        </flux:table.cell>

                    </flux:table.row>

                @empty

                    <flux:table.row>

                        <flux:table.cell
                            colspan="6"
                            align="center"
                            class="py-16"
                        >

                            <div class="mx-auto flex max-w-md flex-col items-center">

                                <div
                                    class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800"
                                >
                                    <flux:icon.users class="size-8" />
                                </div>

                                @if (
                                    filled($search) ||
                                    $statusFilter !== 'all' ||
                                    $branchFilter !== 'all'
                                )

                                    <flux:heading size="lg">
                                        لا توجد نتائج
                                    </flux:heading>

                                    <p class="mt-2 text-sm text-zinc-500">
                                        لم نجد موظفين يطابقون معايير البحث والفلاتر الحالية.
                                    </p>

                                    <flux:button
                                        variant="ghost"
                                        class="mt-4"
                                        wire:click="clearFilters"
                                    >
                                        مسح الفلاتر
                                    </flux:button>

                                @else

                                    <flux:heading size="lg">
                                        لا يوجد موظفون بعد
                                    </flux:heading>

                                    <p class="mt-2 text-sm text-zinc-500">
                                        أضف أول موظف إلى المتجر وعيّن له الفرع والدور المناسب.
                                    </p>

                                    <flux:button
                                        variant="primary"
                                        icon="user-plus"
                                        class="mt-4"
                                        wire:click="openCreateModal"
                                    >
                                        إضافة أول موظف
                                    </flux:button>

                                @endif

                            </div>

                        </flux:table.cell>

                    </flux:table.row>

                @endforelse

            </flux:table.rows>

        </flux:table>

    </div>


    {{-- ============================================================
        CREATE / EDIT MODAL
    ============================================================= --}}

    <flux:modal
        wire:model="showModal"
        class="md:w-[680px]"
    >

        <form
            wire:submit="save"
            class="space-y-6"
        >

            {{-- Modal Header --}}

            <div>

                <div class="flex items-start gap-3">

                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl
                        {{ $isEditing
                            ? 'bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400'
                            : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'
                        }}"
                    >
                        @if ($isEditing)

                            <flux:icon.pencil-square class="size-5" />

                        @else

                            <flux:icon.user-plus class="size-5" />

                        @endif
                    </div>

                    <div>

                        <flux:heading size="lg">

                            {{ $isEditing ? 'تعديل بيانات الموظف' : 'إضافة موظف جديد' }}

                        </flux:heading>

                        <flux:subheading class="mt-1">

                            {{ $isEditing
                                ? 'حدّث بيانات الحساب والفرع والدور الوظيفي.'
                                : 'أنشئ حساب الموظف وحدد الفرع والدور المناسب.'
                            }}

                        </flux:subheading>

                    </div>

                </div>

            </div>


            {{-- Account Information --}}

            <div class="space-y-4">

                <div class="text-xs font-semibold uppercase tracking-wider text-zinc-400">
                    بيانات الحساب
                </div>


                {{-- Name --}}

                <flux:field>

                    <flux:label>
                        اسم الموظف
                    </flux:label>

                    <flux:input
                        wire:model="name"
                        placeholder="مثال: خالد العلي"
                        autocomplete="name"
                    />

                    <flux:error name="name" />

                </flux:field>


                {{-- Email --}}

                <flux:field>

                    <flux:label>
                        البريد الإلكتروني
                    </flux:label>

                    <flux:input
                        type="email"
                        wire:model="email"
                        placeholder="employee@example.com"
                        autocomplete="email"
                        dir="ltr"
                    />

                    <flux:error name="email" />

                </flux:field>


                {{-- Password --}}

                <flux:field>

                    <flux:label>

                        كلمة المرور

                        @if ($isEditing)
                            <span class="text-xs font-normal text-zinc-400">
                                — اتركها فارغة إذا لم ترد تغييرها
                            </span>
                        @endif

                    </flux:label>

                    <flux:input
                        type="password"
                        wire:model="password"
                        placeholder="{{ $isEditing ? '••••••••' : '8 أحرف على الأقل' }}"
                        autocomplete="{{ $isEditing ? 'new-password' : 'new-password' }}"
                        dir="ltr"
                    />

                    <flux:error name="password" />

                </flux:field>

            </div>


            {{-- Assignment --}}

            <div class="space-y-4">

                <div class="text-xs font-semibold uppercase tracking-wider text-zinc-400">
                    التعيين الوظيفي
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                    {{-- Branch --}}

                    <flux:field>

                        <flux:label>
                            الفرع
                        </flux:label>

                        <flux:select wire:model="branch_id">

                            <option value="">
                                اختر الفرع
                            </option>

                            @foreach ($branches as $branch)

                                <option value="{{ $branch->id }}">
                                    {{ $branch->name }}
                                </option>

                            @endforeach

                        </flux:select>

                        <flux:error name="branch_id" />

                    </flux:field>


                    {{-- Role --}}

                    <flux:field>

                        <flux:label>
                            الدور الوظيفي
                        </flux:label>

                        <flux:select wire:model="role_id">

                            <option value="">
                                اختر الدور
                            </option>

                            @foreach ($roles as $role)

                                <option value="{{ $role->id }}">
                                    {{ $role->name }}
                                </option>

                            @endforeach

                        </flux:select>

                        <flux:error name="role_id" />

                    </flux:field>

                </div>

            </div>


            {{-- Status --}}

            <div
                class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-950/40"
            >

                <label class="flex cursor-pointer items-center justify-between gap-4">

                    <div class="flex items-center gap-3">

                        <div
                            class="flex h-9 w-9 items-center justify-center rounded-lg
                            {{ $is_active
                                ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400'
                                : 'bg-zinc-200 text-zinc-500 dark:bg-zinc-800'
                            }}"
                        >
                            <flux:icon.check-circle class="size-5" />
                        </div>

                        <div>

                            <div class="text-sm font-medium text-zinc-900 dark:text-white">
                                حساب الموظف نشط
                            </div>

                            <div class="mt-0.5 text-xs text-zinc-500">
                                يمكن للموظف تسجيل الدخول واستخدام النظام.
                            </div>

                        </div>

                    </div>

                    <input
                        type="checkbox"
                        wire:model="is_active"
                        class="size-5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500"
                    >

                </label>

            </div>


            {{-- Footer --}}

            <div
                class="flex flex-col-reverse gap-2 border-t border-zinc-100 pt-5 sm:flex-row sm:justify-end dark:border-zinc-800"
            >

                <flux:button
                    type="button"
                    variant="ghost"
                    wire:click="closeModal"
                    wire:loading.attr="disabled"
                >
                    إلغاء
                </flux:button>

                <flux:button
                    type="submit"
                    variant="primary"
                    icon="{{ $isEditing ? 'check' : 'user-plus' }}"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >

                    <span wire:loading.remove wire:target="save">
                        {{ $isEditing ? 'حفظ التعديلات' : 'إضافة الموظف' }}
                    </span>

                    <span
                        wire:loading
                        wire:target="save"
                        class="inline-flex items-center gap-2"
                    >
                        <flux:icon.arrow-path class="size-4 animate-spin" />
                        جاري الحفظ...
                    </span>

                </flux:button>

            </div>

        </form>

    </flux:modal>

</flux:main>
