<?php

use App\Models\CustomerOrder;
use App\Models\Order;
use App\Models\Party;
use App\Models\TelegramLink;
use App\Services\OnlineShop;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $shop = '';

    #[Url]
    public string $tab = 'orders';

    public function mount(string $shop): void
    {
        $this->shop = $shop;
        abort_unless(app(OnlineShop::class)->customer(app(OnlineShop::class)->branch($shop)), 403);
        $this->tab = in_array($this->tab, ['orders', 'invoices', 'statement'], true) ? $this->tab : 'orders';
    }

    /** Opens the store's Telegram bot with a one-time link for this customer's invoices and balance. */
    public function linkTelegram(): void
    {
        $account = app(OnlineShop::class)->customer(app(OnlineShop::class)->branch($this->shop));
        abort_unless($account, 403);
        $url = TelegramLink::issueFor($account->tenant_id, partyId: $account->party_id)->startUrl();
        abort_unless($url, 404);
        $this->redirect($url);
    }

    public function render(): mixed
    {
        $service = app(OnlineShop::class);
        $branch = $service->branch($this->shop);
        $account = $service->customer($branch);
        abort_unless($account, 403);

        $data = ['account' => $account, 'balance' => (float) Party::withoutGlobalScopes()->whereKey($account->party_id)->value('current_balance'),
            'telegramAvailable' => filled(config('services.telegram.bot_token')) && filled(config('services.telegram.bot_username')),
            'telegramLinked' => TelegramLink::query()->linked()->where('tenant_id', $account->tenant_id)->where('party_id', $account->party_id)->exists()];
        $data += match ($this->tab) {
            'invoices' => ['invoices' => Order::withoutGlobalScopes()->where('tenant_id', $account->tenant_id)->where('customer_id', $account->party_id)
                ->whereNull('deleted_at')->where('status', 'completed')->latest('created_at')->limit(100)
                ->get(['id', 'invoice_number', 'type', 'total', 'paid_amount', 'created_at'])],
            'statement' => ['statement' => $service->statement($account)],
            default => ['orders' => CustomerOrder::query()->where('tenant_id', $account->tenant_id)
                ->where(fn ($query) => $query->where('party_id', $account->party_id)->orWhere('customer_account_id', $account->id))
                ->with('order:id,invoice_number')->latest()->limit(100)->get()],
        };

        return $this->view($data)->layout('layouts::shop', ['branch' => $branch, 'title' => 'حسابي']);
    }
};
?>

<div class="space-y-4" data-shop-account-page>
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4">
        <div>
            <h1 class="text-xl font-black">{{ $account->party?->name }}</h1>
            <p class="text-sm text-slate-500" dir="ltr">{{ $account->phone }}</p>
            @if ($telegramAvailable)
                @if ($telegramLinked)
                    <p class="mt-1 text-xs font-bold text-sky-700">✈️ فواتيرك تصلك على تيليجرام</p>
                @else
                    <button type="button" wire:click="linkTelegram" class="mt-2 rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-sky-700" data-shop-telegram-button>✈️ استلم فواتيرك على تيليجرام</button>
                @endif
            @endif
        </div>
        <div class="text-left">
            <div class="text-xs text-slate-500">رصيدك لدى المتجر</div>
            <div @class(['font-mono text-xl font-black', 'text-rose-600' => $balance > 0, 'text-emerald-600' => $balance <= 0])>{{ number_format(abs($balance), 2) }}</div>
            <div class="text-[11px] text-slate-500">{{ $balance > 0 ? 'مستحق عليك' : ($balance < 0 ? 'لك عند المتجر' : 'لا يوجد رصيد') }}</div>
        </div>
    </div>

    <div class="flex gap-2" role="tablist">
        @foreach (['orders' => 'طلباتي', 'invoices' => 'فواتيري', 'statement' => 'كشف الحساب'] as $key => $label)
            <button type="button" role="tab" wire:click="$set('tab', '{{ $key }}')" @class(['rounded-xl px-4 py-2 text-sm font-bold', 'bg-teal-600 text-white' => $tab === $key, 'bg-white text-slate-600 border border-slate-200' => $tab !== $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
        @if ($tab === 'orders')
            <table class="w-full text-right text-sm" data-shop-orders>
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-3">الطلب</th><th class="p-3">التاريخ</th><th class="p-3">الحالة</th><th class="p-3">المجموع</th><th class="p-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($orders as $order)
                        <tr><td class="p-3 font-mono" dir="ltr">{{ $order->reference }}</td><td class="p-3">{{ $order->created_at->format('Y-m-d') }}</td>
                            <td class="p-3">{{ $order->statusLabel() }}@if ($order->order)<span class="text-xs text-slate-500"> · فاتورة {{ $order->order->invoice_number }}</span>@endif</td>
                            <td class="p-3 font-mono">{{ number_format((float) $order->total, 2) }}</td>
                            <td class="p-3"><a href="{{ route('shop.track', ['shop' => $shop, 'token' => $order->tracking_token]) }}" wire:navigate class="font-bold text-teal-700">التفاصيل</a></td></tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-slate-500">لا توجد طلبات بعد.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @elseif ($tab === 'invoices')
            <table class="w-full text-right text-sm" data-shop-invoices>
                <thead class="bg-slate-50 text-xs text-slate-500"><tr><th class="p-3">الفاتورة</th><th class="p-3">التاريخ</th><th class="p-3">المبلغ</th><th class="p-3">المدفوع</th><th class="p-3"></th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($invoices as $invoice)
                        <tr><td class="p-3 font-mono" dir="ltr">{{ $invoice->invoice_number }}{{ $invoice->type === 'return' ? ' (مرتجع)' : '' }}</td><td class="p-3">{{ $invoice->created_at->format('Y-m-d') }}</td>
                            <td class="p-3 font-mono">{{ number_format(abs((float) $invoice->total), 2) }}</td><td class="p-3 font-mono">{{ number_format(abs((float) $invoice->paid_amount), 2) }}</td>
                            <td class="p-3"><a href="{{ route('shop.invoice.pdf', ['shop' => $shop, 'order' => $invoice->id]) }}" class="font-bold text-teal-700">PDF</a></td></tr>
                    @empty
                        <tr><td colspan="5" class="p-8 text-center text-slate-500">لا توجد فواتير.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @else
            <table class="w-full text-right text-sm" data-shop-statement>
                <thead class="bg-slate-50 text-xs text-slate-500"><tr>@foreach ($statement['columns'] as $label)<th class="p-3">{{ $label }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($statement['rows'] as $row)
                        <tr>@foreach ($statement['columns'] as $key => $label)<td class="p-3">{{ \App\Services\ReportingService::formatCell($key, $row->{$key} ?? null) }}</td>@endforeach</tr>
                    @empty
                        <tr><td colspan="{{ count($statement['columns']) }}" class="p-8 text-center text-slate-500">لا توجد حركات.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    </div>
</div>
