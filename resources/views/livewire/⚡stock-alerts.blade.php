<?php

use App\Models\BranchProduct;
use App\Models\TenantSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /** How many low items the menu lists before linking to the full list. */
    public const LIST_LIMIT = 8;

    #[Computed]
    public function canSee(): bool
    {
        return (bool) auth()->user()?->can('products.view');
    }

    /**
     * Items whose stock in the active branch reached their alert threshold (all branches for an
     * owner who has not picked one).
     */
    private function lowStockQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $user = auth()->user();
        $tenantId = (int) (session('active_tenant_id') ?: $user?->tenant_id);

        return BranchProduct::query()
            ->join('products', 'products.id', '=', 'branch_products.product_id')
            ->where('branch_products.tenant_id', $tenantId)
            ->where('products.tenant_id', $tenantId)
            ->whereNull('products.deleted_at')
            ->whereColumn('branch_products.stock_quantity', '<=', 'branch_products.alert_quantity')
            ->when($user?->activeBranchId(), fn ($query, int $branchId) => $query->where('branch_products.branch_id', $branchId));
    }

    #[Computed]
    public function lowStockCount(): int
    {
        return $this->canSee ? $this->lowStockQuery()->count() : 0;
    }

    /**
     * Purchased batches of the active branch that expired or expire within 30 days while the product is
     * still in stock, for stores that track expiry dates and users who can open the expiry report.
     */
    #[Computed]
    public function expiringCount(): int
    {
        $user = auth()->user();
        $tenantId = (int) (session('active_tenant_id') ?: $user?->tenant_id);
        if (! $user?->can('reports.analytics') || ! $user->can('reports.inventory') || ! TenantSetting::getBool($tenantId, 'track_expiry')) {
            return 0;
        }

        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('branch_products', fn ($join) => $join->on('branch_products.branch_id', '=', 'purchases.branch_id')->on('branch_products.product_id', '=', 'purchase_items.product_id'))
            ->where('purchases.tenant_id', $tenantId)->whereNull('purchases.deleted_at')
            ->where('branch_products.stock_quantity', '>', 0)
            ->when($user->activeBranchId(), fn ($query, int $branchId) => $query->where('purchases.branch_id', $branchId))
            ->whereNotNull('purchase_items.expiry_date')
            ->whereDate('purchase_items.expiry_date', '<=', today()->addDays(30)->toDateString())
            ->count();
    }

    /** @return Collection<int, BranchProduct> */
    #[Computed]
    public function lowStockItems(): Collection
    {
        if (! $this->canSee || $this->lowStockCount === 0) {
            return collect();
        }

        return $this->lowStockQuery()
            ->select('branch_products.id', 'products.name', 'branch_products.stock_quantity', 'branch_products.alert_quantity')
            ->orderBy('branch_products.stock_quantity')
            ->orderBy('products.name')
            ->limit(self::LIST_LIMIT)
            ->get();
    }
};
?>

<div data-stock-alerts>
    @if ($this->expiringCount > 0)
        <div class="px-3 pt-2">
            <a href="{{ route('tenant.reports', ['report' => 'expiring']) }}" wire:navigate data-expiry-alert
                class="flex w-full items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-bold text-amber-800 hover:bg-amber-100 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-300">
                <flux:icon name="clock" class="size-4 shrink-0" />
                <span class="flex-1">صلاحية منتهية أو قريبة</span>
                <span class="rounded-full bg-amber-600 px-2 py-0.5 text-xs text-white">{{ number_format($this->expiringCount) }}</span>
            </a>
        </div>
    @endif
    @if ($this->lowStockCount > 0)
        <flux:dropdown position="bottom" align="start" class="w-full px-3 pt-2">
            <button type="button" class="flex w-full items-center gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-start text-sm font-bold text-rose-700 hover:bg-rose-100 dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-300">
                <flux:icon name="bell-alert" class="size-4 shrink-0" />
                <span class="flex-1">تنبيهات المخزون</span>
                <span class="rounded-full bg-rose-600 px-2 py-0.5 text-xs text-white">{{ number_format($this->lowStockCount) }}</span>
            </button>

            <flux:menu class="w-72">
                <div class="px-2 py-1.5 text-xs font-semibold text-zinc-500">أصناف وصلت حد التنبيه أو نفدت</div>
                @foreach ($this->lowStockItems as $item)
                    <div class="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 text-sm">
                        <span class="truncate">{{ $item->name }}</span>
                        <span @class(['shrink-0 font-mono text-xs font-black', 'text-rose-600' => (float) $item->stock_quantity <= 0, 'text-amber-600' => (float) $item->stock_quantity > 0])>
                            {{ rtrim(rtrim(number_format((float) $item->stock_quantity, 2, '.', ''), '0'), '.') }} / {{ rtrim(rtrim(number_format((float) $item->alert_quantity, 2, '.', ''), '0'), '.') }}
                        </span>
                    </div>
                @endforeach
                <flux:menu.separator />
                <flux:menu.item icon="arrow-left" :href="route('tenant.product', ['stock' => 'low'])" wire:navigate>
                    عرض كل الأصناف ({{ number_format($this->lowStockCount) }})
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
