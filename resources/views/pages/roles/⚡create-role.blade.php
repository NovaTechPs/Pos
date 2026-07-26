<?php

use Livewire\Component;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public string $name = '';
    public string $description = '';
    public array $selectedPermissions = [];

    protected array $rules = [
        'name' => 'required|string|max:255',
        'description' => 'nullable|string|max:500',
        'selectedPermissions' => 'array',
    ];

    public function save(): void
    {
        $this->validate();

        // 1. إنشاء الدور وربطه بـ Tenant المستخدم
        $role = Role::create([
            'tenant_id' => Auth::user()->tenant_id,
            'name' => $this->name,
            'description' => $this->description,
        ]);

        // 2. ربط الصلاحيات في الجدول الوسيط
        if (!empty($this->selectedPermissions)) {
            $role->permissions()->attach($this->selectedPermissions);
        }

        session()->flash('success', 'تم إنشاء الدور وتعيين الصلاحيات بنجاح.');

        $this->reset(['name', 'description', 'selectedPermissions']);
    }

    public function with(): array
    {
        return [
            'permissionGroups' => Permission::all()->groupBy('group'),
        ];
    }
};
?>

<div class="max-w-4xl mx-auto p-6 bg-white rounded-lg shadow-md text-gray-900" dir="rtl">
    <h2 class="text-xl font-bold mb-4 text-gray-900 border-b pb-2">إضافة دور جديد وتحديد الصلاحيات</h2>

    @if (session()->has('success'))
        <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-md">
            {{ session('success') }}
        </div>
    @endif

    <form wire:submit="save">
        {{-- اسم الدور --}}
        <div class="mb-4">
            <label class="block text-gray-900 font-medium mb-2">اسم الدور (مثال: كاشير مسائي)</label>
            <input type="text" wire:model="name" class="w-full border-gray-300 rounded-md shadow-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500">
            @error('name') <span class="text-red-600 text-sm block mt-1">{{ $message }}</span> @enderror
        </div>

        {{-- الوصف --}}
        <div class="mb-4">
            <label class="block text-gray-900 font-medium mb-2">الوصف (اختياري)</label>
            <textarea wire:model="description" rows="2" class="w-full border-gray-300 rounded-md shadow-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500"></textarea>
            @error('description') <span class="text-red-600 text-sm block mt-1">{{ $message }}</span> @enderror
        </div>

        {{-- الصلاحيات مقسمة حسب المجموعات --}}
        <div class="mb-6">
            <label class="block text-gray-900 font-bold mb-3">تحديد الصلاحيات المتاحة:</label>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach($permissionGroups as $groupName => $permissions)
                    <div class="border rounded-lg p-4 bg-gray-50 border-gray-200">
                        <h3 class="font-bold text-indigo-700 mb-3 border-b border-gray-200 pb-1">{{ $groupName }}</h3>
                        <div class="space-y-2">
                            @foreach($permissions as $permission)
                                <label class="flex items-center space-x-2 space-x-reverse cursor-pointer">
                                    <input type="checkbox"
                                           wire:model="selectedPermissions"
                                           value="{{ $permission->id }}"
                                           class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                    <span class="text-sm font-medium text-gray-900">{{ $permission->display_name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            @error('selectedPermissions') <span class="text-red-600 text-sm block mt-2">{{ $message }}</span> @enderror
        </div>

        {{-- زر الحفظ --}}
        <div>
            <button type="submit" class="bg-indigo-600 text-white px-6 py-2 rounded-md hover:bg-indigo-700 transition font-medium">
                حفظ الدور
            </button>
        </div>
    </form>
</div>
