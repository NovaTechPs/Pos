<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];
    protected $fillable = [
        'tenant_id',
        'name',
        'company_name',
        'phone',
        'email',
        'tax_number',
        'address',
        'opening_balance',
        'notes',
    ];

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }
}
