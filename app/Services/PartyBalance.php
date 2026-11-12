<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Party;

/**
 * The single place that moves a customer's stored balance for a sale.
 * The unpaid part of a sale is what the customer owes: total minus the amount paid at the till.
 * Delivery fees cancel out (they are in the total and in the paid amount, and the delivery company
 * collects them), and receipt vouchers lower the balance on their own.
 */
class PartyBalance
{
    /**
     * Moves a party's balance on the sales side: a positive amount means the party owes us more
     * (an unpaid sale), a negative one less (a receipt or a return). The party's own direction is applied.
     */
    public function applySale(Party $party, float $amount): void
    {
        $this->apply($party, $party->saleBalanceDirection() * $amount);
    }

    /**
     * Moves a party's balance on the purchases side: a positive amount means we owe the party more
     * (an unpaid purchase), a negative one less (a payment or a purchase return).
     */
    public function applyPurchase(Party $party, float $amount): void
    {
        $this->apply($party, $party->purchaseBalanceDirection() * $amount);
    }

    /** One atomic database update, so concurrent saves never overwrite each other's change. */
    private function apply(Party $party, float $change): void
    {
        $change = round($change, 2);
        if (abs($change) >= 0.005) {
            $party->increment('current_balance', $change);
        }
    }

    /** What the customer still owes on a sale; returns are settled by their own flow. */
    public function saleOutstanding(Order $order): float
    {
        if ($order->type === 'return' || $order->status !== 'completed' || ! $order->customer_id) {
            return 0.0;
        }

        return max(0, round((float) $order->total - (float) $order->paid_amount, 2));
    }

    /**
     * Moves the balance when a sale is saved, edited, moved to another customer or deleted.
     * Pass the sale's customer and outstanding amount before and after the change (0 for none).
     */
    public function moveSaleOutstanding(int $tenantId, ?int $previousCustomerId, float $previousOutstanding, ?int $customerId, float $outstanding): void
    {
        foreach ([[$previousCustomerId, -$previousOutstanding], [$customerId, $outstanding]] as [$partyId, $change]) {
            if (! $partyId || abs($change) < 0.005) {
                continue;
            }
            $party = Party::query()->where('tenant_id', $tenantId)->lockForUpdate()->find($partyId);
            if ($party) {
                $this->applySale($party, $change);
            }
        }
    }
}
