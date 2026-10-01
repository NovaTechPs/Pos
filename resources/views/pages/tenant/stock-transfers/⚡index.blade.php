<?php

use App\Models\Branch;

use App\Models\BranchProduct;

use App\Models\Product;

use App\Models\StockTransfer;

use App\Models\StockTransferItem;

use Illuminate\Support\Facades\Auth;

use Illuminate\Support\Facades\DB;

use Illuminate\Validation\Rule;

use Livewire\Component;

use Livewire\WithPagination;

return new class extends Component {

    use WithPagination;

    public ?int $fromBranchId = null;

    public ?int $toBranchId = null;

    public string $productSearch = '';

    public string $listSearch = '';

    public string $statusFilter = '';

    public string $notes = '';

    public array $cart = [];

    public bool $showForm = false;

    public ?int $viewTransferId = null;

    public ?int $cancelTransferId = null;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public function mount(): void

    {

        $tenantId = $this->tenantId();

        if (!$tenantId) {

            abort(403, 'لم يتم تحديد المتجر الحالي.');

        }

        $user = Auth::user();

        if ($user?->isTenantOwner()) {
            $this->fromBranchId = (int) (
                session('active_branch_id')
                ?: Branch::query()
                    ->where('tenant_id', $tenantId)
                    ->orderBy('id')
                    ->value('id')
            );
        } else {
            $this->fromBranchId = $user?->branch_id
                ? (int) $user->branch_id
                : (int) (session('active_branch_id') ?: Branch::query()
                    ->where('tenant_id', $tenantId)
                    ->orderBy('id')
                    ->value('id'));
        }

    }

    private function tenantId(): ?int

    {

        $id = session('active_tenant_id');

        return $id ? (int) $id : null;

    }

    private function userBranchId(): ?int

    {

        $user = Auth::user();

        // مالك المتجر يستطيع اختيار أي فرع حتى لو كان branch_id موجودًا لديه.
        if ($user?->isTenantOwner()) {
            return null;
        }

        $id = $user?->branch_id;

        return $id ? (int) $id : null;

    }

    public function updatedFromBranchId($value): void

    {

        $lockedBranchId = $this->userBranchId();

        if ($lockedBranchId) {

            $this->fromBranchId = $lockedBranchId;

            return;

        }

        $this->fromBranchId = $value ? (int) $value : null;

        $this->cart = [];

        $this->resetPage();

    }

    public function updatedToBranchId($value): void

    {

        $this->toBranchId = $value ? (int) $value : null;

        $this->errorMessage = null;

    }

    public function updatedProductSearch(): void

    {

        $this->resetPage();

    }

    public function updatedListSearch(): void

    {

        $this->resetPage();

    }

    public function updatedStatusFilter(): void

    {

        $this->resetPage();

    }

    public function openCreate(): void

    {

        $this->resetValidation();

        $this->errorMessage = null;

        $this->successMessage = null;

        $this->cart = [];

        $this->notes = '';

        $this->toBranchId = null;

        $this->showForm = true;

    }

    public function closeForm(): void

    {

        $this->showForm = false;

        $this->cart = [];

        $this->notes = '';

        $this->toBranchId = null;

        $this->resetValidation();

    }

    public function addProduct(int $productId): void

    {

        $tenantId = $this->tenantId();

        $fromBranchId = $this->validatedFromBranchId();

        if (!$tenantId || !$fromBranchId) {

            $this->errorMessage = 'يرجى اختيار الفرع المصدر أولاً.';

            return;

        }

        $product = Product::query()

            ->where('tenant_id', $tenantId)

            ->whereKey($productId)

            ->first();

        if (!$product) {

            $this->errorMessage = 'المنتج غير موجود.';

            return;

        }

        $source = BranchProduct::query()

            ->where('tenant_id', $tenantId)

            ->where('branch_id', $fromBranchId)

            ->where('product_id', $productId)

            ->first();

        if (!$source) {

            $this->errorMessage = 'المنتج غير مرتبط بالفرع المصدر.';

            return;

        }

        $key = (string) $productId;

        if (!isset($this->cart[$key])) {

            $this->cart[$key] = [

                'id' => $productId,

                'name' => $product->name,

                'quantity' => 1,

                'source_stock' => (float) $source->stock_quantity,

            ];

        }

        $this->errorMessage = null;

    }

    public function removeProduct(int $productId): void

    {

        unset($this->cart[(string) $productId]);

    }

    public function setQuantity(int $productId, $quantity): void

    {

        $key = (string) $productId;

        if (!isset($this->cart[$key])) {

            return;

        }

        $quantity = (float) str_replace(',', '.', (string) $quantity);

        $this->cart[$key]['quantity'] = max(0.001, round($quantity, 3));

    }

    public function saveTransfer(): void

    {

        $tenantId = $this->tenantId();

        $fromBranchId = $this->validatedFromBranchId();

        $toBranchId = $this->validatedToBranchId();

        $this->validate([

            'fromBranchId' => ['required', 'integer'],

            'toBranchId' => [

                'required',

                'integer',

                'different:fromBranchId',

            ],

            'notes' => ['nullable', 'string', 'max:2000'],

        ], [

            'fromBranchId.required' => 'اختر الفرع المصدر.',

            'toBranchId.required' => 'اختر الفرع المستلم.',

            'toBranchId.different' => 'لا يمكن أن يكون الفرع المصدر والمستلم نفس الفرع.',

        ]);

        if (!$tenantId || !$fromBranchId || !$toBranchId) {

            $this->errorMessage = 'بيانات الفروع غير مكتملة.';

            return;

        }

        if (empty($this->cart)) {

            $this->errorMessage = 'أضف صنفًا واحدًا على الأقل إلى التحويل.';

            return;

        }

        $branchIds = Branch::query()

            ->where('tenant_id', $tenantId)

            ->whereIn('id', [$fromBranchId, $toBranchId])

            ->pluck('id')

            ->map(fn ($id) => (int) $id)

            ->all();

        if (count($branchIds) !== 2 || $fromBranchId === $toBranchId) {

            $this->errorMessage = 'الفروع المحددة غير صالحة لهذا المتجر.';

            return;

        }

        try {

            DB::transaction(function () use ($tenantId, $fromBranchId, $toBranchId): void {

                $transfer = StockTransfer::create([

                    'tenant_id' => $tenantId,

                    'from_branch_id' => $fromBranchId,

                    'to_branch_id' => $toBranchId,

                    'transfer_number' => $this->generateTransferNumber($tenantId),

                    'status' => 'pending',

                    'created_by' => Auth::id(),

                    'transfer_date' => now(),

                    'notes' => trim($this->notes) ?: null,

                ]);

                foreach ($this->cart as $item) {

                    $productId = (int) ($item['id'] ?? 0);

                    $quantity = round((float) ($item['quantity'] ?? 0), 3);

                    if ($productId <= 0 || $quantity <= 0) {

                        throw new \RuntimeException('يوجد صنف أو كمية غير صالحة في التحويل.');

                    }

                    $productExists = Product::query()

                        ->where('tenant_id', $tenantId)

                        ->whereKey($productId)

                        ->exists();

                    if (!$productExists) {

                        throw new \RuntimeException('أحد المنتجات لم يعد موجودًا.');

                    }

                    $sourceExists = BranchProduct::query()

                        ->where('tenant_id', $tenantId)

                        ->where('branch_id', $fromBranchId)

                        ->where('product_id', $productId)

                        ->exists();

                    $destinationExists = BranchProduct::query()

                        ->where('tenant_id', $tenantId)

                        ->where('branch_id', $toBranchId)

                        ->where('product_id', $productId)

                        ->exists();

                    if (!$sourceExists) {

                        $name = Product::query()->whereKey($productId)->value('name') ?? $productId;

                        throw new \RuntimeException("المنتج {$name} غير مرتبط بالفرع المصدر.");

                    }

                    if (!$destinationExists) {

                        $name = Product::query()->whereKey($productId)->value('name') ?? $productId;

                        throw new \RuntimeException("المنتج {$name} غير مرتبط بالفرع المستلم. اربطه بالفرع أولاً من إدارة المنتجات.");

                    }

                    StockTransferItem::create([

                        'tenant_id' => $tenantId,

                        'stock_transfer_id' => $transfer->id,

                        'product_id' => $productId,

                        'quantity' => $quantity,

                    ]);

                }

            });

            $this->showForm = false;

            $this->cart = [];

            $this->notes = '';

            $this->successMessage = 'تم إنشاء طلب التحويل بنجاح، وأصبح بانتظار الاعتماد.';

            $this->resetPage();

        } catch (\Throwable $e) {

            $this->errorMessage = $e->getMessage();

        }

    }

    public function approve(int $id): void

    {

        $tenantId = $this->tenantId();

        if (!$tenantId) {

            $this->errorMessage = 'لم يتم تحديد المتجر الحالي.';

            return;

        }

        try {

            DB::transaction(function () use ($tenantId, $id): void {

                $transfer = StockTransfer::query()

                    ->where('tenant_id', $tenantId)

                    ->whereKey($id)

                    ->lockForUpdate()

                    ->first();

                if (!$transfer) {

                    throw new \RuntimeException('التحويل غير موجود.');

                }

                if ($transfer->status !== 'pending') {

                    throw new \RuntimeException('لا يمكن اعتماد هذا التحويل لأن حالته الحالية ليست بانتظار الاعتماد.');

                }

                if ((int) $transfer->from_branch_id === (int) $transfer->to_branch_id) {

                    throw new \RuntimeException('الفرع المصدر والمستلم لا يمكن أن يكونا متطابقين.');

                }

                $items = StockTransferItem::query()

                    ->where('tenant_id', $tenantId)

                    ->where('stock_transfer_id', $transfer->id)

                    ->orderBy('product_id')

                    ->get();

                if ($items->isEmpty()) {

                    throw new \RuntimeException('لا توجد أصناف في هذا التحويل.');

                }

                foreach ($items as $item) {

                    $productName = Product::query()

                        ->where('tenant_id', $tenantId)

                        ->whereKey($item->product_id)

                        ->value('name') ?? $item->product_id;

                    $source = BranchProduct::query()

                        ->where('tenant_id', $tenantId)

                        ->where('branch_id', $transfer->from_branch_id)

                        ->where('product_id', $item->product_id)

                        ->lockForUpdate()

                        ->first();

                    $destination = BranchProduct::query()

                        ->where('tenant_id', $tenantId)

                        ->where('branch_id', $transfer->to_branch_id)

                        ->where('product_id', $item->product_id)

                        ->lockForUpdate()

                        ->first();

                    if (!$source) {

                        throw new \RuntimeException("المنتج {$productName} غير مرتبط بالفرع المصدر.");

                    }

                    if (!$destination) {

                        throw new \RuntimeException("المنتج {$productName} غير مرتبط بالفرع المستلم.");

                    }

                    $quantity = (float) $item->quantity;

                    $available = (float) $source->stock_quantity;

                    if ($available < $quantity) {

                        throw new \RuntimeException(

                            "المخزون غير كافٍ للمنتج {$productName}. المتوفر في الفرع المصدر: {$available}، المطلوب: {$quantity}."

                        );

                    }

                }

                foreach ($items as $item) {

                    $quantity = (float) $item->quantity;

                    $source = BranchProduct::query()

                        ->where('tenant_id', $tenantId)

                        ->where('branch_id', $transfer->from_branch_id)

                        ->where('product_id', $item->product_id)

                        ->lockForUpdate()

                        ->firstOrFail();

                    $destination = BranchProduct::query()

                        ->where('tenant_id', $tenantId)

                        ->where('branch_id', $transfer->to_branch_id)

                        ->where('product_id', $item->product_id)

                        ->lockForUpdate()

                        ->firstOrFail();

                    $source->decrement('stock_quantity', $quantity);

                    $destination->increment('stock_quantity', $quantity);

                }

                $transfer->update([

                    'status' => 'completed',

                    'approved_by' => Auth::id(),

                    'approved_at' => now(),

                ]);

            });

            $this->successMessage = 'تم اعتماد التحويل ونقل المخزون بين الفرعين بنجاح.';

            $this->resetPage();

        } catch (\Throwable $e) {

            $this->errorMessage = $e->getMessage();

        }

    }

    public function askCancel(int $id): void

    {

        $this->cancelTransferId = $id;

        $this->errorMessage = null;

    }

    public function cancel(int $id): void

    {

        $tenantId = $this->tenantId();

        if (!$tenantId) {

            $this->errorMessage = 'لم يتم تحديد المتجر الحالي.';

            return;

        }

        try {

            DB::transaction(function () use ($tenantId, $id): void {

                $transfer = StockTransfer::query()

                    ->where('tenant_id', $tenantId)

                    ->whereKey($id)

                    ->lockForUpdate()

                    ->first();

                if (!$transfer) {

                    throw new \RuntimeException('التحويل غير موجود.');

                }

                if ($transfer->status === 'cancelled') {

                    throw new \RuntimeException('التحويل ملغى بالفعل.');

                }

                if ($transfer->status === 'pending') {

                    $transfer->update([

                        'status' => 'cancelled',

                        'cancelled_by' => Auth::id(),

                        'cancelled_at' => now(),

                    ]);

                    return;

                }

                if ($transfer->status !== 'completed') {

                    throw new \RuntimeException('لا يمكن إلغاء التحويل بالحالة الحالية.');

                }

                $items = StockTransferItem::query()

                    ->where('tenant_id', $tenantId)

                    ->where('stock_transfer_id', $transfer->id)

                    ->orderBy('product_id')

                    ->get();

                foreach ($items as $item) {

                    $destination = BranchProduct::query()

                        ->where('tenant_id', $tenantId)

                        ->where('branch_id', $transfer->to_branch_id)

                        ->where('product_id', $item->product_id)

                        ->lockForUpdate()

                        ->first();

                    if (!$destination) {

                        throw new \RuntimeException('المنتج غير مرتبط بالفرع المستلم، ولا يمكن عكس التحويل.');

                    }

                    $quantity = (float) $item->quantity;

                    if ((float) $destination->stock_quantity < $quantity) {

                        $name = Product::query()->where('tenant_id', $tenantId)->whereKey($item->product_id)->value('name') ?? $item->product_id;

                        throw new \RuntimeException(

                            "لا يمكن إلغاء التحويل لأن المخزون الحالي في الفرع المستلم من المنتج {$name} أقل من الكمية المحولة."

                        );

                    }

                }

                foreach ($items as $item) {

                    $quantity = (float) $item->quantity;

                    $destination = BranchProduct::query()

                        ->where('tenant_id', $tenantId)

                        ->where('branch_id', $transfer->to_branch_id)

                        ->where('product_id', $item->product_id)

                        ->lockForUpdate()

                        ->firstOrFail();

                    $source = BranchProduct::query()

                        ->where('tenant_id', $tenantId)

                        ->where('branch_id', $transfer->from_branch_id)

                        ->where('product_id', $item->product_id)

                        ->lockForUpdate()

                        ->firstOrFail();

                    $destination->decrement('stock_quantity', $quantity);

                    $source->increment('stock_quantity', $quantity);

                }

                $transfer->update([

                    'status' => 'cancelled',

                    'cancelled_by' => Auth::id(),

                    'cancelled_at' => now(),

                ]);

            });

            $this->cancelTransferId = null;

            $this->successMessage = 'تم إلغاء التحويل وعكس حركة المخزون بنجاح.';

            $this->resetPage();

        } catch (\Throwable $e) {

            $this->cancelTransferId = null;

            $this->errorMessage = $e->getMessage();

        }

    }

    public function showTransfer(int $id): void

    {

        $transfer = $this->transferQuery()

            ->with(['items.product', 'fromBranch', 'toBranch', 'creator', 'approver', 'canceller'])

            ->whereKey($id)

            ->first();

        if (!$transfer) {

            $this->errorMessage = 'التحويل غير موجود.';

            return;

        }

        $this->viewTransferId = $id;

    }

    public function closeView(): void

    {

        $this->viewTransferId = null;

    }

    private function transferQuery()

    {

        return StockTransfer::query()

            ->where('tenant_id', $this->tenantId());

    }

    private function validatedFromBranchId(): ?int

    {

        $id = $this->userBranchId() ?: ($this->fromBranchId ?: null);

        if (!$id) {

            return null;

        }

        $exists = Branch::query()

            ->where('tenant_id', $this->tenantId())

            ->whereKey($id)

            ->exists();

        return $exists ? (int) $id : null;

    }

    private function validatedToBranchId(): ?int

    {

        $id = $this->toBranchId ?: null;

        if (!$id) {

            return null;

        }

        $exists = Branch::query()

            ->where('tenant_id', $this->tenantId())

            ->whereKey($id)

            ->exists();

        return $exists ? (int) $id : null;

    }

    private function generateTransferNumber(int $tenantId): string

    {

        do {

            $number = 'TR-' . now()->format('Ymd') . '-' . random_int(100000, 999999);

        } while (

            StockTransfer::query()

                ->where('tenant_id', $tenantId)

                ->where('transfer_number', $number)

                ->exists()

        );

        return $number;

    }

    public function render()

    {

        $tenantId = $this->tenantId();

        $userBranchId = $this->userBranchId();

        $branches = Branch::query()

            ->where('tenant_id', $tenantId)

            ->when($userBranchId, fn ($q) => $q->whereKey($userBranchId))

            ->orderBy('name')

            ->get();

        $destinationBranches = Branch::query()

            ->where('tenant_id', $tenantId)

            ->when($this->fromBranchId, fn ($q) => $q->where('id', '!=', $this->fromBranchId))

            ->orderBy('name')

            ->get();

        $products = collect();

        if ($this->fromBranchId) {

            $products = Product::query()

                ->where('tenant_id', $tenantId)

                ->whereHas('branchProducts', function ($q) {

                    $q->where('tenant_id', $this->tenantId())

                        ->where('branch_id', $this->fromBranchId);

                })

                ->when(trim($this->productSearch) !== '', function ($q) {

                    $search = trim($this->productSearch);

                    $words = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY);

                    foreach ($words as $word) {

                        $q->where(function ($sub) use ($word) {

                            $sub->where('name', 'like', '%' . $word . '%')


                                ->orWhereHas('barcodes', fn ($barcode) => $barcode->where('barcode', 'like', '%' . $word . '%'));

                        });

                    }

                })

                ->with([
                    'branchProducts' => fn ($q) => $q
                        ->where('tenant_id', $tenantId)
                        ->where('branch_id', $this->fromBranchId),
                    'barcodes' => fn ($q) => $q->where('tenant_id', $tenantId),
                ])

                ->orderBy('name')

                ->limit(30)

                ->get();

        }

        $transfers = $this->transferQuery()

            ->with(['fromBranch', 'toBranch', 'creator'])

            ->when(trim($this->listSearch) !== '', function ($q) {

                $search = trim($this->listSearch);

                $q->where(function ($sub) use ($search) {

                    $sub->where('transfer_number', 'like', '%' . $search . '%')

                        ->orWhereHas('fromBranch', fn ($b) => $b->where('name', 'like', '%' . $search . '%'))

                        ->orWhereHas('toBranch', fn ($b) => $b->where('name', 'like', '%' . $search . '%'));

                });

            })

            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))

            ->latest('id')

            ->paginate(12);

        $viewTransfer = $this->viewTransferId

            ? $this->transferQuery()

                ->with(['items.product', 'fromBranch', 'toBranch', 'creator', 'approver', 'canceller'])

                ->find($this->viewTransferId)

            : null;

        return $this->view([

            'branches' => $branches,

            'userBranchId' => $userBranchId,

            'destinationBranches' => $destinationBranches,

            'products' => $products,

            'transfers' => $transfers,

            'viewTransfer' => $viewTransfer,

        ])->layout('layouts::tenant');

    }

};

