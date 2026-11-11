<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductOffer;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public string $branchFilter = '';
    public string $statusFilter = '';
    public bool $showModal = false;
    public ?int $offerId = null;
    public ?int $productId = null;
    public ?int $branchId = null;
    public string $productSearch = '';
    public string $productName = '';
    public string $quantity = '';
    public string $price = '';
    public string $startAt = '';
    public string $endAt = '';
    public bool $hasEndDate = false;
    public bool $isActive = true;

    private function tenantId(): ?int
    {
        $tenantId = session('active_tenant_id');

        return $tenantId ? (int) $tenantId : null;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBranchFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedProductSearch(string $value): void
    {
        if (trim($value) !== $this->productName) {
            $this->productId = null;
            $this->productName = '';
        }
    }

    public function openCreateModal(): void
    {
        $this->authorize('products.price.edit');
        $this->resetForm();
        $this->branchId = auth()->user()?->activeBranchId()
            ?: (int) Branch::query()->where('tenant_id', $this->tenantId())->value('id');
        $this->loadOfferRetailPrice();
        $this->showModal = true;
    }

    public function selectProduct(int $productId): void
    {
        $product = Product::query()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($productId);

        $this->productId = $product->id;
        $this->productName = $product->name;
        $this->productSearch = $product->name;
        $this->loadOfferRetailPrice();
    }

    public function edit(int $offerId): void
    {
        $this->authorize('products.price.edit');
        $offer = ProductOffer::query()
            ->where('tenant_id', $this->tenantId())
            ->with('product:id,name')
            ->findOrFail($offerId);

        $this->offerId = $offer->id;
        $this->productId = $offer->product_id;
        $this->productName = $offer->product?->name ?? '';
        $this->productSearch = $this->productName;
        $this->branchId = $offer->branch_id;
        $this->quantity = (string) $offer->offer_quantity;
        $this->price = (string) $offer->offer_price;
        $this->startAt = $offer->start_at?->format('Y-m-d\\TH:i') ?? '';
        $this->hasEndDate = $offer->end_at !== null;
        $this->endAt = $offer->end_at?->format('Y-m-d\\TH:i') ?? '';
        $this->isActive = $offer->is_active;
        $this->resetValidation();
        $this->loadOfferRetailPrice();
        $this->showModal = true;
    }

    public bool $updateOfferRetailPrice = false;
    public string $offerRetailPrice = '';

    private function loadOfferRetailPrice(): void
    {
        $this->updateOfferRetailPrice = true;
        $this->offerRetailPrice = (string) (\App\Models\BranchProduct::query()
            ->where('tenant_id', $this->tenantId())->where('branch_id', $this->branchId)
            ->where('product_id', $this->productId)->value('retail_price') ?? '0.00');
    }

    public function updatedBranchId(): void
    {
        $this->loadOfferRetailPrice();
    }

    public function save(): void
    {
        $this->authorize('products.price.edit');
        $tenantId = $this->tenantId();

        $this->validate([
            'productId' => ['required', 'integer', Rule::exists('products', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId))],
            'offerRetailPrice' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'startAt' => ['required', 'date'],
            'endAt' => [$this->hasEndDate ? 'required' : 'nullable', 'date', 'after_or_equal:startAt'],
            'isActive' => ['boolean'],
        ], [], [
            'productId' => 'الصنف',
            'branchId' => 'الفرع',
            'quantity' => 'كمية العرض',
            'price' => 'سعر العرض',
            'startAt' => 'بداية العرض',
            'endAt' => 'نهاية العرض',
        ]);

        $startAt = Carbon::parse($this->startAt);
        $endAt = $this->hasEndDate ? Carbon::parse($this->endAt) : null;
        $overlaps = ProductOffer::query()
            ->where('tenant_id', $tenantId)
            ->where('branch_id', $this->branchId)
            ->where('product_id', $this->productId)
            ->where('is_active', true)
            ->when($this->offerId, fn ($query) => $query->where('id', '!=', $this->offerId))
            ->where('start_at', '<=', $endAt ?? now()->addYears(100))
            ->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>=', $startAt))
            ->exists();

        if ($this->isActive && $overlaps) {
            $this->addError('startAt', 'يوجد عرض آخر متداخل لنفس الصنف والفرع في هذه الفترة.');

            return;
        }

        $data = [
            'branch_id' => $this->branchId,
            'product_id' => $this->productId,
            'offer_quantity' => $this->quantity,
            'offer_price' => $this->price,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'is_active' => $this->isActive,
        ];

        \Illuminate\Support\Facades\DB::transaction(function () use ($tenantId, $data): void {
            if ($this->offerId) {
                ProductOffer::query()->where('tenant_id', $tenantId)->whereKey($this->offerId)->update($data);
                session()->flash('message', 'تم تعديل العرض بنجاح.');
            } else {
                ProductOffer::query()->create($data + ['tenant_id' => $tenantId, 'created_by' => auth()->id()]);
                session()->flash('message', 'تمت إضافة العرض بنجاح.');
            }

            \App\Models\BranchProduct::query()->updateOrCreate([
                'tenant_id' => $tenantId, 'branch_id' => $this->branchId, 'product_id' => $this->productId,
            ], ['retail_price' => $this->offerRetailPrice]);
            Product::query()->where('tenant_id', $tenantId)->whereKey($this->productId)->update(['is_price_unified' => false]);
        });

        $this->closeModal();
    }

    public function delete(int $offerId): void
    {
        $this->authorize('products.price.edit');
        ProductOffer::query()->where('tenant_id', $this->tenantId())->findOrFail($offerId)->delete();
        session()->flash('message', 'تم حذف العرض.');
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['offerId', 'productId', 'branchId', 'productSearch', 'productName', 'quantity', 'price', 'startAt', 'endAt']);
        $this->hasEndDate = false;
        $this->isActive = true;
        $this->resetValidation();
    }

    public function render()
    {
        $tenantId = $this->tenantId();
        $offers = ProductOffer::query()
            ->where('tenant_id', $tenantId)
            ->with(['product:id,name,product_number', 'branch:id,name'])
            ->when($this->branchFilter !== '', fn ($query) => $query->where('branch_id', $this->branchFilter))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->when(trim($this->search) !== '', function ($query): void {
                $search = trim($this->search);
                $query->whereHas('product', fn ($productQuery) => $productQuery->where('name', 'like', "%{$search}%")->orWhere('product_number', 'like', "%{$search}%"));
            })
            ->orderByDesc('created_at')
            ->paginate(20);

        $branches = Branch::query()->where('tenant_id', $tenantId)->orderBy('name')->get(['id', 'name']);
        $productResults = $this->showModal && trim($this->productSearch) !== '' && !$this->productId
            ? Product::query()->where('tenant_id', $tenantId)->where(function ($query): void {
                $search = trim($this->productSearch);
                $query->where('name', 'like', "%{$search}%")->orWhere('product_number', 'like', "%{$search}%");
            })->orderBy('name')->limit(12)->get(['id', 'name', 'product_number'])
            : collect();

        return $this->view(compact('offers', 'branches', 'productResults'))->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="min-h-[calc(100vh-4rem)] space-y-5 bg-slate-100 p-3 sm:p-4 lg:p-6">
    <div class="mx-auto max-w-[1500px] space-y-5">
        @if (session()->has('message'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">{{ session('message') }}</div>
        @endif

        <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 bg-slate-950 p-5 text-white sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-xl font-black">العروض الترويجية</h1>
                    <p class="mt-1 text-sm text-slate-300">عرض وإدارة عروض الكمية حسب الصنف والفرع.</p>
                </div>
                @can('products.price.edit')
                    <button type="button" wire:click="openCreateModal" class="rounded-xl bg-emerald-500 px-4 py-2.5 text-sm font-black text-white hover:bg-emerald-400">+ إضافة عرض</button>
                @endcan
            </div>

            <div class="grid gap-3 border-b border-slate-100 p-4 md:grid-cols-3">
                <input type="search" wire:model.live.debounce.250ms="search" placeholder="ابحث باسم الصنف أو رقمه..." class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm outline-none focus:border-indigo-400" />
                <select wire:model.live="branchFilter" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><option value="">كل الفروع</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>
                <select wire:model.live="statusFilter" class="rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><option value="">كل الحالات</option><option value="active">مفعّل</option><option value="inactive">معطّل</option></select>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[850px] text-right text-sm">
                    <thead class="bg-slate-900 text-xs text-white"><tr><th class="p-3">الصنف</th><th class="p-3">الفرع</th><th class="p-3">الكمية</th><th class="p-3">سعر العرض</th><th class="p-3">الفترة</th><th class="p-3">الحالة</th><th class="p-3">الإجراءات</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($offers as $offer)
                            <tr wire:key="offer-{{ $offer->id }}" class="hover:bg-slate-50">
                                <td class="p-3"><div class="font-bold text-slate-900">{{ $offer->product?->name ?? 'صنف محذوف' }}</div><div class="font-mono text-xs text-slate-400">{{ $offer->product?->product_number }}</div></td>
                                <td class="p-3">{{ $offer->branch?->name }}</td>
                                <td class="p-3">{{ number_format((float) $offer->offer_quantity, 3) }}</td>
                                <td class="p-3 font-black text-amber-700">{{ number_format((float) $offer->offer_price, 2) }}</td>
                                <td class="p-3 text-xs text-slate-600">{{ $offer->start_at?->format('Y-m-d H:i') ?? '—' }}<br>إلى {{ $offer->end_at?->format('Y-m-d H:i') ?? 'مفتوح' }}</td>
                                <td class="p-3"><span class="rounded-full px-2.5 py-1 text-xs font-bold {{ $offer->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">{{ $offer->is_active ? 'مفعّل' : 'معطّل' }}</span></td>
                                <td class="p-3"><div class="flex items-center gap-2">@can('products.price.edit')<button type="button" wire:click="edit({{ $offer->id }})" class="rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700">تعديل</button><button type="button" wire:click="delete({{ $offer->id }})" wire:confirm="هل تريد حذف هذا العرض؟" class="rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700">حذف</button>@endcan</div></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-12 text-center font-semibold text-slate-400">لا توجد عروض مطابقة.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4">{{ $offers->links() }}</div>
        </section>
    </div>

    <flux:modal wire:model="showModal" class="w-full max-w-xl">
        <div class="space-y-4">
            <div><flux:heading size="lg">{{ $offerId ? 'تعديل العرض' : 'إضافة عرض' }}</flux:heading><flux:subheading>حدد الصنف والفرع والكمية والسعر ومدة العرض.</flux:subheading></div>
            <form wire:submit="save" data-arrow-nav class="space-y-4">
                <flux:input type="number" min="0" max="999999999" step="0.01" wire:model="offerRetailPrice" label="سعر التجزئة للفرع المحدد" />
                <flux:error name="offerRetailPrice" />
                <div class="relative">
                    <label class="mb-1 block text-sm font-bold">الصنف</label>
                    <input type="search" wire:model.live.debounce.200ms="productSearch" @disabled($offerId) placeholder="ابحث باسم الصنف أو رقمه..." class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm" />
                    @error('productId')<div class="mt-1 text-xs text-rose-600">{{ $message }}</div>@enderror
                    @if ($productResults->isNotEmpty())
                        <div class="absolute z-30 mt-1 max-h-56 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl">
                            @foreach ($productResults as $product)
                                <button type="button" data-arrow-nav-item wire:click="selectProduct({{ $product->id }})" class="block w-full border-b border-slate-100 p-3 text-right hover:bg-indigo-50"><span class="font-bold">{{ $product->name }}</span><span class="mr-2 font-mono text-xs text-slate-400">{{ $product->product_number }}</span></button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div><label class="mb-1 block text-sm font-bold">الفرع</label><select wire:model.live="branchId" class="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"><option value="">اختر الفرع</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select>@error('branchId')<div class="mt-1 text-xs text-rose-600">{{ $message }}</div>@enderror</div>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-bold">كمية العرض</label><input type="number" min="0.01" step="0.001" wire:model="quantity" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" />@error('quantity')<div class="mt-1 text-xs text-rose-600">{{ $message }}</div>@enderror</div><div><label class="mb-1 block text-sm font-bold">سعر العرض</label><input type="number" min="0" step="0.01" wire:model="price" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" />@error('price')<div class="mt-1 text-xs text-rose-600">{{ $message }}</div>@enderror</div></div>
                <div class="grid gap-3 sm:grid-cols-2"><div><label class="mb-1 block text-sm font-bold">بداية العرض</label><input type="datetime-local" wire:model="startAt" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" />@error('startAt')<div class="mt-1 text-xs text-rose-600">{{ $message }}</div>@enderror</div><div><label class="mb-1 block text-sm font-bold">نهاية العرض</label><label class="mb-2 flex items-center gap-2 text-xs"><input type="checkbox" wire:model.live="hasEndDate" />له تاريخ انتهاء</label>@if ($hasEndDate)<input type="datetime-local" wire:model="endAt" class="w-full rounded-xl border border-slate-200 px-3 py-2.5" />@error('endAt')<div class="mt-1 text-xs text-rose-600">{{ $message }}</div>@enderror@else<div class="rounded-lg bg-emerald-50 p-2 text-xs text-emerald-700">العرض بدون تاريخ انتهاء</div>@endif</div></div>
                <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" wire:model="isActive" />العرض مفعّل</label>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" wire:click="closeModal" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold">إلغاء</button><button type="submit" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white">{{ $offerId ? 'حفظ التعديل' : 'إضافة العرض' }}</button></div>
            </form>
        </div>
    </flux:modal>
</flux:main>
