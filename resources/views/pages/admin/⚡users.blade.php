<?php

use Livewire\Component;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

new class extends Component {
    public $tenants;
    public $plans;
    public $tenant_id = null;

    // بيانات المتجر (Tenant)
    public string $tenant_name = '';
    public string $domain = '';
    public ?int $plan_id = null; // تعديل: استخدام plan_id بدلاً من plan النصي
    public bool $is_active = true;

    // بيانات المالك (Owner)
    public bool $is_existing_owner = false;
    public ?int $selected_user_id = null;
    public $existing_users = [];

    // حالة المودال الرئيسي (الإضافة والتعديل)
    public bool $showModal = false;
    public bool $isEditing = false;

    // حالة مودال عرض متاجر المالك
    public bool $showOwnerTenantsModal = false;
    public ?User $selectedOwner = null;

    public string $owner_name = '';
    public string $owner_email = '';
    public string $owner_password = '';

    protected function rules()
    {
        $rules = [
            'tenant_name' => 'required|string|max:255',
            'domain'      => ['required', 'string', 'max:255', Rule::unique('tenants', 'domain')->ignore($this->tenant_id)],
            'plan_id'     => 'required|exists:plans,id', // التحقق من وجود المعرف في جدول plans
            'is_active'   => 'boolean',
        ];

        if (!$this->isEditing) {
            if ($this->is_existing_owner) {
                $rules['selected_user_id'] = 'required|exists:users,id';
            } else {
                $rules['owner_name']     = 'required|string|max:255';
                $rules['owner_email']    = 'required|email|max:255|unique:users,email';
                $rules['owner_password'] = 'required|string|min:8';
            }
        }

        return $rules;
    }

    public function mount()
    {
        $this->loadTenants();
        $this->loadOwners();
        $this->loadPlans();
    }

    public function loadTenants()
    {
        // شحن علاقة الباقة (plan) والمستأجر مع مالكه
        $this->tenants = Tenant::with(['plan', 'owner' => function($query) {
            $query->withCount('tenants');
        }])->latest()->get();
    }

    public function loadOwners()
    {
        $this->existing_users = User::all();
    }

    public function loadPlans()
    {
        $this->plans = Plan::where('is_active', true)->get();

        if (empty($this->plan_id) && $this->plans->isNotEmpty()) {
            $this->plan_id = $this->plans->first()->id;
        }
    }

    public function showOwnerTenants($userId)
    {
        $this->selectedOwner = User::with('tenants.plan')->find($userId);
        if ($this->selectedOwner) {
            $this->showOwnerTenantsModal = true;
        }
    }

    public function openCreateModal()
    {
        $this->resetInputFields();
        $this->loadOwners();
        $this->loadPlans();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit($id)
    {
        $tenant = Tenant::findOrFail($id);
        $this->tenant_id   = $tenant->id;
        $this->tenant_name = $tenant->name;
        $this->domain      = $tenant->domain;
        $this->plan_id     = $tenant->plan_id; // جلب plan_id
        $this->is_active   = (bool) $tenant->is_active;

        $this->isEditing = true;
        $this->showModal = true;
        $this->showOwnerTenantsModal = false;
    }

    public function save()
    {
        $this->validate();

        DB::transaction(function () {
            if ($this->isEditing) {
                $tenant = Tenant::findOrFail($this->tenant_id);
                $tenant->update([
                    'name'      => $this->tenant_name,
                    'domain'    => $this->domain,
                    'plan_id'   => $this->plan_id, // حفظ plan_id
                    'is_active' => $this->is_active,
                ]);
            } else {
                if ($this->is_existing_owner && $this->selected_user_id) {
                    $ownerId = $this->selected_user_id;
                } else {
                    $owner = User::create([
                        'name'     => $this->owner_name,
                        'email'    => $this->owner_email,
                        'password' => Hash::make($this->owner_password),
                    ]);
                    $ownerId = $owner->id;
                }

                Tenant::create([
                    'name'      => $this->tenant_name,
                    'domain'    => $this->domain,
                    'plan_id'   => $this->plan_id, // ربط المتجر بالباقة المختارة
                    'is_active' => $this->is_active,
                    'owner_id'  => $ownerId,
                ]);
            }
        });

        session()->flash('message', $this->isEditing ? 'تم تحديث بيانات المتجر بنجاح.' : 'تم إضافة المتجر بنجاح.');

        $this->closeModal();
        $this->loadTenants();
    }

    public function delete($id)
    {
        Tenant::findOrFail($id)->delete();
        session()->flash('message', 'تم حذف المتجر بنجاح.');
        $this->loadTenants();
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->showOwnerTenantsModal = false;
        $this->resetInputFields();
    }

    private function resetInputFields()
    {
        $this->tenant_id          = null;
        $this->tenant_name        = '';
        $this->domain             = '';
        $this->plan_id            = $this->plans->first()->id ?? null;
        $this->is_active          = true;

        $this->is_existing_owner  = false;
        $this->selected_user_id   = null;
        $this->owner_name         = '';
        $this->owner_email        = '';
        $this->owner_password     = '';

        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->layout('layouts::saas');
    }
};
?>

{{-- غلاف رئيسي لمنع خطأ Multiple Root Elements --}}
<flux:main class="space-y-6">
    <!-- الهيدر العلوي -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-zinc-200 dark:border-zinc-800">
        <div>
            <flux:heading size="xl" level="1">إدارة المستأجرين (Tenants)</flux:heading>
            <flux:subheading>إدارة وتعديل حسابات المحلات وأصحاب الأعمال في النظام</flux:subheading>
        </div>
        <div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                إضافة متجر جديد
            </flux:button>
        </div>
    </div>

    <!-- تنبيه النجاح -->
    @if (session()->has('message'))
        <flux:badge variant="success" class="w-full justify-start p-3 text-sm">
            {{ session('message') }}
        </flux:badge>
    @endif

    <!-- جدول العرض -->
    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>اسم المتجر / الشركة</flux:table.column>
                <flux:table.column>مالك المتجر</flux:table.column>
                <flux:table.column>النطاق (Domain)</flux:table.column>
                <flux:table.column>الباقة (Plan)</flux:table.column>
                <flux:table.column align="center">الحالة</flux:table.column>
                <flux:table.column align="end">الإجراءات</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($tenants as $tenant)
                    <flux:table.row key="{{ $tenant->id }}">
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            {{ $tenant->name }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($tenant->owner)
                                <div class="flex items-center gap-2">
                                    <button type="button" wire:click="showOwnerTenants({{ $tenant->owner->id }})" class="text-right group hover:underline cursor-pointer focus:outline-none">
                                        <div class="font-medium text-xs text-zinc-900 dark:text-zinc-100 group-hover:text-indigo-600 transition">
                                            {{ $tenant->owner->name }}
                                        </div>
                                        <div class="text-xs text-zinc-400">
                                            {{ $tenant->owner->email }}
                                        </div>
                                    </button>

                                    @php
                                        $count = $tenant->owner->tenants_count ?? 1;
                                    @endphp

                                    <button type="button" wire:click="showOwnerTenants({{ $tenant->owner->id }})" class="cursor-pointer">
                                        @if($count > 1)
                                            <flux:badge size="sm" color="indigo" inset="top bottom" class="hover:bg-indigo-200 transition">
                                                {{ $count }} متاجر
                                            </flux:badge>
                                        @else
                                            <flux:badge size="sm" variant="subtle" color="zinc" inset="top bottom">
                                                متجر واحد
                                            </flux:badge>
                                        @endif
                                    </button>
                                </div>
                            @else
                                <span class="text-xs text-zinc-400">غير محدد</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" color="zinc">{{ $tenant->domain }}</flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" variant="outline" color="indigo">
                                {{ $tenant->plan->name ?? 'بدون باقة' }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell align="center">
                            @if($tenant->is_active)
                                <flux:badge size="sm" variant="solid" color="emerald">نشط</flux:badge>
                            @else
                                <flux:badge size="sm" variant="solid" color="red">غير نشط</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="edit({{ $tenant->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash" class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30" wire:click="delete({{ $tenant->id }})" wire:confirm="هل أنت تأكد من إزالة هذا المستأجر؟" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" align="center" class="py-8 text-zinc-500">
                            لا يوجد مستأجرين مضافين بعد.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <!-- 1. مودال عرض المتاجر الخاصة بمالك محدد -->
    <flux:modal wire:model="showOwnerTenantsModal" class="md:w-160 space-y-6">
        @if($selectedOwner)
            <div>
                <flux:heading size="lg">متاجر المستخدم: {{ $selectedOwner->name }}</flux:heading>
                <flux:subheading>{{ $selectedOwner->email }} — إجمالي المتاجر الملوكة: ({{ $selectedOwner->tenants->count() }})</flux:subheading>
            </div>

            <div class="space-y-3">
                @forelse($selectedOwner->tenants as $t)
                    <div class="flex items-center justify-between p-3 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-900">
                        <div>
                            <div class="font-semibold text-sm text-zinc-900 dark:text-white">{{ $t->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $t->domain }}</div>
                        </div>

                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" variant="outline" color="indigo">{{ $t->plan->name ?? 'بدون باقة' }}</flux:badge>

                            @if($t->is_active)
                                <flux:badge size="sm" variant="solid" color="emerald">نشط</flux:badge>
                            @else
                                <flux:badge size="sm" variant="solid" color="red">غير نشط</flux:badge>
                            @endif

                            <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="edit({{ $t->id }})" />
                        </div>
                    </div>
                @empty
                    <div class="text-center py-4 text-zinc-500 text-sm">لا توجد متاجر مرتبطة بهذا المستخدم.</div>
                @endforelse
            </div>

            <div class="flex justify-end pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إغلاق</flux:button>
            </div>
        @endif
    </flux:modal>

    <!-- 2. مودال إضافة / تعديل المتجر -->
    <flux:modal wire:model="showModal" class="md:w-160 space-y-6">
        <div>
            <flux:heading size="lg">{{ $isEditing ? 'تعديل بيانات المتجر' : 'إضافة متجر جديد' }}</flux:heading>
            <flux:subheading>أدخل بيانات المتجر {{ $isEditing ? '' : 'والمالك الخاص به' }}</flux:subheading>
        </div>

        <form wire:submit.prevent="save" class="space-y-6">

            <div class="space-y-4">
                <flux:heading level="3" size="sm" class="font-semibold text-zinc-500 border-b pb-2">بيانات المتجر</flux:heading>

                <flux:field>
                    <flux:label>اسم الشركة / المتجر</flux:label>
                    <flux:input wire:model="tenant_name" placeholder="مثال: شركة الأمل" />
                    <flux:error name="tenant_name" />
                </flux:field>

                <flux:field>
                    <flux:label>النطاق (Domain)</flux:label>
                    <flux:input wire:model="domain" placeholder="company.domain.com" />
                    <flux:error name="domain" />
                </flux:field>

                <!-- القائمة المنسدلة الديناميكية للباقات الربط بـ plan_id -->
                <flux:field>
                    <flux:label>الباقة المختارة (Plan)</flux:label>
                    <flux:select wire:model="plan_id">
                        <flux:select.option value="">-- اختر الباقة --</flux:select.option>
                        @foreach($plans as $p)
                            <flux:select.option value="{{ $p->id }}">{{ $p->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="plan_id" />
                </flux:field>
            </div>

            @if(!$isEditing)
                <div class="space-y-4 pt-2">
                    <flux:heading level="3" size="sm" class="font-semibold text-zinc-500 border-b pb-2">بيانات مالك المتجر</flux:heading>

                    <flux:checkbox wire:model.live="is_existing_owner" label="تعيين المتجر لزبون / مالك حالي" />

                    @if($is_existing_owner)
                        <flux:field>
                            <flux:label>اختر الزبون من القائمة</flux:label>
                            <flux:select wire:model="selected_user_id">
                                <flux:select.option value="">-- اختر زبون --</flux:select.option>
                                @foreach($existing_users as $user)
                                    <flux:select.option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:error name="selected_user_id" />
                        </flux:field>
                    @else
                        <flux:field>
                            <flux:label>اسم المالك الجديد</flux:label>
                            <flux:input wire:model="owner_name" placeholder="مثال: أحمد محمد" />
                            <flux:error name="owner_name" />
                        </flux:field>

                        <flux:field>
                            <flux:label>البريد الإلكتروني</flux:label>
                            <flux:input type="email" wire:model="owner_email" placeholder="owner@company.com" />
                            <flux:error name="owner_email" />
                        </flux:field>

                        <flux:field>
                            <flux:label>كلمة المرور</flux:label>
                            <flux:input type="password" wire:model="owner_password" placeholder="••••••••" />
                            <flux:error name="owner_password" />
                        </flux:field>
                    @endif
                </div>
            @endif

            <flux:checkbox wire:model="is_active" label="حساب نشط (Active)" />

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ البيانات</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:main>
