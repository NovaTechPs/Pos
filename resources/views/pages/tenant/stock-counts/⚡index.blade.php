<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Services\PosProductSearch;
use App\Services\StockMovementContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    /** The count on screen: an open draft being counted, or an approved one being reviewed. */
    #[Url(as: 'count')]
    public ?int $countId = null;

    public string $scanInput = '';

    public string $notes = '';

    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->authorize('products.stock.adjust');
        if ($this->countId && ! $this->countQuery()->whereKey($this->countId)->exists()) {
            $this->countId = null;
        }
        $this->countId ??= $this->countQuery()->where('status', 'draft')->latest('id')->value('id');
        $this->notes = (string) ($this->currentCount()?->notes ?? '');
    }

    private function tenantId(): int
    {
        $tenantId = (int) auth()->user()?->tenant_id;
        abort_unless($tenantId > 0, 403);

        return $tenantId;
    }

    private function branchId(): int
    {
        $branchId = (int) auth()->user()?->activeBranchId();
        abort_unless($branchId > 0 && Branch::withoutGlobalScopes()->where('tenant_id', $this->tenantId())->whereKey($branchId)->exists(), 422, 'اختر الفرع أولاً.');

        return $branchId;
    }

    /** Counts of the active branch only. */
    private function countQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return StockCount::query()->where('tenant_id', $this->tenantId())->where('branch_id', $this->branchId());
    }

    private function currentCount(): ?StockCount
    {
        return $this->countId ? $this->countQuery()->find($this->countId) : null;
    }

    private function draftOrFail(): StockCount
    {
        $count = $this->currentCount();
        if (! $count || $count->isApproved()) {
            throw ValidationException::withMessages(['count' => 'هذا الجرد معتمد ولا يمكن تعديله.']);
        }

        return $count;
    }

    public function startCount(): void
    {
        $this->authorize('products.stock.adjust');
        $draft = $this->countQuery()->where('status', 'draft')->latest('id')->first();
        $draft ??= StockCount::query()->create([
            'tenant_id' => $this->tenantId(), 'branch_id' => $this->branchId(), 'status' => 'draft', 'created_by' => auth()->id(),
            'count_number' => 'CNT-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
        ]);
        $this->countId = $draft->id;
        $this->notes = (string) $draft->notes;
        $this->resetMessages();
        $this->dispatch('stock-count-focus-scan');
    }

    public function openCount(int $countId): void
    {
        $this->countId = $this->countQuery()->findOrFail($countId)->id;
        $this->notes = (string) $this->currentCount()?->notes;
        $this->resetMessages();
    }

    public function closeCount(): void
    {
        $this->countId = null;
        $this->notes = '';
        $this->resetMessages();
    }

    /**
     * Adds one piece of the scanned product to the count; "6*barcode" adds six. Returns false when
     * nothing matched, so the typed text stays as a name search.
     */
    public function scanBarcode(string $value): bool
    {
        $this->authorize('products.stock.adjust');
        $this->resetMessages();
        $value = trim($value);
        $quantity = 1.0;
        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*\*\s*(.+)$/u', $value, $match)) {
            $quantity = (float) str_replace(',', '.', $match[1]);
            $value = trim($match[2]);
        }
        if ($value === '' || mb_strlen($value) > 100 || $quantity <= 0) {
            return false;
        }

        $tenantId = $this->tenantId();
        $productId = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('product_number', $value)
                ->orWhereHas('barcodes', fn ($barcodes) => $barcodes->where('tenant_id', $tenantId)->where('barcode', $value)))
            ->value('id');
        if (! $productId) {
            return false;
        }

        $this->addProduct((int) $productId, $quantity);

        return true;
    }

    public function addProduct(int $productId, float $quantity = 1): void
    {
        $this->authorize('products.stock.adjust');
        $count = $this->draftOrFail();
        $product = Product::withoutGlobalScopes()->where('tenant_id', $this->tenantId())->whereNull('deleted_at')->findOrFail($productId);

        $item = StockCountItem::query()->firstOrNew(['stock_count_id' => $count->id, 'product_id' => $product->id], ['tenant_id' => $this->tenantId(), 'counted_quantity' => 0]);
        $item->counted_quantity = round((float) $item->counted_quantity + $quantity, 3);
        $item->save();
        $count->touch();

        $this->scanInput = '';
        $this->successMessage = $product->name.' — المعدود: '.$this->formatQuantity((float) $item->counted_quantity);
        $this->dispatch('stock-count-focus-scan');
    }

    public function setCounted(int $itemId, mixed $quantity): void
    {
        $this->authorize('products.stock.adjust');
        $count = $this->draftOrFail();
        $quantity = (float) str_replace(',', '.', trim((string) $quantity));
        if ($quantity < 0 || $quantity > 999999999) {
            $this->errorMessage = 'الكمية المعدودة غير صحيحة.';

            return;
        }
        $count->items()->whereKey($itemId)->firstOrFail()->update(['counted_quantity' => round($quantity, 3)]);
        $this->resetMessages();
    }

    public function removeItem(int $itemId): void
    {
        $this->authorize('products.stock.adjust');
        $this->draftOrFail()->items()->whereKey($itemId)->delete();
    }

    public function updatedNotes(): void
    {
        $this->draftOrFail()->update(['notes' => trim($this->notes) ?: null]);
    }

    public function deleteDraft(): void
    {
        $this->authorize('products.stock.adjust');
        $this->draftOrFail()->delete();
        $this->closeCount();
        $this->successMessage = 'تم حذف مسودة الجرد.';
    }

    /**
     * Sets each counted product's branch stock to the counted quantity and keeps the system quantity
     * it replaced. Products that were not counted keep their stock.
     */
    public function approve(): void
    {
        $this->authorize('products.stock.adjust');
        $count = $this->draftOrFail();
        if (! $count->items()->exists()) {
            $this->errorMessage = 'أضف الأصناف المعدودة قبل اعتماد الجرد.';

            return;
        }

        DB::transaction(function () use ($count): void {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            abort_if($count->isApproved(), 422, 'تم اعتماد هذا الجرد مسبقاً.');
            $items = $count->items()->with('product:id,cost_price')->get();
            $stocks = BranchProduct::query()->where('tenant_id', $count->tenant_id)->where('branch_id', $count->branch_id)
                ->whereIn('product_id', $items->pluck('product_id'))->lockForUpdate()->get()->keyBy('product_id');

            foreach ($items as $item) {
                $stock = $stocks->get($item->product_id) ?? BranchProduct::query()->create([
                    'tenant_id' => $count->tenant_id, 'branch_id' => $count->branch_id, 'product_id' => $item->product_id, 'stock_quantity' => 0,
                ]);
                $systemQuantity = (float) $stock->stock_quantity;
                $difference = round((float) $item->counted_quantity - $systemQuantity, 3);
                $item->update(['system_quantity' => $systemQuantity, 'unit_cost' => (float) ($item->product?->cost_price ?? 0)]);
                if (abs($difference) >= 0.0005) {
                    StockMovementContext::reference('stock_count', $count->id, fn () => $stock->increment('stock_quantity', $difference), $count->count_number);
                }
            }

            $count->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now(), 'notes' => trim($this->notes) ?: null]);
        });

        $this->resetMessages();
        $this->successMessage = 'تم اعتماد الجرد وتعديل أرصدة الأصناف المعدودة.';
    }

    private function resetMessages(): void
    {
        $this->successMessage = null;
        $this->errorMessage = null;
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 3, '.', ''), '0'), '.');
    }

    /** @return Collection<int, Product> */
    private function searchResults(): Collection
    {
        $search = app(PosProductSearch::class)->normalize(trim($this->scanInput));
        if (mb_strlen($search) < 2 || preg_match('/^\d+(?:[.,]\d+)?\s*\*/u', trim($this->scanInput))) {
            return collect();
        }
        $words = array_slice(array_filter(explode(' ', $search)), 0, 8);
        $tenantId = $this->tenantId();

        return Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where(fn ($query) => $query->where(function ($names) use ($words): void {
                foreach ($words as $word) {
                    $names->where(fn ($wordQuery) => $wordQuery->where('search_name', 'like', '%'.$word.'%')->orWhere('name', 'like', '%'.$word.'%'));
                }
            })->orWhere('product_number', 'like', '%'.$search.'%'))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'product_number']);
    }

    public function render(): mixed
    {
        $this->authorize('products.stock.adjust');
        $count = $this->currentCount();
        $lines = collect();
        if ($count) {
            $current = BranchProduct::query()->where('tenant_id', $count->tenant_id)->where('branch_id', $count->branch_id)
                ->whereIn('product_id', $count->items()->select('product_id'))->pluck('stock_quantity', 'product_id');
            $lines = $count->items()->with('product:id,name,product_number,cost_price')->latest('updated_at')->latest('id')->get()
                ->map(function (StockCountItem $item) use ($count, $current): array {
                    $system = $count->isApproved() ? (float) $item->system_quantity : (float) ($current[$item->product_id] ?? 0);
                    $cost = $count->isApproved() ? (float) $item->unit_cost : (float) ($item->product?->cost_price ?? 0);
                    $difference = round((float) $item->counted_quantity - $system, 3);

                    return ['id' => $item->id, 'name' => $item->product?->name ?? '—', 'number' => $item->product?->product_number,
                        'counted' => (float) $item->counted_quantity, 'system' => $system, 'difference' => $difference, 'value' => round($difference * $cost, 2)];
                });
        }
        $recent = $count ? collect() : $this->countQuery()->withCount('items')->with('creator:id,name')->latest('id')->limit(20)->get();

        return $this->view([
            'count' => $count,
            'lines' => $lines,
            'results' => $count && ! $count->isApproved() ? $this->searchResults() : collect(),
            'recent' => $recent,
            'shortage' => $lines->where('difference', '<', 0),
            'surplus' => $lines->where('difference', '>', 0),
        ])->layout('layouts::tenant');
    }
};
?>

