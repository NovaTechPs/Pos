<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function hasFeature(string $key): bool
    {
        if (! $this->plan || ! $this->plan->is_active) {
            return false;
        }

        return $this->plan->hasFeature($key);
    }

    public function getLimit(string $key, mixed $default = 0): mixed
    {
        if (! $this->plan) {
            return $default;
        }

        return $this->plan->getFeatureValue($key, $default);
    }

    /** @return HasOne<TenantSetting, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(TenantSetting::class);
    }
}
