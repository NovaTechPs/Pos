<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Party;
use App\Models\TelegramLink;
use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Answers messages sent to the shared WaslaPOS bot: /start <token> links the chat; linked customers
 * ask for their balance, latest invoices or statement; linked staff ask for today's figures, an
 * item's stock or the biggest customer debts, as far as their permissions allow.
 */
class TelegramBot
{
    private const HELP = "الأوامر المتاحة:\n/balance — رصيدي\n/invoices — آخر فواتيري\n/statement — كشف حسابي PDF\n/stop — إيقاف الرسائل";

    private const STAFF_HELP = "الأوامر المتاحة:\n/today — مبيعات اليوم حتى الآن\n/stock اسم الصنف أو الباركود — الكمية في كل فرع\n/debts — أكثر الزبائن ديوناً\n/stop — إيقاف الرسائل";

    public function __construct(private TelegramAlert $telegram, private TelegramNotifier $notifier) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        $message = $update['message'] ?? null;
        $chatId = isset($message['chat']['id']) ? (string) $message['chat']['id'] : null;
        $text = trim((string) ($message['text'] ?? ''));
        if (! $chatId || $text === '' || ($message['chat']['type'] ?? 'private') !== 'private') {
            return;
        }

        $command = Str::lower(Str::before(Str::before($text, ' '), '@'));
        $argument = str_contains($text, ' ') ? trim(Str::after($text, ' ')) : '';
        match (true) {
            $command === '/start' => $this->start($chatId, $argument, $message),
            in_array($command, ['/balance', 'رصيدي', 'الرصيد'], true) => $this->balance($chatId),
            in_array($command, ['/invoices', 'فواتيري', 'الفواتير'], true) => $this->invoices($chatId),
            in_array($command, ['/statement', 'كشف', 'كشف_حساب'], true) => $this->statement($chatId),
            in_array($command, ['/today', 'اليوم'], true) => $this->staffAnswer($chatId, 'reports.analytics', fn (User $user): string => $this->notifier->todayText((int) $user->tenant_id)),
            in_array($command, ['/stock', 'مخزون', 'المخزون'], true) => $this->staffAnswer($chatId, 'products.view', fn (User $user): string => $this->stock((int) $user->tenant_id, $argument)),
            in_array($command, ['/debts', 'الديون', 'ديون'], true) => $this->staffAnswer($chatId, 'customers.view', fn (User $user): string => $this->debts((int) $user->tenant_id)),
            in_array($command, ['/stop', 'إيقاف', 'ايقاف'], true) => $this->stop($chatId),
            default => $this->telegram->sendTo($chatId, $this->staffLink($chatId) && $this->customerLinks($chatId)->isEmpty() ? self::STAFF_HELP : self::HELP),
        };
    }

    /** Sends the customer their statement from the start of the month two months ago until today. */
    private function statement(string $chatId): void
    {
        $links = $this->customerLinks($chatId);
        if ($links->isEmpty()) {
            $this->telegram->sendTo($chatId, 'هذا الحساب غير مربوط بأي زبون.');

            return;
        }
        $from = now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString();
        $links->each(fn (TelegramLink $link) => $this->notifier->statement($link, $from, today()->toDateString()));
    }

    /** @param Closure(User): string $answer */
    private function staffAnswer(string $chatId, string $permission, Closure $answer): void
    {
        $user = $this->staffLink($chatId)?->user;
        if (! $user instanceof User || ! $user->tenant_id || ! $user->canAccessApplication()) {
            $this->telegram->sendTo($chatId, 'هذا الأمر لموظفي المتجر المربوطين فقط.');

            return;
        }
        if (! $user->hasPermission($permission)) {
            $this->telegram->sendTo($chatId, 'لا تملك صلاحية هذا الأمر.');

            return;
        }
        $this->telegram->sendTo($chatId, $answer($user));
    }

    private function stock(int $tenantId, string $search): string
    {
        if (mb_strlen($search) < 2) {
            return "اكتب اسم الصنف أو الباركود بعد الأمر، مثلاً:\n/stock سكر";
        }
        $products = DB::table('products')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->where(fn ($query) => $query->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('product_number', $search)
                ->orWhereIn('id', DB::table('product_barcodes')->where('tenant_id', $tenantId)->where('barcode', $search)->select('product_id')))
            ->orderBy('name')->limit(5)->get(['id', 'name']);
        if ($products->isEmpty()) {
            return "لا يوجد صنف يطابق «{$search}».";
        }
        $stock = DB::table('branch_products')->join('branches', 'branches.id', '=', 'branch_products.branch_id')
            ->where('branch_products.tenant_id', $tenantId)->whereIn('branch_products.product_id', $products->pluck('id'))
            ->orderBy('branches.name')->get(['branch_products.product_id', 'branch_products.stock_quantity', 'branch_products.alert_quantity', 'branches.name'])
            ->groupBy('product_id');

        return '📦 المخزون'.$products->map(fn (stdClass $product): string => "\n\n{$product->name}"
            .collect($stock[$product->id] ?? [])->map(fn (stdClass $row): string => "\n• {$row->name}: ".$this->quantity($row->stock_quantity)
                .((float) $row->stock_quantity <= (float) $row->alert_quantity ? ' ⚠️' : ''))->implode(''))->implode('');
    }

    private function debts(int $tenantId): string
    {
        $customers = DB::table('parties')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('type', ['customer', 'both'])
            ->where('current_balance', '>', 0.005)->orderByDesc('current_balance');
        $total = (float) (clone $customers)->sum('current_balance');
        $top = $customers->limit(10)->get(['name', 'phone', 'current_balance']);
        if ($top->isEmpty()) {
            return 'لا توجد ديون على الزبائن ✅';
        }

        return "💳 أكثر الزبائن ديوناً\nإجمالي الديون: ".number_format($total, 2)
            .$top->map(fn (stdClass $party, int $index): string => "\n".($index + 1).". {$party->name}: ".number_format((float) $party->current_balance, 2))->implode('');
    }

    private function staffLink(string $chatId): ?TelegramLink
    {
        return TelegramLink::query()->where('chat_id', $chatId)->whereNotNull('user_id')->with('user.role.permissions')->first();
    }

    private function quantity(mixed $quantity): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.');
    }

    /** @param array<string, mixed> $message */
    private function start(string $chatId, string $token, array $message): void
    {
        $link = $token !== '' ? TelegramLink::query()->where('link_token', $token)->where('link_token_expires_at', '>', now())->first() : null;
        if (! $link) {
            $this->telegram->sendTo($chatId, $token === ''
                ? "أهلاً بك في واصلة 👋\nلربط حسابك افتح رابط الربط الذي أرسله لك المتجر."
                : 'رابط الربط غير صالح أو انتهت مدته. اطلب من المتجر رابطاً جديداً.');

            return;
        }

        $link->update([
            'chat_id' => $chatId,
            'chat_name' => trim(($message['from']['first_name'] ?? '').' '.($message['from']['last_name'] ?? '')) ?: null,
            'link_token' => null,
            'link_token_expires_at' => null,
            'linked_at' => now(),
        ]);
        $store = Tenant::query()->whereKey($link->tenant_id)->value('name') ?: 'المتجر';

        $this->telegram->sendTo($chatId, $link->party_id
            ? "✅ تم ربط حسابك لدى {$store}.\nستصلك فواتيرك وسندات القبض هنا تلقائياً.\n\n".self::HELP
            : "✅ تم ربط حسابك في {$store}.\nستصلك الإشعارات التي اخترتها من إعدادات حسابك.\n\n".self::STAFF_HELP);
    }

    private function balance(string $chatId): void
    {
        $links = $this->customerLinks($chatId);
        if ($links->isEmpty()) {
            $this->telegram->sendTo($chatId, 'هذا الحساب غير مربوط بأي زبون.');

            return;
        }
        $lines = $links->map(function (TelegramLink $link): string {
            $party = Party::withoutGlobalScopes()->find($link->party_id);
            $store = Tenant::query()->whereKey($link->tenant_id)->value('name');
            $balance = (float) $party?->current_balance;

            return "{$store}: ".($balance > 0.005 ? 'مستحق عليك '.number_format($balance, 2) : ($balance < -0.005 ? 'لك عند المتجر '.number_format(abs($balance), 2) : 'لا يوجد رصيد'));
        });
        $this->telegram->sendTo($chatId, "💰 رصيدك\n".$lines->implode("\n"));
    }

    private function invoices(string $chatId): void
    {
        $links = $this->customerLinks($chatId);
        $orderIds = $links->flatMap(fn (TelegramLink $link) => Order::withoutGlobalScopes()->where('tenant_id', $link->tenant_id)
            ->where('customer_id', $link->party_id)->whereNull('deleted_at')->where('status', 'completed')->where('type', '<>', 'return')
            ->latest('id')->limit(3)->pluck('id'));
        if ($orderIds->isEmpty()) {
            $this->telegram->sendTo($chatId, 'لا توجد فواتير بعد.');

            return;
        }
        $orderIds->each(fn (int $orderId) => $this->notifier->invoice($orderId));
    }

    private function stop(string $chatId): void
    {
        $count = TelegramLink::query()->where('chat_id', $chatId)->update(['chat_id' => null, 'chat_name' => null, 'linked_at' => null]);
        $this->telegram->sendTo($chatId, $count > 0 ? 'تم إيقاف الرسائل وفك الربط. يمكنك الربط من جديد برابط من المتجر.' : 'هذا الحساب غير مربوط.');
    }

    /** @return Collection<int, TelegramLink> */
    private function customerLinks(string $chatId): Collection
    {
        return TelegramLink::query()->where('chat_id', $chatId)->whereNotNull('party_id')->get();
    }
}
