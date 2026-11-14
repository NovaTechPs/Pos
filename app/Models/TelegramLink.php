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
     * What a staff member can choose to receive, and the permission each one needs.
     *
     * @var array<string, array{label: string, permission: string}>
     */
    public const STAFF_NOTIFICATIONS = [
        'daily_summary' => ['label' => 'ملخص اليوم (المبيعات، الأصناف الناقصة، الصلاحية)', 'permission' => 'reports.analytics'],
        'shift_closed' => ['label' => 'إغلاق الورديات وفرق الصندوق', 'permission' => 'reports.daily_settlement'],
        'online_orders' => ['label' => 'طلبات المتجر الإلكتروني الجديدة', 'permission' => 'store.orders.view'],
    ];

    protected $fillable = ['tenant_id', 'user_id', 'party_id', 'chat_id', 'chat_name', 'link_token', 'link_token_expires_at', 'notifications', 'linked_at'];

    protected function casts(): array
    {
        return ['notifications' => 'array', 'link_token_expires_at' => 'datetime', 'linked_at' => 'datetime'];
    }

    /**
     * The link row for a staff member or a customer, with a fresh one-time token valid for a week.
     */
    public static function issueFor(int $tenantId, ?int $userId = null, ?int $partyId = null): self
    {
        $link = self::query()->firstOrNew(['tenant_id' => $tenantId, 'user_id' => $userId, 'party_id' => $partyId]);
        $link->notifications ??= $userId ? array_fill_keys(array_keys(self::STAFF_NOTIFICATIONS), true) : null;
        $link->link_token = Str::random(32);
        $link->link_token_expires_at = now()->addDays(7);
        $link->save();

        return $link;
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
