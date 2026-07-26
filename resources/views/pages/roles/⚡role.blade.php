<?php

use Livewire\Component;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    // متغير لتخزين الدور المحدد لفتح نافذة التفاصيل Modal
    public ?Role $selectedRole = null;
    public bool $showModal = false;

    // فتح تفاصيل الدور والصلاحيات
    public function viewRoleDetails(int $roleId): void
    {
        $this->selectedRole = Role::with(['permissions', 'users'])
            ->where('tenant_id', Auth::user()->tenant_id)
            ->findOrFail($roleId);

        $this->showModal = true;
    }

    // إغلاق النافذة المنبثقة
    public function closeModal(): void
    {
        $this->showModal = false;
        $this->selectedRole = null;
    }

    // حذف دور معين
    public function deleteRole(int $roleId): void
    {
        $role = Role::where('tenant_id', Auth::user()->tenant_id)->findOrFail($roleId);

        // منع حذف الدور إذا كان مرتبطة به حسابات موظفين
        if ($role->users()->count() > 0) {
            session()->flash('error', 'لا يمكن حذف هذا الدور لأنه مرتبط بموظفين حاليين.');
            return;
        }

        $role->delete();
        session()->flash('success', 'تم حذف الدور بنجاح.');
    }

    public function with(): array
    {
        return [
            'roles' => Role::where('tenant_id', Auth::user()->tenant_id)
                ->withCount(['permissions', 'users'])
                ->get(),
        ];
    }
};
?>

<div class="max-w-6xl mx-auto p-6 bg-white rounded-lg shadow-md text-gray-900" dir="rtl">

    {{-- الهيدر والزر الإضافي --}}
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <div>
            <h2 class="text-xl font-bold text-gray-900">إدارة الأدوار والصلاحيات</h2>
            <p class="text-sm text-gray-600">عرض قائمة الأدوار المتاحة في النظام والصلاحيات الممنوحة لكل منها.</p>
        </div>
        {{-- <a href="{{ route('roles.create') }}" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 transition font-medium text-sm">
            + إضافة دور جديد
        </a> --}}
    </div>

    {{-- رسائل التنبيه --}}
    @if (session()->has('success'))
        <div class="mb-4 p-4 bg-green-100 text-green-800 rounded-md font-medium">
            {{ session('success') }}
        </div>
    @endif

    @if (session()->has('error'))
        <div class="mb-4 p-4 bg-red-100 text-red-800 rounded-md font-medium">
            {{ session('error') }}
        </div>
    @endif

    {{-- جدول عرض الأدوار --}}
    <div class="overflow-x-auto">
        <table class="w-full text-right border-collapse">
            <thead>
                <tr class="bg-gray-100 text-gray-800 text-sm font-bold border-b border-gray-200">
                    <th class="p-3">اسم الدور</th>
                    <th class="p-3">الوصف</th>
                    <th class="p-3">عدد الصلاحيات</th>
                    <th class="p-3">عدد الموظفين</th>
                    <th class="p-3 text-center">العمليات</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($roles as $role)
                    <tr class="hover:bg-gray-50 transition">
                        <td class="p-3 font-bold text-indigo-700">{{ $role->name }}</td>
                        <td class="p-3 text-gray-600 text-sm">{{ $role->description ?? 'لا يوجد وصف' }}</td>
                        <td class="p-3">
                            <span class="bg-indigo-50 text-indigo-700 px-2.5 py-1 rounded-full text-xs font-bold border border-indigo-200">
                                {{ $role->permissions_count }} صلاحية
                            </span>
                        </td>
                        <td class="p-3">
                            <span class="bg-gray-100 text-gray-800 px-2.5 py-1 rounded-full text-xs font-bold border border-gray-300">
                                {{ $role->users_count }} موظف
                            </span>
                        </td>
                        <td class="p-3 text-center space-x-2 space-x-reverse">
                            <button wire:click="viewRoleDetails({{ $role->id }})" class="bg-blue-600 text-white px-3 py-1.5 rounded text-xs hover:bg-blue-700 transition">
                                عرض الصلاحيات
                            </button>
                            <button wire:confirm="هل أنت تأكد من رغبتك في حذف هذا الدور؟" wire:click="deleteRole({{ $role->id }})" class="bg-red-600 text-white px-3 py-1.5 rounded text-xs hover:bg-red-700 transition">
                                حذف
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="p-4 text-center text-gray-500">لا يوجد أدوار معرفة حتى الآن.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- نافذة منبثقة (Modal) لعرض الصلاحيات والموظفين بالتفصيل --}}
    @if($showModal && $selectedRole)
        <div class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl overflow-hidden">
                <div class="p-4 bg-gray-100 border-b flex justify-between items-center">
                    <h3 class="font-bold text-lg text-gray-900">تفاصيل الدور: {{ $selectedRole->name }}</h3>
                    <button wire:click="closeModal" class="text-gray-500 hover:text-gray-800 font-bold text-xl">&times;</button>
                </div>

                <div class="p-6 max-h-[70vh] overflow-y-auto space-y-6">
                    {{-- الصلاحيات الممنوحة --}}
                    <div>
                        <h4 class="font-bold text-indigo-700 mb-3 border-b pb-1">الصلاحيات الممنوحة:</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            @forelse($selectedRole->permissions->groupBy('group') as $group => $perms)
                                <div class="bg-gray-50 p-3 rounded border border-gray-200">
                                    <span class="block font-bold text-xs text-gray-500 mb-1">{{ $group }}</span>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($perms as $perm)
                                            <span class="bg-green-100 text-green-800 text-xs px-2 py-0.5 rounded font-medium">
                                                ✓ {{ $perm->display_name }}
                                            </span>
                                        @endforeach
                                    </div>
                                </div>
                            @empty
                                <p class="text-sm text-gray-500">لا توجد صلاحيات معينة لهذا الدور.</p>
                            @endforelse
                        </div>
                    </div>

                    {{-- الموظفون التابعون لهذا الدور --}}
                    <div>
                        <h4 class="font-bold text-indigo-700 mb-3 border-b pb-1">الموظفون المرتبطون بهذه الصفة:</h4>
                        <ul class="list-disc list-inside text-sm text-gray-700 space-y-1">
                            @forelse($selectedRole->users as $user)
                                <li>{{ $user->name }} ({{ $user->email }})</li>
                            @empty
                                <li class="text-gray-500 list-none">لا يوجد موظفون مسجلون بهذا الدور حالياً.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>

                <div class="p-4 bg-gray-50 border-t text-left">
                    <button wire:click="closeModal" class="bg-gray-600 text-white px-4 py-2 rounded text-sm hover:bg-gray-700 transition">
                        إغلاق
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>
