<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOrderItem extends Model
{
    protected $fillable = ['tenant_id', 'customer_order_id', 'product_id', 'quantity', 'unit_price', 'total'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'total' => 'decimal:2'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withoutGlobalScopes();
    }
}
