<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $guarded = [];
    protected $fillable = [
    'tenant_id',
    'name',
    'domain',
    'phone',
    'address',
    'type',
    'created_by',
];

    public function stocks()
    {
        return $this->hasMany(BranchProduct::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
