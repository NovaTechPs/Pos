<?php

use App\Traits\HasProductPriceHistory;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithoutUrlPagination;

new class extends Component {
    use HasProductPriceHistory;
    use WithPagination;
    use WithoutUrlPagination;

    private function tenantId(): ?int
    {
        $user = auth()->user();

        if ($user?->tenant_id) {
            return (int) $user->tenant_id;
        }

        $tenantId = session('active_tenant_id');

        if ($user && $tenantId && $user->tenants()->whereKey($tenantId)->exists()) {
            return (int) $tenantId;
        }

        return $user?->tenants()->first()?->id;
    }
};
?>

<div>
    @include('components.product-price-history')
</div>