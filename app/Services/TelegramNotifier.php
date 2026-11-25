<?php

namespace App\Services;

use App\Http\Controllers\ReportPrintController;
use App\Models\Branch;
use App\Models\CustomerOrder;
use App\Models\Order;
use App\Models\Party;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Shift;
use App\Models\TelegramLink;
use App\Models\TenantSetting;
use App\Models\User;
use Closure;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Sends store events to linked Telegram chats: invoice, return and receipt PDFs to the customer they
 * belong to, and online orders, closed shifts, the daily summary and (for the owner) every invoice,
 * return, voucher and purchase to staff who asked for them. Messages go
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
            if ($order->status === 'completed' && ($order->wasRecentlyCreated || $order->wasChanged('status'))) {
                app(self::class)->later('order:'.$order->id, function (self $notifier) use ($order): void {
                    $notifier->invoice($order->id);
                    $notifier->staffOrder($order->id);
                    $notifier->saleChecks($order->id);
                });
            }
        });
        Payment::created(function (Payment $payment): void {
            if (! $payment->order_id && ! $payment->purchase_id && ! $payment->purchase_return_id) {
                app(self::class)->later('payment:'.$payment->id, function (self $notifier) use ($payment): void {
                    $notifier->receipt($payment->id);
                    $notifier->staffVoucher($payment->id);
                });
            }
        });
        Purchase::created(function (Purchase $purchase): void {
            app(self::class)->later('purchase:'.$purchase->id, fn (self $notifier) => $notifier->staffPurchase($purchase->id));
        });

        // A saved document whose amount changes, or that is deleted, warns the owner who did it.
        Order::updated(function (Order $order): void {
            if ($order->getOriginal('status') === 'completed' && $order->status === 'completed' && $order->wasChanged('total')) {
                app(self::class)->changed('order', $order->id, 'edited', (float) $order->getOriginal('total'));
            }
        });
        Order::deleted(function (Order $order): void {
            if ($order->getOriginal('status') === 'completed') {
                app(self::class)->changed('order', $order->id, 'deleted', (float) $order->getOriginal('total'));
            }
        });
        Payment::updated(function (Payment $payment): void {
            if ($payment->wasChanged('amount') && ! $payment->order_id && ! $payment->purchase_id && ! $payment->purchase_return_id) {
                app(self::class)->changed('payment', $payment->id, 'edited', (float) $payment->getOriginal('amount'));
            }
        });
        Payment::deleted(function (Payment $payment): void {
            if (! $payment->order_id && ! $payment->purchase_id && ! $payment->purchase_return_id) {
                app(self::class)->changed('payment', $payment->id, 'deleted', (float) $payment->getOriginal('amount'));
            }
        });
        Purchase::updated(function (Purchase $purchase): void {
            if ($purchase->wasChanged('total')) {
                app(self::class)->changed('purchase', $purchase->id, 'edited', (float) $purchase->getOriginal('total'));
            }
        });
        Purchase::deleted(function (Purchase $purchase): void {
            app(self::class)->changed('purchase', $purchase->id, 'deleted', (float) $purchase->getOriginal('total'));
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

    /**
     * Queues the owner's warning about an edited or deleted document. A document saved in this same
     * request is still being written (totals settle after its lines), so only later edits count, and
     * the first amount seen in the request is the "before" amount.
     *
     * @param  'order'|'payment'|'purchase'  $kind
     * @param  'edited'|'deleted'  $action
     */
    public function changed(string $kind, int $id, string $action, float $amountBefore): void
    {
        if (isset($this->queued[$kind.':'.$id]) || isset($this->queued[$kind.'-deleted:'.$id])) {
            return;
        }
        $userId = auth()->user()?->id;
        $this->later($kind.'-'.$action.':'.$id, fn (self $notifier) => $notifier->documentChanged($kind, $id, $action, $amountBefore, $userId));
    }

    /**
     * @param  'order'|'payment'|'purchase'  $kind
     * @param  'edited'|'deleted'  $action
     */
    public function documentChanged(string $kind, int $id, string $action, float $amountBefore, ?int $userId): void
    {
        $document = match ($kind) {
            'order' => Order::withoutGlobalScopes()->find($id),
            'payment' => Payment::withoutGlobalScopes()->find($id),
            'purchase' => Purchase::withoutGlobalScopes()->find($id),
        };
        if (! $document) {
            return;
        }
        [$name, $number, $amountNow] = match ($kind) {
            'order' => [$document->type === 'return' ? 'مردود مبيعات' : 'فاتورة مبيعات', $document->invoice_number, $document->total],
            'payment' => [$document->type === 'receipt' ? 'سند قبض' : 'سند صرف', $document->voucher_number, $document->amount],
            'purchase' => ['فاتورة مشتريات', $document->reference_number, $document->total],
        };
        $branch = Branch::withoutGlobalScopes()->whereKey($document->branch_id)->value('name');
        $by = $userId ? User::query()->whereKey($userId)->value('name') : null;
        $text = ($action === 'deleted' ? "🗑️ حذف {$name}" : "✏️ تعديل {$name}")." — {$branch}"
            .($number ? "\nالرقم: {$number}" : '')
            .($action === 'deleted'
                ? "\nالمبلغ: ".$this->money($amountBefore)
                : "\nالمبلغ قبل: ".$this->money($amountBefore)."\nالمبلغ بعد: ".$this->money($amountNow))
            ."\nبواسطة: ".($by ?: 'غير معروف')
            ."\nالوقت: ".now()->format('Y-m-d h:i A');
        $this->staffLinks($document->tenant_id, 'document_changes')
            ->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));
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
        $title = $order->type === 'return' ? '↩️ مردود مبيعات رقم ' : '🧾 فاتورة رقم ';
        $filename = PdfFilename::forDocument($party?->name, $order->invoice_number, 'زبون', $order->type === 'return' ? 'مردود مبيعات' : 'فاتورة مبيعات');
        $pdf = app('dompdf.wrapper')->loadView('pages.tenant.pos.partials.invoice-pdf', [
            'order' => $order,
            'accountBalance' => $party?->current_balance,
            'storeName' => $order->branch?->name ?: 'المتجر',
            'documentTitle' => Str::beforeLast($filename, '.pdf'),
        ])->output();

        $this->telegram->sendDocument($link->chat_id, $filename, $pdf, $title.$order->invoice_number
            ."\nالمبلغ: ".$this->money($order->total).$this->balanceLine($party));
    }

    /** Sends the customer their collection receipt as the same PDF voucher the store prints. */
    public function receipt(int $paymentId): void
    {
        $payment = Payment::withoutGlobalScopes()->whereKey($paymentId)->whereNull('deleted_at')->where('type', 'receipt')
            ->with(['party' => fn ($party) => $party->withoutGlobalScopes(), 'branch' => fn ($branch) => $branch->withoutGlobalScopes(),
                'creator' => fn ($creator) => $creator->withoutGlobalScopes(), 'tenant'])
            ->first();
        $link = $payment ? $this->customerLink($payment->tenant_id, $payment->party_id) : null;
        if (! $payment || ! $link) {
            return;
        }
        $party = $payment->party;
        $store = $payment->branch?->name ?: 'المتجر';
        $filename = PdfFilename::forDocument($party?->name, $payment->voucher_number ?: (string) $payment->id, 'سند قبض', 'سند قبض');
        $pdf = app('dompdf.wrapper')->loadView('pages.tenant.accounting.partials.voucher-pdf', [
            'voucher' => $payment,
            'documentTitle' => Str::beforeLast($filename, '.pdf'),
        ])->output();

        $this->telegram->sendDocument($link->chat_id, $filename, $pdf, "✅ {$store}: تم استلام دفعة منك بقيمة ".$this->money($payment->amount)
            .($payment->voucher_number ? "\nرقم السند: {$payment->voucher_number}" : '').$this->balanceLine($party));
    }

    /** Tells the owner about a completed sales invoice or return. */
    public function staffOrder(int $orderId): void
    {
        $order = Order::withoutGlobalScopes()->whereKey($orderId)->whereNull('deleted_at')->first();
        if (! $order) {
            return;
        }
        $isReturn = $order->type === 'return';
        $branch = Branch::withoutGlobalScopes()->whereKey($order->branch_id)->value('name');
        $customer = $order->customer_name ?: ($order->customer_id ? Party::withoutGlobalScopes()->whereKey($order->customer_id)->value('name') : null);
        $seller = $order->created_by ? User::query()->whereKey($order->created_by)->value('name') : null;
        $text = ($isReturn ? '↩️ مردود مبيعات' : '🧾 فاتورة مبيعات')." — {$branch}"
            ."\nالرقم: {$order->invoice_number}"
            ."\nالزبون: ".($customer ?: 'زبون عام')
            ."\nالمبلغ: ".$this->money($order->total)
            .($isReturn ? '' : "\nالمدفوع: ".$this->money($order->paid_amount).((float) $order->total - (float) $order->paid_amount > 0.005 ? "\nالمتبقي آجل: ".$this->money((float) $order->total - (float) $order->paid_amount) : ''))
            .($seller ? "\nبواسطة: {$seller}" : '');
        $this->staffLinks($order->tenant_id, $isReturn ? 'sales_returns' : 'sales_invoices')
            ->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));
    }

    /** Tells the owner about a receipt or payment voucher (customer collections, supplier payments, expenses). */
    public function staffVoucher(int $paymentId): void
    {
        $payment = Payment::withoutGlobalScopes()->whereKey($paymentId)->whereNull('deleted_at')
            ->with(['party' => fn ($party) => $party->withoutGlobalScopes()])->first();
        if (! $payment) {
            return;
        }
        $isReceipt = $payment->type === 'receipt';
        $branch = Branch::withoutGlobalScopes()->whereKey($payment->branch_id)->value('name');
        $account = $payment->employee_payment_id ? 'رواتب الموظفين' : $payment->accountName();
        $text = ($isReceipt ? '💰 سند قبض' : '💸 سند صرف')." — {$branch}"
            .($payment->voucher_number ? "\nالرقم: {$payment->voucher_number}" : '')
            ."\nالحساب: {$account}"
            ."\nالمبلغ: ".$this->money($payment->amount)
            .(filled($payment->notes) ? "\nالبيان: ".Str::limit((string) $payment->notes, 200) : '');
        $this->staffLinks($payment->tenant_id, $isReceipt ? 'receipts' : 'payments')
            ->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));
    }

    /** Tells the owner about a new purchase invoice. */
    public function staffPurchase(int $purchaseId): void
    {
        $purchase = Purchase::withoutGlobalScopes()->whereKey($purchaseId)->whereNull('deleted_at')->first();
        if (! $purchase) {
            return;
        }
        $branch = Branch::withoutGlobalScopes()->whereKey($purchase->branch_id)->value('name');
        $supplier = $purchase->supplier_id ? Party::withoutGlobalScopes()->whereKey($purchase->supplier_id)->value('name') : null;
        $text = "📦 فاتورة مشتريات — {$branch}"
            .($purchase->reference_number ? "\nالرقم: {$purchase->reference_number}" : '')
            ."\nالمورد: ".($supplier ?: 'مورد عام')
            ."\nالمبلغ: ".$this->money($purchase->total)
            ."\nالمدفوع: ".$this->money($purchase->paid_amount);
        $this->staffLinks($purchase->tenant_id, 'purchases')
            ->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));
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
        $links = $this->staffLinks($shift->tenant_id, 'shift_closed');
        if (abs($difference) >= 0.005) {
            $links = $links->merge($this->staffLinks($shift->tenant_id, 'shift_problems'))->unique('chat_id');
        }
        $links->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));
    }

    /**
     * Warns the owner about a completed sale with a large discount or lines sold below cost, and
     * tells staff who asked for it which items this sale brought down to their reorder level.
     */
    public function saleChecks(int $orderId): void
    {
        $order = Order::withoutGlobalScopes()->whereKey($orderId)->whereNull('deleted_at')->where('type', '<>', 'return')->first();
        if (! $order) {
            return;
        }
        $items = DB::table('order_items')->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('order_items.order_id', $orderId)
            ->get(['order_items.product_id', 'order_items.quantity', 'order_items.unit_price', 'order_items.total_price', 'order_items.total_cost', 'order_items.discount', 'products.name']);
        $branch = Branch::withoutGlobalScopes()->whereKey($order->branch_id)->value('name');
        $seller = $order->created_by ? User::query()->whereKey($order->created_by)->value('name') : null;

        $this->riskySale($order, $items, (string) $branch, $seller);
        $this->lowStockAfterSale($order, $items, (string) $branch);
    }

    /** @param Collection<int, stdClass> $items */
    private function riskySale(Order $order, Collection $items, string $branch, ?string $seller): void
    {
        $threshold = (float) TenantSetting::get($order->tenant_id, 'telegram_discount_alert_percent', 10);
        $discount = (float) $order->discount + (float) $items->sum('discount');
        $beforeDiscount = (float) $order->total + $discount;
        $discountPercent = $beforeDiscount > 0.005 ? $discount / $beforeDiscount * 100 : 0;
        $belowCost = $items->filter(fn (stdClass $item): bool => (float) $item->unit_price > 0 && (float) $item->total_price + 0.005 < (float) $item->total_cost);
        $cost = (float) $items->sum('total_cost');
        $atLoss = $cost > 0.005 && (float) $order->total + 0.005 < $cost;
        $bigDiscount = $threshold > 0 && $discountPercent + 0.001 >= $threshold;
        if (! $bigDiscount && $belowCost->isEmpty() && ! $atLoss) {
            return;
        }

        $text = "⚠️ فاتورة تحتاج مراجعة — {$branch}\nالرقم: {$order->invoice_number}\nالمبلغ: ".$this->money($order->total)
            .($bigDiscount ? "\nخصم كبير: ".$this->money($discount).' ('.number_format($discountPercent, 1).'%)' : '')
            .($atLoss ? "\nالفاتورة بأقل من تكلفتها ({$this->money($cost)})" : '')
            .$belowCost->take(10)->map(fn (stdClass $item): string => "\n• {$item->name}: بيع ".$this->money($item->total_price).' والتكلفة '.$this->money($item->total_cost))->implode('')
            .($seller ? "\nبواسطة: {$seller}" : '');
        $this->staffLinks($order->tenant_id, 'risky_sales')->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));
    }

    /** @param Collection<int, stdClass> $items */
    private function lowStockAfterSale(Order $order, Collection $items, string $branch): void
    {
        $sold = $items->groupBy('product_id')->map(fn (Collection $lines): float => (float) $lines->sum('quantity'));
        if ($sold->isEmpty()) {
            return;
        }
        $crossed = DB::table('branch_products')->join('products', 'products.id', '=', 'branch_products.product_id')
            ->where('branch_products.branch_id', $order->branch_id)->whereIn('branch_products.product_id', $sold->keys())
            ->whereColumn('branch_products.stock_quantity', '<=', 'branch_products.alert_quantity')
            ->get(['branch_products.product_id', 'branch_products.stock_quantity', 'branch_products.alert_quantity', 'products.name'])
            ->filter(fn (stdClass $row): bool => (float) $row->stock_quantity + $sold[$row->product_id] > (float) $row->alert_quantity);
        if ($crossed->isEmpty()) {
            return;
        }

        $text = "📉 أصناف وصلت حد الطلب — {$branch}"
            .$crossed->map(fn (stdClass $row): string => "\n• {$row->name}: المتبقي ".$this->quantity($row->stock_quantity).' (حد الطلب '.$this->quantity($row->alert_quantity).')')->implode('');
        $this->staffLinks($order->tenant_id, 'low_stock')->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));
    }

    /** Sends today's summary to every staff chat of the store that asked for it; returns how many were sent. */
    public function dailySummary(int $tenantId): int
    {
        $links = $this->staffLinks($tenantId, 'daily_summary');
        if ($links->isEmpty()) {
            return 0;
        }
        $text = $this->todayText($tenantId);
        $links->each(fn (TelegramLink $link) => $this->telegram->sendTo($link->chat_id, $text));

        return $links->count();
    }

    /** Today's sales, returns, collections, low stock and expiring batches for the store, up to now. */
    public function todayText(int $tenantId): string
    {
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

        return '📊 ملخص اليوم '.today()->format('Y-m-d')
            ."\nالمبيعات: ".$this->money($sales->sales ?? 0).' ('.(int) ($sales->invoices ?? 0).' فاتورة)'
            ."\nالمرتجعات: ".$this->money($sales->returns ?? 0)
            ."\nالمقبوضات: ".$this->money($collected)
            ."\nأصناف وصلت حد الطلب: {$lowStock}"
            .($expiring > 0 ? "\nدفعات تنتهي صلاحيتها خلال 30 يوماً: {$expiring}" : '');
    }

    /**
     * Sends every linked customer of the store last month's account statement as a PDF, when the
     * store has the monthly statement on and the customer had movement or still has a balance.
     * Returns how many statements were sent.
     */
    public function monthlyStatements(int $tenantId, ?Carbon $month = null): int
    {
        if (! TenantSetting::getBool($tenantId, 'telegram_monthly_statements', true)) {
            return 0;
        }
        $month ??= now()->subMonthNoOverflow();
        $from = $month->copy()->startOfMonth()->toDateString();
        $to = $month->copy()->endOfMonth()->toDateString();

        return TelegramLink::query()->linked()->where('tenant_id', $tenantId)->whereNotNull('party_id')->get()
            ->filter(fn (TelegramLink $link): bool => $this->statement($link, $from, $to, skipQuiet: true))->count();
    }

    /**
     * Sends the customer of a link their account statement for the period as a PDF. With $skipQuiet,
     * a period without movement that ends with no balance sends nothing. Returns whether it was sent.
     */
    public function statement(TelegramLink $link, string $from, string $to, bool $skipQuiet = false): bool
    {
        $party = Party::withoutGlobalScopes()->where('tenant_id', $link->tenant_id)->find($link->party_id);
        $owner = User::query()->where('tenant_id', $link->tenant_id)->where('type', 'tenant_user')->where('is_owner', true)->first();
        if (! $party || ! $owner || ! $link->chat_id) {
            return false;
        }
        $definition = (new ReportingService($owner, null, false, 'statement'))
            ->prepare('statement', ['party_id' => $party->id, 'from' => $from, 'to' => $to, 'party_type' => 'all', 'balance_side' => 'all', 'stock_filter' => 'all']);
        $rows = $definition['query']->limit(ReportPrintController::PDF_ROW_LIMIT + 1)->get();
        if ($skipQuiet && $rows->isEmpty() && abs((float) ($definition['summary']['رصيد آخر الفترة'] ?? 0)) < 0.005) {
            return false;
        }
        // The customer sees the period figures only, not the store's reconciliation checks.
        $definition['summary'] = array_intersect_key($definition['summary'], array_flip(['رصيد أول الفترة', 'إجمالي المدين', 'إجمالي الدائن', 'رصيد آخر الفترة']));
        $definition['notes'] = [$party->name.' — الرصيد الموجب مستحق عليك للمتجر، والسالب رصيد لك عند المتجر.'];
        $storeName = DB::table('tenants')->where('id', $link->tenant_id)->value('name') ?: 'المتجر';
        $filename = PdfFilename::forReport('كشف حساب', $party->name, $from, $to);
        $pdf = app('dompdf.wrapper')->loadView('pages.tenant.reports.pdf', [
            'title' => 'كشف حساب', 'definition' => $definition, 'from' => $from, 'to' => $to,
            'tenantName' => $storeName, 'branchName' => 'كل الفروع', 'filename' => $filename,
            'rows' => $rows->take(ReportPrintController::PDF_ROW_LIMIT), 'truncated' => $rows->count() > ReportPrintController::PDF_ROW_LIMIT,
        ])->setPaper('a4', 'landscape')->output();

        return $this->telegram->sendDocument($link->chat_id, $filename, $pdf, "📄 {$storeName}: كشف حسابك من {$from} إلى {$to}".$this->balanceLine($party));
    }

    /**
     * Reminds linked customers who still owe the store and have paid nothing for the store's chosen
     * number of days, at most once in that many days. Off (0 days) unless the owner turns it on.
     * Returns how many reminders were sent.
     */
    public function debtReminders(int $tenantId): int
    {
        $days = (int) TenantSetting::get($tenantId, 'telegram_debt_reminder_days', 0);
        if ($days <= 0) {
            return 0;
        }
        $since = now()->subDays($days);
        $storeName = DB::table('tenants')->where('id', $tenantId)->value('name') ?: 'المتجر';

        return TelegramLink::query()->linked()->where('tenant_id', $tenantId)->whereNotNull('party_id')
            ->where(fn ($query) => $query->whereNull('last_reminded_at')->orWhere('last_reminded_at', '<=', $since))
            ->with('party')->get()
            ->filter(function (TelegramLink $link) use ($tenantId, $since, $storeName): bool {
                if (! $link->party || (float) $link->party->current_balance <= 0.005) {
                    return false;
                }
                $paidRecently = DB::table('payments')->where('tenant_id', $tenantId)->where('party_id', $link->party_id)
                    ->where('type', 'receipt')->whereNull('deleted_at')->where('created_at', '>', $since)->exists();
                if ($paidRecently) {
                    return false;
                }
                $sent = $this->telegram->sendTo($link->chat_id, "🔔 {$storeName}: تذكير لطيف\nرصيدك المستحق: "
                    .$this->money($link->party->current_balance)."\nلكشف الحساب أرسل /statement. شكراً لتعاملك معنا.");
                if ($sent) {
                    $link->update(['last_reminded_at' => now()]);
                }

                return $sent;
            })->count();
    }

    private function customerLink(int $tenantId, ?int $partyId): ?TelegramLink
    {
        return $partyId ? TelegramLink::query()->linked()->where('tenant_id', $tenantId)->where('party_id', $partyId)->first() : null;
    }

    /** @return Collection<int, TelegramLink> */
    private function staffLinks(int $tenantId, string $notification): Collection
    {
        return TelegramLink::query()->linked()->where('tenant_id', $tenantId)->whereNotNull('user_id')->with('user.role.permissions')->get()
            ->filter(fn (TelegramLink $link): bool => $link->wants($notification) && $link->user
                && (int) $link->user->tenant_id === $tenantId && $link->user->canAccessApplication() && TelegramLink::allows($link->user, $notification))
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

    private function quantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.');
    }
}
