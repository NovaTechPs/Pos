<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockVoucher extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'branch_id', 'voucher_number', 'type', 'notes', 'created_by'];

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<StockVoucherItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(StockVoucherItem::class);
    }
}
