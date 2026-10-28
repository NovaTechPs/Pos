<?php

namespace App\Models;

use App\Services\InventoryQueryBuilder;
use App\Services\StockMovementContext;
use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchProduct extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function newEloquentBuilder(mixed $query): InventoryQueryBuilder
    {
        return new InventoryQueryBuilder($query);
    }

    protected static function booted(): void
    {
        static::creating(function (self $stock): void {
            $stock->forceFill(StockMovementContext::attributes());
            if ($stock->stock_source_type === 'manual') {
                $stock->stock_source_type = 'opening';
            }
        });
    }

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
}
