<?php

use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

new class extends Component {
    public string $kind = 'sale';
    public string $partySearch = '';
    public array $partyResults = [];
    public ?int $selectedPartyId = null;
    public string $selectedPartyName = '';
    public string $productSearch = '';
    public array $productResults = [];
    public array $lines = [];
    public string $notes = '';
    public ?string $errorMessage = null;
    public ?string $successMessage = null;

    private function tenantId(): int
    {
        return (int) (session('active_tenant_id') ?? auth()->user()?->tenant_id ?? 0);
    }

    private function branchId(): ?int
    {
        $tenantId = $this->tenantId();
        $branchId = auth()->user()?->branch_id ?: session('active_branch_id');

        $branch = Branch::query()->where('tenant_id', $tenantId)->when($branchId, fn ($query) => $query->whereKey($branchId))->first();

        return $branch?->id ? (int) $branch->id : null;
    }

    public function updatedKind(): void
    {
        $this->selectedPartyId = null;
        $this->selectedPartyName = '';
        $this->partySearch = '';
        $this->partyResults = [];
        $this->productSearch = '';
        $this->productResults = [];
        $this->lines = [];
        $this->errorMessage = null;
        $this->successMessage = null;
    }

    public function updatedPartySearch(): void
    {
        $this->selectedPartyId = null;
        $this->selectedPartyName = '';
        $this->lines = [];
        $this->productResults = [];

        $search = trim($this->partySearch);
        if ($search === '') {
            $this->partyResults = [];
            return;
        }

        $types = $this->kind === 'sale' ? ['customer', 'both'] : ['supplier', 'both'];
        $this->partyResults = Party::query()->where('tenant_id', $this->tenantId())->whereIn('type', $types)
            ->where('is_active', true)->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%");
            })->orderBy('name')->limit(10)->get(['id', 'name', 'phone'])->toArray();
    }

    public function selectParty(int $partyId): void
    {
        $types = $this->kind === 'sale' ? ['customer', 'both'] : ['supplier', 'both'];
        $party = Party::query()->where('tenant_id', $this->tenantId())->whereIn('type', $types)
            ->where('is_active', true)->find($partyId);

        if (!$party) {
            $this->errorMessage = 'العميل أو المورد المحدد غير صالح.';
            return;
        }

        $this->selectedPartyId = (int) $party->id;
        $this->selectedPartyName = $party->name;
        $this->partySearch = $party->name;
        $this->partyResults = [];
        $this->productSearch = '';
        $this->productResults = [];
        $this->lines = [];
        $this->errorMessage = null;
    }

    public function updatedProductSearch(): void
    {
        $this->searchProducts();
    }

    public function searchProducts(): void
    {
        $this->productResults = [];
        if (!$this->selectedPartyId || trim($this->productSearch) === '') {
            return;
        }

        $tenantId = $this->tenantId();
        $branchId = $this->branchId();
        $search = trim($this->productSearch);
        $this->productResults = Product::query()->where('tenant_id', $tenantId)
            ->whereHas('branchProducts', fn ($query) => $query->where('branch_id', $branchId))
            ->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', fn ($barcodeQuery) => $barcodeQuery->where('barcode', 'like', "%{$search}%"));
            })->with(['branchProducts' => fn ($query) => $query->where('branch_id', $branchId)])
            ->orderBy('name')->limit(12)->get()->map(function (Product $product): array {
                $stock = $product->branchProducts->first();
                return [
                    'id' => (int) $product->id,
                    'name' => $product->name,
                    'stock' => (float) ($stock?->stock_quantity ?? 0),
                ];
            })->all();
    }

    private function remainingQuantity(int $productId, int $partyId): float
    {
        if ($this->kind === 'sale') {
            $sold = (float) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.tenant_id', $this->tenantId())->where('orders.customer_id', $partyId)
                ->whereIn('orders.type', ['pos', 'wholesale', 'online'])->where('orders.status', 'completed')
                ->whereNull('orders.deleted_at')->where('order_items.product_id', $productId)->sum('order_items.quantity');
            $returned = (float) DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.tenant_id', $this->tenantId())->where('orders.customer_id', $partyId)
                ->where('orders.type', 'return')->whereNull('orders.deleted_at')
                ->where('order_items.product_id', $productId)->sum(DB::raw('ABS(order_items.quantity)'));

            return max(0, $sold - $returned);
        }

        $purchased = (float) DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.tenant_id', $this->tenantId())->where('purchases.supplier_id', $partyId)
            ->whereNull('purchases.deleted_at')->where('purchase_items.product_id', $productId)->sum('purchase_items.quantity');
        $returned = (float) DB::table('purchase_return_items')->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_returns.tenant_id', $this->tenantId())->where('purchase_returns.supplier_id', $partyId)
            ->where('purchase_return_items.product_id', $productId)->sum('purchase_return_items.quantity');

        return max(0, $purchased - $returned);
    }

    private function latestPrice(int $productId, int $partyId, Product $product): float
    {
        if ($this->kind === 'sale') {
            $last = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('o.tenant_id', $this->tenantId())->where('o.customer_id', $partyId)
                ->whereIn('o.type', ['pos', 'wholesale', 'online'])->where('o.status', 'completed')
                ->whereNull('o.deleted_at')->where('oi.product_id', $productId)
                ->orderByDesc('o.created_at')->orderByDesc('oi.id')
                ->first(['oi.quantity', 'oi.total_price', 'o.subtotal', 'o.discount']);

            if ($last && abs((float) $last->quantity) > 0.000001) {
                $discountFactor = (float) $last->subtotal > 0
                    ? max(0, (float) $last->subtotal - (float) $last->discount) / (float) $last->subtotal
                    : 1;
                return round(abs((float) $last->total_price / (float) $last->quantity) * $discountFactor, 2);
            }

            $branchProduct = BranchProduct::query()->where('tenant_id', $this->tenantId())
                ->where('branch_id', $this->branchId())->where('product_id', $productId)->first();

            return (float) ($branchProduct?->retail_price ?? 0);
        }

        $lastCost = DB::table('purchase_items as pi')->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->where('p.tenant_id', $this->tenantId())->where('p.supplier_id', $partyId)
            ->whereNull('p.deleted_at')->where('pi.product_id', $productId)
            ->orderByDesc('p.created_at')->orderByDesc('pi.id')->value(DB::raw('pi.subtotal / NULLIF(pi.quantity, 0)'));

        return $lastCost !== null ? round((float) $lastCost, 2) : (float) $product->cost_price;
    }

    public function addProduct(int $productId): void
    {
        $this->errorMessage = null;
        $tenantId = $this->tenantId();
        $branchId = $this->branchId();

        if (!$this->selectedPartyId || !$branchId) {
            $this->errorMessage = 'اختر العميل أو المورد وحدد الفرع أولاً.';
            return;
        }

        $product = Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->first();
        $branchProduct = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
            ->where('product_id', $productId)->first();
        if (!$product || !$branchProduct) {
            $this->errorMessage = 'الصنف غير مرتبط بالفرع الحالي.';
            return;
        }

        $available = $this->remainingQuantity($productId, $this->selectedPartyId);
        if ($this->kind === 'purchase') {
            $available = min($available, (float) $branchProduct->stock_quantity);
        }
        if ($available <= 0) {
            $this->errorMessage = 'لا توجد كمية متاحة للإرجاع لهذا الصنف مع هذا الطرف.';
            return;
        }

        $lineIndex = collect($this->lines)->search(fn (array $line): bool => (int) $line['id'] === $productId);
        if ($lineIndex !== false) {
            if ((float) $this->lines[$lineIndex]['quantity'] + 1 > $available) {
                $this->errorMessage = 'الكمية المطلوب إرجاعها أكبر من الكمية المتاحة.';
                return;
            }
            $this->lines[$lineIndex]['quantity'] = (float) $this->lines[$lineIndex]['quantity'] + 1;
        } else {
            $this->lines[] = [
                'id' => $productId,
                'name' => $product->name,
                'available' => $available,
                'quantity' => 1,
                'price' => $this->latestPrice($productId, $this->selectedPartyId, $product),
            ];
        }

        $this->productSearch = '';
        $this->productResults = [];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function saveReturn(): void
    {
        Gate::authorize('returns.create');
        $this->errorMessage = null;
        $this->successMessage = null;
        $tenantId = $this->tenantId();
        $branchId = $this->branchId();

        if (!$this->selectedPartyId || !$branchId || !$this->lines || $tenantId < 1) {
            $this->errorMessage = 'اختر العميل أو المورد وأضف صنفاً واحداً على الأقل.';
            return;
        }

        $partyTypes = $this->kind === 'sale' ? ['customer', 'both'] : ['supplier', 'both'];
        $party = Party::query()->where('tenant_id', $tenantId)->whereIn('type', $partyTypes)
            ->where('is_active', true)->find($this->selectedPartyId);
        if (!$party) {
            $this->errorMessage = 'العميل أو المورد المحدد غير صالح.';
            return;
        }

        $items = [];
        foreach ($this->lines as $line) {
            $productId = (int) ($line['id'] ?? 0);
            $quantity = round((float) ($line['quantity'] ?? 0), 2);
            $price = round((float) ($line['price'] ?? 0), 2);
            if (!$productId || $quantity <= 0 || $price < 0) {
                $this->errorMessage = 'تأكد من الصنف والكمية والسعر في كل سطر.';
                return;
            }
            $items[$productId] = ['quantity' => $quantity, 'price' => $price];
        }

        try {
            DB::transaction(function () use ($tenantId, $branchId, $party, $items): void {
                $lockedParty = Party::query()->where('tenant_id', $tenantId)->whereKey($party->id)->lockForUpdate()->firstOrFail();
                $validated = [];
                $total = 0.0;

                foreach ($items as $productId => $line) {
                    $product = Product::query()->where('tenant_id', $tenantId)->whereKey($productId)->lockForUpdate()->firstOrFail();
                    $stock = BranchProduct::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
                        ->where('product_id', $productId)->lockForUpdate()->firstOrFail();
                    $available = $this->remainingQuantity($productId, (int) $lockedParty->id);
                    if ($this->kind === 'purchase') {
                        $available = min($available, (float) $stock->stock_quantity);
                    }
                    if ($line['quantity'] > $available + 0.00001) {
                        throw new \DomainException('كمية المرتجع تتجاوز الكمية التي تعامل بها هذا العميل أو المورد سابقاً.');
                    }

                    $lineTotal = round($line['quantity'] * $line['price'], 2);
                    $total += $lineTotal;
                    $validated[] = ['product' => $product, 'stock' => $stock, ...$line, 'total' => $lineTotal];
                }

                $total = round($total, 2);
                if ($this->kind === 'sale') {
                    $return = Order::create([
                        'tenant_id' => $tenantId, 'branch_id' => $branchId, 'created_by' => auth()->id(),
                        'customer_id' => $lockedParty->id, 'customer_name' => $lockedParty->name,
                        'customer_phone' => $lockedParty->phone, 'customer_address' => $lockedParty->address,
                        'invoice_number' => 'RET-' . now()->format('YmdHis') . '-' . random_int(100, 999),
                        'type' => 'return', 'status' => 'completed', 'subtotal' => -$total,
                        'tax_amount' => 0, 'discount_type' => 'fixed', 'discount_rate' => 0,
                        'discount' => 0, 'delivery_fee' => 0, 'total' => -$total,
                        'total_cost' => 0, 'total_profit' => -$total, 'paid_amount' => 0,
                        'payment_status' => 'unpaid', 'notes' => $this->notes ?: null,
                    ]);

                    foreach ($validated as $line) {
                        DB::table('order_items')->insert([
                            'tenant_id' => $tenantId, 'order_id' => $return->id, 'product_id' => $line['product']->id,
                            'quantity' => -$line['quantity'], 'unit_price' => $line['price'], 'total_price' => -$line['total'],
                            'cost_price' => $line['product']->cost_price, 'discount' => 0,
                            'total_cost' => -(float) $line['product']->cost_price * $line['quantity'],
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $line['stock']->increment('stock_quantity', $line['quantity']);
                    }
                } else {
                    $reference = 'PR-' . now()->format('YmdHis') . '-' . random_int(100, 999);
                    $returnId = DB::table('purchase_returns')->insertGetId([
                        'tenant_id' => $tenantId, 'branch_id' => $branchId, 'purchase_id' => null,
                        'supplier_id' => $lockedParty->id, 'created_by' => auth()->id(),
                        'reference_number' => $reference, 'total' => $total,
                        'notes' => $this->notes ?: null, 'created_at' => now(), 'updated_at' => now(),
                    ]);

                    foreach ($validated as $line) {
                        DB::table('purchase_return_items')->insert([
                            'tenant_id' => $tenantId, 'purchase_return_id' => $returnId, 'purchase_item_id' => null,
                            'product_id' => $line['product']->id, 'quantity' => $line['quantity'],
                            'unit_cost' => $line['price'], 'subtotal' => $line['total'],
                            'created_at' => now(), 'updated_at' => now(),
                        ]);
                        $line['stock']->decrement('stock_quantity', $line['quantity']);
                    }

                    $lockedParty->current_balance = (float) $lockedParty->current_balance - $total;
                    $lockedParty->save();
                }
            });

            $this->successMessage = $this->kind === 'sale'
                ? 'تم تسجيل مردود المبيعات على حساب العميل وإعادة الأصناف للمخزون.'
                : 'تم تسجيل مردود المشتريات على حساب المورد وخصم الأصناف من المخزون.';
            $this->lines = [];
            $this->notes = '';
            $this->productSearch = '';
            $this->productResults = [];
        } catch (\Throwable $exception) {
            report($exception);
            $this->errorMessage = $exception instanceof \DomainException
                ? $exception->getMessage()
                : 'تعذر حفظ المرتجع. تحقق من الرصيد والمخزون ثم حاول مجدداً.';
        }
    }
}; ?>

<div class="mx-auto max-w-6xl space-y-6 p-6" dir="rtl">
    <div>
        <h1 class="text-2xl font-black text-slate-900">مردودات المبيعات والمشتريات / Returns</h1>
        <p class="mt-1 text-sm text-slate-500">سجّل المرتجع على حساب العميل أو المورد، وأضف الأصناف من تعاملات الطرف السابقة.</p>
    </div>

    @if ($errorMessage)<div class="rounded-xl bg-rose-50 p-4 text-sm font-bold text-rose-700">{{ $errorMessage }}</div>@endif
    @if ($successMessage)<div class="rounded-xl bg-emerald-50 p-4 text-sm font-bold text-emerald-700">{{ $successMessage }}</div>@endif

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="grid gap-4 md:grid-cols-2">
            <label class="text-sm font-bold">نوع المرتجع / Return type
                <select wire:model.live="kind" class="mt-2 w-full rounded-xl border-slate-300">
                    <option value="sale">مردود مبيعات / Sales return</option>
                    <option value="purchase">مردود مشتريات / Purchase return</option>
                </select>
            </label>
            <div class="relative text-sm font-bold">
                {{ $kind === 'sale' ? 'العميل / Customer' : 'المورد / Supplier' }}
                <input wire:model.live.debounce.250ms="partySearch" class="mt-2 w-full rounded-xl border-slate-300" placeholder="ابحث بالاسم أو الهاتف / Search name or phone">
                @if ($partyResults)
                    <div class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                        @foreach ($partyResults as $party)
                            <button type="button" wire:click="selectParty({{ $party['id'] }})" class="flex w-full items-center justify-between border-b border-slate-100 px-4 py-3 text-right hover:bg-indigo-50">
                                <span>{{ $party['name'] }}</span><span class="text-xs text-slate-500">{{ $party['phone'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        @if ($selectedPartyId)
            <div class="mt-4 rounded-lg bg-indigo-50 p-3 text-sm font-bold text-indigo-800">الطرف المحدد / Selected party: {{ $selectedPartyName }}</div>
        @endif
    </section>

    @if ($selectedPartyId)
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <label class="text-sm font-bold">إضافة صنف / Add item
                <input wire:model.live.debounce.250ms="productSearch" class="mt-2 w-full rounded-xl border-slate-300" placeholder="ابحث بالاسم أو امسح الباركود / Search or scan barcode">
            </label>
            @if ($productResults)
                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($productResults as $product)
                        <button type="button" wire:click="addProduct({{ $product['id'] }})" class="flex items-center justify-between rounded-xl border border-slate-200 p-3 text-right hover:border-indigo-400 hover:bg-indigo-50">
                            <span class="font-bold">{{ $product['name'] }}</span><span class="text-xs text-slate-500">المخزون {{ number_format($product['stock'], 2) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($lines)
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto"><table class="w-full text-right text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="p-4">الصنف / Item</th><th class="p-4">المتاح / Available</th><th class="p-4">السعر الأخير / Last price</th><th class="p-4">الكمية / Quantity</th><th class="p-4">الإجمالي / Total</th><th></th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($lines as $index => $line)
                            <tr>
                                <td class="p-4 font-bold">{{ $line['name'] }}</td><td class="p-4">{{ number_format($line['available'], 2) }}</td>
                                <td class="p-4"><input type="number" min="0" step="0.01" wire:model.live="lines.{{ $index }}.price" class="w-28 rounded-lg border-slate-300"></td>
                                <td class="p-4"><input type="number" min="0.01" max="{{ $line['available'] }}" step="0.01" wire:model.live="lines.{{ $index }}.quantity" class="w-28 rounded-lg border-slate-300"></td>
                                <td class="p-4 font-bold">{{ number_format((float) $line['quantity'] * (float) $line['price'], 2) }}</td>
                                <td class="p-4"><button type="button" wire:click="removeLine({{ $index }})" class="text-rose-600">حذف</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                <div class="flex flex-col gap-4 border-t border-slate-100 p-5 md:flex-row md:items-end md:justify-between">
                    <label class="flex-1 text-sm font-bold">ملاحظات / Notes <input wire:model="notes" class="mt-2 w-full rounded-xl border-slate-300"></label>
                    <button wire:click="saveReturn" wire:loading.attr="disabled" class="rounded-xl bg-indigo-600 px-6 py-3 font-black text-white">حفظ المرتجع / Save return</button>
                </div>
            </section>
        @endif
    @endif
</div>
