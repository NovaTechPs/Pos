<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class StockMovement extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['created_at' => 'datetime', 'quantity' => 'decimal:3', 'quantity_before' => 'decimal:3', 'quantity_after' => 'decimal:3'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['stock' => 'سجل حركة المخزون ثابت. استخدم حركة تصحيح مستقلة.']));
        static::deleting(fn () => throw ValidationException::withMessages(['stock' => 'لا يمكن حذف سجل حركة المخزون.']));
    }
}
