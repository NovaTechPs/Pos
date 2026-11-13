<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'stock_count_id', 'product_id', 'counted_quantity', 'system_quantity', 'unit_cost'];

    protected $casts = ['counted_quantity' => 'decimal:3', 'system_quantity' => 'decimal:3', 'unit_cost' => 'decimal:2'];

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
