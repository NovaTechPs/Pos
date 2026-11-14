<?php

use App\Models\CustomerOrder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $status = 'open';

    public ?int $selectedId = null;

    public function mount(): void
    {
        $this->authorize('store.orders.view');
    }

    private function tenantId(): int
    {
        return (int) auth()->user()->tenant_id;
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function show(int $id): void
    {
        $this->selectedId = CustomerOrder::query()->where('tenant_id', $this->tenantId())->findOrFail($id)->id;
    }

    public function setStatus(int $id, string $status): void
    {
        $this->authorize('store.orders.update');
        abort_unless(in_array($status, ['new', 'processing', 'cancelled'], true), 422);
        CustomerOrder::query()->where('tenant_id', $this->tenantId())->whereNull('order_id')->findOrFail($id)->update(['status' => $status]);
    }

    public function render(): mixed
    {
        $this->authorize('store.orders.view');
        $orders = CustomerOrder::query()->where('tenant_id', $this->tenantId())
            ->when($this->status === 'open', fn ($query) => $query->whereIn('status', ['new', 'processing']))
            ->when(in_array($this->status, array_keys(CustomerOrder::STATUSES), true), fn ($query) => $query->where('status', $this->status))
            ->with(['branch:id,name', 'order:id,invoice_number'])->withCount('items')
            ->latest()->paginate(25);
        $selected = $this->selectedId
            ? CustomerOrder::query()->where('tenant_id', $this->tenantId())->with(['items.product:id,name,product_number', 'party:id,name,current_balance', 'order:id,invoice_number'])->find($this->selectedId)
            : null;

        return $this->view([
            'orders' => $orders,
            'selected' => $selected,
            'newCount' => CustomerOrder::query()->where('tenant_id', $this->tenantId())->where('status', 'new')->count(),
        ])->layout('layouts::tenant');
    }
};
?>

