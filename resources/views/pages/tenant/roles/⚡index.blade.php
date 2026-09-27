<?php

use Livewire\Component;
use Livewire\Attributes\On;

use App\Models\Role;
use App\Models\Permission;

new class extends Component
{
    /*
    |--------------------------------------------------------------------------
    | Role
    |--------------------------------------------------------------------------
    */

    public ?int $role_id = null;

    public string $name = '';

    public string $description = '';

    public string $searchRole = '';

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    public array $selectedPermissions = [];

    public string $searchPermission = '';

    /*
    |--------------------------------------------------------------------------
    | Modal
    |--------------------------------------------------------------------------
    */

    public bool $showModal = false;

    public bool $isEditing = false;

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
                'max:500',
            ],

            'selectedPermissions' => [
                'nullable',
                'array',
            ],

            'selectedPermissions.*' => [
                'exists:permissions,id',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */

    private function getTenantId(): ?int
    {
        $tenantId = session('active_tenant_id');

        return $tenantId
            ? (int) $tenantId
            : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant Changed
    |--------------------------------------------------------------------------
    */

    #[On('tenant-changed')]
    public function refreshRoles(): void
    {
        $this->closeModal();

        $this->searchRole = '';

        $this->searchPermission = '';
    }

    /*
    |--------------------------------------------------------------------------
    | Create
    |--------------------------------------------------------------------------
    */

    public function openCreateModal(): void
    {
        $this->resetInputFields();

        $this->isEditing = false;

        $this->showModal = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Edit
    |--------------------------------------------------------------------------
    */

    public function edit(int $id): void
    {
        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            session()->flash(
                'error',
                'يرجى اختيار المتجر أولاً.'
            );

            return;
        }

        $role = Role::query()
            ->where('tenant_id', $tenantId)
            ->with('permissions')
            ->findOrFail($id);

        $this->resetValidation();

        $this->role_id = $role->id;

        $this->name = $role->name;

        $this->description = $role->description ?? '';

        $this->selectedPermissions = $role->permissions
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->toArray();

        $this->searchPermission = '';

        $this->isEditing = true;

        $this->showModal = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Toggle Permission Group
    |--------------------------------------------------------------------------
    */

    public function toggleGroup(array $groupIds): void
    {
        $groupIds = array_map(
            'strval',
            $groupIds
        );

        $selected = array_map(
            'strval',
            $this->selectedPermissions
        );

        $allSelected = !empty($groupIds)
            && empty(array_diff($groupIds, $selected));

        if ($allSelected) {
            $this->selectedPermissions = array_values(
                array_diff(
                    $selected,
                    $groupIds
                )
            );

            return;
        }

        $this->selectedPermissions = array_values(
            array_unique(
                array_merge(
                    $selected,
                    $groupIds
                )
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Toggle Visible Permissions
    |--------------------------------------------------------------------------
    */

    public function toggleAllPermissions(array $permissionIds): void
    {
        $permissionIds = array_map(
            'strval',
            $permissionIds
        );

        $selected = array_map(
            'strval',
            $this->selectedPermissions
        );

        if (empty($permissionIds)) {
            return;
        }

        $allSelected = empty(
            array_diff(
                $permissionIds,
                $selected
            )
        );

        if ($allSelected) {
            $this->selectedPermissions = array_values(
                array_diff(
                    $selected,
                    $permissionIds
                )
            );

            return;
        }

        $this->selectedPermissions = array_values(
            array_unique(
                array_merge(
                    $selected,
                    $permissionIds
                )
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Save
    |--------------------------------------------------------------------------
    */

    public function save(): void
    {
        $validated = $this->validate();

        $tenantId = $this->getTenantId();

        if (!$tenantId) {
            session()->flash(
                'error',
                'يرجى اختيار متجر أولاً لتتمكن من إضافة أو تعديل الدور.'
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Update
        |--------------------------------------------------------------------------
        */

        if ($this->isEditing && $this->role_id) {
            $role = Role::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail($this->role_id);

            $role->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            $message = 'تم تحديث الدور والصلاحيات بنجاح.';
        }

        /*
        |--------------------------------------------------------------------------
        | Create
        |--------------------------------------------------------------------------
        */

        else {
            $role = Role::create([
                'tenant_id' => $tenantId,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ]);

            $message = 'تم إنشاء الدور وإضافة الصلاحيات بنجاح.';
        }

        /*
        |--------------------------------------------------------------------------
        | Sync Permissions
        |--------------------------------------------------------------------------
        */

        $permissionIds = collect(
            $this->selectedPermissions
        )
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->toArray();

        $role->permissions()->sync($permissionIds);

        session()->flash(
            'message',
            $message
        );

        $this->closeModal();
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
            session()->flash(
                'error',
                'يرجى اختيار المتجر أولاً.'
            );

            return;
        }

        $role = Role::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $role->permissions()->detach();

        $role->delete();

        session()->flash(
            'message',
            'تم حذف الدور بنجاح.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Close Modal
    |--------------------------------------------------------------------------
    */

    public function closeModal(): void
    {
        $this->showModal = false;

        $this->isEditing = false;

        $this->resetInputFields();
    }

    /*
    |--------------------------------------------------------------------------
    | Reset Form
    |--------------------------------------------------------------------------
    */

    private function resetInputFields(): void
    {
        $this->role_id = null;

        $this->name = '';

        $this->description = '';

        $this->selectedPermissions = [];

        $this->searchPermission = '';

        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        $tenantId = $this->getTenantId();

        /*
        |--------------------------------------------------------------------------
        | Roles
        |--------------------------------------------------------------------------
        */

        $rolesQuery = Role::query()
            ->with('permissions')
            ->when(
                $tenantId,
                fn ($query) => $query->where(
                    'tenant_id',
                    $tenantId
                ),
                fn ($query) => $query->whereRaw('1 = 0')
            );

        if (trim($this->searchRole) !== '') {
            $search = trim($this->searchRole);

            $rolesQuery->where(function ($query) use ($search) {
                $query
                    ->where(
                        'name',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'description',
                        'like',
                        '%' . $search . '%'
                    );
            });
        }

        $roles = $rolesQuery
            ->orderBy('name')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Permissions
        |--------------------------------------------------------------------------
        |
        | مهم:
        | نحتفظ بـ $permissions لأن الـ Blade يستخدمها
        | بالإضافة إلى $permissionsGrouped.
        |
        */

        $permissionsQuery = Permission::query();

        if (trim($this->searchPermission) !== '') {
            $search = trim($this->searchPermission);

            $permissionsQuery->where(function ($query) use ($search) {
                $query
                    ->where(
                        'display_name',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'name',
                        'like',
                        '%' . $search . '%'
                    )
                    ->orWhere(
                        'group',
                        'like',
                        '%' . $search . '%'
                    );
            });
        }

        $permissions = $permissionsQuery
            ->orderBy('group')
            ->orderBy('display_name')
            ->get();

        $permissionsGrouped = $permissions->groupBy('group');

        /*
        |--------------------------------------------------------------------------
        | Statistics
        |--------------------------------------------------------------------------
        */

        $totalPermissions = Permission::count();

        $assignedPermissions = $roles->sum(
            fn ($role) => $role->permissions->count()
        );

        $visiblePermissionIds = $permissions
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->toArray();

        /*
        |--------------------------------------------------------------------------
        | View
        |--------------------------------------------------------------------------
        */

        return $this->view([
            'roles' => $roles,

            // مهم جداً
            'permissions' => $permissions,

            'permissionsGrouped' => $permissionsGrouped,

            'totalPermissions' => $totalPermissions,

            'assignedPermissions' => $assignedPermissions,

            'visiblePermissionIds' => $visiblePermissionIds,
        ])->layout('layouts::tenant');
    }
};

?>

<flux:main class="space-y-6">

    <div
        dir="rtl"
        class="space-y-6"
    >

        {{-- =========================================================
             HEADER
        ========================================================== --}}

        <div
            class="flex flex-col gap-4 border-b border-zinc-200 pb-5
                   sm:flex-row sm:items-center sm:justify-between
                   dark:border-zinc-800"
        >

            <div class="flex items-center gap-3">

                <div
                    class="flex h-11 w-11 shrink-0 items-center justify-center
                           rounded-xl bg-zinc-900 text-white shadow-sm
                           dark:bg-white dark:text-zinc-900"
                >
                    <flux:icon
                        name="shield-check"
                        class="size-5"
                    />
                </div>

                <div>

                    <flux:heading
                        size="xl"
                        level="1"
                    >
                        إدارة الأدوار والصلاحيات
                    </flux:heading>

                    <flux:subheading>
                        أنشئ أدوار الموظفين وحدد الصلاحيات المتاحة لكل دور داخل المتجر.
                    </flux:subheading>

                </div>

            </div>

            <flux:button
                variant="primary"
                icon="plus"
                wire:click="openCreateModal"
                wire:loading.attr="disabled"
            >
                إضافة دور جديد
            </flux:button>

        </div>


        {{-- =========================================================
             ALERTS
        ========================================================== --}}

        @if (session()->has('message'))

            <div
                class="flex items-start gap-3 rounded-xl border
                       border-emerald-200 bg-emerald-50 px-4 py-3
                       text-sm text-emerald-800
                       dark:border-emerald-900/50
                       dark:bg-emerald-950/30
                       dark:text-emerald-300"
            >

                <flux:icon
                    name="check-circle"
                    class="mt-0.5 size-5 shrink-0"
                />

                <span>
                    {{ session('message') }}
                </span>

            </div>

        @endif


        @if (session()->has('error'))

            <div
                class="flex items-start gap-3 rounded-xl border
                       border-red-200 bg-red-50 px-4 py-3
                       text-sm text-red-800
                       dark:border-red-900/50
                       dark:bg-red-950/30
                       dark:text-red-300"
            >

                <flux:icon
                    name="exclamation-circle"
                    class="mt-0.5 size-5 shrink-0"
                />

                <span>
                    {{ session('error') }}
                </span>

            </div>

        @endif


        {{-- =========================================================
             STATISTICS
        ========================================================== --}}

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">

            {{-- Roles --}}

            <flux:card>

                <div class="flex items-center justify-between">

                    <div>

                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            إجمالي الأدوار
                        </p>

                        <p
                            class="mt-1 text-2xl font-bold
                                   text-zinc-900 dark:text-white"
                        >
                            {{ $roles->count() }}
                        </p>

                        <p class="mt-1 text-xs text-zinc-500">
                            الأدوار المعرفة في المتجر
                        </p>

                    </div>

                    <div
                        class="flex h-11 w-11 items-center justify-center
                               rounded-xl bg-blue-50 text-blue-600
                               dark:bg-blue-950/40 dark:text-blue-400"
                    >
                        <flux:icon
                            name="users"
                            class="size-5"
                        />
                    </div>

                </div>

            </flux:card>


            {{-- Permissions --}}

            <flux:card>

                <div class="flex items-center justify-between">

                    <div>

                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            إجمالي الصلاحيات
                        </p>

                        <p
                            class="mt-1 text-2xl font-bold
                                   text-zinc-900 dark:text-white"
                        >
                            {{ $totalPermissions }}
                        </p>

                        <p class="mt-1 text-xs text-zinc-500">
                            الصلاحيات المتاحة للنظام
                        </p>

                    </div>

                    <div
                        class="flex h-11 w-11 items-center justify-center
                               rounded-xl bg-violet-50 text-violet-600
                               dark:bg-violet-950/40 dark:text-violet-400"
                    >
                        <flux:icon
                            name="key"
                            class="size-5"
                        />
                    </div>

                </div>

            </flux:card>


            {{-- Assigned --}}

            <flux:card>

                <div class="flex items-center justify-between">

                    <div>

                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            الصلاحيات الموزعة
                        </p>

                        <p
                            class="mt-1 text-2xl font-bold
                                   text-zinc-900 dark:text-white"
                        >
                            {{ $assignedPermissions }}
                        </p>

                        <p class="mt-1 text-xs text-zinc-500">
                            إجمالي الصلاحيات المرتبطة بالأدوار
                        </p>

                    </div>

                    <div
                        class="flex h-11 w-11 items-center justify-center
                               rounded-xl bg-emerald-50 text-emerald-600
                               dark:bg-emerald-950/40 dark:text-emerald-400"
                    >
                        <flux:icon
                            name="check-badge"
                            class="size-5"
                        />
                    </div>

                </div>

            </flux:card>

        </div>


        {{-- =========================================================
             SEARCH
        ========================================================== --}}

        <flux:card>

            <div
                class="flex flex-col gap-3
                       sm:flex-row sm:items-center sm:justify-between"
            >

                <div class="w-full sm:max-w-md">

                    <flux:input
                        wire:model.live.debounce.300ms="searchRole"
                        icon="magnifying-glass"
                        placeholder="ابحث عن دور أو وصف..."
                    />

                </div>

                <div
                    class="text-sm text-zinc-500 dark:text-zinc-400"
                >
                    {{ $roles->count() }}
                    {{ $roles->count() === 1 ? 'دور' : 'أدوار' }}
                </div>

            </div>

        </flux:card>


        {{-- =========================================================
             ROLES TABLE
        ========================================================== --}}

        <flux:card class="overflow-hidden p-0">

            <div class="overflow-x-auto">

                <flux:table>

                    <flux:table.columns>

                        <flux:table.column>
                            الدور
                        </flux:table.column>

                        <flux:table.column>
                            الوصف
                        </flux:table.column>

                        <flux:table.column>
                            الصلاحيات
                        </flux:table.column>

                        <flux:table.column align="end">
                            الإجراءات
                        </flux:table.column>

                    </flux:table.columns>


                    <flux:table.rows>

                        @forelse ($roles as $role)

                            <flux:table.row
                                wire:key="role-row-{{ $role->id }}"
                            >

                                {{-- Role --}}

                                <flux:table.cell>

                                    <div class="flex items-center gap-3">

                                        <div
                                            class="flex h-10 w-10 shrink-0
                                                   items-center justify-center
                                                   rounded-xl bg-zinc-100
                                                   text-zinc-700
                                                   dark:bg-zinc-800
                                                   dark:text-zinc-300"
                                        >

                                            <flux:icon
                                                name="shield-check"
                                                class="size-5"
                                            />

                                        </div>

                                        <div class="min-w-0">

                                            <div
                                                class="font-semibold
                                                       text-zinc-900
                                                       dark:text-white"
                                            >
                                                {{ $role->name }}
                                            </div>

                                            <div
                                                class="mt-0.5 text-xs
                                                       text-zinc-500"
                                            >
                                                #{{ $role->id }}
                                            </div>

                                        </div>

                                    </div>

                                </flux:table.cell>


                                {{-- Description --}}

                                <flux:table.cell>

                                    <div
                                        class="max-w-md truncate
                                               text-sm text-zinc-600
                                               dark:text-zinc-400"
                                        title="{{ $role->description }}"
                                    >
                                        {{ $role->description ?: 'لا يوجد وصف لهذا الدور.' }}
                                    </div>

                                </flux:table.cell>


                                {{-- Permissions --}}

                                <flux:table.cell>

                                    <flux:badge
                                        size="sm"
                                        color="indigo"
                                        variant="subtle"
                                    >
                                        {{ $role->permissions->count() }}
                                        صلاحية
                                    </flux:badge>

                                </flux:table.cell>


                                {{-- Actions --}}

                                <flux:table.cell align="end">

                                    <div
                                        class="flex items-center
                                               justify-end gap-1"
                                    >

                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="pencil-square"
                                            wire:click="edit({{ $role->id }})"
                                            title="تعديل الدور"
                                        />

                                        <flux:button
                                            variant="ghost"
                                            size="sm"
                                            icon="trash"
                                            class="text-red-500
                                                   hover:bg-red-50
                                                   dark:hover:bg-red-950/30"
                                            wire:click="delete({{ $role->id }})"
                                            wire:confirm="هل أنت متأكد من حذف هذا الدور؟"
                                            title="حذف الدور"
                                        />

                                    </div>

                                </flux:table.cell>

                            </flux:table.row>

                        @empty

                            <flux:table.row>

                                <flux:table.cell
                                    colspan="4"
                                    align="center"
                                >

                                    <div
                                        class="flex flex-col
                                               items-center justify-center
                                               py-14"
                                    >

                                        <div
                                            class="flex h-14 w-14
                                                   items-center justify-center
                                                   rounded-2xl bg-zinc-100
                                                   text-zinc-400
                                                   dark:bg-zinc-800"
                                        >

                                            <flux:icon
                                                name="shield-exclamation"
                                                class="size-7"
                                            />

                                        </div>

                                        <p
                                            class="mt-4 font-medium
                                                   text-zinc-700
                                                   dark:text-zinc-300"
                                        >
                                            لا توجد أدوار
                                        </p>

                                        <p class="mt-1 text-sm text-zinc-500">

                                            @if ($searchRole)
                                                لا توجد نتائج مطابقة للبحث.
                                            @else
                                                لم تتم إضافة أي أدوار لهذا المتجر بعد.
                                            @endif

                                        </p>

                                        @if ($searchRole)

                                            <flux:button
                                                class="mt-4"
                                                variant="ghost"
                                                size="sm"
                                                wire:click="$set('searchRole', '')"
                                            >
                                                مسح البحث
                                            </flux:button>

                                        @else

                                            <flux:button
                                                class="mt-4"
                                                variant="primary"
                                                size="sm"
                                                icon="plus"
                                                wire:click="openCreateModal"
                                            >
                                                إضافة أول دور
                                            </flux:button>

                                        @endif

                                    </div>

                                </flux:table.cell>

                            </flux:table.row>

                        @endforelse

                    </flux:table.rows>

                </flux:table>

            </div>

        </flux:card>


        {{-- =========================================================
             MODAL
        ========================================================== --}}

        <flux:modal
            wire:model="showModal"
            class="w-full max-w-4xl"
        >

            <form
                wire:submit.prevent="save"
                class="space-y-6"
            >

                {{-- Header --}}

                <div
                    class="border-b border-zinc-200 pb-4
                           dark:border-zinc-800"
                >

                    <div class="flex items-start gap-3">

                        <div
                            class="flex h-10 w-10 shrink-0
                                   items-center justify-center
                                   rounded-xl bg-zinc-100
                                   text-zinc-700
                                   dark:bg-zinc-800
                                   dark:text-zinc-300"
                        >

                            <flux:icon
                                name="{{ $isEditing
                                    ? 'pencil-square'
                                    : 'shield-check' }}"
                                class="size-5"
                            />

                        </div>

                        <div>

                            <flux:heading size="lg">

                                {{ $isEditing
                                    ? 'تعديل الدور'
                                    : 'إضافة دور جديد'
                                }}

                            </flux:heading>

                            <flux:subheading>
                                حدد اسم الدور ووصفه ثم اختر الصلاحيات المتاحة لهذا الدور.
                            </flux:subheading>

                        </div>

                    </div>

                </div>


                {{-- Basic Information --}}

                <div
                    class="grid grid-cols-1 gap-4 md:grid-cols-2"
                >

                    <flux:field>

                        <flux:label>
                            اسم الدور
                        </flux:label>

                        <flux:input
                            wire:model="name"
                            placeholder="مثال: كاشير، مدير فرع، محاسب..."
                            autofocus
                        />

                        <flux:error name="name" />

                    </flux:field>


                    <flux:field>

                        <flux:label>
                            وصف الدور
                        </flux:label>

                        <flux:input
                            wire:model="description"
                            placeholder="وصف مختصر لمسؤوليات هذا الدور"
                        />

                        <flux:error name="description" />

                    </flux:field>

                </div>


                {{-- Permissions Header --}}

                <div class="space-y-3">

                    <div
                        class="flex flex-col gap-3
                               sm:flex-row sm:items-center
                               sm:justify-between"
                    >

                        <div>

                            <div class="flex items-center gap-2">

                                <flux:heading size="sm">
                                    الصلاحيات
                                </flux:heading>

                                <flux:badge
                                    size="sm"
                                    color="indigo"
                                    variant="subtle"
                                >
                                    {{ count($selectedPermissions) }}
                                    محددة
                                </flux:badge>

                            </div>

                            <p
                                class="mt-1 text-xs text-zinc-500"
                            >
                                اختر الصلاحيات التي يستطيع هذا الدور استخدامها.
                            </p>

                        </div>


                        <div class="w-full sm:w-72">

                            <flux:input
                                wire:model.live.debounce.250ms="searchPermission"
                                icon="magnifying-glass"
                                placeholder="ابحث في الصلاحيات..."
                                size="sm"
                            />

                        </div>

                    </div>


                    {{-- Permission Toolbar --}}

                    <div
                        class="flex flex-wrap items-center
                               justify-between gap-2 rounded-xl
                               border border-zinc-200
                               bg-zinc-50 px-3 py-2
                               dark:border-zinc-800
                               dark:bg-zinc-900/50"
                    >

                        <div class="text-xs text-zinc-500">

                            {{ $permissions->count() }}
                            صلاحية ظاهرة

                        </div>


                        <button
                            type="button"
                            wire:click="toggleAllPermissions(@js($visiblePermissionIds))"
                            class="text-xs font-semibold
                                   text-indigo-600
                                   hover:text-indigo-700
                                   dark:text-indigo-400"
                        >
                            تحديد / إلغاء تحديد الظاهر
                        </button>

                    </div>


                    {{-- Permission Groups --}}

                    <div
                        class="max-h-[48vh] space-y-4
                               overflow-y-auto pr-1"
                    >

                        @forelse ($permissionsGrouped as $group => $groupPermissions)

                            @php

                                $groupIds = $groupPermissions
                                    ->pluck('id')
                                    ->map(fn ($id) => (string) $id)
                                    ->toArray();

                                $selectedIds = array_map(
                                    'strval',
                                    $selectedPermissions
                                );

                                $selectedInGroup = count(
                                    array_intersect(
                                        $groupIds,
                                        $selectedIds
                                    )
                                );

                                $allSelected =
                                    !empty($groupIds)
                                    && $selectedInGroup === count($groupIds);

                            @endphp


                            <div
                                wire:key="permission-group-{{ md5($group) }}"
                                class="overflow-hidden rounded-xl
                                       border border-zinc-200
                                       bg-white
                                       dark:border-zinc-800
                                       dark:bg-zinc-900"
                            >

                                {{-- Group Header --}}

                                <div
                                    class="flex flex-col gap-2
                                           border-b border-zinc-200
                                           bg-zinc-50 px-4 py-3
                                           sm:flex-row
                                           sm:items-center
                                           sm:justify-between
                                           dark:border-zinc-800
                                           dark:bg-zinc-900/70"
                                >

                                    <div class="flex items-center gap-2">

                                        <div
                                            class="flex h-8 w-8
                                                   items-center justify-center
                                                   rounded-lg bg-white
                                                   text-zinc-600 shadow-sm
                                                   dark:bg-zinc-800
                                                   dark:text-zinc-300"
                                        >

                                            <flux:icon
                                                name="folder"
                                                class="size-4"
                                            />

                                        </div>

                                        <div>

                                            <div
                                                class="text-sm font-semibold
                                                       text-zinc-800
                                                       dark:text-zinc-200"
                                            >
                                                {{ $group ?: 'عام' }}
                                            </div>

                                            <div
                                                class="text-xs text-zinc-500"
                                            >
                                                {{ $selectedInGroup }}
                                                من
                                                {{ count($groupIds) }}
                                                محددة
                                            </div>

                                        </div>

                                    </div>


                                    <button
                                        type="button"
                                        wire:click="toggleGroup(@js($groupIds))"
                                        class="text-xs font-semibold
                                               text-indigo-600
                                               hover:text-indigo-700
                                               dark:text-indigo-400"
                                    >

                                        {{ $allSelected
                                            ? 'إلغاء تحديد الكل'
                                            : 'تحديد المجموعة'
                                        }}

                                    </button>

                                </div>


                                {{-- Permission Items --}}

                                <div
                                    class="grid grid-cols-1 gap-2 p-3
                                           sm:grid-cols-2"
                                >

                                    @foreach ($groupPermissions as $permission)

                                        @php
                                            $isSelected = in_array(
                                                (string) $permission->id,
                                                array_map(
                                                    'strval',
                                                    $selectedPermissions
                                                ),
                                                true
                                            );
                                        @endphp

                                        <label
                                            wire:key="permission-{{ $permission->id }}"
                                            class="group flex cursor-pointer
                                                   items-start gap-3
                                                   rounded-xl border p-3
                                                   transition
                                                   {{ $isSelected
                                                        ? 'border-indigo-300 bg-indigo-50/70 dark:border-indigo-700 dark:bg-indigo-950/30'
                                                        : 'border-zinc-200 bg-white hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-800/70'
                                                   }}"
                                        >

                                            <input
                                                type="checkbox"
                                                wire:model="selectedPermissions"
                                                value="{{ $permission->id }}"
                                                class="mt-0.5 rounded
                                                       border-zinc-300
                                                       text-indigo-600
                                                       focus:ring-indigo-500
                                                       dark:border-zinc-600
                                                       dark:bg-zinc-800"
                                            >

                                            <div class="min-w-0">

                                                <div
                                                    class="text-sm font-medium
                                                           text-zinc-800
                                                           dark:text-zinc-200"
                                                >
                                                    {{ $permission->display_name }}
                                                </div>

                                                @if ($permission->name)

                                                    <div
                                                        class="mt-0.5 truncate
                                                               text-[11px]
                                                               text-zinc-400"
                                                    >
                                                        {{ $permission->name }}
                                                    </div>

                                                @endif

                                            </div>

                                        </label>

                                    @endforeach

                                </div>

                            </div>

                        @empty

                            <div
                                class="flex flex-col items-center
                                       justify-center rounded-xl
                                       border border-dashed
                                       border-zinc-300 py-12
                                       text-center
                                       dark:border-zinc-700"
                            >

                                <div
                                    class="flex h-12 w-12
                                           items-center justify-center
                                           rounded-xl bg-zinc-100
                                           text-zinc-400
                                           dark:bg-zinc-800"
                                >

                                    <flux:icon
                                        name="magnifying-glass"
                                        class="size-5"
                                    />

                                </div>

                                <p
                                    class="mt-3 text-sm font-medium
                                           text-zinc-700
                                           dark:text-zinc-300"
                                >
                                    لا توجد صلاحيات
                                </p>

                                <p class="mt-1 text-xs text-zinc-500">
                                    لا توجد صلاحيات مطابقة للبحث الحالي.
                                </p>

                            </div>

                        @endforelse

                    </div>

                </div>


                {{-- Footer --}}

                <div
                    class="flex flex-col-reverse gap-2 border-t
                           border-zinc-200 pt-4
                           sm:flex-row sm:items-center
                           sm:justify-end
                           dark:border-zinc-800"
                >

                    <flux:button
                        type="button"
                        variant="ghost"
                        wire:click="closeModal"
                    >
                        إلغاء
                    </flux:button>


                    <flux:button
                        type="submit"
                        variant="primary"
                        wire:loading.attr="disabled"
                    >

                        <span
                            wire:loading.remove
                            wire:target="save"
                        >
                            {{ $isEditing
                                ? 'حفظ التعديلات'
                                : 'إنشاء الدور'
                            }}
                        </span>

                        <span
                            wire:loading
                            wire:target="save"
                        >
                            جاري الحفظ...
                        </span>

                    </flux:button>

                </div>

            </form>

        </flux:modal>

    </div>

</flux:main>
