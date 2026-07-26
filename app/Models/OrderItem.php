<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
  use BelongsToTenant;

    public $timestamps = false;
    protected $guarded = [];

    public function product() { return $this->belongsTo(Product::class); }
}
