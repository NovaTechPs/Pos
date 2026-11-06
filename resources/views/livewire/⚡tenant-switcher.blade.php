<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use App\Models\Branch;
use App\Models\Tenant;

new class extends Component {
    #[Locked]
    public array $tenants = [];

    #[Locked]
    public ?int $currentTenantId = null;

    #[Locked]
    public ?string $currentTenantSlug = null;

    #[Locked]
    public bool $canSwitchTenants = false;

    #[Locked]
    public array $branches = [];

    #[Locked]
    public ?int $currentBranchId = null;

    #[Locked]
    public bool $canSwitchBranches = false;

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user) return;

        if ($user->isSaaSAdmin()) {
            $this->tenants = Tenant::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->toArray();
            $this->canSwitchTenants = count($this->tenants) > 1;

            $currentTenant = collect($this->tenants)->firstWhere('id', session('active_tenant_id'))
                ?? ($this->tenants[0] ?? null);

            if ($currentTenant) {
                $this->currentTenantId = (int) $currentTenant['id'];
                $this->currentTenantSlug = $currentTenant['slug'] ?? $currentTenant['domain'] ?? (string) $currentTenant['id'];
            }
        } elseif ($user->isTenantOwner()) {
            $this->tenants = $user->ownedTenants()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->toArray();
            $this->canSwitchTenants = count($this->tenants) > 1;

            $currentTenant = collect($this->tenants)->firstWhere('id', session('active_tenant_id'))
                ?? ($this->tenants[0] ?? null);

            if ($currentTenant) {
                $this->currentTenantId = (int) $currentTenant['id'];
                $this->currentTenantSlug = $currentTenant['slug'] ?? $currentTenant['domain'] ?? (string) $currentTenant['id'];
            }
        } else {
            $this->canSwitchTenants = false;
            $this->currentTenantId = $user->tenant_id;

            if ($user->tenant) {
                $this->tenants = [$user->tenant->toArray()];
                $this->currentTenantSlug = $user->tenant->slug ?? $user->tenant->domain ?? (string) $user->tenant_id;
            }
        }

        if ($this->currentTenantId) {
            session([
                'active_tenant_id'   => $this->currentTenantId,
                'active_tenant_slug' => $this->currentTenantSlug,
            ]);
        }

        $this->loadBranches();
    }

    public function switchTenant(int $tenantId): mixed
    {
        $user = auth()->user();
        if (!$user) {
            return null;
        }

        $tenantQuery = Tenant::query()
            ->whereKey($tenantId)
            ->where('is_active', true);

        if ($user->isTenantOwner()) {
            $tenantQuery->where('owner_id', $user->id);
        } elseif (!$user->isSaaSAdmin()) {
            return null;
        }

        $tenant = $tenantQuery->first();

        if ($tenant) {
            $slug = $tenant->slug ?? $tenant->domain ?? (string) $tenant->id;

            $this->currentTenantId = $tenant->id;
            $this->currentTenantSlug = $slug;

            session([
                'active_tenant_id'   => $tenant->id,
                'active_tenant_slug' => $slug,
            ]);

            $this->loadBranches(true);

            $this->dispatch('tenant-changed', tenantId: $tenant->id);

            return $this->redirect(request()->header('Referer') ?? route('tenant.dashboard'), navigate: true);
        }

        return null;
    }

    public function switchBranch(int $branchId): mixed
    {
        $user = auth()->user();
        if (! $user || ! $this->canSwitchBranches || ! $this->currentTenantId) {
            return null;
        }

        $branch = Branch::query()
            ->whereKey($branchId)
            ->where('tenant_id', $this->currentTenantId)
            ->where('type', 'branch')
            ->first();

        if (! $branch) {
            return null;
        }

        $this->currentBranchId = $branch->id;
        session(['active_branch_id' => $branch->id]);
        $this->dispatch('branch-changed', branchId: $branch->id);

        return $this->redirect(request()->header('Referer') ?? route('tenant.dashboard'), navigate: true);
    }

    private function loadBranches(bool $selectDefault = false): void
    {
        $user = auth()->user();
        $maySwitch = $user && ($user->isSaaSAdmin() || $user->isTenantOwner());
        $this->branches = $this->currentTenantId && $maySwitch
            ? Branch::query()
                ->where('tenant_id', $this->currentTenantId)
                ->where('type', 'branch')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->toArray()
            : [];
        $this->canSwitchBranches = count($this->branches) > 1;

        $branchIds = collect($this->branches)->pluck('id');
        $selectedBranchId = (int) session('active_branch_id');

        if ($selectDefault || ! $branchIds->contains($selectedBranchId)) {
            $selectedBranchId = (int) ($this->branches[0]['id'] ?? 0);
        }

        $this->currentBranchId = $selectedBranchId ?: null;

        if ($this->currentBranchId) {
            session(['active_branch_id' => $this->currentBranchId]);
        } elseif ($maySwitch) {
            session()->forget('active_branch_id');
        } else {
            $this->currentBranchId = $user?->branch_id;
        }
    }

    #[Computed]
    public function currentTenant(): ?array
    {
        if (empty($this->tenants)) {
            return auth()->user()?->tenant?->toArray();
        }

        return collect($this->tenants)->firstWhere('id', $this->currentTenantId);
    }
}; ?>

