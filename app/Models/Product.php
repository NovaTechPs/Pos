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
}
