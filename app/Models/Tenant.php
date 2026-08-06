<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $guarded = [];

    protected $fillable = ['name', 'plan_id', 'is_active', 'domain', 'owner_id'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * فحص هل المتجر يملك ميزة/صلاحية معينة من خطته
     */
    public function hasFeature(string $key): bool
    {
        if (! $this->plan || ! $this->plan->is_active) {
            return false;
        }

        return $this->plan->hasFeature($key);
    }

    /**
     * جلب قيمة الحد الأقصى للميزة
     */
    public function getLimit(string $key, $default = 0)
    {
        if (! $this->plan) {
            return $default;
        }

        return $this->plan->getFeatureValue($key, $default);
    }

    // جلب صاحب المتجر الأصلي
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    // جلب جميع الموظفين التابعين لهذا المتجر (كاشير، باص، جملة...)
    public function employees()
    {
        return $this->hasMany(User::class, 'tenant_id');
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
