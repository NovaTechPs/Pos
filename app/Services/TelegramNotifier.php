<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\TelegramLink;
use App\Models\TenantSetting;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends store events to linked Telegram chats: invoices and receipts to the customer they belong to,
 * and online orders, closed shifts and the daily summary to staff who asked for them. Messages go
 * out after the response is sent, so a till never waits for Telegram.
 */
#[Scoped]
class TelegramNotifier
{
    /** @var array<string, true> Events already queued in this request, so repeated saves send once. */
    private array $queued = [];

    public function __construct(private TelegramAlert $telegram) {}

    /** Hooks the notifier into the models whose changes it reports. */
    public static function listen(): void
    {
        Order::saved(function (Order $order): void {
            if ($order->status === 'completed' && $order->customer_id && $order->type !== 'return'
                && ($order->wasRecentlyCreated || $order->wasChanged('status'))) {
                app(self::class)->later('order:'.$order->id, fn (self $notifier) => $notifier->invoice($order->id));
            }
        });
        Payment::created(function (Payment $payment): void {
            if ($payment->type === 'receipt' && $payment->party_id && ! $payment->order_id) {
                app(self::class)->later('payment:'.$payment->id, fn (self $notifier) => $notifier->receipt($payment->id));
            }
        });
        Shift::updated(function (Shift $shift): void {
            if ($shift->status === 'closed' && $shift->wasChanged('status')) {
                app(self::class)->later('shift:'.$shift->id, fn (self $notifier) => $notifier->shiftClosed($shift->id));
            }
        });
        CustomerOrder::created(function (CustomerOrder $order): void {
            app(self::class)->later('customer-order:'.$order->id, fn (self $notifier) => $notifier->onlineOrderPlaced($order->id));
        });
        CustomerOrder::updated(function (CustomerOrder $order): void {
            if ($order->wasChanged('status') && $order->party_id) {
                app(self::class)->later('customer-order-status:'.$order->id, fn (self $notifier) => $notifier->customerOrderStatus($order->id));
            }
        });
    }

    /** @param Closure(self): void $send */
    public function later(string $key, Closure $send): void
    {
        if (! $this->telegram->botConfigured() || isset($this->queued[$key]) || config('branch-sync.mode') === 'edge' || config('app.desktop')) {
            return;
        }
        $this->queued[$key] = true;
        app()->terminating(function () use ($send): void {
            try {
                $send($this);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    public function invoice(int $orderId): void
    {
        $order = Order::withoutGlobalScopes()->whereKey($orderId)->whereNull('deleted_at')
            ->with(['items' => fn ($items) => $items->withoutGlobalScopes(), 'items.product' => fn ($product) => $product->withoutGlobalScopes(), 'user', 'branch'])
            ->first();
        $link = $order ? $this->customerLink($order->tenant_id, $order->customer_id) : null;
        if (! $order || ! $link) {
            return;
        }
        $party = Party::withoutGlobalScopes()->find($order->customer_id);
        $filename = PdfFilename::forDocument($party?->name, $order->invoice_number, 'زبون');
        $pdf = app('dompdf.wrapper')->loadView('pages.tenant.pos.partials.invoice-pdf', [
            'order' => $order,
            'accountBalance' => $party?->current_balance,
            'storeName' => $order->branch?->name ?: 'المتجر',
            'documentTitle' => Str::beforeLast($filename, '.pdf'),
        ])->output();

        $this->telegram->sendDocument($link->chat_id, $filename, $pdf, '🧾 فاتورة رقم '.$order->invoice_number
            ."\nالمبلغ: ".$this->money($order->total).$this->balanceLine($party));
    }

    public function receipt(int $paymentId): void
    {
        $payment = Payment::withoutGlobalScopes()->whereKey($paymentId)->whereNull('deleted_at')->first();
        $link = $payment ? $this->customerLink($payment->tenant_id, $payment->party_id) : null;
        if (! $payment || ! $link) {
            return;
        }
        $party = Party::withoutGlobalScopes()->find($payment->party_id);
        $store = Branch::withoutGlobalScopes()->whereKey($payment->branch_id)->value('name') ?: 'المتجر';

        $this->telegram->sendTo($link->chat_id, "✅ {$store}: تم استلام دفعة منك بقيمة ".$this->money($payment->amount)
            .($payment->voucher_number ? "\nرقم السند: {$payment->voucher_number}" : '').$this->balanceLine($party));
    }

    public function customerOrderStatus(int $customerOrderId): void
    {
        $order = CustomerOrder::query()->find($customerOrderId);
        $link = $order ? $this->customerLink($order->tenant_id, $order->party_id) : null;
        if (! $order || ! $link) {
            return;
        }

        $this->telegram->sendTo($link->chat_id, '📦 طلبك رقم '.$order->reference.' أصبح: '.(CustomerOrder::STATUSES[$order->status] ?? $order->status));
    }

    public function onlineOrderPlaced(int $customerOrderId): void
    {
        $order = CustomerOrder::query()->find($customerOrderId);
        if (! $order) {
            return;
        }
        $text = "🛒 طلب جديد من المتجر الإلكتروني\nرقم الطلب: {$order->reference}\nالزبون: {$order->customer_name} {$order->customer_phone}\nالمبلغ: ".$this->money($order->total);
        $this->staffLinks($order->tenant_id, 'online_orders')->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));
    }

    public function shiftClosed(int $shiftId): void
    {
        $shift = Shift::withoutGlobalScopes()->find($shiftId);
        if (! $shift) {
            return;
        }
        $branch = Branch::withoutGlobalScopes()->whereKey($shift->branch_id)->value('name');
        $closedBy = User::query()->whereKey($shift->closed_by ?: $shift->user_id)->value('name');
        $difference = (float) $shift->difference;
        $text = "🔒 إغلاق وردية — {$branch}\nأغلقها: {$closedBy}\nالمبيعات: ".$this->money($shift->total_sales)
            ."\nالمرتجعات: ".$this->money($shift->total_returns)
            ."\nالمتوقع في الصندوق: ".$this->money($shift->expected_cash)
            ."\nالموجود فعلاً: ".$this->money($shift->actual_cash)
            ."\n".(abs($difference) < 0.005 ? 'الصندوق مطابق ✅' : ($difference > 0 ? 'زيادة: ' : 'عجز ⚠️: ').$this->money(abs($difference)));
        $this->staffLinks($shift->tenant_id, 'shift_closed')->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));
    }

