<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockVoucher;
use App\Models\StockVoucherItem;
use App\Services\StockMovementContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;

new class extends Component
{
    public string $type = 'in';
    public ?int $branchId = null;
    public string $notes = '';
    public string $productSearch = '';
    /** @var list<array{product_id: string, quantity: float|int|string}> */
    public array $lines = [['product_id' => '', 'quantity' => 1]];
    public ?string $successMessage = null;
    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->authorize('products.stock.adjust');
        $this->branchId = auth()->user()?->activeBranchId();
    }

    public function addLine(): void
    {
        $this->lines[] = ['product_id' => '', 'quantity' => 1];
    }

    public function addProduct(int $productId): void
    {
        $this->authorize('products.stock.adjust');
        $tenantId = auth()->user()?->tenant_id;

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->find($productId);

        if (! $product) {
            $this->errorMessage = 'الصنف المحدد غير متاح.';

            return;
        }

        foreach ($this->lines as $line) {
            if ((int) ($line['product_id'] ?? 0) === $product->id) {
                $this->errorMessage = 'الصنف موجود بالفعل في السند. عدّل كميته من الجدول.';

                return;
            }
        }

        $emptyIndex = collect($this->lines)->search(fn (array $line): bool => blank($line['product_id']));

        if ($emptyIndex === false) {
            $this->lines[] = ['product_id' => (string) $product->id, 'quantity' => 1];
        } else {
            $this->lines[$emptyIndex]['product_id'] = (string) $product->id;
            $this->lines[$emptyIndex]['quantity'] = 1;
        }

        $this->productSearch = '';
        $this->errorMessage = null;
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines ?: [['product_id' => '', 'quantity' => 1]]);
    }

    public function save(): void
    {
        $this->authorize('products.stock.adjust');
        $this->successMessage = null;
        $this->errorMessage = null;
        $this->validate([
            'type' => ['required', 'in:in,out'],
            'branchId' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999'],
        ]);

        $user = Auth::user();
        $tenantId = $user?->tenant_id;
        abort_unless($tenantId && $user, 403);
        abort_unless(Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($this->branchId)->exists(), 404);
        $productIds = array_map(fn (array $line): int => (int) $line['product_id'], $this->lines);
        abort_unless(Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('id', $productIds)->count() === count($productIds), 404);

        $voucher = strtoupper($this->type === 'in' ? 'SIN' : 'SOUT').'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(5));
        $sourceType = $this->type === 'in' ? 'stock_in' : 'stock_out';

        DB::transaction(function () use ($tenantId, $user, $voucher, $sourceType): void {
            $stocks = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $this->branchId)
                ->whereIn('product_id', array_map(fn (array $line): int => (int) $line['product_id'], $this->lines))->lockForUpdate()->get()->keyBy('product_id');

            foreach ($this->lines as $line) {
                $productId = (int) $line['product_id'];
                $quantity = (float) $line['quantity'];
                $stock = $stocks->get($productId);

                if (! $stock && $this->type === 'out') {
                    throw \Illuminate\Validation\ValidationException::withMessages(['lines' => ['لا يمكن إخراج صنف غير موجود في مخزون الفرع.']]);
                }
                if (! $stock) {
                    $stock = BranchProduct::create([
                        'tenant_id' => $tenantId, 'branch_id' => $this->branchId, 'product_id' => $productId,
                        'stock_quantity' => 0, 'created_by' => $user->id,
                    ]);
                    $stocks->put($productId, $stock);
                }
                if ($this->type === 'out' && (float) $stock->stock_quantity + .0005 < $quantity) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['lines' => ['كمية الإخراج أكبر من المتبقي للصنف المحدد.']]);
                }

                $stockVoucher ??= StockVoucher::create([
                    'tenant_id' => $tenantId, 'branch_id' => $this->branchId, 'voucher_number' => $voucher,
                    'type' => $this->type, 'notes' => trim($this->notes) ?: null, 'created_by' => $user->id,
                ]);
                StockVoucherItem::create([
                    'tenant_id' => $tenantId, 'stock_voucher_id' => $stockVoucher->id,
                    'product_id' => $productId, 'quantity' => $quantity,
                ]);
                StockMovementContext::reference($sourceType, $stockVoucher->id, function () use ($stock, $quantity): void {
                    $this->type === 'in'
                        ? $stock->increment('stock_quantity', $quantity)
                        : $stock->decrement('stock_quantity', $quantity);
                }, $voucher);
            }
        });

        $this->successMessage = 'تم حفظ سند '.($this->type === 'in' ? 'الإدخال' : 'الإخراج').' بنجاح: '.$voucher;
        $this->notes = '';
        $this->lines = [['product_id' => '', 'quantity' => 1]];
        $this->resetValidation();
    }

    public function render(): mixed
    {
        $this->authorize('products.stock.adjust');
        $tenantId = auth()->user()?->tenant_id;
        abort_unless($tenantId, 403);
        $user = auth()->user();
        $branches = Branch::withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->when($user?->branch_id && ! $user->isTenantOwner() && ! $user->isSaaSAdmin(), fn ($query) => $query->whereKey($user->branch_id))
            ->orderBy('name')->get(['id', 'name']);
        $search = trim($this->productSearch);
        $products = Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->when($search !== '', fn ($query) => $query->where(fn ($productQuery) => $productQuery
                ->where('name', 'like', "%{$search}%")
                ->orWhere('product_number', 'like', "%{$search}%")
                ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery
                    ->where('tenant_id', $tenantId)
                    ->where('barcode', 'like', "%{$search}%"))))
            ->with(['branchProducts' => fn ($query) => $query
                ->where('tenant_id', $tenantId)
                ->when($this->branchId, fn ($branchQuery) => $branchQuery->where('branch_id', $this->branchId))])
            ->orderBy('name')->limit($search === '' ? 30 : 50)->get(['id', 'name', 'product_number']);
        $recent = StockMovement::query()->where('tenant_id', $tenantId)->whereIn('source_type', ['stock_in', 'stock_out'])->when($this->branchId, fn ($query) => $query->where('branch_id', $this->branchId))->with(['product:id,name,product_number', 'branch:id,name'])->latest('id')->limit(30)->get();

        return $this->view(compact('branches', 'products', 'recent'))->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><flux:heading size="xl">سند إدخال وإخراج بضاعة</flux:heading><flux:text>السند ينشئ حركة مخزون ثابتة لكل صنف ويظهر في تقرير حركة المخزون.</flux:text></div>
        <flux:button :href="route('tenant.stock-movements')" wire:navigate>سجل حركات المخزون</flux:button>
    </div>
    @if ($successMessage)<div class="rounded-xl bg-emerald-50 p-4 font-bold text-emerald-800">{{ $successMessage }}</div>@endif
    @if ($errorMessage)<div class="rounded-xl bg-rose-50 p-4 font-bold text-rose-800">{{ $errorMessage }}</div>@endif
    <div class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-900">
        <div class="grid gap-4 md:grid-cols-3">
            <flux:select label="نوع السند" wire:model.live="type"><option value="in">سند إدخال بضاعة</option><option value="out">سند إخراج بضاعة</option></flux:select>
            @if (!auth()->user()->isTenantOwner() && !auth()->user()->isSaaSAdmin())
                <flux:select label="الفرع / المخزن" wire:model="branchId"><option value="">اختر الفرع</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>
            @endif
            <flux:input label="ابحث عن صنف" wire:model.live.debounce.250ms="productSearch" placeholder="الاسم أو رقم الصنف" />
        </div>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800">
            <div class="mb-2 text-xs font-bold text-slate-500">نتائج الأصناف — ابحث بالاسم أو رقم الصنف أو الباركود ثم اضغط الصنف لإضافته للسند.</div>
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($products as $product)
                    @php $stock = $product->branchProducts->first()?->stock_quantity; @endphp
                    <button type="button" wire:click="addProduct({{ $product->id }})" wire:loading.attr="disabled" wire:target="addProduct({{ $product->id }})" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white p-3 text-right transition hover:border-indigo-300 hover:bg-indigo-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900">
                        <span class="min-w-0"><span class="block truncate text-sm font-black text-slate-800 dark:text-white">{{ $product->name }}</span><span class="mt-1 block font-mono text-[10px] text-slate-400">{{ $product->product_number ?: '#'.$product->id }}</span></span>
                        <span class="shrink-0 rounded-md bg-slate-100 px-2 py-1 font-mono text-xs font-black {{ $stock !== null && $stock <= 0 ? 'text-rose-700' : 'text-emerald-700' }} dark:bg-slate-800">{{ $stock === null ? '—' : number_format((float) $stock, 3) }}</span>
                    </button>
                @empty
                    <div class="col-span-full py-3 text-center text-sm text-slate-500">لا توجد أصناف مطابقة للبحث.</div>
                @endforelse
            </div>
        </div>
        <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700"><table class="w-full text-right text-sm"><thead class="bg-slate-50 dark:bg-slate-800"><tr><th class="p-3">الصنف</th><th class="p-3">الكمية</th><th class="p-3">إجراء</th></tr></thead><tbody>@foreach ($lines as $index => $line)<tr wire:key="voucher-line-{{ $index }}" class="border-t border-slate-100 dark:border-slate-800"><td class="p-3 min-w-80"><select wire:model="lines.{{ $index }}.product_id" class="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 dark:bg-slate-900"><option value="">اختر الصنف</option>@foreach ($products as $product)<option value="{{ $product->id }}">{{ $product->product_number ? $product->product_number.' — ' : '' }}{{ $product->name }}</option>@endforeach</select>@error('lines.'.$index.'.product_id')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</td><td class="p-3 min-w-40"><input wire:model="lines.{{ $index }}.quantity" type="number" min="0.001" step="0.001" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-center tabular-nums dark:bg-slate-900">@error('lines.'.$index.'.quantity')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror</td><td class="p-3"><button type="button" wire:click="removeLine({{ $index }})" class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700">حذف</button></td></tr>@endforeach</tbody></table></div>
        @error('lines')<p class="text-sm font-bold text-rose-600">{{ $message }}</p>@enderror
        <div class="flex"><button type="button" wire:click="addLine" class="rounded-lg bg-slate-100 px-4 py-2 text-sm font-bold text-slate-700 dark:bg-slate-800">+ إضافة صنف</button></div>
        <flux:textarea label="سبب السند أو ملاحظات" wire:model="notes" placeholder="مثال: بضاعة وصلت دون فاتورة / تلف / استعمال داخلي" />
        <div class="flex justify-end"><flux:button variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">حفظ سند {{ $type === 'in' ? 'الإدخال' : 'الإخراج' }}</flux:button></div>
    </div>
    <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700"><table class="w-full text-right text-sm"><thead class="bg-slate-50 dark:bg-slate-800"><tr><th class="p-3">التاريخ</th><th class="p-3">السند</th><th class="p-3">النوع</th><th class="p-3">الفرع</th><th class="p-3">الصنف</th><th class="p-3">الكمية</th></tr></thead><tbody>@forelse($recent as $movement)<tr class="border-t border-slate-100 dark:border-slate-800"><td class="p-3">{{ $movement->created_at->format('Y-m-d H:i') }}</td><td class="p-3 font-mono text-xs">{{ $movement->source_reference }}</td><td class="p-3">{{ $movement->source_type === 'stock_in' ? 'إدخال' : 'إخراج' }}</td><td class="p-3">{{ $movement->branch?->name }}</td><td class="p-3">{{ $movement->product?->name }}</td><td class="p-3 tabular-nums {{ $movement->quantity > 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($movement->quantity, 3) }}</td></tr>@empty<tr><td colspan="6" class="p-6 text-center text-slate-500">لا توجد سندات إدخال أو إخراج بعد.</td></tr>@endforelse</tbody></table></div>
</flux:main>