<div class="px-3 py-2 border-b border-zinc-200 dark:border-zinc-800">
    @if ($canSwitchTenants)
        <!-- تظهر القائمة لمدير النظام أو مالك أكثر من متجر -->
        <flux:dropdown position="bottom" align="start" class="w-full">
            <flux:button variant="ghost" icon-trailing="chevron-down" class="w-full justify-between font-semibold">
                <div class="flex items-center gap-2 truncate">
                    <flux:icon name="building-storefront" class="size-4 text-indigo-600 dark:text-indigo-400" />
                    <span class="truncate">{{ $this->currentTenant['name'] ?? __('اختر متجر') }}</span>
                </div>
            </flux:button>

            <flux:menu class="w-60">
                @foreach ($tenants as $tenant)
                    <flux:menu.item wire:click="switchTenant({{ $tenant['id'] }})"
                        :icon="$tenant['id'] == $currentTenantId ? 'check' : ''">
                        <div class="flex flex-col">
                            <span class="font-medium text-sm text-zinc-900 dark:text-zinc-100">{{ $tenant['name'] }}</span>
                            @if (!empty($tenant['domain']))
                                <span class="text-xs text-zinc-400">{{ $tenant['domain'] }}</span>
                            @endif
                        </div>
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @else
        <!-- عرض اسم المتجر فقط للموظف أو عند وجود متجر واحد -->
        <div class="flex items-center gap-2 px-2 py-1.5 font-semibold text-zinc-800 dark:text-zinc-200">
            <flux:icon name="building-storefront" class="size-4 text-indigo-600 dark:text-indigo-400" />
            <span class="truncate text-sm">{{ $this->currentTenant['name'] ?? __('لا يوجد متجر مرتبط') }}</span>
        </div>
    @endif

    @if ($canSwitchBranches)
        <flux:dropdown position="bottom" align="start" class="w-full mt-2">
            <flux:button variant="ghost" icon-trailing="chevron-down" class="w-full justify-between font-semibold">
                <div class="flex items-center gap-2 truncate">
                    <flux:icon name="building-office-2" class="size-4 text-emerald-600 dark:text-emerald-400" />
                    <span class="truncate">{{ collect($branches)->firstWhere('id', $currentBranchId)['name'] ?? __('اختر فرعاً') }}</span>
                </div>
            </flux:button>

            <flux:menu class="w-60">
                @foreach ($branches as $branch)
                    <flux:menu.item wire:click="switchBranch({{ $branch['id'] }})"
                        :icon="$branch['id'] == $currentBranchId ? 'check' : ''">
                        {{ $branch['name'] }}
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    @endif
</div>
