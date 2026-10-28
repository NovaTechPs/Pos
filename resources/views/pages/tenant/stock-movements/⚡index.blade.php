<?php

use App\Models\Branch;
use App\Models\StockMovement;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $sourceType = '';
    public ?int $branchId = null;

    public function mount(): void
    {
        $this->authorize('products.view');
        $this->dateFrom = today()->startOfMonth()->toDateString();
        $this->dateTo = today()->toDateString();
        $this->branchId = auth()->user()?->branch_id;
    }

    public function updated(): void { $this->resetPage(); }

    public function render()
    {
        $this->authorize('products.view');
        $tenantId = auth()->user()?->tenant_id ?? session('active_tenant_id');
        abort_unless($tenantId, 403);
        $branchId = auth()->user()?->branch_id ?? $this->branchId;
        $query = StockMovement::query()->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->dateFrom), fn ($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when(preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->dateTo), fn ($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->when($this->sourceType !== '', fn ($q) => $q->where('source_type', $this->sourceType))
            ->when(trim($this->search) !== '', fn ($q) => $q->whereHas('product', fn ($p) => $p->where(fn ($p) => $p->where('name', 'like', '%'.trim($this->search).'%')->orWhere('product_number', 'like', '%'.trim($this->search).'%'))));
        return $this->view([
            'movements' => $query->with(['product', 'branch'])->latest('id')->paginate(30),
            'branches' => Branch::query()->where('tenant_id', $tenantId)->when(auth()->user()?->branch_id, fn ($q) => $q->whereKey(auth()->user()->branch_id))->get(),
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="space-y-4">
    <flux:heading size="xl">سجل حركات المخزون</flux:heading>
    <flux:text>كل حركة تعرض الرصيد قبلها وبعدها والمستند المرتبط بها. التصحيح يُسجّل كحركة جديدة.</flux:text>
    <div class="grid grid-cols-1 gap-3 md:grid-cols-5">
        <flux:input label="الصنف" placeholder="اسم الصنف أو رقمه" wire:model.live.debounce.250ms="search" />
        <flux:select label="الفرع" wire:model.live="branchId"><option value="">كل الفروع المسموحة</option>@foreach ($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</flux:select>
        <flux:input type="date" label="من" wire:model.live="dateFrom" />
        <flux:input type="date" label="إلى" wire:model.live="dateTo" />
        <flux:select label="مصدر الحركة" wire:model.live="sourceType"><option value="">الكل</option>@foreach (['opening' => 'رصيد افتتاحي', 'manual' => 'تعديل مخزون', 'order' => 'فاتورة مبيعات', 'purchase' => 'فاتورة مشتريات', 'sales_return' => 'مردود مبيعات', 'purchase_return' => 'مردود مشتريات', 'stock_transfer' => 'تحويل مخزون', 'transfer_reversal' => 'عكس تحويل', 'sale_reversal' => 'عكس فاتورة', 'sync' => 'مزامنة'] as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</flux:select>
    </div>
    <div class="overflow-x-auto rounded-xl border border-slate-200">
        <table class="w-full text-right text-sm">
            <thead class="bg-slate-50"><tr><th class="p-3">التاريخ</th><th class="p-3">الفرع</th><th class="p-3">رقم الصنف</th><th class="p-3">الصنف</th><th class="p-3">المستند</th><th class="p-3">قبل</th><th class="p-3">الحركة</th><th class="p-3">بعد</th></tr></thead>
            <tbody>@forelse ($movements as $movement)<tr class="border-t border-slate-100">
                <td class="p-3">{{ $movement->created_at->format('Y-m-d H:i') }}</td><td class="p-3">{{ $movement->branch?->name }}</td>
                <td class="p-3">{{ $movement->product?->product_number }}</td><td class="p-3">{{ $movement->product?->name }}</td>
                <td class="p-3">{{ ['opening' => 'رصيد افتتاحي', 'manual' => 'تعديل', 'order' => 'بيع', 'purchase' => 'شراء', 'sales_return' => 'مردود بيع', 'purchase_return' => 'مردود شراء', 'stock_transfer' => 'تحويل', 'transfer_reversal' => 'عكس تحويل', 'sale_reversal' => 'عكس بيع', 'sync' => 'مزامنة'][$movement->source_type] ?? 'حركة' }} {{ $movement->source_reference ?: ($movement->source_id ? '#'.$movement->source_id : '') }}</td>
                <td class="p-3">{{ number_format($movement->quantity_before, 3) }}</td><td class="p-3 {{ $movement->quantity > 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ number_format($movement->quantity, 3) }}</td><td class="p-3">{{ number_format($movement->quantity_after, 3) }}</td>
            </tr>@empty<tr><td colspan="8" class="p-5 text-center">لا توجد حركات ضمن البحث الحالي.</td></tr>@endforelse</tbody>
        </table>
    </div>
    {{ $movements->links() }}
</flux:main>
