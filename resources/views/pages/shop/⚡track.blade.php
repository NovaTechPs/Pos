<?php

use App\Models\CustomerOrder;
use App\Services\OnlineShop;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $shop = '';

    #[Locked]
    public string $token = '';

    public function mount(string $shop, string $token): void
    {
        $this->shop = $shop;
        $this->token = $token;
        $this->order();
    }

    /** Anyone with the order's private link sees its status; nothing else of the customer's account. */
    private function order(): CustomerOrder
    {
        $branch = app(OnlineShop::class)->branch($this->shop);

        return CustomerOrder::query()->where('tenant_id', $branch->tenant_id)->where('tracking_token', $this->token)
            ->with(['items.product:id,name', 'order:id,invoice_number'])->firstOrFail();
    }

    public function render(): mixed
    {
        return $this->view(['order' => $this->order()])
            ->layout('layouts::shop', ['branch' => app(OnlineShop::class)->branch($this->shop), 'title' => 'متابعة الطلب']);
    }
};
?>

<div class="mx-auto max-w-2xl space-y-4" data-shop-track>
    <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-xl font-black">طلب رقم <span class="font-mono" dir="ltr">{{ $order->reference }}</span></h1>
                <p class="text-sm text-slate-500">{{ $order->created_at->format('Y-m-d H:i') }} · {{ $order->customer_name }}</p>
            </div>
            <span @class(['rounded-full px-3 py-1 text-sm font-bold',
                'bg-sky-100 text-sky-800' => $order->status === 'new',
                'bg-amber-100 text-amber-800' => $order->status === 'processing',
                'bg-emerald-100 text-emerald-800' => $order->status === 'invoiced',
                'bg-rose-100 text-rose-700' => $order->status === 'cancelled'])>{{ $order->statusLabel() }}</span>
        </div>
        @if ($order->status === 'new')
            <p class="mt-3 rounded-xl bg-sky-50 p-3 text-sm text-sky-900">وصل طلبك إلى المتجر، وسيتواصل معك لتأكيده. احتفظ بهذا الرابط لمتابعة حالته.</p>
        @elseif ($order->order)
            <p class="mt-3 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900">تم إصدار الفاتورة رقم <span class="font-mono" dir="ltr">{{ $order->order->invoice_number }}</span>.</p>
        @endif
    </div>

    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white">
        <table class="w-full text-right text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-3">الصنف</th><th class="p-3">الكمية</th><th class="p-3">السعر</th><th class="p-3">المجموع</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($order->items as $item)
                    <tr><td class="p-3">{{ $item->product?->name }}</td><td class="p-3 font-mono">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, '.', ''), '0'), '.') }}</td>
                        <td class="p-3 font-mono">{{ number_format((float) $item->unit_price, 2) }}</td><td class="p-3 font-mono">{{ number_format((float) $item->total, 2) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot class="bg-slate-50 font-black"><tr><td class="p-3" colspan="3">المجموع التقديري</td><td class="p-3 font-mono">{{ number_format((float) $order->total, 2) }}</td></tr></tfoot>
        </table>
    </div>
    <a href="{{ route('shop.home', ['shop' => $shop]) }}" wire:navigate class="inline-block font-bold text-teal-700">← العودة للمتجر</a>
</div>
