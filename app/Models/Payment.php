<?php

namespace App\Models;

use App\Services\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /** Types offered for an expense voucher (a payment to no account) and for cash expenses at the POS. */
    public const EXPENSE_CATEGORIES = [
        'دعاية وإعلان', 'إيجار', 'كهرباء', 'مياه', 'إنترنت واتصالات', 'صيانة', 'مواصلات',
        'ضيافة', 'رسوم بنكية', 'نثريات', 'أخرى',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'amount' => 'decimal:2',
    ];

    /** The account a voucher is for: its party, or «مصروف: النوع» for an expense voucher. */
    public function accountName(): string
    {
        if ($this->party) {
            return (string) $this->party->name;
        }
        if (! $this->isExpense()) {
            return 'غير محدد';
        }

        return $this->expense_category ? 'مصروف: '.$this->expense_category : 'مصروف';
    }

    /** A payment voucher to no account and no document: an operating expense. */
    public function isExpense(): bool
    {
        return $this->type === 'payment' && ! $this->party_id && ! $this->order_id && ! $this->purchase_id
            && ! $this->purchase_return_id && ! $this->employee_payment_id;
    }

    /*
    |--------------------------------------------------------------------------
    | Party
    |--------------------------------------------------------------------------
    |
    | payments.party_id → parties.id
    |
    */
    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'party_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<PurchaseReturn, $this> */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    /**
     * Vouchers the user may see under their data scope: receipt vouchers follow «سندات القبض», payment
     * vouchers «سندات الصرف» (per branch: none, view, only their own, or all). The owner sees every voucher.
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (DataScope::unrestricted($user)) {
            return $query;
        }
        $scope = app(DataScope::class);

        return $query->where(fn (Builder $any) => $any
            ->where(fn (Builder $receipts) => $scope->apply($receipts->where('payments.type', 'receipt'), $user, 'receipts', 'payments.branch_id', 'payments.created_by'))
            ->orWhere(fn (Builder $payments) => $scope->apply($payments->where('payments.type', 'payment'), $user, 'payments', 'payments.branch_id', 'payments.created_by')));
    }

    /** Editing or deleting this voucher: «full» in its branch, or «own» when the user wrote it. */
    public function mayBeChangedBy(?User $user): bool
    {
        return app(DataScope::class)->canChange($user, $this->type === 'payment' ? 'payments' : 'receipts',
            $this->branch_id ? (int) $this->branch_id : null, $this->created_by ? (int) $this->created_by : null);
    }
}
