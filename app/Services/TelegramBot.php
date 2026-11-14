<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Party;
use App\Models\TelegramLink;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Answers messages sent to the shared WaslaPOS bot: /start <token> links the chat, and linked
 * customers can ask for their balance or their latest invoices.
 */
class TelegramBot
{
    private const HELP = "الأوامر المتاحة:\n/balance — رصيدي\n/invoices — آخر فواتيري\n/stop — إيقاف الرسائل";

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
        match (true) {
            $command === '/start' => $this->start($chatId, trim(Str::after($text, ' ')) === $text ? '' : trim(Str::after($text, ' ')), $message),
            in_array($command, ['/balance', 'رصيدي', 'الرصيد'], true) => $this->balance($chatId),
            in_array($command, ['/invoices', 'فواتيري', 'الفواتير'], true) => $this->invoices($chatId),
            in_array($command, ['/stop', 'إيقاف', 'ايقاف'], true) => $this->stop($chatId),
            default => $this->telegram->sendTo($chatId, self::HELP),
        };
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
            : "✅ تم ربط حسابك في {$store}.\nستصلك الإشعارات التي اخترتها من إعدادات حسابك.\n/stop — إيقاف الرسائل");
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