@php
    $quantity = fn (float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
@endphp

<flux:main dir="rtl" class="mx-auto max-w-4xl space-y-4" data-stock-count-page>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">الجرد بالجوال</flux:heading>
            <flux:text class="mt-1">امسح الأصناف أو ابحث عنها وعدّها، ثم اعتمد الجرد ليصحّح أرصدة الأصناف المعدودة فقط.</flux:text>
        </div>
        @if ($count)
            <flux:button size="sm" variant="ghost" icon="arrow-right" wire:click="closeCount">كل الجرود</flux:button>
        @endif
    </div>

    @if ($successMessage)<div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-bold text-emerald-800">{{ $successMessage }}</div>@endif
    @if ($errorMessage)<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">{{ $errorMessage }}</div>@endif
    @error('count')<div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-bold text-rose-800">{{ $message }}</div>@enderror

    @if (! $count)

        <button type="button" wire:click="startCount" class="flex w-full items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-5 py-4 text-base font-black text-white shadow-sm hover:bg-indigo-500">
            <flux:icon name="clipboard-document-check" class="size-5" /> بدء جرد جديد
        </button>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
            <div class="border-b border-slate-100 p-3 text-sm font-black dark:border-slate-800">آخر الجرود في هذا الفرع</div>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($recent as $previous)
                    <button type="button" wire:key="count-{{ $previous->id }}" wire:click="openCount({{ $previous->id }})" class="flex w-full items-center justify-between gap-3 p-3 text-start hover:bg-slate-50 dark:hover:bg-slate-800">
                        <div>
                            <div class="font-mono text-xs font-bold">{{ $previous->count_number }}</div>
                            <div class="mt-0.5 text-xs text-slate-500">{{ $previous->created_at->format('Y-m-d H:i') }} · {{ $previous->items_count }} صنف · {{ $previous->creator?->name }}</div>
                        </div>
                        <span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-emerald-100 text-emerald-800' => $previous->isApproved(), 'bg-amber-100 text-amber-800' => ! $previous->isApproved()])>
                            {{ $previous->isApproved() ? 'معتمد' : 'مسودة' }}
                        </span>
                    </button>
                @empty
                    <p class="p-6 text-center text-sm text-slate-500">لا توجد جرود بعد.</p>
                @endforelse
            </div>
        </section>

    @else

        <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">
            <span class="font-mono text-xs font-bold">{{ $count->count_number }}</span>
            <span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-emerald-100 text-emerald-800' => $count->isApproved(), 'bg-amber-100 text-amber-800' => ! $count->isApproved()])>
                {{ $count->isApproved() ? 'معتمد '.$count->approved_at?->format('Y-m-d H:i') : 'مسودة — العدّ محفوظ تلقائياً' }}
            </span>
        </div>

        @if (! $count->isApproved())
            <section class="sticky top-0 z-10 space-y-2 rounded-2xl border border-indigo-200 bg-white p-3 shadow-sm dark:border-indigo-900 dark:bg-slate-900"
                x-data="posBarcodeCamera()" x-on:stock-count-focus-scan.window="$nextTick(() => $refs.scan?.focus())">
                <div class="flex items-center gap-2">
                    <button type="button" x-on:click="start()" aria-label="مسح الباركود بالكاميرا" title="مسح الباركود بالكاميرا" data-stock-count-camera
                        class="flex size-12 shrink-0 items-center justify-center rounded-xl border border-indigo-200 bg-indigo-50 text-indigo-700">
                        <flux:icon.camera class="size-6" />
                    </button>
                    <input x-ref="scan" type="text" autocomplete="off" wire:model.live.debounce.250ms="scanInput"
                        x-on:keydown.enter.prevent="$wire.scanBarcode($el.value).then((found) => { if (!found && $el.value.trim() !== '') $wire.set('errorMessage', 'لا يوجد صنف بهذا الباركود. اختر من نتائج البحث.') })"
                        placeholder="امسح الباركود أو اكتب اسم الصنف · 6* للكمية"
                        class="min-w-0 flex-1 rounded-xl border-2 border-indigo-200 px-3 py-3 text-sm font-bold outline-none focus:border-indigo-500 dark:border-slate-700 dark:bg-slate-800">
                </div>
                <x-barcode-camera-dialog hint="وجّه الكاميرا إلى الباركود؛ تُضاف قطعة واحدة لكل مسح." />

                @if ($results->isNotEmpty())
                    <div class="divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                        @foreach ($results as $product)
                            <button type="button" wire:key="result-{{ $product->id }}" wire:click="addProduct({{ $product->id }})" class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-start text-sm hover:bg-indigo-50 dark:hover:bg-slate-800">
                                <span class="font-bold">{{ $product->name }}</span>
                                <span class="shrink-0 font-mono text-xs text-slate-500">{{ $product->product_number }} · +1</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        <div class="grid grid-cols-3 gap-2 text-center">
            <div class="rounded-xl border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-900"><div class="text-[11px] text-slate-500">أصناف معدودة</div><div class="text-lg font-black">{{ $lines->count() }}</div></div>
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-2 dark:border-rose-900 dark:bg-rose-950/30"><div class="text-[11px] text-rose-700">عجز</div><div class="text-lg font-black text-rose-700">{{ $shortage->count() }} · {{ number_format(abs($shortage->sum('value')), 2) }}</div></div>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-2 dark:border-emerald-900 dark:bg-emerald-950/30"><div class="text-[11px] text-emerald-700">زيادة</div><div class="text-lg font-black text-emerald-700">{{ $surplus->count() }} · {{ number_format($surplus->sum('value'), 2) }}</div></div>
        </div>

        <section class="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:divide-slate-800 dark:border-slate-700 dark:bg-slate-900">
            @forelse ($lines as $line)
                <div wire:key="line-{{ $line['id'] }}" class="flex items-center gap-3 p-3" data-stock-count-line>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-bold">{{ $line['name'] }}</div>
                        <div class="mt-0.5 text-xs text-slate-500">
                            النظام: {{ $quantity($line['system']) }}
                            · الفرق:
                            <span @class(['font-bold', 'text-rose-600' => $line['difference'] < 0, 'text-emerald-600' => $line['difference'] > 0])>{{ $line['difference'] > 0 ? '+' : '' }}{{ $quantity($line['difference']) }}</span>
                        </div>
                    </div>
                    @if ($count->isApproved())
                        <div class="font-mono text-base font-black">{{ $quantity($line['counted']) }}</div>
                    @else
                        <input type="number" min="0" step="0.001" inputmode="decimal" value="{{ $quantity($line['counted']) }}" aria-label="الكمية المعدودة"
                            wire:change="setCounted({{ $line['id'] }}, $event.target.value)" x-on:focus="$event.target.select()"
                            class="w-24 rounded-lg border border-slate-300 px-2 py-2 text-center font-mono text-base font-black outline-none focus:border-indigo-500 dark:border-slate-700 dark:bg-slate-800">
                        <button type="button" wire:click="removeItem({{ $line['id'] }})" aria-label="حذف الصنف من الجرد" class="rounded-lg bg-rose-50 px-2.5 py-2 text-sm font-black text-rose-700">×</button>
                    @endif
                </div>
            @empty
                <p class="p-8 text-center text-sm text-slate-500">امسح أول صنف لبدء العدّ.</p>
            @endforelse
        </section>

        @if (! $count->isApproved())
            <flux:textarea wire:model.live.debounce.600ms="notes" rows="2" placeholder="ملاحظات الجرد (اختياري)" />
            <div class="flex flex-col gap-2 sm:flex-row sm:justify-between">
                <flux:button variant="ghost" class="text-rose-600" wire:click="deleteDraft" wire:confirm="حذف مسودة الجرد وكل ما عُدّ فيها؟">حذف المسودة</flux:button>
                <flux:button variant="primary" icon="check-badge" wire:click="approve" wire:loading.attr="disabled" wire:target="approve"
                    wire:confirm="سيُصبح رصيد كل صنف معدود مساوياً للكمية المعدودة، وتُسجّل الفروقات كحركات جرد. الأصناف غير المعدودة لا تتغير. متابعة؟">
                    اعتماد الجرد وتصحيح الأرصدة
                </flux:button>
            </div>
        @elseif ($count->notes)
            <p class="rounded-xl bg-slate-50 p-3 text-sm text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $count->notes }}</p>
        @endif

    @endif

</flux:main>
