<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Party extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'name',
        'code',
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

    /** @param array<string, mixed> $options */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return $this->getConnection()->transaction(function () use ($options): bool {
            Tenant::on($this->getConnectionName())->whereKey($this->tenant_id)->lockForUpdate()->firstOrFail();

            if (blank($this->code)) {
                $lastCode = static::on($this->getConnectionName())->withTrashed()
                    ->where('tenant_id', $this->tenant_id)
                    ->max($this->getConnection()->raw('CAST(code AS DECIMAL(20, 0))'));

                $this->code = (string) ((int) $lastCode + 1);
            }

            return parent::save($options);
        });
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Party $party): void {
            if (auth()->check() && empty($party->created_by)) {
                $party->creator()->associate(auth()->user());
            }
        });

        static::updating(function (Party $party): void {
            if (auth()->check()) {
                $party->updater()->associate(auth()->user());
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | User who created the party
    |--------------------------------------------------------------------------
    */
    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | User who last updated the party
    |--------------------------------------------------------------------------
    */
    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Tenant
    |--------------------------------------------------------------------------
    */
    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Branch
    |--------------------------------------------------------------------------
    */
    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Customer / Supplier scopes
    |--------------------------------------------------------------------------
    */

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->whereIn('type', [
            'customer',
            'both',
        ]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->whereIn('type', [
            'supplier',
            'both',
        ]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
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
    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'supplier_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Payments / Receipts
    |--------------------------------------------------------------------------
    |
    | payments.party_id → parties.id
    |
    */
    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'party_id');
    }

    /** @return HasMany<PurchaseReturn, $this> */
    public function purchaseReturns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'supplier_id');
    }
}
