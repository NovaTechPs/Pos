<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            $order->sync_uuid ??= (string) Str::uuid();
            if ($order->type === 'return' && $order->original_order_id) {
                $original = self::query()->find($order->original_order_id);
                $order->source ??= $original?->source;
                $order->sale_kind ??= $original?->sale_kind;
            }
            $order->source ??= match ($order->type) {
                'pos' => 'pos', 'online' => 'online', default => request()->is('api/*') ? 'api' : 'web',
            };
            $order->sale_kind ??= $order->type === 'wholesale' ? 'wholesale' : 'retail';
        });
    }

    protected $guarded = [];

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function originalOrder(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_order_id');
    }

    /** @return HasMany<Order, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(self::class, 'original_order_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'customer_id');
    }
}
