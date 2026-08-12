<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use App\Models\Category;
use Illuminate\Validation\Rule;

new class extends Component {
    use WithPagination;

    // --- حقول النموذج ---
    public ?int $category_id = null;
    public string $name = '';
    public string $code = '';
    public string $description = '';
    public int $sort_order = 0;
    public bool $is_active = true;

    // --- حالة الواجهة والبحث ---
    public string $search = '';
    public bool $showModal = false;
    public bool $isEditing = false;

    protected function rules()
    {
        $tenantId = session('active_tenant_id');

        return [
            'name' => 'required|string|max:255',
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('categories', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($this->category_id)
            ],
            'description' => 'nullable|string|max:500',
            'sort_order' => 'required|integer|min:0',
            'is_active' => 'boolean',
        ];
    }

    protected $validationAttributes = [
        'name' => 'اسم التصنيف',
        'code' => 'كود التصنيف',
        'description' => 'الوصف',
        'sort_order' => 'أولوية الترتيب',
        'is_active' => 'حالة التفعيل',
    ];

    public function updatedSearch()
    {
        $this->resetPage();
    }

    // إعادة تعيين الصفحة وإغلاق النافذة عند تغيير المتجر النشط
    #[On('tenant-changed')]
    public function handleTenantChanged()
    {
        $this->resetPage();
        $this->closeModal();
    }

    public function openCreateModal()
    {
        $this->resetInputFields();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function edit($id)
    {
        $tenantId = session('active_tenant_id');

        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);

        $this->category_id = $category->id;
        $this->name = $category->name;
        $this->code = $category->code ?? '';
        $this->description = $category->description ?? '';
        $this->sort_order = $category->sort_order;
        $this->is_active = (bool) $category->is_active;

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function save()
    {
        $this->validate();

        $tenantId = session('active_tenant_id');

        if (!$tenantId) {
            session()->flash('message', 'يرجى اختيار متجر أولاً لتتمكن من إتمام العملية.');
            return;
        }

        $data = [
            'tenant_id' => $tenantId,
            'name' => $this->name,
            'code' => $this->code ?: null,
            'description' => $this->description ?: null,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];

        if ($this->isEditing && $this->category_id) {
            Category::where('tenant_id', $tenantId)->findOrFail($this->category_id)->update($data);
        } else {
            Category::create($data);
        }

        session()->flash('message', $this->isEditing ? 'تم تحديث بيانات التصنيف بنجاح.' : 'تم إضافة التصنيف بنجاح.');

        $this->closeModal();
    }

    public function delete($id)
    {
        $tenantId = session('active_tenant_id');

        $category = Category::where('tenant_id', $tenantId)->findOrFail($id);

        if ($category->products()->count() > 0) {
            session()->flash('message', 'لا يمكن حذف التصنيف لأنه يحتوي على منتجات مرتبطة به.');
            return;
        }

        $category->delete();
        session()->flash('message', 'تم نقل التصنيف إلى سلة المهملات.');
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetInputFields();
    }

    private function resetInputFields()
    {
        $this->category_id = null;
        $this->name = '';
        $this->code = '';
        $this->description = '';
        $this->sort_order = 0;
        $this->is_active = true;
        $this->resetValidation();
    }

    public function render()
    {
        $tenantId = session('active_tenant_id');

        $categories = Category::where('tenant_id', $tenantId)
            ->when($this->search, function ($q) {
                $q->where(function ($query) {
                    $query->where('name', 'like', '%' . $this->search . '%')
                        ->orWhere('code', 'like', '%' . $this->search . '%');
                });
            })
            ->orderBy('sort_order', 'asc')
            ->latest()
            ->paginate(10);

        return $this->view([
            'categories' => $categories,
        ])->layout('layouts::tenant');
    }
};
?>
<flux:main class="space-y-6">

<div>
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-zinc-200 dark:border-zinc-800">
        <div>
            <flux:heading size="xl" level="1">إدارة التصنيفات الأقسام</flux:heading>
            <flux:subheading>إضافة وتعديل التصنيفات وترتيب عرضها في الكاشير للمتجر الحالي</flux:subheading>
        </div>
        <div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                إضافة تصنيف جديد
            </flux:button>
        </div>
    </div>

    <!-- Alert Message -->
    @if (session()->has('message'))
        <flux:badge variant="success" class="w-full justify-start p-3 text-sm my-4">
            {{ session('message') }}
        </flux:badge>
    @endif

    <!-- Search & Filter Bar -->
    <div class="flex items-center justify-between gap-4 mt-4 mb-6">
        <div class="w-full sm:w-80">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="بحث باسم التصنيف أو الكود..." />
        </div>
    </div>

    <!-- Categories Table -->
    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>الترتيب</flux:table.column>
                <flux:table.column>اسم التصنيف</flux:table.column>
                <flux:table.column>الكود</flux:table.column>
                <flux:table.column>الوصف</flux:table.column>
                <flux:table.column>الحالة</flux:table.column>
                <flux:table.column align="end">الإجراءات</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($categories as $category)
                    <flux:table.row wire:key="category-row-{{ $category->id }}">
                        <flux:table.cell class="font-mono text-zinc-500">
                            {{ $category->sort_order }}
                        </flux:table.cell>

                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            {{ $category->name }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($category->code)
                                <flux:badge size="sm" variant="subtle" color="zinc">{{ $category->code }}</flux:badge>
                            @else
                                <span class="text-zinc-400 text-xs">لا يوجد</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-600 dark:text-zinc-400">
                            {{ $category->description ?? '-' }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($category->is_active)
                                <flux:badge size="sm" color="emerald">مفعل</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">معطل</flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="edit({{ $category->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash"
                                    class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"
                                    wire:click="delete({{ $category->id }})"
                                    wire:confirm="هل أنت تأكد من نقل هذا التصنيف لسلة المهملات؟" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" align="center" class="py-8 text-zinc-500">
                            لا يوجد تصنيفات مضافة لهذا المتجر بعد.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if($categories->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                {{ $categories->links() }}
            </div>
        @endif
    </flux:card>

    <!-- Create/Edit Modal -->
    <flux:modal wire:model="showModal" class="w-full max-w-xl space-y-6">
        <div>
            <flux:heading size="lg">{{ $isEditing ? 'تعديل التصنيف' : 'إضافة تصنيف جديد' }}</flux:heading>
            <flux:subheading>أدخل بيانات التصنيف مع تحديد حالة التفعيل والترتيب</flux:subheading>
        </div>

        <form wire:submit.prevent="save" class="space-y-4">
            <!-- اسم التصنيف -->
            <flux:field>
                <flux:label>اسم التصنيف</flux:label>
                <flux:input wire:model="name" placeholder="مثال: مشروبات، حلويات، شوكولاتة..." />
                <flux:error name="name" />
            </flux:field>

            <!-- الكود وأولوية الترتيب -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>كود التصنيف (اختياري)</flux:label>
                    <flux:input wire:model="code" placeholder="مثال: CAT-01" />
                    <flux:error name="code" />
                </flux:field>

                <flux:field>
                    <flux:label>أولوية الترتيب</flux:label>
                    <flux:input type="number" wire:model="sort_order" min="0" placeholder="0" />
                    <flux:error name="sort_order" />
                </flux:field>
            </div>

            <!-- الوصف -->
            <flux:field>
                <flux:label>الوصف</flux:label>
                <flux:textarea wire:model="description" rows="3" placeholder="وصف اختياري للقسم..." />
                <flux:error name="description" />
            </flux:field>

            <!-- التفعيل -->
            <flux:checkbox wire:model="is_active" label="مفعل للظهور في واجهة الكاشير" />

            <!-- الأزرار -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ التصنيف</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
</flux:main>
