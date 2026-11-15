<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'shop_branding',
    ];

    protected function casts(): array
    {
        return ['shop_branding' => 'array'];
    }

    /** @return HasMany<BranchProduct, $this> */
    public function stocks(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
