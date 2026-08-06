<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
   protected $fillable = ['name', 'price','slug'];

    public function tenants()
    {
        return $this->hasMany(Tenant::class);
    }

public function features()
    {
        return $this->hasMany(PlanFeature::class);
    }

    /**
     * التحقق مما إذا كانت الخطة تحتوي على ميزة معينة
     */
    public function hasFeature(string $key): bool
    {
        return $this->features()->where('feature_key', $key)->exists();
    }

    /**
     * جلب قيمة الميزة (مثل الحد الأقصى للمنتجات)
     */
    public function getFeatureValue(string $key, $default = null)
    {
        $feature = $this->features()->where('feature_key', $key)->first();
        return $feature ? $feature->value : $default;
    }
}
