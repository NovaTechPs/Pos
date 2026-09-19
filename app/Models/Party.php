<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Party extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * الحقول المسموح بتعبئتها بحرية (Mass Assignment)
     */
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'phone',
        'email',
        'tax_number',
        'address',
        'type', // 'customer', 'supplier', 'both'
        'opening_balance',
        'current_balance',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /**
     * تحويل أنواع البيانات عند الاستعلام (Casting)
     */
    protected $casts = [
        'is_active' => 'boolean',
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
    ];

    /**
     * الأحداث التلقائية (Boot Method)
     * لتعبئة created_by و updated_by تلقائياً بالسيشن الحالي للمستخدم
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($party) {
            if (auth()->check() && empty($party->created_by)) {
                $party->created_by = auth()->id();
            }
        });

        static::updating(function ($party) {
            if (auth()->check()) {
                $party->updated_by = auth()->id();
            }
        });
    }

    /* =========================================================================
     | العلاقات (Relationships)
     | ========================================================================= */

    /**
     * علاقة المستخدم الذي أنشأ السجل
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * علاقة المستخدم الذي عدّل السجل
     */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * علاقة المستأجر (Tenant)
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * علاقة الفرع (Branch)
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /* =========================================================================
     | النطاقات (Scopes) للتصفية الفعالة
     | ========================================================================= */

    /**
     * فلترة العملاء فقط (تشمل الزبائن ومن هم زبون ومورد معاً)
     */
    public function scopeCustomers($query)
    {
        return $query->whereIn('type', ['customer', 'both']);
    }

    /**
     * فلترة الموردين فقط (تشمل الموردين ومن هم زبون ومورد معاً)
     */
    public function scopeSuppliers($query)
    {
        return $query->whereIn('type', ['supplier', 'both']);
    }

    /**
     * فلترة السجلات النشطة فقط
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id');
    }
    public function payments()
    {
return $this->morphMany(Payment::class, 'payable');
    }
}
