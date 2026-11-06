<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockVoucherItem extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'stock_voucher_id', 'product_id', 'quantity', 'unit_cost'];

    protected $casts = ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:2'];

    /** @return BelongsTo<StockVoucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(StockVoucher::class, 'stock_voucher_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
