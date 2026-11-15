<?php

namespace App\Models;

use Database\Factories\StoreCustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class StoreCustomer extends Authenticatable
{
    /** @use HasFactory<StoreCustomerFactory> */
    use HasFactory;

    protected $fillable = ['tenant_id', 'name', 'phone', 'password', 'email'];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime', 'phone_verified_at' => 'datetime'];
    }

    /** @return HasMany<StoreCustomerAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(StoreCustomerAddress::class)->where('tenant_id', $this->tenant_id);
    }
}
