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

    /*
    |--------------------------------------------------------------------------
    | Product
    |--------------------------------------------------------------------------
    */

    public ?int $product_id = null;
    public ?int $category_id = null;
    public string $name = '';

    /*
    |--------------------------------------------------------------------------
    | Barcodes
    |--------------------------------------------------------------------------
    */

    public array $barcodes = [''];

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    public $cost_price = '0.00';
    public $retail_price = '';
    public $wholesale_price = '';
    public int $min_wholesale_quantity = 1;

    public bool $is_price_unified = true;

    /**
     * [
     *   branch_id => [
     *      retail_price,
     *      wholesale_price,
     *      min_wholesale_quantity,
     *      offer_price,
     *      offer_quantity
     *   ]
     * ]
     */
    public array $branchPricesInput = [];

    public $offer_price = null;
    public ?int $offer_quantity = null;

    /*
    |--------------------------------------------------------------------------
    | Website
    |--------------------------------------------------------------------------
    */

    public bool $show_in_website = true;

    /*
    |--------------------------------------------------------------------------
    | Images
    |--------------------------------------------------------------------------
    */

    public $image = null;
    public ?string $existing_image = null;

    public $images = [];
    public array $existing_images = [];

    /*
    |--------------------------------------------------------------------------
    | Category quick create
    |--------------------------------------------------------------------------
    */

    public string $newCategoryName = '';
    public string $newCategoryCode = '';
    public bool $showCategoryModal = false;

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public string $search = '';
    public ?string $selectedCategoryFilter = '';
    public ?string $selectedWebsiteFilter = '';

    /*
    |--------------------------------------------------------------------------
    | Modals
    |--------------------------------------------------------------------------
    */

    public bool $showModal = false;
    public bool $isEditing = false;

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected function rules(): array
    {
        $tenantId = $this->tenantId();

        $rules = [
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where(fn($query) => $query->where('tenant_id', $tenantId))],

            'name' => ['required', 'string', 'max:255'],

            'barcodes' => ['required', 'array', 'min:1'],

            'barcodes.*' => ['required', 'string', 'distinct', 'max:100'],

            'cost_price' => ['required', 'numeric', 'min:0'],

            'is_price_unified' => ['boolean'],

            'show_in_website' => ['boolean'],

            'image' => ['nullable', 'image', 'max:2048'],

            'images.*' => ['nullable', 'image', 'max:2048'],
        ];

        if ($this->is_price_unified) {
            $rules['retail_price'] = ['required', 'numeric', 'min:0'];

            $rules['wholesale_price'] = ['required', 'numeric', 'min:0'];

            $rules['min_wholesale_quantity'] = ['required', 'integer', 'min:1'];

            $rules['offer_price'] = ['nullable', 'numeric', 'min:0'];

            $rules['offer_quantity'] = ['nullable', 'integer', 'min:1', 'required_with:offer_price'];
        } else {
            $rules['branchPricesInput.*.retail_price'] = ['required', 'numeric', 'min:0'];

            $rules['branchPricesInput.*.wholesale_price'] = ['required', 'numeric', 'min:0'];

            $rules['branchPricesInput.*.min_wholesale_quantity'] = ['required', 'integer', 'min:1'];

            $rules['branchPricesInput.*.offer_price'] = ['nullable', 'numeric', 'min:0'];

            $rules['branchPricesInput.*.offer_quantity'] = ['nullable', 'integer', 'min:1'];
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
        'is_price_unified' => 'سياسة الأسعار',
        'show_in_website' => 'العرض في الموقع',
        'image' => 'الصورة الأساسية',
        'images.*' => 'صور المعرض',
        'branchPricesInput.*.retail_price' => 'سعر التجزئة للفرع',
        'branchPricesInput.*.wholesale_price' => 'سعر الجملة للفرع',
        'branchPricesInput.*.min_wholesale_quantity' => 'أقل كمية جملة للفرع',
        'branchPricesInput.*.offer_price' => 'سعر العرض للفرع',
        'branchPricesInput.*.offer_quantity' => 'كمية العرض للفرع',
    ];

    /*
    |--------------------------------------------------------------------------
    | Tenant / Branch Helpers
    |--------------------------------------------------------------------------
    */

    private function tenantId(): ?int
    {
        $tenantId = session('active_tenant_id');

        return $tenantId ? (int) $tenantId : null;
    }

    private function activeBranchId(): ?int
    {
        $branchId = session('active_branch_id');

        return $branchId ? (int) $branchId : null;
    }

    private function tenantHasBranches(): bool
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return false;
        }

        return DB::table('branches')->where('tenant_id', $tenantId)->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedWebsiteFilter(): void
    {
        $this->resetPage();
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant Changed
    |--------------------------------------------------------------------------
    */

    #[On('tenant-changed')]
    public function handleTenantChanged(): void
    {
        $this->resetPage();

        $this->closeModal();
        $this->closeCategoryModal();

        $this->search = '';
        $this->selectedCategoryFilter = '';
        $this->selectedWebsiteFilter = '';
    }

    /*
    |--------------------------------------------------------------------------
    | Barcode Management
    |--------------------------------------------------------------------------
    */

    public function addBarcodeField(): void
    {
        $this->barcodes[] = '';
    }

    public function removeBarcodeField(int $index): void
    {
        if (count($this->barcodes) <= 1) {
            return;
        }

        unset($this->barcodes[$index]);

        $this->barcodes = array_values($this->barcodes);
    }

    public function generateBarcode(int $index): void
    {
        if (!array_key_exists($index, $this->barcodes)) {
            return;
        }

        $this->barcodes[$index] = (string) random_int(100000000000, 999999999999);
    }

    /*
    |--------------------------------------------------------------------------
    | Product Create
    |--------------------------------------------------------------------------
    */

    public function openCreateModal(): void
    {
        $this->resetInputFields();

        if (!$this->tenantId()) {
            session()->flash('error', 'يرجى اختيار المتجر أولاً قبل إضافة منتج.');

            return;
        }

        if (!$this->tenantHasBranches()) {
            session()->flash('error', 'لا يمكن إضافة المنتج قبل إنشاء فرع واحد على الأقل.');

            return;
        }

        $this->loadBranchesForInput();

        $this->isEditing = false;
        $this->showModal = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Branch Pricing
    |--------------------------------------------------------------------------
    */

    public function loadBranchesForInput(): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            $this->branchPricesInput = [];

            return;
        }

        $branches = DB::table('branches')->where('tenant_id', $tenantId)->orderBy('name')->get();

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

    /*
    |--------------------------------------------------------------------------
    | Edit Product
    |--------------------------------------------------------------------------
    */

    public function edit(int $id): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            session()->flash('error', 'يرجى اختيار المتجر أولاً.');

            return;
        }

        $product = Product::query()->where('tenant_id', $tenantId)->with('barcodes')->findOrFail($id);

        $this->resetInputFields();

        $this->product_id = $product->id;
        $this->category_id = $product->category_id;
        $this->name = $product->name;
        $this->cost_price = $product->cost_price;

        $this->is_price_unified = (bool) ($product->is_price_unified ?? true);

        $this->show_in_website = (bool) ($product->show_in_website ?? true);

        /*
        |--------------------------------------------------------------------------
        | Branch Prices
        |--------------------------------------------------------------------------
        */

        $existingPrices = DB::table('branch_products')->where('tenant_id', $tenantId)->where('product_id', $product->id)->get()->keyBy('branch_id');

        $branches = DB::table('branches')->where('tenant_id', $tenantId)->orderBy('name')->get();

        $this->branchPricesInput = [];

        foreach ($branches as $branch) {
            $price = $existingPrices->get($branch->id);

            $this->branchPricesInput[$branch->id] = [
                'retail_price' => $price?->retail_price ?? '',
                'wholesale_price' => $price?->wholesale_price ?? '',
                'min_wholesale_quantity' => $price?->min_wholesale_quantity ?? 1,
                'offer_price' => $price?->offer_price,
                'offer_quantity' => $price?->offer_quantity,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Active Branch Preview
        |--------------------------------------------------------------------------
        */

        $currentBranchId = $this->activeBranchId();

        if ($currentBranchId && isset($this->branchPricesInput[$currentBranchId])) {
            $current = $this->branchPricesInput[$currentBranchId];

            $this->retail_price = $current['retail_price'];

            $this->wholesale_price = $current['wholesale_price'];

            $this->min_wholesale_quantity = $current['min_wholesale_quantity'];

            $this->offer_price = $current['offer_price'];

            $this->offer_quantity = $current['offer_quantity'];
        }

        /*
        |--------------------------------------------------------------------------
        | Barcodes
        |--------------------------------------------------------------------------
        */

        $this->barcodes = $product->barcodes->pluck('barcode')->values()->toArray();

        if (empty($this->barcodes)) {
            $this->barcodes = [''];
        }

        /*
        |--------------------------------------------------------------------------
        | Images
        |--------------------------------------------------------------------------
        */

        $this->existing_image = $product->image;

        $this->existing_images = is_array($product->images) ? $product->images : (json_decode($product->images ?? '[]', true) ?: []);

        $this->isEditing = true;
        $this->showModal = true;
    }

    /*
    |--------------------------------------------------------------------------
    | Website Visibility
    |--------------------------------------------------------------------------
    */

    public function toggleWebsiteStatus(int $id): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return;
        }

        $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($id);

        $product->update([
            'show_in_website' => !$product->show_in_website,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Image Management
    |--------------------------------------------------------------------------
    */

    public function removeSingleExistingImage(): void
    {
        if (!$this->existing_image || !$this->product_id) {
            return;
        }

        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return;
        }

        $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($this->product_id);

        Storage::disk('public')->delete($this->existing_image);

        $product->update([
            'image' => null,
        ]);

        $this->existing_image = null;
    }

    public function removeExistingImage(int $index): void
    {
        if (!isset($this->existing_images[$index]) || !$this->product_id) {
            return;
        }

        $tenantId = $this->tenantId();

        if (!$tenantId) {
            return;
        }

        $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($this->product_id);

        $path = $this->existing_images[$index];

        Storage::disk('public')->delete($path);

        unset($this->existing_images[$index]);

        $this->existing_images = array_values($this->existing_images);

        $product->update([
            'images' => $this->existing_images,
        ]);
    }

    public function removeNewImage(int $index): void
    {
        if (!isset($this->images[$index])) {
            return;
        }

        unset($this->images[$index]);

        $this->images = array_values($this->images);
    }

    /*
    |--------------------------------------------------------------------------
    | Barcode Conflict Check
    |--------------------------------------------------------------------------
    */

    private function validateBarcodeConflicts(int $tenantId, array $barcodes): void
    {
        $barcodes = array_values(array_unique(array_filter(array_map('trim', $barcodes))));

        if (empty($barcodes)) {
            return;
        }

        $query = ProductBarcode::query()->where('tenant_id', $tenantId)->whereIn('barcode', $barcodes);

        if ($this->isEditing && $this->product_id) {
            $query->where('product_id', '!=', $this->product_id);
        }

        $conflicting = $query->pluck('barcode')->unique()->values();

        if ($conflicting->isNotEmpty()) {
            $this->addError('barcodes', 'الباركود مستخدم مسبقاً: ' . $conflicting->implode('، '));

            throw new \Illuminate\Validation\ValidationException($this->getValidator());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Save Product
    |--------------------------------------------------------------------------
    */

    public function save(): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            session()->flash('error', 'يرجى اختيار المتجر أولاً.');

            return;
        }

        if (!$this->tenantHasBranches()) {
            session()->flash('error', 'يجب إنشاء فرع واحد على الأقل قبل حفظ المنتج.');

            return;
        }

        $this->validate();

        /*
        |--------------------------------------------------------------------------
        | Clean Barcodes
        |--------------------------------------------------------------------------
        */

        $cleanBarcodes = array_values(array_unique(array_filter(array_map('trim', $this->barcodes))));

        if (empty($cleanBarcodes)) {
            $this->addError('barcodes', 'يجب إضافة باركود واحد على الأقل.');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Check duplicate barcodes against other products
        |--------------------------------------------------------------------------
        */

        $conflictingBarcodes = ProductBarcode::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('barcode', $cleanBarcodes)
            ->when($this->isEditing && $this->product_id, fn($query) => $query->where('product_id', '!=', $this->product_id))
            ->pluck('barcode')
            ->unique()
            ->values();

        if ($conflictingBarcodes->isNotEmpty()) {
            $this->addError('barcodes', 'الباركود مستخدم مسبقاً: ' . $conflictingBarcodes->implode('، '));

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate branch ownership
        |--------------------------------------------------------------------------
        */

        $branches = DB::table('branches')->where('tenant_id', $tenantId)->orderBy('id')->get();

        $branchIds = $branches->pluck('id')->map(fn($id) => (int) $id)->values();

        if ($branchIds->isEmpty()) {
            session()->flash('error', 'لا يوجد فرع تابع لهذا المتجر.');

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Upload Main Image
        |--------------------------------------------------------------------------
        */

        $mainImagePath = $this->existing_image;

        if ($this->image) {
            $mainImagePath = $this->image->store('products', 'public');
        }

        /*
        |--------------------------------------------------------------------------
        | Additional Images
        |--------------------------------------------------------------------------
        */

        $additionalImagePaths = $this->existing_images;

        if (!empty($this->images)) {
            foreach ($this->images as $uploadedImage) {
                $additionalImagePaths[] = $uploadedImage->store('products/gallery', 'public');
            }
        }

        $additionalImagePaths = array_values(array_unique(array_filter($additionalImagePaths)));

        /*
        |--------------------------------------------------------------------------
        | Product Data
        |--------------------------------------------------------------------------
        */

        $productData = [
            'tenant_id' => $tenantId,
            'category_id' => $this->category_id ?: null,
            'name' => trim($this->name),
            'cost_price' => $this->cost_price,
            'is_price_unified' => $this->is_price_unified,
            'show_in_website' => $this->show_in_website,
            'image' => $mainImagePath,
            'images' => $additionalImagePaths,
        ];

        try {
            DB::transaction(function () use ($tenantId, $productData, $cleanBarcodes, $branchIds) {
                /*
                |--------------------------------------------------------------------------
                | Product
                |--------------------------------------------------------------------------
                */

                if ($this->isEditing && $this->product_id) {
                    $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($this->product_id);

                    $oldMainImage = $product->image;

                    $product->update($productData);

                    /*
                    |--------------------------------------------------------------------------
                    | Delete old main image only after successful replacement
                    |--------------------------------------------------------------------------
                    */

                    if ($this->image && $oldMainImage && $oldMainImage !== $mainImagePath) {
                        Storage::disk('public')->delete($oldMainImage);
                    }
                } else {
                    $product = Product::create($productData);
                }

                /*
                |--------------------------------------------------------------------------
                | Branch Prices
                |--------------------------------------------------------------------------
                */

                foreach ($branchIds as $branchId) {
                    if ($this->is_price_unified) {
                        $payload = [
                            'retail_price' => $this->retail_price,

                            'wholesale_price' => $this->wholesale_price,

                            'min_wholesale_quantity' => $this->min_wholesale_quantity,

                            'offer_price' => $this->offer_price !== '' && $this->offer_price !== null ? $this->offer_price : null,

                            'offer_quantity' => $this->offer_quantity !== '' && $this->offer_quantity !== null ? $this->offer_quantity : null,

                            'updated_at' => now(),
                        ];
                    } else {
                        $branchData = $this->branchPricesInput[$branchId] ?? [];

                        $payload = [
                            'retail_price' => $branchData['retail_price'] ?? 0,

                            'wholesale_price' => $branchData['wholesale_price'] ?? 0,

                            'min_wholesale_quantity' => $branchData['min_wholesale_quantity'] ?? 1,

                            'offer_price' => ($branchData['offer_price'] ?? null) !== '' ? $branchData['offer_price'] ?? null : null,

                            'offer_quantity' => ($branchData['offer_quantity'] ?? null) !== '' ? $branchData['offer_quantity'] ?? null : null,

                            'updated_at' => now(),
                        ];
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Correct updateOrInsert behavior
                    |--------------------------------------------------------------------------
                    */

                    $existingBranchProduct = DB::table('branch_products')->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('product_id', $product->id)->exists();

                    if ($existingBranchProduct) {
                        DB::table('branch_products')->where('tenant_id', $tenantId)->where('branch_id', $branchId)->where('product_id', $product->id)->update($payload);
                    } else {
                        DB::table('branch_products')->insert(
                            array_merge(
                                [
                                    'tenant_id' => $tenantId,

                                    'branch_id' => $branchId,

                                    'product_id' => $product->id,

                                    'created_at' => now(),
                                ],
                                $payload,
                            ),
                        );
                    }
                }

                /*
                |--------------------------------------------------------------------------
                | Barcodes
                |--------------------------------------------------------------------------
                */

                ProductBarcode::query()->where('tenant_id', $tenantId)->where('product_id', $product->id)->delete();

                foreach ($cleanBarcodes as $barcode) {
                    ProductBarcode::create([
                        'tenant_id' => $tenantId,
                        'product_id' => $product->id,
                        'barcode' => $barcode,
                    ]);
                }
            });

            $message = $this->isEditing ? 'تم تحديث بيانات المنتج بنجاح.' : 'تم إضافة المنتج بنجاح.';

            session()->flash('message', $message);

            $this->closeModal();

            $this->resetPage();
        } catch (\Throwable $e) {
            report($e);

            /*
            |--------------------------------------------------------------------------
            | Remove newly uploaded files if transaction failed
            |--------------------------------------------------------------------------
            */

            if ($this->image && $mainImagePath && $mainImagePath !== $this->existing_image) {
                Storage::disk('public')->delete($mainImagePath);
            }

            foreach ($additionalImagePaths as $path) {
                if (!in_array($path, $this->existing_images, true)) {
                    Storage::disk('public')->delete($path);
                }
            }

            session()->flash('error', 'حدث خطأ أثناء حفظ المنتج: ' . $e->getMessage());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Category
    |--------------------------------------------------------------------------
    */

    public function openCategoryModal(): void
    {
        $this->newCategoryName = '';
        $this->newCategoryCode = '';

        $this->resetValidation(['newCategoryName', 'newCategoryCode']);

        $this->showCategoryModal = true;
    }

    public function closeCategoryModal(): void
    {
        $this->showCategoryModal = false;

        $this->newCategoryName = '';
        $this->newCategoryCode = '';

        $this->resetValidation(['newCategoryName', 'newCategoryCode']);
    }

    public function saveCategory(): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            session()->flash('error', 'يرجى اختيار المتجر أولاً.');

            return;
        }

        $this->validate([
            'newCategoryName' => ['required', 'string', 'max:255'],

            'newCategoryCode' => ['nullable', 'string', 'max:50', Rule::unique('categories', 'code')->where(fn($query) => $query->where('tenant_id', $tenantId))],
        ]);

        $category = Category::create([
            'tenant_id' => $tenantId,
            'name' => trim($this->newCategoryName),
            'code' => $this->newCategoryCode !== '' ? trim($this->newCategoryCode) : null,
            'is_active' => true,
        ]);

        $this->category_id = $category->id;

        $this->closeCategoryModal();

        session()->flash('message', 'تم إنشاء التصنيف وتحديده للمنتج.');
    }

    /*
    |--------------------------------------------------------------------------
    | Delete
    |--------------------------------------------------------------------------
    */

    public function delete(int $id): void
    {
        $tenantId = $this->tenantId();

        if (!$tenantId) {
            session()->flash('error', 'يرجى اختيار المتجر أولاً.');

            return;
        }

        try {
            DB::transaction(function () use ($tenantId, $id) {
                $product = Product::query()->where('tenant_id', $tenantId)->findOrFail($id);

                /*
                |--------------------------------------------------------------------------
                | Images
                |--------------------------------------------------------------------------
                */

                if ($product->image) {
                    Storage::disk('public')->delete($product->image);
                }

                $images = is_array($product->images) ? $product->images : (json_decode($product->images ?? '[]', true) ?: []);

                foreach ($images as $path) {
                    Storage::disk('public')->delete($path);
                }

                /*
                |--------------------------------------------------------------------------
                | Branch Products
                |--------------------------------------------------------------------------
                */

                DB::table('branch_products')->where('tenant_id', $tenantId)->where('product_id', $product->id)->delete();

                /*
                |--------------------------------------------------------------------------
                | Barcodes
                |--------------------------------------------------------------------------
                */

                ProductBarcode::query()->where('tenant_id', $tenantId)->where('product_id', $product->id)->delete();

                /*
                |--------------------------------------------------------------------------
                | Product
                |--------------------------------------------------------------------------
                */

                $product->delete();
            });

            session()->flash('message', 'تم نقل المنتج إلى سلة المهملات.');

            $this->resetPage();
        } catch (\Throwable $e) {
            report($e);

            session()->flash('error', 'تعذر حذف المنتج حالياً.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Modal
    |--------------------------------------------------------------------------
    */

    public function closeModal(): void
    {
        $this->showModal = false;

        $this->resetInputFields();
    }

    private function resetInputFields(): void
    {
        $this->product_id = null;
        $this->category_id = null;

        $this->name = '';

        $this->barcodes = [''];

        $this->cost_price = '0.00';
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

        $this->isEditing = false;

        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    public function render()
    {
        $tenantId = $this->tenantId();

        $branchId = $this->activeBranchId();

        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        */

        $categories = collect();

        if ($tenantId) {
            $categories = Category::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('name')->get();
        }

        /*
        |--------------------------------------------------------------------------
        | Branches
        |--------------------------------------------------------------------------
        */

        $allBranches = collect();

        if ($tenantId) {
            $allBranches = DB::table('branches')->where('tenant_id', $tenantId)->orderBy('name')->get();
        }

        /*
        |--------------------------------------------------------------------------
        | Products Query
        |--------------------------------------------------------------------------
        */

        $productsQuery = Product::query()
            ->where('tenant_id', $tenantId)
            ->with(['category', 'barcodes'])
            ->when($this->search !== '', function ($query) {
                $search = trim($this->search);

                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%')->orWhereHas('barcodes', function ($barcodeQuery) use ($search) {
                        $barcodeQuery->where('barcode', 'like', '%' . $search . '%');
                    });
                });
            })
            ->when($this->selectedCategoryFilter !== '', function ($query) {
                $query->where('category_id', $this->selectedCategoryFilter);
            })
            ->when($this->selectedWebsiteFilter !== '' && $this->selectedWebsiteFilter !== null, function ($query) {
                $query->where('show_in_website', $this->selectedWebsiteFilter === '1');
            })
            ->latest();

        /*
        |--------------------------------------------------------------------------
        | Statistics
        |--------------------------------------------------------------------------
        */

        $totalProducts = $tenantId ? Product::where('tenant_id', $tenantId)->count() : 0;

        $websiteProducts = $tenantId ? Product::where('tenant_id', $tenantId)->where('show_in_website', true)->count() : 0;

        $hiddenProducts = $tenantId ? Product::where('tenant_id', $tenantId)->where('show_in_website', false)->count() : 0;

        $totalCategories = $tenantId ? Category::where('tenant_id', $tenantId)->where('is_active', true)->count() : 0;

        /*
        |--------------------------------------------------------------------------
        | Paginated Products
        |--------------------------------------------------------------------------
        */

        $products = $productsQuery->paginate(12);

        /*
        |--------------------------------------------------------------------------
        | Current Branch Prices
        |--------------------------------------------------------------------------
        */

        $branchPrices = collect();

        if ($tenantId && $branchId && $products->isNotEmpty()) {
            $branchPrices = DB::table('branch_products')->where('tenant_id', $tenantId)->where('branch_id', $branchId)->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id');
        }

        return $this->view([
            'products' => $products,
            'categories' => $categories,
            'branchPrices' => $branchPrices,
            'allBranches' => $allBranches,

            'totalProducts' => $totalProducts,
            'websiteProducts' => $websiteProducts,
            'hiddenProducts' => $hiddenProducts,
            'totalCategories' => $totalCategories,

            'activeBranchId' => $branchId,
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main class="space-y-6" dir="rtl">

    {{-- ================================================================
        FLASH MESSAGES
    ================================================================= --}}

    @if (session()->has('message'))
        <div
            class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800 shadow-sm dark:border-emerald-900/60 dark:bg-emerald-950/20 dark:text-emerald-300">
            <div
                class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-emerald-100 dark:bg-emerald-900/40">
                <flux:icon name="check-circle" class="size-5" />
            </div>

            <div class="flex-1">
                <div class="font-semibold">تمت العملية بنجاح</div>
                <div class="mt-0.5 text-sm opacity-80">
                    {{ session('message') }}
                </div>
            </div>
        </div>
    @endif

    @if (session()->has('error'))
        <div
            class="flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4 text-red-800 shadow-sm dark:border-red-900/60 dark:bg-red-950/20 dark:text-red-300">
            <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-red-100 dark:bg-red-900/40">
                <flux:icon name="exclamation-triangle" class="size-5" />
            </div>

            <div class="flex-1">
                <div class="font-semibold">تعذر تنفيذ العملية</div>

                <div class="mt-0.5 text-sm opacity-80">
                    {{ session('error') }}
                </div>
            </div>
        </div>
    @endif


    {{-- ================================================================
        HEADER
    ================================================================= --}}

    <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">

        <div class="flex items-start gap-4">

            <div
                class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-400">
                <flux:icon name="cube" class="size-6" />
            </div>

            <div>
                <flux:heading size="xl" level="1">
                    المنتجات
                </flux:heading>

                <flux:subheading class="mt-1">
                    إدارة المنتجات والأسعار والباركودات والعروض والفروع من مكان واحد.
                </flux:subheading>
            </div>

        </div>

        <div class="flex flex-wrap items-center gap-2">

            <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                إضافة منتج
            </flux:button>

        </div>

    </div>


    {{-- ================================================================
        STATISTICS
    ================================================================= --}}

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">

        {{-- Total --}}

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <div class="flex items-center justify-between">

                <div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        إجمالي المنتجات
                    </div>

                    <div class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                        {{ number_format($totalProducts) }}
                    </div>
                </div>

                <div
                    class="flex size-11 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-400">
                    <flux:icon name="cube" class="size-5" />
                </div>

            </div>

        </div>


        {{-- Website --}}

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <div class="flex items-center justify-between">

                <div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        معروض في الموقع
                    </div>

                    <div class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {{ number_format($websiteProducts) }}
                    </div>
                </div>

                <div
                    class="flex size-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                    <flux:icon name="globe-alt" class="size-5" />
                </div>

            </div>

        </div>


        {{-- Hidden --}}

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <div class="flex items-center justify-between">

                <div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        مخفي من الموقع
                    </div>

                    <div class="mt-2 text-2xl font-bold text-amber-600 dark:text-amber-400">
                        {{ number_format($hiddenProducts) }}
                    </div>
                </div>

                <div
                    class="flex size-11 items-center justify-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400">
                    <flux:icon name="eye-slash" class="size-5" />
                </div>

            </div>

        </div>


        {{-- Categories --}}

        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

            <div class="flex items-center justify-between">

                <div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                        التصنيفات النشطة
                    </div>

                    <div class="mt-2 text-2xl font-bold text-violet-600 dark:text-violet-400">
                        {{ number_format($totalCategories) }}
                    </div>
                </div>

                <div
                    class="flex size-11 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-950/40 dark:text-violet-400">
                    <flux:icon name="squares-2x2" class="size-5" />
                </div>

            </div>

        </div>

    </div>


    {{-- ================================================================
        SEARCH / FILTERS
    ================================================================= --}}

    <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

        <div class="mb-4 flex flex-col gap-1">

            <div class="font-semibold text-zinc-900 dark:text-white">
                البحث والتصفية
            </div>

            <div class="text-sm text-zinc-500 dark:text-zinc-400">
                ابحث باسم المنتج أو الباركود، ثم استخدم الفلاتر لتضييق النتائج.
            </div>

        </div>

        <div class="grid grid-cols-1 gap-3 lg:grid-cols-12">

            {{-- Search --}}

            <div class="lg:col-span-6">

                <flux:input wire:model.live.debounce.300ms="search" placeholder="ابحث باسم المنتج أو الباركود..."
                    icon="magnifying-glass" />

            </div>


            {{-- Category --}}

            <div class="lg:col-span-3">

                <select wire:model.live="selectedCategoryFilter"
                    class="h-10 w-full rounded-xl border border-zinc-200 bg-white px-3 text-sm text-zinc-800 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">
                        جميع التصنيفات
                    </option>

                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}">
                            {{ $category->name }}
                        </option>
                    @endforeach
                </select>

            </div>


            {{-- Website --}}

            <div class="lg:col-span-3">

                <select wire:model.live="selectedWebsiteFilter"
                    class="h-10 w-full rounded-xl border border-zinc-200 bg-white px-3 text-sm text-zinc-800 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                    <option value="">
                        جميع حالات الموقع
                    </option>

                    <option value="1">
                        معروض في الموقع
                    </option>

                    <option value="0">
                        مخفي من الموقع
                    </option>
                </select>

            </div>

        </div>

    </div>


    {{-- ================================================================
        DESKTOP TABLE
    ================================================================= --}}

    <div
        class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900 xl:block">

        <div class="overflow-x-auto">

            <table class="w-full min-w-[1200px] text-right text-sm">

                <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800/60">

                    <tr class="text-xs font-semibold text-zinc-500 dark:text-zinc-400">

                        <th class="px-5 py-4">
                            المنتج
                        </th>

                        <th class="px-5 py-4">
                            التصنيف
                        </th>

                        <th class="px-5 py-4">
                            الباركود
                        </th>

                        <th class="px-5 py-4">
                            التكلفة
                        </th>

                        <th class="px-5 py-4">
                            التجزئة
                        </th>

                        <th class="px-5 py-4">
                            الجملة
                        </th>

                        <th class="px-5 py-4">
                            العرض
                        </th>

                        <th class="px-5 py-4">
                            التسعير
                        </th>

                        <th class="px-5 py-4">
                            الموقع
                        </th>

                        <th class="px-5 py-4 text-center">
                            الإجراءات
                        </th>

                    </tr>

                </thead>

                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">

                    @forelse ($products as $product)

                        @php
                            $priceData = $branchPrices[$product->id] ?? null;
                        @endphp

                        <tr class="transition hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30">

                            {{-- Product --}}

                            <td class="px-5 py-4">

                                <div class="flex items-center gap-3">

                                    @if ($product->image)
                                        <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}"
                                            class="size-12 rounded-xl border border-zinc-200 object-cover dark:border-zinc-700">
                                    @else
                                        <div
                                            class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800">
                                            <flux:icon name="photo" class="size-6" />
                                        </div>
                                    @endif

                                    <div class="min-w-0">

                                        <div class="font-semibold text-zinc-900 dark:text-white">
                                            {{ $product->name }}
                                        </div>

                                        <div class="mt-1 text-xs text-zinc-400">
                                            #{{ $product->id }}
                                        </div>

                                    </div>

                                </div>

                            </td>


                            {{-- Category --}}

                            <td class="px-5 py-4">

                                @if ($product->category)
                                    <span
                                        class="inline-flex items-center rounded-lg bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ $product->category->name }}
                                    </span>
                                @else
                                    <span class="text-xs text-zinc-400">
                                        بدون تصنيف
                                    </span>
                                @endif

                            </td>


                            {{-- Barcode --}}

                            <td class="max-w-[180px] px-5 py-4">

                                @if ($product->barcodes->isNotEmpty())
                                    <div class="flex max-w-[180px] flex-wrap gap-1">

                                        @foreach ($product->barcodes->take(2) as $barcode)
                                            <span
                                                class="rounded-md border border-zinc-200 bg-zinc-50 px-2 py-1 font-mono text-[11px] text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                                {{ $barcode->barcode }}
                                            </span>
                                        @endforeach

                                        @if ($product->barcodes->count() > 2)
                                            <span
                                                class="rounded-md bg-indigo-50 px-2 py-1 text-[11px] font-semibold text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400">
                                                +{{ $product->barcodes->count() - 2 }}
                                            </span>
                                        @endif

                                    </div>
                                @else
                                    <span class="text-xs text-zinc-400">
                                        لا يوجد
                                    </span>
                                @endif

                            </td>


                            {{-- Cost --}}

                            <td class="px-5 py-4">

                                <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                    {{ number_format($product->cost_price, 2) }}
                                </span>

                            </td>


                            {{-- Retail --}}

                            <td class="px-5 py-4">

                                <span class="font-bold text-zinc-900 dark:text-white">
                                    {{ $priceData ? number_format($priceData->retail_price, 2) : '0.00' }}
                                </span>

                            </td>


                            {{-- Wholesale --}}

                            <td class="px-5 py-4">

                                <div class="font-semibold text-zinc-800 dark:text-zinc-200">
                                    {{ $priceData ? number_format($priceData->wholesale_price, 2) : '0.00' }}
                                </div>

                                <div class="mt-1 text-[11px] text-zinc-400">
                                    من {{ $priceData->min_wholesale_quantity ?? 1 }}
                                </div>

                            </td>


                            {{-- Offer --}}

                            <td class="px-5 py-4">

                                @if ($priceData && $priceData->offer_price !== null)
                                    <div class="font-semibold text-amber-600 dark:text-amber-400">
                                        {{ number_format($priceData->offer_price, 2) }}
                                    </div>

                                    <div class="mt-1 text-[11px] text-zinc-400">
                                        من {{ $priceData->offer_quantity ?? 1 }}
                                    </div>
                                @else
                                    <span class="text-xs text-zinc-400">
                                        لا يوجد
                                    </span>
                                @endif

                            </td>


                            {{-- Pricing --}}

                            <td class="px-5 py-4">

                                @if ($product->is_price_unified)
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400">
                                        <flux:icon name="link" class="size-3.5" />
                                        موحد
                                    </span>
                                @else
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                                        <flux:icon name="building-storefront" class="size-3.5" />
                                        حسب الفرع
                                    </span>
                                @endif

                            </td>


                            {{-- Website --}}

                            <td class="px-5 py-4">

                                <button type="button" wire:click="toggleWebsiteStatus({{ $product->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleWebsiteStatus({{ $product->id }})"
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold transition">

                                    @if ($product->show_in_website)
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">

                                            <span class="size-1.5 rounded-full bg-emerald-500"></span>

                                            معروض

                                        </span>
                                    @else
                                        <span
                                            class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-2.5 py-1 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">

                                            <span class="size-1.5 rounded-full bg-zinc-400"></span>

                                            مخفي

                                        </span>
                                    @endif

                                </button>

                            </td>


                            {{-- Actions --}}

                            <td class="px-5 py-4">

                                <div class="flex items-center justify-center gap-1">

                                    <flux:button variant="ghost" icon="pencil-square"
                                        wire:click="edit({{ $product->id }})" title="تعديل المنتج" />

                                    <flux:button variant="ghost" icon="trash"
                                        class="text-red-600 hover:text-red-700"
                                        wire:click="delete({{ $product->id }})"
                                        wire:confirm="هل أنت متأكد من نقل هذا المنتج إلى سلة المهملات؟"
                                        title="حذف المنتج" />

                                </div>

                            </td>

                        </tr>

                    @empty

                        <tr>

                            <td colspan="10" class="px-5 py-16 text-center">

                                <div class="mx-auto flex max-w-sm flex-col items-center">

                                    <div
                                        class="flex size-16 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800">
                                        <flux:icon name="cube-transparent" class="size-8" />
                                    </div>

                                    <div class="mt-4 font-semibold text-zinc-900 dark:text-white">
                                        لا توجد منتجات
                                    </div>

                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                        لم نجد منتجات مطابقة للبحث أو الفلاتر الحالية.
                                    </p>

                                    <flux:button class="mt-4" variant="subtle" wire:click="openCreateModal"
                                        icon="plus">
                                        إضافة أول منتج
                                    </flux:button>

                                </div>

                            </td>

                        </tr>

                    @endforelse

                </tbody>

            </table>

        </div>


        {{-- Pagination --}}

        @if ($products->hasPages())
            <div class="border-t border-zinc-200 px-5 py-4 dark:border-zinc-800">
                {{ $products->links() }}
            </div>
        @endif

    </div>


    {{-- ================================================================
        MOBILE / TABLET CARDS
    ================================================================= --}}

    <div class="space-y-3 xl:hidden">

        @forelse ($products as $product)

            @php
                $priceData = $branchPrices[$product->id] ?? null;
            @endphp

            <div
                class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

                <div class="flex gap-3">

                    @if ($product->image)
                        <img src="{{ Storage::url($product->image) }}" alt="{{ $product->name }}"
                            class="size-16 shrink-0 rounded-xl border border-zinc-200 object-cover dark:border-zinc-700">
                    @else
                        <div
                            class="flex size-16 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800">
                            <flux:icon name="photo" class="size-7" />
                        </div>
                    @endif


                    <div class="min-w-0 flex-1">

                        <div class="flex items-start justify-between gap-2">

                            <div class="min-w-0">

                                <div class="truncate font-semibold text-zinc-900 dark:text-white">
                                    {{ $product->name }}
                                </div>

                                <div class="mt-1 text-xs text-zinc-400">
                                    {{ $product->category->name ?? 'بدون تصنيف' }}
                                </div>

                            </div>

                            @if ($product->show_in_website)
                                <span
                                    class="shrink-0 rounded-full bg-emerald-50 px-2 py-1 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">
                                    معروض
                                </span>
                            @else
                                <span
                                    class="shrink-0 rounded-full bg-zinc-100 px-2 py-1 text-[10px] font-semibold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                    مخفي
                                </span>
                            @endif

                        </div>


                        <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">

                            <div class="rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-800/60">

                                <div class="text-[10px] text-zinc-400">
                                    التكلفة
                                </div>

                                <div class="mt-1 text-sm font-semibold">
                                    {{ number_format($product->cost_price, 2) }}
                                </div>

                            </div>


                            <div class="rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-800/60">

                                <div class="text-[10px] text-zinc-400">
                                    التجزئة
                                </div>

                                <div class="mt-1 text-sm font-bold text-indigo-600 dark:text-indigo-400">
                                    {{ $priceData ? number_format($priceData->retail_price, 2) : '0.00' }}
                                </div>

                            </div>


                            <div class="rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-800/60">

                                <div class="text-[10px] text-zinc-400">
                                    الجملة
                                </div>

                                <div class="mt-1 text-sm font-semibold">
                                    {{ $priceData ? number_format($priceData->wholesale_price, 2) : '0.00' }}
                                </div>

                            </div>


                            <div class="rounded-xl bg-zinc-50 p-2.5 dark:bg-zinc-800/60">

                                <div class="text-[10px] text-zinc-400">
                                    العرض
                                </div>

                                <div class="mt-1 text-sm font-semibold text-amber-600 dark:text-amber-400">

                                    @if ($priceData && $priceData->offer_price !== null)
                                        {{ number_format($priceData->offer_price, 2) }}
                                    @else
                                        —
                                    @endif

                                </div>

                            </div>

                        </div>


                        @if ($product->barcodes->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-1">

                                @foreach ($product->barcodes->take(3) as $barcode)
                                    <span
                                        class="rounded-md bg-zinc-100 px-2 py-1 font-mono text-[10px] text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ $barcode->barcode }}
                                    </span>
                                @endforeach

                                @if ($product->barcodes->count() > 3)
                                    <span
                                        class="rounded-md bg-indigo-50 px-2 py-1 text-[10px] font-semibold text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400">
                                        +{{ $product->barcodes->count() - 3 }}
                                    </span>
                                @endif

                            </div>
                        @endif

                    </div>

                </div>


                <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-3 dark:border-zinc-800">

                    <div class="text-xs text-zinc-400">

                        @if ($product->is_price_unified)
                            أسعار موحدة
                        @else
                            أسعار حسب الفروع
                        @endif

                    </div>

                    <div class="flex items-center gap-1">

                        <flux:button size="sm" variant="subtle" icon="pencil-square"
                            wire:click="edit({{ $product->id }})">
                            تعديل
                        </flux:button>

                        <flux:button size="sm" variant="ghost" icon="trash" class="text-red-600"
                            wire:click="delete({{ $product->id }})"
                            wire:confirm="هل أنت متأكد من نقل هذا المنتج إلى سلة المهملات؟" />

                    </div>

                </div>

            </div>

        @empty

            <div
                class="rounded-2xl border border-zinc-200 bg-white px-5 py-14 text-center dark:border-zinc-800 dark:bg-zinc-900">

                <div
                    class="mx-auto flex size-16 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800">
                    <flux:icon name="cube-transparent" class="size-8" />
                </div>

                <div class="mt-4 font-semibold">
                    لا توجد منتجات
                </div>

                <p class="mt-1 text-sm text-zinc-500">
                    لا توجد نتائج مطابقة للبحث الحالي.
                </p>

                <flux:button class="mt-4" variant="primary" icon="plus" wire:click="openCreateModal">
                    إضافة منتج
                </flux:button>

            </div>

        @endforelse


        @if ($products->hasPages())
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                {{ $products->links() }}
            </div>
        @endif

    </div>


    {{-- ================================================================
        PRODUCT MODAL
    ================================================================= --}}

    <flux:modal wire:model="showModal" class="w-full max-w-5xl">

        <div class="max-h-[85vh] overflow-y-auto px-1">

            {{-- Modal Header --}}

            <div
                class="sticky top-0 z-20 -mx-1 mb-6 border-b border-zinc-200 bg-white/95 px-1 pb-4 backdrop-blur dark:border-zinc-800 dark:bg-zinc-900/95">

                <div class="flex items-start gap-3">

                    <div
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-indigo-100 text-indigo-600 dark:bg-indigo-950/50 dark:text-indigo-400">

                        <flux:icon name="{{ $isEditing ? 'pencil-square' : 'cube' }}" class="size-5" />

                    </div>

                    <div>

                        <flux:heading size="lg">

                            {{ $isEditing ? 'تعديل المنتج' : 'إضافة منتج جديد' }}

                        </flux:heading>

                        <flux:subheading class="mt-1">

                            {{ $isEditing
                                ? 'قم بتحديث بيانات المنتج والأسعار والباركودات والصور.'
                                : 'أدخل البيانات الأساسية للمنتج ثم حدد الأسعار والفروع والباركودات.' }}

                        </flux:subheading>

                    </div>

                </div>

            </div>


            <form wire:submit.prevent="save" class="space-y-6">

                {{-- ====================================================
                    BASIC INFORMATION
                ===================================================== --}}

                <section
                    class="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-4 dark:border-zinc-800 dark:bg-zinc-800/30">

                    <div class="mb-4 flex items-center gap-3">

                        <div
                            class="flex size-9 items-center justify-center rounded-xl bg-white text-indigo-600 shadow-sm dark:bg-zinc-900 dark:text-indigo-400">
                            <flux:icon name="information-circle" class="size-5" />
                        </div>

                        <div>

                            <div class="font-semibold">
                                المعلومات الأساسية
                            </div>

                            <div class="text-xs text-zinc-500">
                                الاسم والتصنيف والصورة الرئيسية.
                            </div>

                        </div>

                    </div>


                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">

                        <flux:field>

                            <flux:label>
                                اسم المنتج
                            </flux:label>

                            <flux:input wire:model="name" placeholder="مثال: آيفون 15 برو ماكس" />

                            <flux:error name="name" />

                        </flux:field>


                        <flux:field>

                            <flux:label>
                                التصنيف
                            </flux:label>

                            <div class="flex gap-2">

                                <select wire:model="category_id"
                                    class="h-10 min-w-0 flex-1 rounded-xl border border-zinc-200 bg-white px-3 text-sm text-zinc-800 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">

                                    <option value="">
                                        بدون تصنيف
                                    </option>

                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}">
                                            {{ $category->name }}
                                        </option>
                                    @endforeach

                                </select>

                                <flux:button type="button" variant="subtle" icon="plus"
                                    wire:click="openCategoryModal" title="إضافة تصنيف" />

                            </div>

                            <flux:error name="category_id" />

                        </flux:field>

                    </div>

                </section>


                {{-- ====================================================
                    IMAGES
                ===================================================== --}}

                <section class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-800">

                    <div class="mb-4 flex items-center gap-3">

                        <div
                            class="flex size-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-400">
                            <flux:icon name="photo" class="size-5" />
                        </div>

                        <div>

                            <div class="font-semibold">
                                صور المنتج
                            </div>

                            <div class="text-xs text-zinc-500">
                                صورة رئيسية بالإضافة إلى معرض صور اختياري.
                            </div>

                        </div>

                    </div>


                    {{-- Main Image --}}

                    <div class="rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-700">

                        <div class="mb-3 text-sm font-medium">
                            الصورة الرئيسية
                        </div>

                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">

                            @if ($image)
                                <div
                                    class="relative size-24 shrink-0 overflow-hidden rounded-2xl border border-indigo-200 dark:border-indigo-900">

                                    <img src="{{ $image->temporaryUrl() }}" class="size-full object-cover">

                                    <div
                                        class="absolute bottom-0 inset-x-0 bg-indigo-600/80 py-1 text-center text-[10px] text-white">
                                        جديدة
                                    </div>

                                </div>
                            @elseif ($existing_image)
                                <div
                                    class="group relative size-24 shrink-0 overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">

                                    <img src="{{ Storage::url($existing_image) }}" class="size-full object-cover">

                                    <button type="button" wire:click="removeSingleExistingImage"
                                        class="absolute inset-0 flex items-center justify-center bg-red-600/75 text-white opacity-0 transition group-hover:opacity-100"
                                        title="حذف الصورة">
                                        <flux:icon name="trash" class="size-5" />
                                    </button>

                                </div>
                            @else
                                <div
                                    class="flex size-24 shrink-0 items-center justify-center rounded-2xl bg-zinc-100 text-zinc-400 dark:bg-zinc-800">
                                    <flux:icon name="photo" class="size-8" />
                                </div>
                            @endif


                            <div class="flex-1">

                                <input type="file" wire:model="image" accept="image/*"
                                    class="block w-full cursor-pointer text-sm text-zinc-500 file:mr-4 file:rounded-xl file:border-0 file:bg-indigo-50 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-zinc-800 dark:file:text-zinc-200">

                                <div class="mt-2 text-xs text-zinc-400">
                                    JPG / PNG / WEBP — الحد الأقصى 2MB.
                                </div>

                                <div wire:loading wire:target="image"
                                    class="mt-2 text-xs font-medium text-indigo-600">
                                    جاري تجهيز الصورة...
                                </div>

                            </div>

                        </div>

                        <flux:error name="image" />

                    </div>


                    {{-- Gallery --}}

                    <div class="mt-4 rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-700">

                        <div class="mb-3 text-sm font-medium">
                            معرض الصور الإضافية
                        </div>

                        <input type="file" wire:model="images" multiple accept="image/*"
                            class="block w-full cursor-pointer text-sm text-zinc-500 file:mr-4 file:rounded-xl file:border-0 file:bg-zinc-100 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-200">

                        <div wire:loading wire:target="images" class="mt-2 text-xs font-medium text-indigo-600">
                            جاري تجهيز الصور...
                        </div>


                        {{-- Existing Gallery --}}

                        @if (!empty($existing_images))

                            <div class="mt-4">

                                <div class="mb-2 text-xs font-medium text-zinc-500">
                                    الصور المحفوظة
                                </div>

                                <div class="flex flex-wrap gap-2">

                                    @foreach ($existing_images as $index => $imgPath)
                                        <div
                                            class="group relative size-20 overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">

                                            <img src="{{ Storage::url($imgPath) }}" class="size-full object-cover">

                                            <button type="button"
                                                wire:click="removeExistingImage({{ $index }})"
                                                class="absolute inset-0 flex items-center justify-center bg-red-600/75 text-white opacity-0 transition group-hover:opacity-100">
                                                <flux:icon name="trash" class="size-4" />
                                            </button>

                                        </div>
                                    @endforeach

                                </div>

                            </div>

                        @endif


                        {{-- New Gallery --}}

                        @if (!empty($images))

                            <div class="mt-4">

                                <div class="mb-2 text-xs font-medium text-indigo-600">
                                    الصور الجديدة
                                </div>

                                <div class="flex flex-wrap gap-2">

                                    @foreach ($images as $index => $newImage)
                                        <div
                                            class="group relative size-20 overflow-hidden rounded-xl border border-indigo-200 dark:border-indigo-900">

                                            <img src="{{ $newImage->temporaryUrl() }}"
                                                class="size-full object-cover">

                                            <button type="button" wire:click="removeNewImage({{ $index }})"
                                                class="absolute inset-0 flex items-center justify-center bg-red-600/75 text-white opacity-0 transition group-hover:opacity-100">
                                                <flux:icon name="x-mark" class="size-4" />
                                            </button>

                                        </div>
                                    @endforeach

                                </div>

                            </div>

                        @endif

                        <flux:error name="images.*" />

                    </div>

                </section>


                {{-- ====================================================
                    BARCODES
                ===================================================== --}}

                <section class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-800">

                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                        <div class="flex items-center gap-3">

                            <div
                                class="flex size-9 items-center justify-center rounded-xl bg-violet-50 text-violet-600 dark:bg-violet-950/40 dark:text-violet-400">
                                <flux:icon name="qr-code" class="size-5" />
                            </div>

                            <div>

                                <div class="font-semibold">
                                    الباركودات
                                </div>

                                <div class="text-xs text-zinc-500">
                                    يمكنك ربط أكثر من باركود بالمنتج نفسه.
                                </div>

                            </div>

                        </div>

                        <flux:button type="button" size="sm" variant="subtle" icon="plus"
                            wire:click="addBarcodeField">
                            إضافة باركود
                        </flux:button>

                    </div>


                    <div class="space-y-2">

                        @foreach ($barcodes as $index => $barcode)
                            <div wire:key="barcode-row-{{ $index }}" class="flex items-start gap-2">

                                <div class="flex-1">

                                    <flux:input wire:model="barcodes.{{ $index }}"
                                        placeholder="امسح الباركود أو أدخله يدوياً..." />

                                    @error('barcodes.' . $index)
                                        <div class="mt-1 text-xs text-red-600">
                                            {{ $message }}
                                        </div>
                                    @enderror

                                </div>

                                <flux:button type="button" variant="subtle" icon="sparkles"
                                    wire:click="generateBarcode({{ $index }})" title="توليد باركود" />

                                @if (count($barcodes) > 1)
                                    <flux:button type="button" variant="ghost" icon="trash" class="text-red-600"
                                        wire:click="removeBarcodeField({{ $index }})" title="حذف الباركود" />
                                @endif

                            </div>
                        @endforeach

                    </div>

                    <flux:error name="barcodes" />

                </section>


                {{-- ====================================================
                    PRICING POLICY
                ===================================================== --}}

                <section
                    class="rounded-2xl border border-indigo-200 bg-indigo-50/40 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/20">

                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                        <div class="flex items-start gap-3">

                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-white text-indigo-600 shadow-sm dark:bg-zinc-900 dark:text-indigo-400">
                                <flux:icon name="banknotes" class="size-5" />
                            </div>

                            <div>

                                <div class="font-semibold text-indigo-950 dark:text-indigo-200">
                                    سياسة أسعار الفروع
                                </div>

                                <p class="mt-1 max-w-2xl text-xs leading-5 text-indigo-800/70 dark:text-indigo-300/70">

                                    عند التفعيل يتم استخدام نفس أسعار التجزئة والجملة والعروض لجميع الفروع.
                                    عند الإيقاف يمكنك تحديد الأسعار لكل فرع بشكل مستقل.

                                </p>

                            </div>

                        </div>


                        <flux:switch wire:model.live="is_price_unified" />

                    </div>

                </section>


                {{-- ====================================================
                    UNIFIED PRICES
                ===================================================== --}}

                @if ($is_price_unified)

                    <section class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-800">

                        <div class="mb-4 flex items-center gap-3">

                            <div
                                class="flex size-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                                <flux:icon name="currency-dollar" class="size-5" />
                            </div>

                            <div>

                                <div class="font-semibold">
                                    الأسعار الموحدة
                                </div>

                                <div class="text-xs text-zinc-500">
                                    هذه الأسعار سيتم تطبيقها على جميع فروع المتجر.
                                </div>

                            </div>

                        </div>


                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

                            <flux:field>

                                <flux:label>
                                    سعر التكلفة
                                </flux:label>

                                <flux:input type="number" step="0.01" min="0" wire:model="cost_price"
                                    placeholder="0.00" />

                                <flux:error name="cost_price" />

                            </flux:field>


                            <flux:field>

                                <flux:label>
                                    سعر التجزئة
                                </flux:label>

                                <flux:input type="number" step="0.01" min="0" wire:model="retail_price"
                                    placeholder="0.00" />

                                <flux:error name="retail_price" />

                            </flux:field>


                            <flux:field>

                                <flux:label>
                                    سعر الجملة
                                </flux:label>

                                <flux:input type="number" step="0.01" min="0"
                                    wire:model="wholesale_price" placeholder="0.00" />

                                <flux:error name="wholesale_price" />

                            </flux:field>


                            <flux:field>

                                <flux:label>
                                    أقل كمية للجملة
                                </flux:label>

                                <flux:input type="number" min="1" wire:model="min_wholesale_quantity"
                                    placeholder="1" />

                                <flux:error name="min_wholesale_quantity" />

                            </flux:field>

                        </div>


                        {{-- Offer --}}

                        <div
                            class="mt-5 rounded-2xl border border-amber-200 bg-amber-50/50 p-4 dark:border-amber-900/60 dark:bg-amber-950/10">

                            <div class="mb-4 flex items-center gap-2">

                                <flux:icon name="tag" class="size-5 text-amber-600" />

                                <div>

                                    <div class="font-semibold text-amber-900 dark:text-amber-300">
                                        العرض الموحد
                                    </div>

                                    <div class="text-xs text-amber-700/70 dark:text-amber-400/70">
                                        اختياري
                                    </div>

                                </div>

                            </div>


                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">

                                <flux:field>

                                    <flux:label>
                                        سعر العرض
                                    </flux:label>

                                    <flux:input type="number" step="0.01" min="0"
                                        wire:model="offer_price" placeholder="اتركه فارغاً إذا لا يوجد عرض" />

                                    <flux:error name="offer_price" />

                                </flux:field>


                                <flux:field>

                                    <flux:label>
                                        كمية تطبيق العرض
                                    </flux:label>

                                    <flux:input type="number" min="1" wire:model="offer_quantity"
                                        placeholder="مثال: 1 أو 3" />

                                    <flux:error name="offer_quantity" />

                                </flux:field>

                            </div>

                        </div>

                    </section>
                @else
                    {{-- =================================================
                        BRANCH SPECIFIC PRICES
                    ================================================== --}}

                    <section class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-800">

                        <div class="mb-4 flex items-center justify-between gap-3">

                            <div class="flex items-center gap-3">

                                <div
                                    class="flex size-9 items-center justify-center rounded-xl bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400">
                                    <flux:icon name="building-storefront" class="size-5" />
                                </div>

                                <div>

                                    <div class="font-semibold">
                                        أسعار الفروع
                                    </div>

                                    <div class="text-xs text-zinc-500">
                                        حدد الأسعار لكل فرع بشكل مستقل.
                                    </div>

                                </div>

                            </div>

                            <span
                                class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                {{ $allBranches->count() }} فرع
                            </span>

                        </div>


                        {{-- Cost --}}

                        <div class="mb-5 max-w-sm">

                            <flux:field>

                                <flux:label>
                                    سعر التكلفة الأساسي
                                </flux:label>

                                <flux:input type="number" step="0.01" min="0" wire:model="cost_price"
                                    placeholder="0.00" />

                                <flux:error name="cost_price" />

                            </flux:field>

                        </div>


                        <div class="space-y-4">

                            @forelse ($allBranches as $branch)
                                @php
                                    $branchInput = $branchPricesInput[$branch->id] ?? [];
                                @endphp

                                <div wire:key="branch-price-{{ $branch->id }}"
                                    class="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-4 dark:border-zinc-800 dark:bg-zinc-800/30">

                                    <div
                                        class="mb-4 flex items-center gap-2 border-b border-zinc-200 pb-3 dark:border-zinc-700">

                                        <div
                                            class="flex size-8 items-center justify-center rounded-lg bg-white text-indigo-600 shadow-sm dark:bg-zinc-900 dark:text-indigo-400">
                                            <flux:icon name="building-storefront" class="size-4" />
                                        </div>

                                        <div class="font-semibold">
                                            {{ $branch->name }}
                                        </div>

                                    </div>


                                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">

                                        <flux:field>

                                            <flux:label>
                                                سعر التجزئة
                                            </flux:label>

                                            <flux:input type="number" step="0.01" min="0"
                                                wire:model="branchPricesInput.{{ $branch->id }}.retail_price"
                                                placeholder="0.00" />

                                            <flux:error name="branchPricesInput.{{ $branch->id }}.retail_price" />

                                        </flux:field>


                                        <flux:field>

                                            <flux:label>
                                                سعر الجملة
                                            </flux:label>

                                            <flux:input type="number" step="0.01" min="0"
                                                wire:model="branchPricesInput.{{ $branch->id }}.wholesale_price"
                                                placeholder="0.00" />

                                            <flux:error
                                                name="branchPricesInput.{{ $branch->id }}.wholesale_price" />

                                        </flux:field>


                                        <flux:field>

                                            <flux:label>
                                                أقل كمية للجملة
                                            </flux:label>

                                            <flux:input type="number" min="1"
                                                wire:model="branchPricesInput.{{ $branch->id }}.min_wholesale_quantity"
                                                placeholder="1" />

                                            <flux:error
                                                name="branchPricesInput.{{ $branch->id }}.min_wholesale_quantity" />

                                        </flux:field>

                                    </div>


                                    <div
                                        class="mt-4 grid grid-cols-1 gap-4 border-t border-dashed border-zinc-200 pt-4 dark:border-zinc-700 sm:grid-cols-2">

                                        <flux:field>

                                            <flux:label>
                                                سعر العرض
                                            </flux:label>

                                            <flux:input type="number" step="0.01" min="0"
                                                wire:model="branchPricesInput.{{ $branch->id }}.offer_price"
                                                placeholder="اختياري" />

                                        </flux:field>


                                        <flux:field>

                                            <flux:label>
                                                كمية العرض
                                            </flux:label>

                                            <flux:input type="number" min="1"
                                                wire:model="branchPricesInput.{{ $branch->id }}.offer_quantity"
                                                placeholder="اختياري" />

                                        </flux:field>

                                    </div>

                                </div>

                            @empty

                                <div
                                    class="rounded-2xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-700">

                                    <flux:icon name="building-storefront" class="mx-auto size-8 text-zinc-400" />

                                    <div class="mt-3 font-semibold">
                                        لا توجد فروع
                                    </div>

                                    <p class="mt-1 text-sm text-zinc-500">
                                        يجب إنشاء فرع قبل تحديد أسعار المنتج.
                                    </p>

                                </div>
                            @endforelse

                        </div>

                    </section>

                @endif


                {{-- ====================================================
                    WEBSITE
                ===================================================== --}}

                <section class="rounded-2xl border border-zinc-200 p-4 dark:border-zinc-800">

                    <div class="flex items-center justify-between gap-4">

                        <div class="flex items-start gap-3">

                            <div
                                class="flex size-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400">
                                <flux:icon name="globe-alt" class="size-5" />
                            </div>

                            <div>

                                <div class="font-semibold">
                                    الظهور في الموقع الإلكتروني
                                </div>

                                <div class="mt-1 text-xs text-zinc-500">
                                    تحديد ما إذا كان المنتج متاحاً للعرض في الموقع.
                                </div>

                            </div>

                        </div>

                        <flux:switch wire:model.live="show_in_website" />

                    </div>

                    <flux:error name="show_in_website" />

                </section>


                {{-- ====================================================
                    FOOTER
                ===================================================== --}}

                <div
                    class="sticky bottom-0 z-20 -mx-1 flex flex-col-reverse gap-2 border-t border-zinc-200 bg-white/95 pt-4 backdrop-blur sm:flex-row sm:justify-end dark:border-zinc-800 dark:bg-zinc-900/95">

                    <flux:button type="button" variant="ghost" wire:click="closeModal">
                        إلغاء
                    </flux:button>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">

                        <span wire:loading.remove wire:target="save">
                            {{ $isEditing ? 'حفظ التعديلات' : 'حفظ المنتج' }}
                        </span>

                        <span wire:loading wire:target="save">
                            جاري الحفظ...
                        </span>

                    </flux:button>

                </div>

            </form>

        </div>

    </flux:modal>


    {{-- ================================================================
        CATEGORY MODAL
    ================================================================= --}}

    <flux:modal wire:model="showCategoryModal" class="w-full max-w-md">

        <div>

            <div class="flex items-start gap-3">

                <div
                    class="flex size-10 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-950/40 dark:text-violet-400">
                    <flux:icon name="squares-2x2" class="size-5" />
                </div>

                <div>

                    <flux:heading size="lg">
                        إضافة تصنيف
                    </flux:heading>

                    <flux:subheading class="mt-1">
                        سيتم تحديد التصنيف الجديد للمنتج مباشرة.
                    </flux:subheading>

                </div>

            </div>


            <form wire:submit.prevent="saveCategory" class="mt-6 space-y-4">

                <flux:field>

                    <flux:label>
                        اسم التصنيف
                    </flux:label>

                    <flux:input wire:model="newCategoryName" placeholder="مثال: إلكترونيات، مشروبات..." />

                    <flux:error name="newCategoryName" />

                </flux:field>


                <flux:field>

                    <flux:label>
                        كود التصنيف
                    </flux:label>

                    <flux:input wire:model="newCategoryCode" placeholder="مثال: CAT-001" />

                    <flux:error name="newCategoryCode" />

                    <div class="mt-1 text-xs text-zinc-400">
                        اختياري ويمكن تركه فارغاً.
                    </div>

                </flux:field>


                <div
                    class="flex flex-col-reverse gap-2 border-t border-zinc-200 pt-4 sm:flex-row sm:justify-end dark:border-zinc-800">

                    <flux:button type="button" variant="ghost" wire:click="closeCategoryModal">
                        إلغاء
                    </flux:button>

                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled"
                        wire:target="saveCategory">

                        <span wire:loading.remove wire:target="saveCategory">
                            حفظ التصنيف
                        </span>

                        <span wire:loading wire:target="saveCategory">
                            جاري الحفظ...
                        </span>

                    </flux:button>

                </div>

            </form>

        </div>

    </flux:modal>

</flux:main>
