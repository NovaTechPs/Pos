<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An order request from the online shop. It is not a sale: stock and balances change only when a staff
 * member turns it into an invoice, which is then linked through order_id.
 */
class CustomerOrder extends Model
{
    /** @var array<string, string> */
    public const STATUSES = ['new' => 'جديد', 'processing' => 'قيد التجهيز', 'invoiced' => 'تمت الفوترة', 'cancelled' => 'ملغى'];

    protected $fillable = ['tenant_id', 'branch_id', 'party_id', 'customer_account_id', 'reference', 'tracking_token', 'customer_name',
        'customer_phone', 'customer_address', 'notes', 'price_list', 'status', 'total', 'order_id'];

    protected $casts = ['total' => 'decimal:2'];

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** @return HasMany<CustomerOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CustomerOrderItem::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withoutGlobalScopes();
    }
}
