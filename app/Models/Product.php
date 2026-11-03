<?php

namespace App\Models;

use App\Services\PosProductSearch;
use App\Traits\BelongsToTenant;
use Brick\Math\BigInteger;
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

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $product->search_name = app(PosProductSearch::class)->normalize((string) $product->name);
        });
    }

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        if ($this->exists || filled($this->product_number)) {
            return parent::save($options);
        }

        $this->tenant_id ??= auth()->user()?->tenant_id;

        return $this->getConnection()->transaction(function () use ($options): bool {
            Tenant::on($this->getConnectionName())->whereKey($this->tenant_id)->lockForUpdate()->firstOrFail();

            $products = $this->getConnection()->table('products')
                ->where('tenant_id', $this->tenant_id)->whereNotNull('product_number');
            $driver = $this->getConnection()->getDriverName();
            if ($driver === 'mysql') {
                $products->whereRaw("product_number REGEXP '^[iI][0-9]+$'");
            } elseif ($driver === 'pgsql') {
                $products->whereRaw("product_number ~* '^[i][0-9]+$'");
            }
            $highestProductNumber = $products->orderByRaw('LENGTH(product_number) DESC')
                ->orderByRaw('LOWER(product_number) DESC')->value('product_number');
            $highestNumber = preg_match('/^i([0-9]+)$/i', (string) $highestProductNumber, $matches) === 1
                ? BigInteger::of($matches[1])
                : BigInteger::zero();

            $this->product_number = 'i'.str_pad((string) $highestNumber->plus(1), 8, '0', STR_PAD_LEFT);

            return parent::save($options);
        });
    }

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
