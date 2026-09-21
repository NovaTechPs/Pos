<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];

    public function supplier()
    {
        return $this->belongsTo(Party::class, 'supplier_id')->withTrashed();
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
