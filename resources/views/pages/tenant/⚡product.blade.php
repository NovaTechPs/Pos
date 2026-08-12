<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\Attributes\On;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Validation\Rule;

new class extends Component {
    use WithPagination;

    // --- حقول نموذج المنتج ---
    public ?int $product_id = null;
    public ?int $category_id = null;
    public string $name = '';
    public string $barcode = '';
    public $cost_price = '0.00';
    public $retail_price = '';
    public $wholesale_price = '';
    public int $min_wholesale_quantity = 1;

    // --- حقول نموذج التصنيف السريع ---
    public string $newCategoryName = '';
    public string $newCategoryCode = '';
    public bool $showCategoryModal = false;

    // --- حالة الواجهة والبحث والتصفية ---
    public string $search = '';
    public ?string $selectedCategoryFilter = '';
    public bool $showModal = false;
    public bool $isEditing = false;

    protected function rules()
    {
        $tenantId = session('active_tenant_id');

        return [
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId)
            ],
            'name' => 'required|string|max:255',
            'barcode' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'barcode')
                    ->where('tenant_id', $tenantId)
                    ->ignore($this->product_id)
            ],
            'cost_price' => 'required|numeric|min:0',
            'retail_price' => 'required|numeric|min:0',
            'wholesale_price' => 'required|numeric|min:0',
            'min_wholesale_quantity' => 'required|integer|min:1',
        ];
    }

    protected $validationAttributes = [
        'category_id' => 'التصنيف',
        'name' => 'اسم المنتج',
        'barcode' => 'الباركود',
        'cost_price' => 'سعر التكلفة',
        'retail_price' => 'سعر التجزئة (القطعي)',
        'wholesale_price' => 'سعر الجملة',
        'min_wholesale_quantity' => 'أقل كمية للجملة',
        'newCategoryName' => 'اسم التصنيف الجديد',
        'newCategoryCode' => 'كود التصنيف الجديد',
    ];

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedSelectedCategoryFilter()
    {
        $this->resetPage();
    }

    #[On('tenant-changed')]
    public function handleTenantChanged()
    {
        $this->resetPage();
        $this->closeModal();
        $this->closeCategoryModal();
    }

    // توليد باركود فريد تلقائياً
    public function generateBarcode()
    {
        $this->barcode = (string) rand(100000000000, 999999999999);
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

        $product = Product::where('tenant_id', $tenantId)->findOrFail($id);

        $this->product_id = $product->id;
        $this->category_id = $product->category_id;
        $this->name = $product->name;
        $this->barcode = $product->barcode ?? '';
        $this->cost_price = $product->cost_price;
        $this->retail_price = $product->retail_price;
        $this->wholesale_price = $product->wholesale_price;
        $this->min_wholesale_quantity = $product->min_wholesale_quantity;

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
            'category_id' => $this->category_id ?: null,
            'name' => $this->name,
            'barcode' => $this->barcode ?: null,
            'cost_price' => $this->cost_price,
            'retail_price' => $this->retail_price,
            'wholesale_price' => $this->wholesale_price,
            'min_wholesale_quantity' => $this->min_wholesale_quantity,
        ];

        if ($this->isEditing && $this->product_id) {
            Product::where('tenant_id', $tenantId)->findOrFail($this->product_id)->update($data);
        } else {
            Product::create($data);
        }

        session()->flash('message', $this->isEditing ? 'تم تحديث بيانات المنتج بنجاح.' : 'تم إضافة المنتج بنجاح.');

        $this->closeModal();
    }

    // --- وظائف إضافة تصنيف جديد سريعاً ---
    public function openCategoryModal()
    {
        $this->newCategoryName = '';
        $this->newCategoryCode = '';
        $this->resetValidation(['newCategoryName', 'newCategoryCode']);
        $this->showCategoryModal = true;
    }

    public function closeCategoryModal()
    {
        $this->showCategoryModal = false;
        $this->newCategoryName = '';
        $this->newCategoryCode = '';
    }

    public function saveCategory()
    {
        $tenantId = session('active_tenant_id');

        if (!$tenantId) {
            session()->flash('message', 'يرجى اختيار متجر أولاً لتتمكن من إتمام العملية.');
            return;
        }

        $this->validate([
            'newCategoryName' => 'required|string|max:255',
            'newCategoryCode' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('categories', 'code')->where('tenant_id', $tenantId)
            ],
        ]);

        $category = Category::create([
            'tenant_id' => $tenantId,
            'name' => $this->newCategoryName,
            'code' => $this->newCategoryCode ?: null,
            'is_active' => true,
        ]);

        // تحديد التصنيف الجديد مباشرة في حقل المنتجات
        $this->category_id = $category->id;

        $this->closeCategoryModal();
    }

    public function delete($id)
    {
        $tenantId = session('active_tenant_id');

        Product::where('tenant_id', $tenantId)->findOrFail($id)->delete();
        session()->flash('message', 'تم نقل المنتج إلى سلة المهملات.');
    }

    public function closeModal()
    {
        $this->showModal = false;
        $this->resetInputFields();
    }

    private function resetInputFields()
    {
        $this->product_id = null;
        $this->category_id = null;
        $this->name = '';
        $this->barcode = '';
        $this->cost_price = '0.00';
        $this->retail_price = '';
        $this->wholesale_price = '';
        $this->min_wholesale_quantity = 1;
        $this->resetValidation();
    }

    public function render()
    {
        $tenantId = session('active_tenant_id');

        $categories = Category::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $products = Product::where('tenant_id', $tenantId)
            ->with('category')
            ->when($this->search, function ($q) {
                $q->where(function ($query) {
                    $query->where('name', 'like', '%' . $this->search . '%')
                        ->orWhere('barcode', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->selectedCategoryFilter, function ($q) {
                $q->where('category_id', $this->selectedCategoryFilter);
            })
            ->latest()
            ->paginate(10);

        return $this->view([
            'products' => $products,
            'categories' => $categories,
        ])->layout('layouts::tenant');
    }
};
?>
<flux:main class="space-y-6">

<div>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-zinc-200 dark:border-zinc-800">
        <div>
            <flux:heading size="xl" level="1">إدارة المنتجات والخدمات</flux:heading>
            <flux:subheading>إضافة وتعديل المنتجات والأقسام وأسعار التجزئة والجملة للمتجر الحالي</flux:subheading>
        </div>
        <div>
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                إضافة منتج جديد
            </flux:button>
        </div>
    </div>

    @if (session()->has('message'))
        <flux:badge variant="success" class="w-full justify-start p-3 text-sm my-4">
            {{ session('message') }}
        </flux:badge>
    @endif

    <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-4 mb-6">
        <div class="w-full sm:w-80">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="بحث باسم المنتج أو الباركود..." />
        </div>

        <div class="w-full sm:w-64">
            <flux:select wire:model.live="selectedCategoryFilter" placeholder="جميع التصنيفات">
                <flux:select.option value="">جميع التصنيفات</flux:select.option>
                @foreach($categories as $category)
                    <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    <flux:card class="p-0 overflow-hidden">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>المنتج</flux:table.column>
                <flux:table.column>التصنيف / القسم</flux:table.column>
                <flux:table.column>الباركود</flux:table.column>
                <flux:table.column>سعر التكلفة</flux:table.column>
                <flux:table.column>سعر التجزئة</flux:table.column>
                <flux:table.column>سعر الجملة</flux:table.column>
                <flux:table.column>أقل كمية جملة</flux:table.column>
                <flux:table.column align="end">الإجراءات</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse($products as $product)
                    <flux:table.row wire:key="product-row-{{ $product->id }}">
                        <flux:table.cell class="font-medium text-zinc-900 dark:text-white">
                            {{ $product->name }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($product->category)
                                <flux:badge size="sm" color="indigo" variant="subtle">{{ $product->category->name }}</flux:badge>
                            @else
                                <span class="text-zinc-400 text-xs">غير مصنف</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            @if($product->barcode)
                                <flux:badge size="sm" variant="subtle" color="zinc">{{ $product->barcode }}</flux:badge>
                            @else
                                <span class="text-zinc-400 text-xs">لا يوجد</span>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="text-zinc-600 dark:text-zinc-400">
                            {{ number_format($product->cost_price, 2) }}
                        </flux:table.cell>

                        <flux:table.cell class="font-semibold text-emerald-600 dark:text-emerald-400">
                            {{ number_format($product->retail_price, 2) }}
                        </flux:table.cell>

                        <flux:table.cell class="font-semibold text-indigo-600 dark:text-indigo-400">
                            {{ number_format($product->wholesale_price, 2) }}
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" variant="subtle" color="indigo">
                                {{ $product->min_wholesale_quantity }} قطعة
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex items-center justify-end gap-1">
                                <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="edit({{ $product->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash"
                                    class="text-red-500 hover:bg-red-50 dark:hover:bg-red-950/30"
                                    wire:click="delete({{ $product->id }})"
                                    wire:confirm="هل أنت تأكد من نقل هذا المنتج لسلة المهملات؟" />
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" align="center" class="py-8 text-zinc-500">
                            لا يوجد منتجات مضافة لهذا المتجر بعد.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if($products->hasPages())
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-800">
                {{ $products->links() }}
            </div>
        @endif
    </flux:card>

    <flux:modal wire:model="showModal" class="w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="lg">{{ $isEditing ? 'تعديل المنتج' : 'إضافة منتج جديد' }}</flux:heading>
            <flux:subheading>أدخل بيانات المنتج والتصنيف والأسعار المختلفة مع تحديد تسعيرة الجملة</flux:subheading>
        </div>

        <form wire:submit.prevent="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>اسم المنتج</flux:label>
                    <flux:input wire:model="name" placeholder="مثال: آيفون 15 بروماكس، عصير برتقال..." />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>التصنيف / القسم</flux:label>
                    <div class="flex gap-2">
                        <flux:select wire:model="category_id" class="flex-1" placeholder="اختر التصنيف (اختياري)">
                            <flux:select.option value="">بدون تصنيف</flux:select.option>
                            @foreach($categories as $category)
                                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button type="button" variant="subtle" icon="plus" wire:click="openCategoryModal" title="إضافة تصنيف جديد" />
                    </div>
                    <flux:error name="category_id" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>الباركود (Barcode)</flux:label>
                <div class="flex gap-2">
                    <flux:input wire:model="barcode" class="flex-1" placeholder="امسح الباركود أو أدخله يدوياً..." />
                    <flux:button type="button" variant="subtle" icon="sparkles" wire:click="generateBarcode">توليد</flux:button>
                </div>
                <flux:error name="barcode" />
            </flux:field>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2">
                <flux:field>
                    <flux:label>سعر التكلفة</flux:label>
                    <flux:input type="number" step="0.01" wire:model="cost_price" placeholder="0.00" />
                    <flux:error name="cost_price" />
                </flux:field>

                <flux:field>
                    <flux:label>سعر التجزئة (مفرق)</flux:label>
                    <flux:input type="number" step="0.01" wire:model="retail_price" placeholder="0.00" />
                    <flux:error name="retail_price" />
                </flux:field>

                <flux:field>
                    <flux:label>سعر الجملة</flux:label>
                    <flux:input type="number" step="0.01" wire:model="wholesale_price" placeholder="0.00" />
                    <flux:error name="wholesale_price" />
                </flux:field>
            </div>

            <flux:field class="pt-2">
                <flux:label>أقل كمية لتطبيق سعر الجملة</flux:label>
                <flux:input type="number" wire:model="min_wholesale_quantity" min="1" placeholder="1" />
                <flux:error name="min_wholesale_quantity" />
            </flux:field>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ المنتج</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showCategoryModal" class="w-full max-w-md space-y-6">
        <div>
            <flux:heading size="lg">إضافة تصنيف جديد</flux:heading>
            <flux:subheading>أدخل اسم التصنيف لإضافته مباشرة واختياره للمنتج</flux:subheading>
        </div>

        <form wire:submit.prevent="saveCategory" class="space-y-4">
            <flux:field>
                <flux:label>اسم التصنيف</flux:label>
                <flux:input wire:model="newCategoryName" placeholder="مثال: مشروبات، إلكترونيات..." />
                <flux:error name="newCategoryName" />
            </flux:field>

            <flux:field>
                <flux:label>كود التصنيف (اختياري)</flux:label>
                <flux:input wire:model="newCategoryCode" placeholder="مثال: CAT-01" />
                <flux:error name="newCategoryCode" />
            </flux:field>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeCategoryModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">إضافة التصنيف</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
</flux:main>
