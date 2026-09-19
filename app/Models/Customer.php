<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * الحقول المسموح بإدخالها وتعديلها عبر Mass Assignment
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'phone',
        'type',
        'credit_limit',
        'balance',
    ];
    public function orders()
    {
        return $this->hasMany(Order::class);
    }


    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable');
    }
    public function invoices()
    {
        return $this->hasMany(Order::class);
    }
    public function getBalanceAttribute(): float
    {
        $openingBalance = (float) ($this->opening_balance ?? 0);

        // مجموع الفواتير المكتملة
        $totalOrders = (float) $this->orders()->where('status', 'completed')->sum('total');

        // مجموع المدفوع كاش مباشرة داخل الفواتير
        $paidInOrders = (float) $this->orders()->where('status', 'completed')->sum('paid_amount');

        // مجموع سندات القبض المستلمة خارج الفواتير المباشرة (تجنباً للحساب المزدوج)
        $externalReceipts = (float) $this->payments()
            ->where('type', 'receipt')
            ->where('notes', 'not like', 'سند قبض تلقائي للفاتورة%')
            ->sum('amount');

        // صافي الرصيد المترتب على العميل (الديون)
        return ($openingBalance + $totalOrders) - ($paidInOrders + $externalReceipts);
    }
}
