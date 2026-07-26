<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
   use BelongsToTenant, SoftDeletes;
   protected $guarded = [];

    // علاقة الفاتورة بالمورد
    public function supplier()
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    // علاقة الفاتورة بعناصرها
    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
