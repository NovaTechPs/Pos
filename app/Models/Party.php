<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Party extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'phone',
        'email',
        'tax_number',
        'address',
        'type',
        'opening_balance',
        'current_balance',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'opening_balance' => 'decimal:2',
        'current_balance' => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Party $party): void {
            if (auth()->check() && empty($party->created_by)) {
                $party->created_by = auth()->id();
            }
        });

        static::updating(function (Party $party): void {
            if (auth()->check()) {
                $party->updated_by = auth()->id();
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | User who created the party
    |--------------------------------------------------------------------------
    */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | User who last updated the party
    |--------------------------------------------------------------------------
    */
    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Branch
    |--------------------------------------------------------------------------
    */
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Customer / Supplier scopes
    |--------------------------------------------------------------------------
    */

    public function scopeCustomers($query)
    {
        return $query->whereIn('type', [
            'customer',
            'both',
        ]);
    }

    public function scopeSuppliers($query)
    {
        return $query->whereIn('type', [
            'supplier',
            'both',
        ]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Sales Orders
    |--------------------------------------------------------------------------
    |
    | orders.customer_id → parties.id
    |
    */
    public function orders()
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Payments / Receipts
    |--------------------------------------------------------------------------
    |
    | payments.party_id → parties.id
    |
    */
    public function payments()
    {
        return $this->hasMany(Payment::class, 'party_id');
    }
}
