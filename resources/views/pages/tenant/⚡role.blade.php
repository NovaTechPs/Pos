<?php

use Livewire\Component;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    public ?int $role_id = null;

    public string $name = '';
    public string $description = '';
    public array $selectedPermissions = [];
    public string $searchPermission = ''; // حقل البحث في الصلاحيات

    public bool $showModal = false;
    public bool $isEditing = false;

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'selectedPermissions' => 'nullable|array',
            'selectedPermissions.*' => 'exists:permissions,id',
        ];
    }

    public function openCreateModal()
    {
        $this->resetInputFields();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit($id)
    {
        $role = Role::where('tenant_id', Auth::user()->tenant_id)
            ->with('permissions')
            ->findOrFail($id);

        $this->role_id = $role->id;
        $this->name = $role->name;
        $this->description = $role->description ?? '';
        $this->selectedPermissions = $role->permissions->pluck('id')->map(fn($id) => (string)$id)->toArray();

        $this->isEditing = true;
        $this->showModal = true;
    }

    // تحديد أو إلغاء تحديد مجموعة كاملة
    public function toggleGroup($groupIds)
    {
        $groupIdsStr = array_map('strval', $groupIds);
        $allSelected = empty(array_diff($groupIdsStr, $this->selectedPermissions));

        if ($allSelected) {
            // إلغاء تحديد المجموعة
            $this->selectedPermissions = array_values(array_diff($this->selectedPermissions, $groupIdsStr));
        } else {
            // تحديد المجموعة كاملة
            $this->selectedPermissions = array_values(array_unique(array_merge($this->selectedPermissions, $groupIdsStr)));
        }
    }

    public function save()
    {
        $this->validate();

        if ($this->isEditing && $this->role_id) {
            $role = Role::where('tenant_id', Auth::user()->tenant_id)->findOrFail($this->role_id);
            $role->update([
                'name' => $this->name,
                'description' => $this->description,
            ]);
        } else {
            $role = Role::create([
                'tenant_id' => Auth::user()->tenant_id,
                'name' => $this->name,
                'description' => $this->description,
            ]);
        }

        $permissionIds = array_map('intval', array_filter($this->selectedPermissions));
        $role->permissions()->sync($permissionIds);

        session()->flash('message', $this->isEditing ? 'تم تحديث الدور بنجاح.' : 'تم إضافة الدور بنجاح.');

        $this->closeModal();
    }

    public function delete($id)
    {
        Role::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id)->delete();
        session()->flash('message', 'تم حذف الدور بنجاح.');
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetInputFields();
    }

    private function resetInputFields()
    {
        $this->role_id = null;
        $this->name = '';
        $this->description = '';
        $this->selectedPermissions = [];
        $this->searchPermission = '';
        $this->resetValidation();
    }

    public function render()
    {
        // فلترة الصلاحيات حسب كلمة البحث إن وجدت
        $permissionsQuery = Permission::query();
        if (!empty($this->searchPermission)) {
            $permissionsQuery->where(function($q) {
                $q->where('display_name', 'like', '%' . $this->searchPermission . '%')
                  ->orWhere('group', 'like', '%' . $this->searchPermission . '%');
            });
        }

        return $this->view([
            'roles' => Role::where('tenant_id', Auth::user()->tenant_id)->with('permissions')->get(),
            'permissionsGrouped' => $permissionsQuery->get()->groupBy('group'),
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-zinc-200 dark:border-zinc-800">
        <div>
            <flux:heading size="xl" level="1">إدارة الأدوار والصلاحيات</flux:heading>
            <flux:subheading>تعريف أدوار العمل وتخصيص صلاحيات الموظفين داخل المتجر</flux:subheading>
        </div>
        <div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                إضافة دور جديد
            </flux:button>
        </div>
    </div>

    @if (session()->has('message'))
        <flux:badge variant="success" class="w-full justify-start p-3 text-sm">
            {{ session('message') }}
        </flux:badge>
    @endif

    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>اسم الدور</flux:table.column>
                <flux:table.column>الوصف</flux:table.column>
                <flux:table.column>عدد الصلاحيات</flux:table.column>
                <flux:table.column align="end">الإجراءات</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($roles as $role)
                    <flux:table.row wire:key="role-row-{{ $role->id }}">
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            {{ $role->name }}
                        </flux:table.cell>

                        <flux:table.cell>
                            {{ $role->description ?? '-' }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" color="indigo" variant="subtle">
                                {{ $role->permissions->count() }} صلاحية
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button variant="ghost" size="sm" icon="pencil-square"
                                    wire:click="edit({{ $role->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash"
                                    class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"
                                    wire:click="delete({{ $role->id }})"
                                    wire:confirm="هل أنت تأكد من إزالة هذا الدور؟" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4" align="center" class="py-8 text-zinc-500">
                            لا يوجد أدوار مضافة بعد.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <!-- مودال إضافة وتعديل الدور بأسلوب محسن وأعرض -->
    <flux:modal wire:model="showModal" class="w-full max-w-3xl space-y-6">
        <div>
            <flux:heading size="lg">{{ $isEditing ? 'تعديل الدور' : 'إضافة دور جديد' }}</flux:heading>
            <flux:subheading>حدد بيانات الدور والصلاحيات المتاحة للموظف</flux:subheading>
        </div>

        <form wire:submit.prevent="save" class="space-y-6">
            <!-- تفاصيل الدور -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>اسم الدور</flux:label>
                    <flux:input wire:model="name" placeholder="مثال: كاشير مسائي، مدير فرع..." />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>الوصف</flux:label>
                    <flux:input wire:model="description" placeholder="وصف مختصر لمسؤوليات هذا الدور" />
                    <flux:error name="description" />
                </flux:field>
            </div>

            <!-- رأس قسم الصلاحيات ومربع البحث -->
            <div class="space-y-3 pt-2">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <flux:label class="font-bold text-base">تحديد الصلاحيات المسموح بها</flux:label>
                    <div class="w-full sm:w-64">
                        <flux:input wire:model.live.debounce.200ms="searchPermission" icon="magnifying-glass" placeholder="بحث عن صلاحية..." size="sm" />
                    </div>
                </div>

                <!-- قائمة الصلاحيات المنظمة -->
                <div class="max-h-[50vh] overflow-y-auto space-y-4 p-1 pr-2">
                    @forelse($permissionsGrouped as $group => $permissions)
                        @php
                            $groupIds = $permissions->pluck('id')->toArray();
                            $groupIdsStr = array_map('strval', $groupIds);
                            $allSelected = !empty($groupIdsStr) && empty(array_diff($groupIdsStr, $selectedPermissions));
                        @endphp

                        <div class="border rounded-xl p-4 bg-zinc-50/50 dark:bg-zinc-900/40 dark:border-zinc-800 space-y-3" wire:key="group-card-{{ $group }}">
                            <!-- عنوان المجموعة وزر تحديد الكل -->
                            <div class="flex items-center justify-between border-b pb-2 dark:border-zinc-700/60">
                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm" variant="subtle" color="zinc">{{ strtoupper($group) }}</flux:badge>
                                    <span class="text-xs text-zinc-500">({{ $permissions->count() }} صلاحية)</span>
                                </div>

                                <button type="button"
                                        wire:click="toggleGroup({{ json_encode($groupIds) }})"
                                        class="text-xs font-semibold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300">
                                    {{ $allSelected ? 'إلغاء تحديد الكل' : 'تحديد الكل' }}
                                </button>
                            </div>

                            <!-- شبكة الصلاحيات -->
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach($permissions as $permission)
                                    <label class="flex items-start gap-3 p-2 rounded-lg bg-white dark:bg-zinc-800/80 border border-zinc-200/80 dark:border-zinc-700/50 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors cursor-pointer" wire:key="perm-{{ $permission->id }}">
                                        <input type="checkbox"
                                               wire:model="selectedPermissions"
                                               value="{{ $permission->id }}"
                                               class="mt-0.5 rounded border-zinc-300 text-indigo-600 focus:ring-indigo-500 dark:border-zinc-600 dark:bg-zinc-700">
                                        <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200 leading-tight">
                                            {{ $permission->display_name }}
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="text-center py-6 text-zinc-500 border rounded-xl dark:border-zinc-800">
                            لا توجد صلاحيات تطابق بحثك.
                        </div>
                    @endforelse
                </div>
            </div>

            <!-- أزرار الإجراءات -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ الدور</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:main>
