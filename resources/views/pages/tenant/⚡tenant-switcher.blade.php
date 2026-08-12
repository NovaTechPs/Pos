<?php

use Livewire\Component;
use Livewire\Attributes\Computed;

new class extends Component {
    public array $tenants = [];
    public ?int $currentTenantId = null;

    public function mount(): void
    {
        $user = auth()->user();

        // جلب متاجر المستخدم الحالي
        $this->tenants = $user ? $user->tenants->toArray() : [];

        // تعيين المتجر النشط من Session أو اختيار أول متجر متاح
        $this->currentTenantId = session('active_tenant_id')
            ?? ($this->tenants[0]['id'] ?? null);

        // حفظ المتجر في الـ Session إن لم يكن موجوداً
        if ($this->currentTenantId && !session()->has('active_tenant_id')) {
            session(['active_tenant_id' => $this->currentTenantId]);
        }
    }

    public function switchTenant(int $tenantId)
    {
        // التأكد من أن المتجر ينتمي للمستخدم الحالي
        $tenant = auth()->user()?->tenants()->where('id', $tenantId)->first();

        if ($tenant) {
            session(['active_tenant_id' => $tenant->id]);
            $this->currentTenantId = $tenant->id;

            // إرسال حدث لتحديث بقية مكونات الصفحة
            $this->dispatch('tenant-changed', tenantId: $tenant->id);

            // التوجيه التفاعلي لنظام SPA بدون Refresh كامل
            return $this->redirect(request()->header('Referer') ?? route('dashboard'), navigate: true);
        }
    }

    // خاصية محسوبة تجلب بيانات المتجر الحالي تلقائياً
    #[Computed]
    public function currentTenant()
    {
        return collect($this->tenants)->firstWhere('id', $this->currentTenantId);
    }
}; ?>

<div class="px-3 py-2 border-b border-zinc-200 dark:border-zinc-800">
    <flux:dropdown position="bottom" align="start" class="w-full">
        <flux:button variant="ghost" icon-trailing="chevron-down" class="w-full justify-between font-semibold">
            <div class="flex items-center gap-2 truncate">
                <flux:icon name="building-storefront" class="size-4 text-indigo-600 dark:text-indigo-400" />
                <span class="truncate">{{ $this->currentTenant['name'] ?? 'اختر متجر' }}</span>
            </div>
        </flux:button>

        <flux:menu class="w-60">
            @foreach($tenants as $tenant)
                <flux:menu.item
                    wire:click="switchTenant({{ $tenant['id'] }})"
                    :icon="$tenant['id'] == $currentTenantId ? 'check' : ''">
                    <div class="flex flex-col">
                        <span class="font-medium text-sm text-zinc-900 dark:text-zinc-100">{{ $tenant['name'] }}</span>
                        <span class="text-xs text-zinc-400">{{ $tenant['domain'] }}</span>
                    </div>
                </flux:menu.item>
            @endforeach
        </flux:menu>
    </flux:dropdown>
</div>
