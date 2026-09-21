<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function products()
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

    public function getLimit(string $key, $default = 0)
    {
        if (! $this->plan) {
            return $default;
        }

        return $this->plan->getFeatureValue($key, $default);
    }
}
