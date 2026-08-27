<?php

use Livewire\Component;
use Livewire\Attributes\Computed;

new class extends Component {
    public array $tenants = [];
    public ?int $currentTenantId = null;
    public ?string $currentTenantSlug = null;

    public function mount(): void
    {
        $user = auth()->user();

        // جلب متاجر المستخدم
        $this->tenants = $user ? $user->tenants->toArray() : [];

        // البحث عن المتجر النشط أو اختيار أول متجر متاح
        $currentTenant = collect($this->tenants)->firstWhere('id', session('active_tenant_id'))
            ?? ($this->tenants[0] ?? null);

        if ($currentTenant) {
            $this->currentTenantId = $currentTenant['id'] ?? null;
            $this->currentTenantSlug = $currentTenant['slug'] ?? $currentTenant['domain'] ?? (string) ($currentTenant['id'] ?? '');

            session([
                'active_tenant_id'   => $this->currentTenantId,
                'active_tenant_slug' => $this->currentTenantSlug,
            ]);
        }
    }

    public function switchTenant(int $tenantId): mixed
    {
        // التأكد من أن المتجر ينتمي للمستخدم الحالي
        $tenant = auth()->user()?->tenants()->where('tenants.id', $tenantId)->first();

        if ($tenant) {
            $slug = $tenant->slug ?? $tenant->domain ?? (string) $tenant->id;

            $this->currentTenantId = $tenant->id;
            $this->currentTenantSlug = $slug;

            session([
                'active_tenant_id'   => $tenant->id,
                'active_tenant_slug' => $slug,
            ]);

            // إرسال حدث لتحديث بقية مكونات الصفحة
            $this->dispatch('tenant-changed', tenantId: $tenant->id);

            // التوجيه التفاعلي لنظام SPA بدون Refresh كامل
            return $this->redirect(request()->header('Referer') ?? route('dashboard'), navigate: true);
        }

        return null;
    }

    // خاصية محسوبة تجلب بيانات المتجر الحالي تلقائياً
    #[Computed]
    public function currentTenant(): ?array
    {
        return collect($this->tenants)->firstWhere('id', $this->currentTenantId);
    }
}; ?>

<div class="px-3 py-2 border-b border-zinc-200 dark:border-zinc-800">
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
</div>
