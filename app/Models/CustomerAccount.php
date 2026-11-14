<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A customer's sign-in to a store's online shop, by phone and password. It belongs to one store and one
 * customer party, so the customer sees that party's invoices and statement and nothing else.
 */
class CustomerAccount extends Authenticatable
{
    /** @var array<string, string> */
    public const PRICE_LISTS = ['retail' => 'سعر التجزئة', 'wholesale' => 'سعر الجملة'];

    protected $fillable = ['tenant_id', 'party_id', 'phone', 'password', 'price_list', 'is_active', 'last_login_at'];

    /** The same defaults as the table, so a newly created account behaves like one read back from it. */
    protected $attributes = ['price_list' => 'retail', 'is_active' => true];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'last_login_at' => 'datetime'];
    }

    /** Phone numbers are compared by their digits only. */
    public static function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', strtr($phone, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'])) ?? '';
    }

    public function isWholesale(): bool
    {
        return $this->price_list === 'wholesale';
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withoutGlobalScopes();
    }
}