<flux:main dir="rtl" class="space-y-4" data-customer-orders-page>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">طلبات المتجر الإلكتروني</flux:heading>
            <flux:text class="mt-1">طلبات الزبائن من المتجر. راجع الطلب ثم «حوّل لفاتورة» لتخصم البضاعة ويُسجَّل في حساب الزبون؛ الطلب وحده لا يغيّر المخزون ولا الأرصدة.</flux:text>
        </div>
        @if ($newCount > 0)
            <span class="rounded-full bg-sky-100 px-3 py-1 text-sm font-bold text-sky-800">{{ $newCount }} طلب جديد</span>
        @endif
    </div>

    <div class="flex flex-wrap gap-2" role="tablist">
        @foreach (['open' => 'المفتوحة'] + \App\Models\CustomerOrder::STATUSES + ['all' => 'الكل'] as $key => $label)
            <button type="button" wire:click="$set('status', '{{ $key }}')" @class(['rounded-lg px-3 py-1.5 text-sm font-bold', 'bg-indigo-600 text-white' => $status === $key, 'bg-white text-slate-600 border border-slate-200 dark:bg-slate-900 dark:border-slate-700' => $status !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-[1fr_24rem]">
        <section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
            <table class="w-full min-w-[640px] text-right text-sm">
                <thead class="bg-slate-50 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr><th class="p-3">الطلب</th><th class="p-3">الزبون</th><th class="p-3">الفرع</th><th class="p-3">الأصناف</th><th class="p-3">المجموع</th><th class="p-3">الحالة</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($orders as $order)
                        <tr wire:key="request-{{ $order->id }}" wire:click="show({{ $order->id }})" @class(['cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-800', 'bg-indigo-50 dark:bg-indigo-950/40' => $selectedId === $order->id]) data-customer-order>
                            <td class="p-3"><div class="font-mono text-xs" dir="ltr">{{ $order->reference }}</div><div class="text-xs text-slate-500">{{ $order->created_at->format('Y-m-d H:i') }}</div></td>
                            <td class="p-3"><div class="font-bold">{{ $order->customer_name }}</div><div class="font-mono text-xs text-slate-500" dir="ltr">{{ $order->customer_phone }}</div>@if ($order->price_list === 'wholesale')<span class="text-[11px] font-bold text-teal-700">جملة</span>@endif</td>
                            <td class="p-3">{{ $order->branch?->name }}</td>
                            <td class="p-3">{{ $order->items_count }}</td>
                            <td class="p-3 font-mono">{{ number_format((float) $order->total, 2) }}</td>
                            <td class="p-3">{{ $order->statusLabel() }}@if ($order->order)<div class="font-mono text-xs text-slate-500">{{ $order->order->invoice_number }}</div>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="p-8 text-center text-slate-500">لا توجد طلبات.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="p-3">{{ $orders->links() }}</div>
        </section>

        <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
            @if ($selected)
                <h2 class="font-black">طلب <span class="font-mono" dir="ltr">{{ $selected->reference }}</span></h2>
                <dl class="mt-2 space-y-1 text-sm">
                    <div><dt class="inline text-slate-500">الزبون:</dt> <dd class="inline font-bold">{{ $selected->customer_name }}</dd> @if ($selected->party)<span class="text-xs text-teal-700">(حساب: {{ $selected->party->name }})</span>@else<span class="text-xs text-slate-500">(بدون حساب)</span>@endif</div>
                    <div><dt class="inline text-slate-500">الهاتف:</dt> <dd class="inline font-mono" dir="ltr">{{ $selected->customer_phone }}</dd></div>
                    @if ($selected->customer_address)<div><dt class="inline text-slate-500">العنوان:</dt> <dd class="inline">{{ $selected->customer_address }}</dd></div>@endif
                    @if ($selected->notes)<div class="rounded-lg bg-amber-50 p-2 text-amber-900 dark:bg-amber-950/30">{{ $selected->notes }}</div>@endif
                </dl>
                <div class="mt-3 divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm dark:divide-slate-800 dark:border-slate-700">
                    @foreach ($selected->items as $item)
                        <div class="flex justify-between gap-2 p-2"><span>{{ $item->product?->name }} × {{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</span><span class="font-mono">{{ number_format((float) $item->total, 2) }}</span></div>
                    @endforeach
                    <div class="flex justify-between p-2 font-black"><span>المجموع</span><span class="font-mono">{{ number_format((float) $selected->total, 2) }}</span></div>
                </div>

                @if (! $selected->order_id && $selected->status !== 'cancelled')
                    @can('store.orders.update')
                        <div class="mt-3 flex flex-col gap-2">
                            <a href="{{ route('tenant.sale-invoices', ['customer_order' => $selected->id]) }}" wire:navigate data-convert-to-invoice
                                class="rounded-xl bg-emerald-600 py-2.5 text-center font-bold text-white hover:bg-emerald-500">حوّل لفاتورة مبيعات</a>
                            <div class="flex gap-2">
                                @if ($selected->status === 'new')
                                    <button type="button" wire:click="setStatus({{ $selected->id }}, 'processing')" class="flex-1 rounded-xl border border-amber-300 bg-amber-50 py-2 text-sm font-bold text-amber-800">قيد التجهيز</button>
                                @endif
                                <button type="button" wire:click="setStatus({{ $selected->id }}, 'cancelled')" wire:confirm="إلغاء هذا الطلب؟" class="flex-1 rounded-xl border border-rose-300 bg-rose-50 py-2 text-sm font-bold text-rose-700">إلغاء الطلب</button>
                            </div>
                        </div>
                    @endcan
                @elseif ($selected->order)
                    <p class="mt-3 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900">فاتورة رقم {{ $selected->order->invoice_number }}</p>
                @endif
            @else
                <p class="py-10 text-center text-sm text-slate-500">اختر طلباً لعرض تفاصيله.</p>
            @endif
        </aside>
    </div>
</flux:main>
