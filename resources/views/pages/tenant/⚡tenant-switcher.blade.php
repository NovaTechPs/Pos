<?php

use Livewire\Component;
use Livewire\Attributes\Computed;
use App\Models\Tenant;

new class extends Component {
    public array $tenants = [];
    public ?int $currentTenantId = null;
    public ?string $currentTenantSlug = null;
    public bool $canSwitchTenants = false;

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user) return;

        // التحقق مما إذا كان المستخدم مالكاً ولديه خيار التبديل
        if (!empty($user->is_owner) && method_exists($user, 'tenants')) {
            $this->tenants = $user->tenants->toArray();
            $this->canSwitchTenants = count($this->tenants) > 1;

            $currentTenant = collect($this->tenants)->firstWhere('id', session('active_tenant_id'))
                ?? ($this->tenants[0] ?? null);

            if ($currentTenant) {
                $this->currentTenantId = $currentTenant['id'] ?? null;
                $this->currentTenantSlug = $currentTenant['slug'] ?? $currentTenant['domain'] ?? (string) ($currentTenant['id'] ?? '');
            }
        } else {
            // للموظف أو المستخدم العادي (is_owner is 0 or null)
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
    }

    public function switchTenant(int $tenantId): mixed
    {
        if (!$this->canSwitchTenants) return null;

        $tenant = auth()->user()?->tenants()->where('tenants.id', $tenantId)->first();

        if ($tenant) {
            $slug = $tenant->slug ?? $tenant->domain ?? (string) $tenant->id;

            $this->currentTenantId = $tenant->id;
            $this->currentTenantSlug = $slug;

            session([
                'active_tenant_id'   => $tenant->id,
                'active_tenant_slug' => $slug,
            ]);

            $this->dispatch('tenant-changed', tenantId: $tenant->id);

            return $this->redirect(request()->header('Referer') ?? route('tenant.dashboard'), navigate: true);
        }

        return null;
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
        <!-- عرض القائمة المنسدلة فقط للمالك الذي يملك أكثر من متجر -->
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
        <!-- عرض اسم المتجر فقط بشكل ثابت للموظف أو عند وجود متجر واحد -->
        <div class="flex items-center gap-2 px-2 py-1.5 font-semibold text-zinc-800 dark:text-zinc-200">
            <flux:icon name="building-storefront" class="size-4 text-indigo-600 dark:text-indigo-400" />
            <span class="truncate text-sm">{{ $this->currentTenant['name'] ?? __('لا يوجد متجر مرتبط') }}</span>
        </div>
    @endif
</div>
