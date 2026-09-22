<?php

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Livewire\Attributes\On;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductBarcode;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination;
    use WithFileUploads;

    // --- حقول نموذج المنتج ---
    public ?int $product_id = null;
    public ?int $category_id = null;
    public string $name = '';

    // --- الباركودات المتعددة ---
    public array $barcodes = [''];

    // --- الأسعار والتكلفة وسياسة التسعير ---
    public $cost_price = '0.00';
    public $retail_price = '';
    public $wholesale_price = '';
    public int $min_wholesale_quantity = 1; // للوضع الموحد
    public bool $is_price_unified = true;

    // مصفوفة حفظ الأسعار والكمية لكل فرع [branch_id => [...]]
    public array $branchPricesInput = [];

    // --- حقول العروض (للتسعير الموحد) ---
    public $offer_price = null;
    public ?int $offer_quantity = null;

    public bool $show_in_website = true;

    // --- الصور ---
    public $image = null;
    public ?string $existing_image = null;
    public $images = [];
    public array $existing_images = [];

    // --- حقول التصنيف السريع ---
    public string $newCategoryName = '';
    public string $newCategoryCode = '';
    public bool $showCategoryModal = false;

    // --- حالة الواجهة ---
    public string $search = '';
    public ?string $selectedCategoryFilter = '';
    public ?string $selectedWebsiteFilter = '';
    public bool $showModal = false;
    public bool $isEditing = false;

    protected function rules()
    {
        $tenantId = session('active_tenant_id');

        $rules = [
            'category_id' => [
                'nullable',
                Rule::exists('categories', 'id')->where('tenant_id', $tenantId)
            ],
            'name' => 'required|string|max:255',
            'barcodes' => 'required|array|min:1',
            'barcodes.*' => [
                'required',
                'string',
                'distinct',
                'max:100',
                Rule::unique('product_barcodes', 'barcode')
                    ->where('tenant_id', $tenantId)
                    ->ignore($this->product_id, 'product_id'),
            ],
            'cost_price' => 'required|numeric|min:0',
            'is_price_unified' => 'boolean',
            'show_in_website' => 'boolean',
            'image' => 'nullable|image|max:2048',
            'images.*' => 'nullable|image|max:2048',
        ];

        if ($this->is_price_unified) {
            $rules['retail_price'] = 'required|numeric|min:0';
            $rules['wholesale_price'] = 'required|numeric|min:0';
            $rules['min_wholesale_quantity'] = 'required|integer|min:1';
            $rules['offer_price'] = 'nullable|numeric|min:0';
            $rules['offer_quantity'] = 'nullable|integer|min:1';
        } else {
            $rules['branchPricesInput.*.retail_price'] = 'required|numeric|min:0';
            $rules['branchPricesInput.*.wholesale_price'] = 'required|numeric|min:0';
            $rules['branchPricesInput.*.min_wholesale_quantity'] = 'required|integer|min:1';
            $rules['branchPricesInput.*.offer_price'] = 'nullable|numeric|min:0';
            $rules['branchPricesInput.*.offer_quantity'] = 'nullable|integer|min:1';
        }

        return $rules;
    }

    protected $validationAttributes = [
        'category_id' => 'التصنيف',
        'name' => 'اسم المنتج',
        'barcodes.*' => 'الباركود',
        'cost_price' => 'سعر التكلفة',
        'retail_price' => 'سعر التجزئة',
        'wholesale_price' => 'سعر الجملة',
        'min_wholesale_quantity' => 'أقل كمية للجملة',
        'is_price_unified' => 'أسعار موحدة لكل الفروع',
        'show_in_website' => 'العرض في الموقع',
        'branchPricesInput.*.retail_price' => 'سعر التجزئة للفرع',
        'branchPricesInput.*.wholesale_price' => 'سعر الجملة للفرع',
        'branchPricesInput.*.min_wholesale_quantity' => 'أقل كمية جملة للفرع',
    ];

    public function updatedSearch() { $this->resetPage(); }
    public function updatedSelectedCategoryFilter() { $this->resetPage(); }
    public function updatedSelectedWebsiteFilter() { $this->resetPage(); }

    #[On('tenant-changed')]
    public function handleTenantChanged()
    {
        $this->resetPage();
        $this->closeModal();
        $this->closeCategoryModal();
    }

    public function addBarcodeField()
    {
        $this->barcodes[] = '';
    }

    public function removeBarcodeField($index)
    {
        if (count($this->barcodes) > 1) {
            unset($this->barcodes[$index]);
            $this->barcodes = array_values($this->barcodes);
        }
    }

    public function generateBarcode($index)
    {
        $this->barcodes[$index] = (string) rand(100000000000, 999999999999);
    }

    public function openCreateModal()
    {
        $this->resetInputFields();
        $this->loadBranchesForInput();
        $this->isEditing = false;
        $this->showModal = true;
    }

    public function loadBranchesForInput()
    {
        $tenantId = session('active_tenant_id');
        $branches = DB::table('branches')->where('tenant_id', $tenantId)->get();

        $this->branchPricesInput = [];
        foreach ($branches as $branch) {
            $this->branchPricesInput[$branch->id] = [
                'retail_price' => '',
                'wholesale_price' => '',
                'min_wholesale_quantity' => 1,
                'offer_price' => null,
                'offer_quantity' => null,
            ];
        }
    }

    public function edit($id)
    {
        $tenantId = session('active_tenant_id');
        $product = Product::where('tenant_id', $tenantId)->with(['barcodes'])->findOrFail($id);

        $this->product_id = $product->id;
        $this->category_id = $product->category_id;
        $this->name = $product->name;
        $this->cost_price = $product->cost_price;
        $this->is_price_unified = (bool) ($product->is_price_unified ?? true);
        $this->show_in_website = (bool) ($product->show_in_website ?? true);

        // جلب أسعار الفروع المسجلة للمنتج
        $existingPrices = DB::table('branch_products')
            ->where('tenant_id', $tenantId)
            ->where('product_id', $product->id)
            ->get()
            ->keyBy('branch_id');

        $branches = DB::table('branches')->where('tenant_id', $tenantId)->get();

        $this->branchPricesInput = [];
        foreach ($branches as $b) {
            $bp = $existingPrices->get($b->id);
            $this->branchPricesInput[$b->id] = [
                'retail_price' => $bp?->retail_price ?? '',
                'wholesale_price' => $bp?->wholesale_price ?? '',
                'min_wholesale_quantity' => $bp?->min_wholesale_quantity ?? 1,
                'offer_price' => $bp?->offer_price ?? null,
                'offer_quantity' => $bp?->offer_quantity ?? null,
            ];
        }

        $currentBranchId = session('active_branch_id', 1);
        if (isset($this->branchPricesInput[$currentBranchId])) {
            $this->retail_price = $this->branchPricesInput[$currentBranchId]['retail_price'];
            $this->wholesale_price = $this->branchPricesInput[$currentBranchId]['wholesale_price'];
            $this->min_wholesale_quantity = $this->branchPricesInput[$currentBranchId]['min_wholesale_quantity'];
            $this->offer_price = $this->branchPricesInput[$currentBranchId]['offer_price'];
            $this->offer_quantity = $this->branchPricesInput[$currentBranchId]['offer_quantity'];
        }

        $this->barcodes = $product->barcodes->pluck('barcode')->toArray() ?: [''];
        $this->existing_image = $product->image;
        $this->existing_images = is_array($product->images) ? $product->images : (json_decode($product->images ?? '[]', true) ?: []);

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

        $mainImagePath = $this->existing_image;
        if ($this->image) {
            if ($this->existing_image) {
                Storage::disk('public')->delete($this->existing_image);
            }
            $mainImagePath = $this->image->store('products', 'public');
        }

        $additionalImagePaths = $this->existing_images;
        if (!empty($this->images)) {
            foreach ($this->images as $uploadedImg) {
                $additionalImagePaths[] = $uploadedImg->store('products/gallery', 'public');
            }
        }

        $cleanBarcodes = array_values(array_filter(array_map('trim', $this->barcodes)));

        $productData = [
            'tenant_id' => $tenantId,
            'category_id' => $this->category_id ?: null,
            'name' => $this->name,
            'cost_price' => $this->cost_price,
            'is_price_unified' => $this->is_price_unified,
            'show_in_website' => $this->show_in_website,
            'image' => $mainImagePath,
            'images' => array_values(array_unique($additionalImagePaths)),
        ];

        DB::transaction(function () use ($tenantId, $productData, $cleanBarcodes) {
            if ($this->isEditing && $this->product_id) {
                $product = Product::where('tenant_id', $tenantId)->findOrFail($this->product_id);
                $product->update($productData);
            } else {
                $product = Product::create($productData);
            }

            if ($this->is_price_unified) {
                // حفظ سعر وحجم جملة موحد لكافة الفروع
                $branchIds = DB::table('branches')->where('tenant_id', $tenantId)->pluck('id');
                if ($branchIds->isEmpty()) {
                    $branchIds = collect([session('active_branch_id', 1)]);
                }

                $pricePayload = [
                    'retail_price' => $this->retail_price,
                    'wholesale_price' => $this->wholesale_price,
                    'min_wholesale_quantity' => $this->min_wholesale_quantity,
                    'offer_price' => $this->offer_price !== '' && $this->offer_price !== null ? $this->offer_price : null,
                    'offer_quantity' => $this->offer_quantity !== '' && $this->offer_quantity !== null ? $this->offer_quantity : null,
                    'updated_at' => now(),
                ];

                foreach ($branchIds as $bId) {
                    DB::table('branch_products')->updateOrInsert(
                        [
                            'tenant_id' => $tenantId,
                            'branch_id' => $bId,
                            'product_id' => $product->id,
                        ],
                        array_merge($pricePayload, ['created_at' => now()])
                    );
                }
            } else {
                // حفظ أسعار وأقل كمية جملة خاصة لكل فرع على حدة
                foreach ($this->branchPricesInput as $bId => $pData) {
                    DB::table('branch_products')->updateOrInsert(
                        [
                            'tenant_id' => $tenantId,
                            'branch_id' => $bId,
                            'product_id' => $product->id,
                        ],
                        [
                            'retail_price' => $pData['retail_price'] ?? 0,
                            'wholesale_price' => $pData['wholesale_price'] ?? 0,
                            'min_wholesale_quantity' => $pData['min_wholesale_quantity'] ?? 1,
                            'offer_price' => isset($pData['offer_price']) && $pData['offer_price'] !== '' ? $pData['offer_price'] : null,
                            'offer_quantity' => isset($pData['offer_quantity']) && $pData['offer_quantity'] !== '' ? $pData['offer_quantity'] : null,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }

            // تحديث جدول product_barcodes
            ProductBarcode::where('product_id', $product->id)->delete();
            foreach ($cleanBarcodes as $bCode) {
                ProductBarcode::create([
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    'barcode' => $bCode,
                ]);
            }
        });

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

        DB::table('branch_products')->where('product_id', $product->id)->delete();
        ProductBarcode::where('product_id', $product->id)->delete();
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
        $this->barcodes = [''];
        $this->cost_price = '';
        $this->retail_price = '';
        $this->wholesale_price = '';
        $this->min_wholesale_quantity = 1;
        $this->is_price_unified = true;
        $this->branchPricesInput = [];
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
        $branchId = session('active_branch_id', 1);

        $categories = Category::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $allBranches = DB::table('branches')
            ->where('tenant_id', $tenantId)
            ->get();

        $products = Product::where('tenant_id', $tenantId)
            ->with(['category', 'barcodes'])
            ->when($this->search, function ($q) {
                $q->where(function ($query) {
                    $query->where('name', 'like', '%' . $this->search . '%')
                        ->orWhereHas('barcodes', function ($bQuery) {
                            $bQuery->where('barcode', 'like', '%' . $this->search . '%');
                        });
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

        $productIds = $products->pluck('id');
        $branchPrices = DB::table('branch_products')
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $branchId)
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        return $this->view([
            'products' => $products,
            'categories' => $categories,
            'branchPrices' => $branchPrices,
            'allBranches' => $allBranches,
        ])->layout('layouts::tenant');
    }
};
?>
<flux:main class="space-y-6">
<div>
    @if (session()->has('message'))
        <div class="mb-4 p-4 text-sm text-green-800 rounded-xl bg-green-50 dark:bg-zinc-800 dark:text-green-400 border border-green-200 dark:border-green-800 flex items-center justify-between">
            <span>{{ session('message') }}</span>
        </div>
    @endif

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <flux:heading size="xl" level="1">إدارة المنتجات</flux:heading>
            <flux:subheading>عرض وإدارة كافة المنتجات وتفاصيل الأسعار والعروض والباركودات المتعددة</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">إضافة منتج جديد</flux:button>
        </div>
    </div>

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

    <div class="border border-zinc-200 dark:border-zinc-700 rounded-xl overflow-hidden bg-white dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-right text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-700">
                    <tr>
                        <th class="p-4">الصورة</th>
                        <th class="p-4">المنتج</th>
                        <th class="p-4">التصنيف</th>
                        <th class="p-4">الباركودات</th>
                        <th class="p-4">التكلفة</th>
                        <th class="p-4">التجزئة</th>
                        <th class="p-4">الجملة (أقل كمية)</th>
                        <th class="p-4">سياسة التسعير</th>
                        <th class="p-4">سعر العرض (الكمية)</th>
                        <th class="p-4">العرض بالموقع</th>
                        <th class="p-4 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse($products as $product)
                        @php
                            $mainImg = $product->image ?? null;
                            $priceData = $branchPrices[$product->id] ?? null;
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
                                <div class="flex flex-wrap gap-1">
                                    @forelse($product->barcodes as $b)
                                        <span class="px-2 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 text-zinc-700 dark:text-zinc-300 font-medium">{{ $b->barcode }}</span>
                                    @empty
                                        <span>-</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="p-4 text-zinc-600 dark:text-zinc-400">
                                {{ number_format($product->cost_price, 2) }}
                            </td>
                            <td class="p-4 font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $priceData ? number_format($priceData->retail_price, 2) : '0.00' }}
                            </td>
                            <td class="p-4 text-zinc-600 dark:text-zinc-400">
                                {{ $priceData ? number_format($priceData->wholesale_price, 2) : '0.00' }}
                                <span class="text-xs text-zinc-400">({{ $priceData->min_wholesale_quantity ?? 1 }}+)</span>
                            </td>
                            <td class="p-4">
                                @if($product->is_price_unified)
                                    <span class="px-2.5 py-1 text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400 rounded-full">موحد لكل الفروع</span>
                                @else
                                    <span class="px-2.5 py-1 text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400 rounded-full">خاص بالفروع</span>
                                @endif
                            </td>
                            <td class="p-4 text-zinc-600 dark:text-zinc-400">
                                @if($priceData && $priceData->offer_price)
                                    <span class="font-semibold text-amber-600 dark:text-amber-400">
                                        {{ number_format($priceData->offer_price, 2) }}
                                    </span>
                                    <span class="text-xs text-zinc-400">({{ $priceData->offer_quantity ?? 1 }}+)</span>
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
                            <td colspan="11" class="p-8 text-center text-zinc-500 dark:text-zinc-400">
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
            <flux:subheading>أدخل بيانات المنتج والصور والتصنيف والأسعار والعروض والباركودات المتعددة</flux:subheading>
        </div>

        <form wire:submit.prevent="save" class="space-y-4">
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

            <flux:field>
                <flux:label>معرض الصور الإضافية</flux:label>
                <div class="space-y-3">
                    <input type="file" wire:model="images" multiple accept="image/*" class="block w-full text-sm text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-zinc-800 dark:file:text-zinc-200 cursor-pointer" />
                    <div wire:loading wire:target="images" class="text-xs text-indigo-600 mt-1">جاري رفع الصور...</div>

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

            <div class="p-4 border border-zinc-200 dark:border-zinc-700 rounded-xl bg-zinc-50/50 dark:bg-zinc-800/30 space-y-3">
                <div class="flex items-center justify-between">
                    <flux:label class="font-semibold">الباركودات المتعددة للمنتج</flux:label>
                    <flux:button type="button" variant="subtle" size="sm" icon="plus" wire:click="addBarcodeField">إضافة باركود إضافي</flux:button>
                </div>

                @foreach ($barcodes as $index => $bCode)
                    <div class="flex gap-2 items-center">
                        <flux:input wire:model="barcodes.{{ $index }}" class="flex-1" placeholder="امسح الباركود أو أدخله يدوياً..." />
                        <flux:button type="button" variant="subtle" icon="sparkles" wire:click="generateBarcode({{ $index }})" title="توليد تلقائي" />

                        @if (count($barcodes) > 1)
                            <flux:button type="button" variant="ghost" icon="trash" class="text-red-500 hover:text-red-700" wire:click="removeBarcodeField({{ $index }})" title="حذف هذا الباركود" />
                        @endif
                    </div>
                    <flux:error name="barcodes.{{ $index }}" />
                @endforeach
            </div>

            <!-- مفتاح التبديل (Toggle) لسياسة التسعير -->
            <div class="p-4 border border-indigo-200 dark:border-indigo-900 bg-indigo-50/50 dark:bg-indigo-950/20 rounded-xl flex items-center justify-between gap-4">
                <div>
                    <flux:label class="font-semibold text-indigo-900 dark:text-indigo-300">أسعار موحدة لكل الفروع</flux:label>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">تفعيل التوحيد يطبق السعر وأقل كمية جملة على كل الفروع، وعند إيقافه يفتح تخصيص الأسعار والكميات لكل فرع.</p>
                </div>
                <flux:switch wire:model.live="is_price_unified" />
            </div>

            @if ($is_price_unified)
                <!-- خيار 1: أسعار موحدة لكل الفروع -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
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

                    <flux:field>
                        <flux:label>أقل كمية لتطبيق سعر الجملة</flux:label>
                        <flux:input type="number" wire:model="min_wholesale_quantity" min="1" placeholder="1" />
                        <flux:error name="min_wholesale_quantity" />
                    </flux:field>
                </div>

                <div class="p-4 border border-amber-200 dark:border-amber-800/50 rounded-xl bg-amber-50/50 dark:bg-amber-950/10 space-y-3">
                    <div class="flex items-center gap-2 text-amber-800 dark:text-amber-400 font-semibold text-sm">
                        <flux:icon name="tag" class="w-4 h-4" />
                        <span>تفاصيل العرض الخاص الموحد (اختياري)</span>
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
            @else
                <!-- خيار 2: لوحة فتح تخصيص أسعار وكمية جملة خاصة لكل فرع معتمد للعميل -->
                <div class="space-y-4 pt-2">
                    <flux:field>
                        <flux:label>سعر التكلفة الأساسي</flux:label>
                        <flux:input type="number" step="0.01" wire:model="cost_price" placeholder="0.00" />
                        <flux:error name="cost_price" />
                    </flux:field>

                    <div class="border border-indigo-200 dark:border-indigo-900/50 rounded-xl p-4 bg-indigo-50/30 dark:bg-indigo-950/10 space-y-4">
                        <div class="flex items-center justify-between border-b border-indigo-100 dark:border-indigo-900/40 pb-2">
                            <flux:heading size="sm" class="text-indigo-700 dark:text-indigo-300">تخصيص الأسعار وكميات الجملة حسب فروع العميل</flux:heading>
                            <span class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">عدد الفروع: {{ count($allBranches) }}</span>
                        </div>

                        @foreach ($allBranches as $branch)
                            <div class="p-3 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-xl space-y-3">
                                <div class="font-semibold text-sm text-zinc-800 dark:text-zinc-200 flex items-center gap-2 border-b border-zinc-100 dark:border-zinc-800 pb-2">
                                    <flux:icon name="building-storefront" class="w-4 h-4 text-indigo-500" />
                                    <span>فرع: {{ $branch->name }}</span>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <div>
                                        <flux:label class="text-xs">سعر التجزئة (مفرق)</flux:label>
                                        <flux:input type="number" step="0.01" wire:model="branchPricesInput.{{ $branch->id }}.retail_price" placeholder="0.00" />
                                        <flux:error name="branchPricesInput.{{ $branch->id }}.retail_price" />
                                    </div>
                                    <div>
                                        <flux:label class="text-xs">سعر الجملة</flux:label>
                                        <flux:input type="number" step="0.01" wire:model="branchPricesInput.{{ $branch->id }}.wholesale_price" placeholder="0.00" />
                                        <flux:error name="branchPricesInput.{{ $branch->id }}.wholesale_price" />
                                    </div>
                                    <div>
                                        <flux:label class="text-xs">أقل كمية للجملة</flux:label>
                                        <flux:input type="number" min="1" wire:model="branchPricesInput.{{ $branch->id }}.min_wholesale_quantity" placeholder="1" />
                                        <flux:error name="branchPricesInput.{{ $branch->id }}.min_wholesale_quantity" />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-1 border-t border-dashed border-zinc-100 dark:border-zinc-800">
                                    <div>
                                        <flux:label class="text-xs text-amber-700 dark:text-amber-400">سعر العرض للفرع (اختياري)</flux:label>
                                        <flux:input type="number" step="0.01" wire:model="branchPricesInput.{{ $branch->id }}.offer_price" placeholder="0.00" />
                                    </div>
                                    <div>
                                        <flux:label class="text-xs text-amber-700 dark:text-amber-400">كمية العرض للفرع</flux:label>
                                        <flux:input type="number" wire:model="branchPricesInput.{{ $branch->id }}.offer_quantity" min="1" placeholder="1" />
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="pt-2">
                <div class="flex items-center gap-3 p-3 border border-zinc-200 dark:border-zinc-700 rounded-xl bg-zinc-50 dark:bg-zinc-800/50">
                    <flux:checkbox wire:model="show_in_website" label="عرض المنتج في الموقع الإلكتروني" />
                </div>
                <flux:error name="show_in_website" />
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <flux:button variant="ghost" wire:click="closeModal">إلغاء</flux:button>
                <flux:button type="submit" variant="primary">حفظ المنتج</flux:button>
            </div>
        </form>
    </flux:modal>

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
