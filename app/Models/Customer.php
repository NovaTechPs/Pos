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
    public function payments()
{
    return $this->morphMany(Payment::class, 'payable');
}
public function invoices()
    {
        return $this->hasMany(order::class);
    }
}