    /** Sends today's summary to every staff chat of the store that asked for it; returns how many were sent. */
    public function dailySummary(int $tenantId): int
    {
        $links = $this->staffLinks($tenantId, 'daily_summary');
        if ($links->isEmpty()) {
            return 0;
        }
        $sales = DB::table('orders')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('status', 'completed')
            ->whereBetween('created_at', [today(), today()->endOfDay()])
            ->selectRaw("SUM(CASE WHEN type <> 'return' THEN total ELSE 0 END) AS sales, SUM(CASE WHEN type = 'return' THEN total ELSE 0 END) AS returns, SUM(CASE WHEN type <> 'return' THEN 1 ELSE 0 END) AS invoices")
            ->first();
        $collected = DB::table('payments')->where('tenant_id', $tenantId)->whereNull('deleted_at')->where('type', 'receipt')
            ->whereBetween('created_at', [today(), today()->endOfDay()])->sum('amount');
        $lowStock = DB::table('branch_products')->join('products', 'products.id', '=', 'branch_products.product_id')
            ->where('branch_products.tenant_id', $tenantId)->whereNull('products.deleted_at')
            ->whereColumn('branch_products.stock_quantity', '<=', 'branch_products.alert_quantity')->count();
        $expiring = TenantSetting::getBool($tenantId, 'track_expiry') ? DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('branch_products', fn ($join) => $join->on('branch_products.branch_id', '=', 'purchases.branch_id')->on('branch_products.product_id', '=', 'purchase_items.product_id'))
            ->where('purchases.tenant_id', $tenantId)->whereNull('purchases.deleted_at')->where('branch_products.stock_quantity', '>', 0)
            ->whereNotNull('purchase_items.expiry_date')->whereDate('purchase_items.expiry_date', '<=', today()->addDays(30)->toDateString())
            ->count() : 0;

        $text = '📊 ملخص اليوم '.today()->format('Y-m-d')
            ."\nالمبيعات: ".$this->money($sales->sales ?? 0).' ('.(int) ($sales->invoices ?? 0).' فاتورة)'
            ."\nالمرتجعات: ".$this->money($sales->returns ?? 0)
            ."\nالمقبوضات: ".$this->money($collected)
            ."\nأصناف وصلت حد الطلب: {$lowStock}"
            .($expiring > 0 ? "\nدفعات تنتهي صلاحيتها خلال 30 يوماً: {$expiring}" : '');
        $links->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));

        return $links->count();
    }

    private function customerLink(int $tenantId, ?int $partyId): ?TelegramLink
    {
        return $partyId ? TelegramLink::query()->linked()->where('tenant_id', $tenantId)->where('party_id', $partyId)->first() : null;
    }

    /** @return Collection<int, TelegramLink> */
    private function staffLinks(int $tenantId, string $notification): Collection
    {
        $permission = TelegramLink::STAFF_NOTIFICATIONS[$notification]['permission'];

        return TelegramLink::query()->linked()->where('tenant_id', $tenantId)->whereNotNull('user_id')->with('user.role.permissions')->get()
            ->filter(fn (TelegramLink $link): bool => $link->wants($notification) && $link->user
                && (int) $link->user->tenant_id === $tenantId && $link->user->canAccessApplication() && $link->user->hasPermission($permission))
            ->values();
    }

    private function balanceLine(?Party $party): string
    {
        if (! $party) {
            return '';
        }
        $balance = (float) $party->current_balance;

        return "\n".($balance > 0.005 ? 'رصيدك المستحق: '.$this->money($balance) : ($balance < -0.005 ? 'رصيد لك عند المتجر: '.$this->money(abs($balance)) : 'لا يوجد رصيد مستحق'));
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2);
    }
}
