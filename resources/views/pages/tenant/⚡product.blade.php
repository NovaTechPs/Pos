<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Livewire\Attributes\On;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination;
    use WithFileUploads;

    // --- حقول نموذج المنتج ---
    public ?int $product_id = null;
    public ?int $category_id = null;
    public string $name = '';
    public string $barcode = '';
    public $cost_price = '0.00';
    public $retail_price = '';
    public $wholesale_price = '';
    public int $min_wholesale_quantity = 1;

    // --- حقول العروض الجديدة ---
    public $offer_price = null;
    public ?int $offer_quantity = null;

    public bool $show_in_website = true;

    // --- الصورة الأساسية ($product->image) ---
    public $image = null;                     // صورة أساسية جديدة مراد رفعها
    public ?string $existing_image = null;    // المسار المخزن في عمود image

    // --- الصور الإضافية ($product->images) ---
    public $images = [];                      // مصفوفة الصور الإضافية الجديدة
    public array $existing_images = [];        // المسارات المخزنة في عمود images

    // --- حقول نموذج التصنيف السريع ---
    public string $newCategoryName = '';
    public string $newCategoryCode = '';
    public bool $showCategoryModal = false;

    // --- حالة الواجهة والبحث والتصفية ---
    public string $search = '';
    public ?string $selectedCategoryFilter = '';
    public ?string $selectedWebsiteFilter = '';
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
            'offer_price' => 'nullable|numeric|min:0',
            'offer_quantity' => 'nullable|integer|min:1',
            'show_in_website' => 'boolean',
            'image' => 'nullable|image|max:2048',        // الصورة الأساسية
            'images.*' => 'nullable|image|max:2048',      // الصور الإضافية
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
        'offer_price' => 'سعر العرض',
        'offer_quantity' => 'كمية العرض',
        'show_in_website' => 'العرض في الموقع',
        'image' => 'الصورة الأساسية',
        'images.*' => 'الصور الإضافية',
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

    public function updatedSelectedWebsiteFilter()
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
        $this->offer_price = $product->offer_price;
        $this->offer_quantity = $product->offer_quantity;
        $this->show_in_website = (bool) ($product->show_in_website ?? true);

        // جلب الصورة الأساسية بشكل مستقل
        $this->existing_image = $product->image;

        // جلب الصور الإضافية كـ Array
        $this->existing_images = is_array($product->images)
            ? $product->images
            : (json_decode($product->images ?? '[]', true) ?: []);

        $this->isEditing = true;
        $this->showModal = true;
    }

    public function toggleWebsiteStatus($id)
    {
        $tenantId = session('active_tenant_id');
        $product = Product::where('tenant_id', $tenantId)->findOrFail($id);
        $product->update([
            'show_in_website' => !$product->show_in_website
        ]);
    }

    // حذف الصورة الأساسية المخزنة سابقاً
    public function removeSingleExistingImage()
    {
        if ($this->existing_image) {
            Storage::disk('public')->delete($this->existing_image);
            $this->existing_image = null;

            if ($this->product_id) {
                $tenantId = session('active_tenant_id');
                $product = Product::where('tenant_id', $tenantId)->find($this->product_id);
                if ($product) {
                    $product->update(['image' => null]);
                }
            }
        }
    }

    // حذف صورة إضافية مخزنة سابقاً
    public function removeExistingImage($index)
    {
        if (isset($this->existing_images[$index])) {
            Storage::disk('public')->delete($this->existing_images[$index]);
            unset($this->existing_images[$index]);
            $this->existing_images = array_values($this->existing_images);

            if ($this->product_id) {
                $tenantId = session('active_tenant_id');
                $product = Product::where('tenant_id', $tenantId)->find($this->product_id);
                if ($product) {
                    $product->update(['images' => $this->existing_images]);
                }
            }
        }
    }

    // حذف صورة إضافية مختارة مؤقتاً قبل الحفظ
    public function removeNewImage($index)
    {
        if (isset($this->images[$index])) {
            unset($this->images[$index]);
            $this->images = array_values($this->images);
        }
    }

    public function save()
    {
        $this->validate();

        $tenantId = session('active_tenant_id');

        if (!$tenantId) {
            session()->flash('message', 'يرجى اختيار متجر أولاً لتتمكن من إتمام العملية.');
            return;
        }

        // 1. معالجة الصورة الأساسية (image)
        $mainImagePath = $this->existing_image;
        if ($this->image) {
            if ($this->existing_image) {
                Storage::disk('public')->delete($this->existing_image);
            }
            $mainImagePath = $this->image->store('products', 'public');
        }

        // 2. معالجة الصور الإضافية (images)
        $additionalImagePaths = $this->existing_images;
        if (!empty($this->images)) {
            foreach ($this->images as $uploadedImg) {
                $additionalImagePaths[] = $uploadedImg->store('products/gallery', 'public');
            }
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
            'offer_price' => $this->offer_price !== '' && $this->offer_price !== null ? $this->offer_price : null,
            'offer_quantity' => $this->offer_quantity !== '' && $this->offer_quantity !== null ? $this->offer_quantity : null,
            'show_in_website' => $this->show_in_website,
            'image' => $mainImagePath,
            'images' => array_values(array_unique($additionalImagePaths)),
        ];

        if ($this->isEditing && $this->product_id) {
            Product::where('tenant_id', $tenantId)->findOrFail($this->product_id)->update($data);
        } else {
            Product::create($data);
        }

        session()->flash('message', $this->isEditing ? 'تم تحديث بيانات المنتج بنجاح.' : 'تم إضافة المنتج بنجاح.');

        $this->closeModal();
    }

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

        $this->category_id = $category->id;

        $this->closeCategoryModal();
    }

    public function delete($id)
    {
        $tenantId = session('active_tenant_id');

        $product = Product::where('tenant_id', $tenantId)->findOrFail($id);

        if ($product->image) {
            Storage::disk('public')->delete($product->image);
        }

        $images = is_array($product->images) ? $product->images : json_decode($product->images ?? '[]', true);
        if (!empty($images)) {
            foreach ($images as $path) {
                Storage::disk('public')->delete($path);
            }
        }

        $product->delete();
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
        $this->cost_price = '';
        $this->retail_price = '';
        $this->wholesale_price = '';
        $this->min_wholesale_quantity = 1;
        $this->offer_price = null;
        $this->offer_quantity = null;
        $this->show_in_website = true;
        $this->image = null;
        $this->existing_image = null;
        $this->images = [];
        $this->existing_images = [];
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
            ->when($this->selectedWebsiteFilter !== '' && $this->selectedWebsiteFilter !== null, function ($q) {
                $q->where('show_in_website', $this->selectedWebsiteFilter === '1');
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
    <!-- رسائل التنبيه -->
    @if (session()->has('message'))
        <div class="mb-4 p-4 text-sm text-green-800 rounded-xl bg-green-50 dark:bg-zinc-800 dark:text-green-400 border border-green-200 dark:border-green-800 flex items-center justify-between">
            <span>{{ session('message') }}</span>
        </div>
    @endif

    <!-- الهيدر وأزرار الإضافة -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <flux:heading size="xl" level="1">إدارة المنتجات</flux:heading>
            <flux:subheading>عرض وإدارة كافة المنتجات وتفاصيل الأسعار والعروض والعرض في الموقع الإلكتروني</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">إضافة منتج جديد</flux:button>
        </div>
    </div>

    <!-- شريط البحث والتصفية -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="ابحث باسم المنتج أو الباركود..." icon="magnifying-glass" />

        <select wire:model.live="selectedCategoryFilter" class="px-3 py-2 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg text-sm text-zinc-800 dark:text-zinc-200 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">جميع التصنيفات</option>
            @foreach($categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="selectedWebsiteFilter" class="px-3 py-2 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg text-sm text-zinc-800 dark:text-zinc-200 focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="">جميع حالات العرض بالموقع</option>
            <option value="1">معروض في الموقع</option>
            <option value="0">غير معروض في الموقع</option>
        </select>
    </div>

    <!-- جدول عرض المنتجات -->
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden bg-white dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="p-4">الصورة</th>
                        <th class="p-4">المنتج</th>
                        <th class="p-4">التصنيف</th>
                        <th class="p-4">الباركود</th>
                        <th class="p-4">التكلفة</th>
                        <th class="p-4">التجزئة</th>
                        <th class="p-4">الجملة (أقل كمية)</th>
                        <th class="p-4">سعر العرض (الكمية)</th>
                        <th class="p-4">العرض بالموقع</th>
                        <th class="p-4 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($products as $product)
                        @php
                            $mainImg = $product->image ?? null;
                        @endphp
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition-colors">
                            <td class="p-4">
                                @if($mainImg)
                                    <img src="{{ Storage::url($mainImg) }}" class="w-12 h-12 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700" />
                                @else
                                    <div class="w-12 h-12 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center text-zinc-400">
                                        <flux:icon name="photo" class="w-6 h-6" />
                                    </div>
                                @endif
                            </td>
                            <td class="p-4 font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $product->name }}
                            </td>
                            <td class="p-4 text-zinc-600 dark:text-zinc-400">
                                {{ $product->category->name ?? 'بدون تصنيف' }}
                            </td>
                            <td class="p-4 text-zinc-600 dark:text-zinc-400 font-mono text-xs">
                                {{ $product->barcode ?: '-' }}
                            </td>
                            <td class="p-4 text-zinc-600 dark:text-zinc-400">
                                {{ number_format($product->cost_price, 2) }}
                            </td>
                            <td class="p-4 font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ number_format($product->retail_price, 2) }}
                            </td>
                            <td class="p-4 text-zinc-600 dark:text-zinc-400">
                                {{ number_format($product->wholesale_price, 2) }}
                                <span class="text-xs text-zinc-400">({{ $product->min_wholesale_quantity }}+)</span>
                            </td>
                            <td class="p-4 text-zinc-600 dark:text-zinc-400">
                                @if($product->offer_price)
                                    <span class="font-semibold text-amber-600 dark:text-amber-400">
                                        {{ number_format($product->offer_price, 2) }}
                                    </span>
                                    <span class="text-xs text-zinc-400">({{ $product->offer_quantity ?? 1 }}+)</span>
                                @else
                                    <span class="text-xs text-zinc-400">-</span>
                                @endif
                            </td>
                            <td class="p-4">
                                <button type="button" wire:click="toggleWebsiteStatus({{ $product->id }})" class="px-2.5 py-1 rounded-full text-xs font-medium cursor-pointer transition-colors {{ $product->show_in_website ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400' : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400' }}">
                                    {{ $product->show_in_website ? 'معروض' : 'مخفي' }}
                                </button>
                            </td>
                            <td class="p-4 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <flux:button variant="ghost" icon="pencil-square" wire:click="edit({{ $product->id }})" title="تعديل" />
                                    <flux:button variant="ghost" icon="trash" class="text-red-600 hover:text-red-700" wire:click="delete({{ $product->id }})" wire:confirm="هل أنت تأكد من نقل المنتج إلى سلة المهملات؟" title="حذف" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="p-8 text-center text-zinc-500 dark:text-zinc-400">
                                لا توجد منتجات مطابقة للبحث.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
            {{ $products->links() }}
        </div>
    </div>

    <!-- مودال إضافة/تعديل منتج -->
    <flux:modal wire:model="showModal" class="w-full max-w-2xl space-y-6">
        <div>
            <flux:heading size="lg">{{ $isEditing ? 'تعديل المنتج' : 'إضافة منتج جديد' }}</flux:heading>
            <flux:subheading>أدخل بيانات المنتج والصور والتصنيف والأسعار والعروض مع تحديد حالة العرض بصفحة المتجر</flux:subheading>
        </div>

        <form wire:submit.prevent="save" class="space-y-4">

            <!-- قسم رفع الصورة الأساسية ($product->image) -->
            <flux:field>
                <flux:label>الصورة الأساسية للمنتج</flux:label>
                <div class="flex items-center gap-4">
                    @if ($image)
                        <div class="relative w-20 h-20 rounded-xl overflow-hidden border border-zinc-200 dark:border-zinc-700">
                            <img src="{{ $image->temporaryUrl() }}" class="w-full h-full object-cover" />
                        </div>
                    @elseif ($existing_image)
                        <div class="relative w-20 h-20 rounded-xl overflow-hidden border border-zinc-200 dark:border-zinc-700 group">
                            <img src="{{ Storage::url($existing_image) }}" class="w-full h-full object-cover" />
                            <button type="button" wire:click="removeSingleExistingImage" class="absolute inset-0 bg-black/50 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                <flux:icon name="trash" class="w-5 h-5" />
                            </button>
                        </div>
                    @endif

                    <div class="flex-1">
                        <input type="file" wire:model="image" accept="image/*" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-zinc-800 dark:file:text-zinc-200 cursor-pointer" />
                        <div wire:loading wire:target="image" class="text-xs text-indigo-600 mt-1">جاري رفع الصورة الأساسية...</div>
                    </div>
                </div>
                <flux:error name="image" />
            </flux:field>

            <!-- قسم رفع الصور الإضافية ($product->images) -->
            <flux:field>
                <flux:label>معرض الصور الإضافية</flux:label>
                <div class="space-y-3">
                    <input type="file" wire:model="images" multiple accept="image/*" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-zinc-800 dark:file:text-zinc-200 cursor-pointer" />
                    <div wire:loading wire:target="images" class="text-xs text-indigo-600 mt-1">جاري رفع الصور...</div>

                    <!-- معاينة الصور الإضافية المحفوظة سابقاً -->
                    @if (!empty($existing_images))
                        <div>
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">الصور الإضافية المحفوظة:</span>
                            <div class="flex flex-wrap gap-2 mt-1.5">
                                @foreach ($existing_images as $index => $imgPath)
                                    <div class="relative group w-16 h-16 rounded-xl overflow-hidden border border-zinc-200 dark:border-zinc-700">
                                        <img src="{{ Storage::url($imgPath) }}" class="w-full h-full object-cover" />
                                        <button type="button" wire:click="removeExistingImage({{ $index }})" class="absolute inset-0 bg-red-600/70 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                            <flux:icon name="trash" class="w-4 h-4" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <!-- معاينة الصور الإضافية الجديدة المحددة -->
                    @if (!empty($images))
                        <div>
                            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">الصور الإضافية الجديدة المحددة:</span>
                            <div class="flex flex-wrap gap-2 mt-1.5">
                                @foreach ($images as $index => $newImg)
                                    <div class="relative group w-16 h-16 rounded-xl overflow-hidden border border-zinc-200 dark:border-zinc-700">
                                        <img src="{{ $newImg->temporaryUrl() }}" class="w-full h-full object-cover" />
                                        <button type="button" wire:click="removeNewImage({{ $index }})" class="absolute inset-0 bg-red-600/70 text-white flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                            <flux:icon name="x-mark" class="w-4 h-4" />
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
                <flux:error name="images.*" />
            </flux:field>

            <!-- حقول الاسم والتصنيف -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>اسم المنتج</flux:label>
                    <flux:input wire:model="name" placeholder="مثال: آيفون 15 بروماكس..." />
                    <flux:error name="name" />
                </flux:field>

                <flux:field>
                    <flux:label>التصنيف / القسم</flux:label>
                    <div class="flex gap-2">
                        <select wire:model="category_id" class="flex-1 px-3 py-2 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg text-sm text-zinc-800 dark:text-zinc-200 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="">بدون تصنيف</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
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

            <!-- حقول الأسعار -->
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

            <!-- الكمية والعرض بالموقع -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 items-center">
                <flux:field>
                    <flux:label>أقل كمية لتطبيق سعر الجملة</flux:label>
                    <flux:input type="number" wire:model="min_wholesale_quantity" min="1" placeholder="1" />
                    <flux:error name="min_wholesale_quantity" />
                </flux:field>

                <div class="pt-6">
                    <div class="flex items-center gap-3 p-3 border border-zinc-200 dark:border-zinc-700 rounded-xl bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:checkbox wire:model="show_in_website" label="عرض المنتج في الموقع الإلكتروني" />
                    </div>
                    <flux:error name="show_in_website" />
                </div>
            </div>

            <!-- قسم حقول العروض الخاصة (Offer) -->
            <div class="p-4 border border-amber-200 dark:border-amber-800/50 rounded-xl bg-amber-50/50 dark:bg-amber-950/10 space-y-3">
                <div class="flex items-center gap-2 text-amber-800 dark:text-amber-400 font-semibold text-sm">
                    <flux:icon name="tag" class="w-4 h-4" />
                    <span>تفاصيل العرض الخاص (اختياري)</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>سعر العرض (سعر التخفيض)</flux:label>
                        <flux:input type="number" step="0.01" wire:model="offer_price" placeholder="اتركه فارغاً إن لم يوجد عرض" />
                        <flux:error name="offer_price" />
                    </flux:field>

                    <flux:field>
                        <flux:label>الكمية المطلوبة لتطبيق سعر العرض</flux:label>
                        <flux:input type="number" wire:model="offer_quantity" min="1" placeholder="مثال: 1 أو 3..." />
                        <flux:error name="offer_quantity" />
                    </flux:field>
                </div>
            </div>

            <!-- أزرار الإجراءات -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ المنتج</flux:button>
            </div>
        </form>
    </flux:modal>

    <!-- مودال إضافة تصنيف سريع -->
    <flux:modal wire:model="showCategoryModal" class="w-full max-w-md space-y-6">
        <div>
            <flux:heading size="lg">إضافة تصنيف جديد</flux:heading>
            <flux:subheading>أدخل بيانات التصنيف الجديد لإضافته مباشرة وتحديده للمنتج</flux:subheading>
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
                <flux:button type="submit" variant="primary">حفظ التصنيف</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
</flux:main>
