<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'show_in_website' => 'boolean',
        'images' => 'array',
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleOnWebsite(Builder $query): Builder
    {
        return $query->where('show_in_website', true);
    }

    /** @return HasMany<BranchProduct, $this> */
    public function branchProducts(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_products')
            ->withPivot(
                'stock_quantity',
                'alert_quantity',
                'retail_price',
                'wholesale_price',
                'offer_quantity',
                'offer_price',
                'min_wholesale_quantity'
            )
            ->withTimestamps();
    }

    public function priceForBranch(int $branchId): ?BranchProduct
    {
        return $this->branchProducts()->where('branch_id', $branchId)->first();
    }
}
