<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_gift' => 'boolean'];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function originalOrderItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_order_item_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(self::class, 'original_order_item_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductOffer, $this> */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(ProductOffer::class, 'offer_id');
    }
}
