<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    public function branchStocks()
    {
        return $this->hasMany(BranchProduct::class);
    }
}
