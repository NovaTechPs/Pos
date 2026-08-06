<?php

use Livewire\Component;
use App\Models\User;
use App\Models\Role;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

new class extends Component {
    public $employees;
    public $roles;
    public $branches;
    public $user_id;

    public string $name = '';
    public string $email = '';
    public string $password = '';
    public $branch_id = null;
    public $role_id = null;
    public bool $is_active = true;

    public bool $showModal = false;
    public bool $isEditing = false;

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user_id)],
            'password' => $this->isEditing ? 'nullable|min:8' : 'required|min:8',
            'branch_id' => 'required|exists:branches,id',
            'role_id' => 'required|exists:roles,id',
            'is_active' => 'boolean',
        ];
    }

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $tenantId = Auth::user()->tenant_id;

        // جلب الموظفين التابعين لمتجر المالك فقط (استثناء المالك نفسه)
        $this->employees = User::with(['role', 'branch'])
            ->where('tenant_id', $tenantId)
            ->where('is_owner', false)
            ->get();

        $this->roles = Role::where('tenant_id', $tenantId)->get();
        $this->branches = Branch::where('tenant_id', $tenantId)->get();
    }

    public function openCreateModal()
    {
        $this->resetInputFields();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit($id)
    {
        $employee = User::where('tenant_id', Auth::user()->tenant_id)
            ->where('is_owner', false)
            ->findOrFail($id);

        $this->user_id = $employee->id;
        $this->name = $employee->name;
        $this->email = $employee->email;
        $this->branch_id = $employee->branch_id;
        $this->role_id = $employee->role_id;
        $this->is_active = (bool)$employee->is_active;
        $this->password = '';

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save()
    {

        $this->validate();

        $data = [
            'name' => $this->name,
            'email' => $this->email,
            'tenant_id' => Auth::user()->tenant_id,
            'branch_id' => $this->branch_id,
            'role_id' => $this->role_id,
            'type' => 'tenant_user',
            'is_owner' => false,
            'is_active' => $this->is_active,
        ];

        if (!empty($this->password)) {
            $data['password'] = Hash::make($this->password);
        }

        User::updateOrCreate(['id' => $this->user_id], $data);

        session()->flash('message', $this->isEditing ? 'تم تحديث بيانات الموظف بنجاح.' : 'تم إضافة الموظف بنجاح.');

        $this->closeModal();
        $this->loadData();
    }

    public function delete($id)
    {
        User::where('tenant_id', Auth::user()->tenant_id)
            ->where('is_owner', false)
            ->findOrFail($id)
            ->delete();

        session()->flash('message', 'تم إزالة حساب الموظف بنجاح.');
        $this->loadData();
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetInputFields();
    }

    private function resetInputFields()
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

   public function render()
    {
        return $this->view()->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-zinc-200 dark:border-zinc-800">
        <div>
            <flux:heading size="xl" level="1">إدارة الموظفين</flux:heading>
            <flux:subheading>إضافة الموظفين وتعيين فروعهم وأدوارهم الوظيفية</flux:subheading>
        </div>
        <div>
            <flux:button variant="primary" icon="user-plus" wire:click="openCreateModal">
                إضافة موظف جديد
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
                <flux:table.column>اسم الموظف</flux:table.column>
                <flux:table.column>البريد الإلكتروني</flux:table.column>
                <flux:table.column>الفرع</flux:table.column>
                <flux:table.column>الدورالوظيفي</flux:table.column>
                <flux:table.column align="center">الحالة</flux:table.column>
                <flux:table.column align="end">الإجراءات</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($employees as $employee)
                    <flux:table.row key="{{ $employee->id }}">
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            {{ $employee->name }}
                        </flux:table.cell>

                        <flux:table.cell>
                            {{ $employee->email }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($employee->branch)
                                <flux:badge size="sm" variant="subtle" color="zinc">
                                    {{ $employee->branch->name }}
                                </flux:badge>
                            @else
                                <span class="text-xs text-zinc-400">غير محدد</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($employee->role)
                                <flux:badge size="sm" variant="subtle" color="indigo">
                                    {{ $employee->role->name }}
                                </flux:badge>
                            @else
                                <span class="text-xs text-zinc-400">بدون دور</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="center">
                            @if($employee->is_active)
                                <flux:badge size="sm" color="emerald" variant="solid">نشط</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc" variant="solid">معطل</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button variant="ghost" size="sm" icon="pencil-square"
                                    wire:click="edit({{ $employee->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash"
                                    class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"
                                    wire:click="delete({{ $employee->id }})"
                                    wire:confirm="هل أنت تأكد من إزالة هذا الموظف؟" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" align="center" class="py-8 text-zinc-500">
                            لا يوجد موظفون مضافين بعد.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <flux:modal wire:model="showModal" class="md:w-160 space-y-6">
        <div>
            <flux:heading size="lg">{{ $isEditing ? 'تعديل بيانات الموظف' : 'إضافة موظف جديد' }}</flux:heading>
            <flux:subheading>أدخل بيانات الحساب وحدد الفرع والدور الوظيفي</flux:subheading>
        </div>

        <form wire:submit.prevent="save" class="space-y-4">
            <flux:field>
                <flux:label>اسم الموظف</flux:label>
                <flux:input wire:model="name" placeholder="مثال: خالد العلي" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>البريد الإلكتروني</flux:label>
                <flux:input type="email" wire:model="email" placeholder="employee@example.com" />
                <flux:error name="email" />
            </flux:field>

            <flux:field>
                <flux:label>كلمة المرور {{ $isEditing ? '(اتركها فارغة إذا لم ترد تغييرها)' : '' }}</flux:label>
                <flux:input type="password" wire:model="password" placeholder="••••••••" />
                <flux:error name="password" />
            </flux:field>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>الفرع / المخزن</flux:label>
                    <flux:select wire:model="branch_id">
                        <option value="">-- اختر الفرع --</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="branch_id" />
                </flux:field>

                <flux:field>
                    <flux:label>الدور الوظيفي</flux:label>
                    <flux:select wire:model="role_id">
                        <option value="">-- اختر الدور --</option>
                        @foreach ($roles as $role)
                            <option value="{{ $role->id }}">{{ $role->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="role_id" />
                </flux:field>
            </div>

            <div class="pt-2">
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" wire:model="is_active" class="rounded border-zinc-300 text-indigo-600">
                    <span>حساب الموظف نشط</span>
                </label>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ الموظف</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:main>
