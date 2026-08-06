<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BranchProduct extends Model
{
    use BelongsToTenant;
protected $fillable = ['tenant_id', 'branch_id', 'product_id', 'stock_quantity', 'alert_quantity'];
    protected $guarded = [];

    public function product() { return $this->belongsTo(Product::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
}
