<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'show_in_website' => 'boolean',
        'images' => 'array',
    ];

    public function scopeVisibleOnWebsite($query)
    {
        return $query->where('show_in_website', true);
    }

    public function branchStocks()
    {
        return $this->hasMany(BranchProduct::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    public function branchProducts()
{
    return $this->hasMany(BranchProduct::class);
}

// جلب سعر المنتج لفرع محدد
public function priceForBranch($branchId)
{
    return $this->branchProducts()->where('branch_id', $branchId)->first();
}
public function barcodes()
{
    return $this->hasMany(ProductBarcode::class);
}
public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_products')
                    ->withPivot('stock_quantity')
                    ->withTimestamps();
    }
}
