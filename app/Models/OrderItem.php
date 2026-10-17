<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = [];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function originalOrderItem()
    {
        return $this->belongsTo(self::class, 'original_order_item_id');
    }

    public function returns()
    {
        return $this->hasMany(self::class, 'original_order_item_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function offer()
    {
        return $this->belongsTo(ProductOffer::class, 'offer_id');
    }
}