?>

<flux:main class="space-y-6">

  <div dir="rtl" class="min-h-screen space-y-6 p-4 md:p-6">
    {{-- Header --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-2xl font-black text-zinc-900">تحويل المخزون</h1>
                <p class="mt-1 text-sm text-zinc-500">تحويل الكميات بين فروع المتجر مع الاعتماد والإلغاء الآمن.</p>
            </div>

            <button
                type="button"
                wire:click="openCreate"
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white shadow-sm transition hover:bg-indigo-700"
            >
                <span class="text-lg leading-none">＋</span>
                تحويل جديد
            </button>
        </div>
    </div>

    {{-- Messages --}}
    @if ($successMessage)
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-800">
            {{ $successMessage }}
        </div>
    @endif

    @if ($errorMessage)
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-bold text-red-800">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- Summary --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
            <div class="text-sm font-bold text-zinc-500">إجمالي التحويلات</div>
            <div class="mt-2 text-3xl font-black text-zinc-900">{{ $transfers->total() }}</div>
        </div>
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
            <div class="text-sm font-bold text-amber-700">المعروضة في الصفحة</div>
            <div class="mt-2 text-3xl font-black text-amber-800">{{ $transfers->count() }}</div>
        </div>
        <div class="rounded-2xl border border-indigo-200 bg-indigo-50 p-5 shadow-sm">
            <div class="text-sm font-bold text-indigo-700">حالات التحويل</div>
            <div class="mt-2 text-sm font-black text-indigo-900">معلق ← اعتماد ← مكتمل / ملغى</div>
        </div>
    </div>

    {{-- Transfers list --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
        <div class="border-b border-zinc-200 p-4">
            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                <div class="md:col-span-2">
                    <label class="mb-1 block text-xs font-black text-zinc-600">بحث</label>
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="listSearch"
                        placeholder="بحث برقم التحويل أو الفرع..."
                        class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm font-bold outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                    >
                </div>
                <div>
                    <label class="mb-1 block text-xs font-black text-zinc-600">الحالة</label>
                    <select
                        wire:model.live="statusFilter"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm font-bold outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
                    >
                        <option value="">كل الحالات</option>
                        <option value="pending">معلق</option>
                        <option value="completed">مكتمل</option>
                        <option value="cancelled">ملغى</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-right text-sm">
                <thead class="bg-zinc-50 text-xs font-black text-zinc-600">
                    <tr>
                        <th class="px-4 py-3">رقم التحويل</th>
                        <th class="px-4 py-3">من</th>
                        <th class="px-4 py-3">إلى</th>
                        <th class="px-4 py-3">التاريخ</th>
                        <th class="px-4 py-3">المنشئ</th>
                        <th class="px-4 py-3">الحالة</th>
                        <th class="px-4 py-3">الإجراء</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100">
                    @forelse ($transfers as $transfer)
                        <tr wire:key="transfer-{{ $transfer->id }}" class="transition hover:bg-zinc-50">
                            <td class="px-4 py-4 font-black text-zinc-900">{{ $transfer->transfer_number }}</td>
                            <td class="px-4 py-4 font-bold text-zinc-700">{{ $transfer->fromBranch?->name ?? '—' }}</td>
                            <td class="px-4 py-4 font-bold text-zinc-700">{{ $transfer->toBranch?->name ?? '—' }}</td>
                            <td class="px-4 py-4 text-zinc-600">{{ optional($transfer->transfer_date)->format('Y-m-d H:i') }}</td>
                            <td class="px-4 py-4 text-zinc-600">{{ $transfer->creator?->name ?? '—' }}</td>
                            <td class="px-4 py-4">
                                @if ($transfer->status === 'pending')
                                    <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-800">معلق</span>
                                @elseif ($transfer->status === 'completed')
                                    <span class="inline-flex rounded-full bg-emerald-100 px-3 py-1 text-xs font-black text-emerald-800">مكتمل</span>
                                @else
                                    <span class="inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-black text-red-800">ملغى</span>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="showTransfer({{ $transfer->id }})" class="rounded-lg border border-zinc-300 px-3 py-2 text-xs font-black text-zinc-700 hover:bg-zinc-100">عرض</button>

                                    @if ($transfer->status === 'pending')
                                        <button type="button" wire:click="approve({{ $transfer->id }})" wire:confirm="هل تريد اعتماد التحويل ونقل المخزون؟" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-black text-white hover:bg-emerald-700">اعتماد</button>
                                        <button type="button" wire:click="askCancel({{ $transfer->id }})" class="rounded-lg bg-red-600 px-3 py-2 text-xs font-black text-white hover:bg-red-700">إلغاء</button>
                                    @elseif ($transfer->status === 'completed')
                                        <button type="button" wire:click="askCancel({{ $transfer->id }})" class="rounded-lg border border-red-300 px-3 py-2 text-xs font-black text-red-700 hover:bg-red-50">عكس التحويل</button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center">
                                <div class="text-lg font-black text-zinc-700">لا توجد تحويلات</div>
                                <div class="mt-2 text-sm text-zinc-500">اضغط «تحويل جديد» لإضافة أول تحويل.</div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($transfers->hasPages())
            <div class="border-t border-zinc-200 p-4">
                {{ $transfers->links() }}
            </div>
        @endif
    </div>

    {{-- Create modal --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" wire:keydown.escape="closeForm">
            <div class="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl" @click.stop>
                <div class="flex items-center justify-between border-b border-zinc-200 px-5 py-4">
                    <div>
                        <h2 class="text-xl font-black text-zinc-900">إنشاء تحويل مخزون</h2>
                        <p class="mt-1 text-xs text-zinc-500">اختر الفرع المستلم ثم أضف الأصناف والكميات.</p>
                    </div>
                    <button type="button" wire:click="closeForm" class="rounded-lg px-3 py-2 text-xl font-black text-zinc-500 hover:bg-zinc-100">×</button>
                </div>

                <div class="grid min-h-0 flex-1 grid-cols-1 overflow-y-auto lg:grid-cols-5">
                    <div class="space-y-5 border-b border-zinc-200 p-5 lg:col-span-3 lg:border-b-0 lg:border-l">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-black text-zinc-600">الفرع المصدر</label>
                                <select wire:model.live="fromBranchId" @disabled($userBranchId) class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-3 text-sm font-bold disabled:bg-zinc-100">
                                    <option value="">اختر الفرع</option>
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-black text-zinc-600">الفرع المستلم</label>
                                <select wire:model.live="toBranchId" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-3 text-sm font-bold">
                                    <option value="">اختر الفرع المستلم</option>
                                    @foreach ($destinationBranches as $branch)
                                        <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-black text-zinc-600">البحث عن صنف</label>
                            <input type="text" wire:model.live.debounce.250ms="productSearch" placeholder="اسم المنتج أو الباركود..." class="w-full rounded-xl border border-zinc-300 px-4 py-3 text-sm font-bold outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100">
                        </div>

                        <div class="overflow-hidden rounded-xl border border-zinc-200">
                            <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-3 text-sm font-black text-zinc-700">الأصناف المتاحة في الفرع المصدر</div>
                            <div class="max-h-72 overflow-y-auto divide-y divide-zinc-100">
                                @forelse ($products as $product)
                                    @php($branchProduct = $product->branchProducts->first())
                                    <button type="button" wire:click="addProduct({{ $product->id }})" class="flex w-full items-center justify-between gap-3 px-4 py-3 text-right hover:bg-indigo-50">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-black text-zinc-900">{{ $product->name }}</div>
                                            <div class="mt-1 text-xs text-zinc-500">الباركود: {{ $product->barcodes->first()?->barcode ?? '—' }}</div>
                                        </div>
                                        <div class="shrink-0 text-left">
                                            <div class="text-xs font-bold text-zinc-500">المتوفر</div>
                                            <div class="font-black text-indigo-700">{{ number_format((float) ($branchProduct?->stock_quantity ?? 0), 3) }}</div>
                                        </div>
                                    </button>
                                @empty
                                    <div class="px-4 py-10 text-center text-sm font-bold text-zinc-500">لا توجد أصناف مطابقة.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="space-y-5 bg-zinc-50 p-5 lg:col-span-2">
                        <div class="flex items-center justify-between">
                            <h3 class="font-black text-zinc-900">الأصناف في التحويل</h3>
                            <span class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-black text-indigo-700">{{ count($cart) }} صنف</span>
                        </div>

                        <div class="space-y-3">
                            @forelse ($cart as $item)
                                <div wire:key="cart-{{ $item['id'] }}" class="rounded-xl border border-zinc-200 bg-white p-3 shadow-sm">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-black text-zinc-900">{{ $item['name'] }}</div>
                                            <div class="mt-1 text-xs text-zinc-500">المتاح: {{ number_format((float) $item['source_stock'], 3) }}</div>
                                        </div>
                                        <button type="button" wire:click="removeProduct({{ $item['id'] }})" class="rounded-lg px-2 py-1 text-lg font-black text-red-600 hover:bg-red-50">×</button>
                                    </div>
                                    <div class="mt-3 flex items-center gap-2">
                                        <label class="text-xs font-black text-zinc-600">الكمية</label>
                                        <input type="number" min="0.001" step="0.001" value="{{ $item['quantity'] }}" wire:change="setQuantity({{ $item['id'] }}, $event.target.value)" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-center font-black outline-none focus:border-indigo-500">
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-xl border border-dashed border-zinc-300 bg-white px-4 py-10 text-center text-sm font-bold text-zinc-500">لم تتم إضافة أصناف بعد.</div>
                            @endforelse
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-black text-zinc-600">ملاحظات</label>
                            <textarea wire:model="notes" rows="4" class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-3 text-sm font-bold outline-none focus:border-indigo-500" placeholder="ملاحظات التحويل..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-zinc-200 bg-white p-4 sm:flex-row sm:justify-start">
                    <button type="button" wire:click="closeForm" class="rounded-xl border border-zinc-300 px-5 py-3 text-sm font-black text-zinc-700 hover:bg-zinc-50">إلغاء</button>
                    <button type="button" wire:click="saveTransfer" wire:loading.attr="disabled" class="rounded-xl bg-indigo-600 px-6 py-3 text-sm font-black text-white hover:bg-indigo-700 disabled:opacity-50">حفظ التحويل</button>
                </div>
            </div>
        </div>
    @endif

    {{-- View modal --}}
    @if ($viewTransfer)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="max-h-[90vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
                <div class="flex items-center justify-between border-b border-zinc-200 p-5">
                    <div>
                        <h2 class="text-xl font-black text-zinc-900">تفاصيل التحويل</h2>
                        <div class="mt-1 text-sm font-bold text-zinc-500">{{ $viewTransfer->transfer_number }}</div>
                    </div>
                    <button type="button" wire:click="closeView" class="rounded-lg px-3 py-2 text-xl font-black text-zinc-500 hover:bg-zinc-100">×</button>
                </div>
                <div class="grid grid-cols-1 gap-3 p-5 md:grid-cols-3">
                    <div class="rounded-xl bg-zinc-50 p-4"><div class="text-xs font-bold text-zinc-500">من</div><div class="mt-1 font-black">{{ $viewTransfer->fromBranch?->name }}</div></div>
                    <div class="rounded-xl bg-zinc-50 p-4"><div class="text-xs font-bold text-zinc-500">إلى</div><div class="mt-1 font-black">{{ $viewTransfer->toBranch?->name }}</div></div>
                    <div class="rounded-xl bg-zinc-50 p-4"><div class="text-xs font-bold text-zinc-500">التاريخ</div><div class="mt-1 font-black">{{ optional($viewTransfer->transfer_date)->format('Y-m-d H:i') }}</div></div>
                </div>
                <div class="px-5 pb-5">
                    <div class="overflow-hidden rounded-xl border border-zinc-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-zinc-50"><tr><th class="px-4 py-3 text-right font-black">الصنف</th><th class="px-4 py-3 text-right font-black">الكمية</th></tr></thead>
                            <tbody class="divide-y divide-zinc-100">
                                @foreach ($viewTransfer->items as $item)
                                    <tr><td class="px-4 py-3 font-bold">{{ $item->product?->name ?? '—' }}</td><td class="px-4 py-3 font-black">{{ number_format((float) $item->quantity, 3) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($viewTransfer->notes)
                        <div class="mt-4 rounded-xl bg-zinc-50 p-4 text-sm font-bold text-zinc-700"><span class="font-black">ملاحظات:</span> {{ $viewTransfer->notes }}</div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- Cancel confirmation --}}
    @if ($cancelTransferId)
        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <h2 class="text-lg font-black text-zinc-900">تأكيد الإلغاء</h2>
                <p class="mt-2 text-sm leading-6 text-zinc-600">سيتم إلغاء التحويل. إذا كان مكتملًا فسيتم عكس الكمية من الفرع المستلم إلى الفرع المصدر.</p>
                <div class="mt-5 flex gap-2">
                    <button type="button" wire:click="$set('cancelTransferId', null)" class="flex-1 rounded-xl border border-zinc-300 px-4 py-3 text-sm font-black">تراجع</button>
                    <button type="button" wire:click="cancel({{ $cancelTransferId }})" class="flex-1 rounded-xl bg-red-600 px-4 py-3 text-sm font-black text-white hover:bg-red-700">تأكيد الإلغاء</button>
                </div>
            </div>
        </div>
    @endif
</div>


</flux:main>
