<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A staff member's or a customer's Telegram chat, linked to the shared WaslaPOS bot.
 */
class TelegramLink extends Model
{
    /**
     * What a staff member can choose to receive, the permission each one needs, whether only the store
     * owner may receive it (every document of every branch), and whether a new link starts with it on.
     *
     * @var array<string, array{label: string, permission: string, owner_only: bool, default: bool}>
     */
    public const STAFF_NOTIFICATIONS = [
        'daily_summary' => ['label' => 'ملخص اليوم (المبيعات، الأصناف الناقصة، الصلاحية)', 'permission' => 'reports.analytics', 'owner_only' => false, 'default' => true],
        'shift_closed' => ['label' => 'إغلاق الورديات وفرق الصندوق', 'permission' => 'reports.daily_settlement', 'owner_only' => false, 'default' => true],
        'shift_problems' => ['label' => 'إغلاق وردية فيها عجز أو زيادة فقط', 'permission' => 'reports.daily_settlement', 'owner_only' => false, 'default' => false],
        'low_stock' => ['label' => 'تنبيه فوري عندما يصل صنف إلى حد الطلب بعد البيع', 'permission' => 'products.view', 'owner_only' => false, 'default' => true],
        'online_orders' => ['label' => 'طلبات المتجر الإلكتروني الجديدة', 'permission' => 'store.orders.view', 'owner_only' => false, 'default' => true],
        'sales_invoices' => ['label' => 'كل فاتورة مبيعات (الرقم، الزبون، المبلغ، المدفوع)', 'permission' => 'sales_invoices.view', 'owner_only' => true, 'default' => false],
        'sales_returns' => ['label' => 'كل مردود مبيعات', 'permission' => 'returns.view', 'owner_only' => true, 'default' => false],
        'receipts' => ['label' => 'كل سند قبض (دفعات الزبائن)', 'permission' => 'accounting.receipt.view', 'owner_only' => true, 'default' => false],
        'payments' => ['label' => 'كل سند صرف ومصروف', 'permission' => 'accounting.voucher.view', 'owner_only' => true, 'default' => false],
        'purchases' => ['label' => 'كل فاتورة مشتريات', 'permission' => 'purchases.view', 'owner_only' => true, 'default' => false],
        'document_changes' => ['label' => 'تنبيه عند تعديل أو حذف فاتورة أو سند (مَن فعلها والمبلغ قبل وبعد)', 'permission' => 'sales_invoices.view', 'owner_only' => true, 'default' => true],
        'risky_sales' => ['label' => 'تنبيه عند خصم كبير أو بيع بأقل من التكلفة', 'permission' => 'sales_invoices.view', 'owner_only' => true, 'default' => true],
        'weekly_report' => ['label' => 'تقرير أسبوعي PDF (المبيعات، الربح، المصاريف، أكثر الأصناف مبيعاً)', 'permission' => 'reports.analytics', 'owner_only' => true, 'default' => true],
        'monthly_report' => ['label' => 'تقرير شهري PDF', 'permission' => 'reports.analytics', 'owner_only' => true, 'default' => true],
    ];

    protected $fillable = ['tenant_id', 'user_id', 'party_id', 'chat_id', 'chat_name', 'link_token', 'link_token_expires_at', 'notifications', 'linked_at', 'last_reminded_at'];

    protected function casts(): array
    {
        return ['notifications' => 'array', 'link_token_expires_at' => 'datetime', 'linked_at' => 'datetime', 'last_reminded_at' => 'datetime'];
    }

    /**
     * The link row for a staff member or a customer, with a fresh one-time token valid for a week.
     */
    public static function issueFor(int $tenantId, ?int $userId = null, ?int $partyId = null): self
    {
        $link = self::query()->firstOrNew(['tenant_id' => $tenantId, 'user_id' => $userId, 'party_id' => $partyId]);
        $link->notifications ??= $userId ? array_map(fn (array $notification): bool => $notification['default'], self::STAFF_NOTIFICATIONS) : null;
        $link->link_token = Str::random(32);
        $link->link_token_expires_at = now()->addDays(7);
        $link->save();

        return $link;
    }

    /**
     * The start link printed as a QR on a customer's invoice, or null when the customer already
     * receives Telegram messages or the bot is not configured. A token with more than a week left is
     * reused, so earlier printed invoices keep working; otherwise a new one is issued for 60 days.
     */
    public static function printableStartUrl(int $tenantId, ?int $partyId): ?string
    {
        if (! $partyId || blank(config('services.telegram.bot_token')) || blank(config('services.telegram.bot_username'))) {
            return null;
        }
        $link = self::query()->where('tenant_id', $tenantId)->whereNull('user_id')->where('party_id', $partyId)->first();
        if ($link?->isLinked()) {
            return null;
        }
        if (! $link?->link_token || ! $link->link_token_expires_at || now()->addDays(7)->gt($link->link_token_expires_at)) {
            $link = self::issueFor($tenantId, partyId: $partyId);
            $link->update(['link_token_expires_at' => now()->addDays(60)]);
        }

        return $link->startUrl();
    }

    /** The bot address that links this chat when opened, or null until the bot is configured. */
    public function startUrl(): ?string
    {
        $bot = ltrim((string) config('services.telegram.bot_username'), '@');

        return $bot !== '' && $this->link_token ? 'https://t.me/'.$bot.'?start='.$this->link_token : null;
    }

    public function isLinked(): bool
    {
        return filled($this->chat_id);
    }

    /** Whether the user may receive the notification: owner-only ones go to the store owner alone. */
    public static function allows(User $user, string $notification): bool
    {
        $rule = self::STAFF_NOTIFICATIONS[$notification] ?? null;

        return $rule !== null && (! $rule['owner_only'] || $user->isTenantOwner()) && $user->hasPermission($rule['permission']);
    }

    public function wants(string $notification): bool
    {
        return (bool) ($this->notifications[$notification] ?? false);
    }

    /** @param Builder<self> $query */
    public function scopeLinked(Builder $query): void
    {
        $query->whereNotNull('chat_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withoutGlobalScopes();
    }
}
