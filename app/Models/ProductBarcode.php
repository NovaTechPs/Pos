<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class ProductBarcode extends Model
{
    protected $fillable = ['tenant_id', 'product_id', 'barcode'];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
       public function tenant()
    {
        return $this->belongsTo(
            Tenant::class,
            'tenant_id'
        );
    }
}
