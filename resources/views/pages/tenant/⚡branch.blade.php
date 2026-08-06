<?php

use Livewire\Component;
use App\Models\Branch;
use Illuminate\Support\Facades\Auth;

new class extends Component {
    public $branches;
    public $branch_id;

    public string $name = '';
    public string $phone = '';
    public string $address = '';
    public string $type = 'branch';

    public bool $showModal = false;
    public bool $isEditing = false;

    protected function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'type' => 'required|in:branch,warehouse',
        ];
    }

    public function mount()
    {
        $this->loadBranches();
    }

    public function loadBranches()
    {
        // جلب الفروع والمخازن التابعة لمتجر المالك الحالي فقط
        $tenantId = Auth::user()->tenant_id;

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
        $branch = Branch::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id);

        $this->branch_id = $branch->id;
        $this->name = $branch->name;
        $this->phone = $branch->phone ?? '';
        $this->address = $branch->address ?? '';
        $this->type = $branch->type;

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save()
    {
        $this->validate();

        Branch::updateOrCreate(
            [
                'id' => $this->branch_id,
                'tenant_id' => Auth::user()->tenant_id,
            ],
            [
                'name' => $this->name,
                'phone' => $this->phone,
                'address' => $this->address,
                'type' => $this->type,
            ]
        );

        session()->flash('message', $this->isEditing ? 'تم تحديث بيانات الفرع/المخزن بنجاح.' : 'تم إضافة الفرع/المخزن بنجاح.');

        $this->closeModal();
        $this->loadBranches();
    }

    public function delete($id)
    {
        Branch::where('tenant_id', Auth::user()->tenant_id)->findOrFail($id)->delete();
        session()->flash('message', 'تم حذف الموقع بنجاح.');
        $this->loadBranches();
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetInputFields();
    }

    private function resetInputFields()
    {
        $this->branch_id = null;
        $this->name = '';
        $this->phone = '';
        $this->address = '';
        $this->type = 'branch';
        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6">
    <!-- الهيدر العلوي -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-zinc-200 dark:border-zinc-800">
        <div>
            <flux:heading size="xl" level="1">إدارة الفروع والمخازن</flux:heading>
            <flux:subheading>إضافة وتعديل نقاط البيع والمخازن التابعة لمتجرك</flux:subheading>
        </div>
        <div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                إضافة فرع / مخزن جديد
            </flux:button>
        </div>
    </div>

    @if (session()->has('message'))
        <flux:badge variant="success" class="w-full justify-start p-3 text-sm">
            {{ session('message') }}
        </flux:badge>
    @endif

    <!-- جدول عرض الفروع والمخازن -->
    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>الاسم</flux:table.column>
                <flux:table.column>النوع</flux:table.column>
                <flux:table.column>الهاتف</flux:table.column>
                <flux:table.column>العنوان</flux:table.column>
                <flux:table.column align="end">الإجراءات</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($branches as $branch)
                    <flux:table.row key="{{ $branch->id }}">
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            {{ $branch->name }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($branch->type === 'branch')
                                <flux:badge size="sm" color="emerald" variant="solid">فرع بيع</flux:badge>
                            @else
                                <flux:badge size="sm" color="indigo" variant="solid">مخزن رئيسي</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            {{ $branch->phone ?? '-' }}
                        </flux:table.cell>

                        <flux:table.cell>
                            {{ $branch->address ?? '-' }}
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button variant="ghost" size="sm" icon="pencil-square"
                                    wire:click="edit({{ $branch->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash"
                                    class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"
                                    wire:click="delete({{ $branch->id }})"
                                    wire:confirm="هل أنت تأكد من إزالة هذا الفرع/المخزن؟" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" align="center" class="py-8 text-zinc-500">
                            لا يوجد فروع أو مخازن مضافة بعد. يمكنك البدء بإضافة أول موقع.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </flux:card>

    <!-- المودال المتوافق مع Flux -->
    <flux:modal wire:model="showModal" class="md:w-160 space-y-6">
        <div>
            <flux:heading size="lg">{{ $isEditing ? 'تعديل بيانات الموقع' : 'إضافة فرع / مخزن جديد' }}</flux:heading>
            <flux:subheading>حدد نوع الموقع وتفاصيل التواصل والارتباط</flux:subheading>
        </div>

        <form wire:submit.prevent="save" class="space-y-4">
            <flux:field>
                <flux:label>نوع الموقع</flux:label>
                <flux:select wire:model="type">
                    <option value="branch">فرع بيع (Branch / POS)</option>
                    <option value="warehouse">مخزن (Warehouse)</option>
                </flux:select>
                <flux:error name="type" />
            </flux:field>

            <flux:field>
                <flux:label>اسم الفرع أو المخزن</flux:label>
                <flux:input wire:model="name" placeholder="مثال: فرع وسط البلد أو المخزن المركزي" />
                <flux:error name="name" />
            </flux:field>

            <flux:field>
                <flux:label>رقم الهاتف</flux:label>
                <flux:input wire:model="phone" placeholder="059xxxxxxx" />
                <flux:error name="phone" />
            </flux:field>

            <flux:field>
                <flux:label>العنوان / الموقع</flux:label>
                <flux:input wire:model="address" placeholder="المدينة، الشارع الرئيسي" />
                <flux:error name="address" />
            </flux:field>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ الموقع</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:main>
