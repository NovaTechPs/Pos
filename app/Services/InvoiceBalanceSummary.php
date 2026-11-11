<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Party;

class InvoiceBalanceSummary
{
    /**
     * The five lines printed under a sales invoice.
     *
     * "total" is everything charged before the discount, so
     * previous balance + total - discount - payment = new balance.
     * The new balance is the customer's balance now; the previous balance removes
     * what this invoice added to it. Balances are null for invoices without a customer.
     *
     * @return array{total: float, discount: float, payment: float, previous_balance: ?float, new_balance: ?float}
     */
    public static function forOrder(Order $order, ?float $payment = null): array
    {
        $discount = round((float) $order->discount, 2);
        $invoiceTotal = (float) $order->total;
        $payment = round($payment ?? (float) $order->paid_amount, 2);

        $customer = $order->customer_id
            ? Party::query()->where('tenant_id', $order->tenant_id)->whereKey($order->customer_id)->first()
            : null;

        if (! $customer) {
            // Walk-in cash: money handed back as change is not a payment on the invoice.
            $payment = round(min($payment, max(0, $invoiceTotal)), 2);
        }

        $newBalance = $customer ? round((float) $customer->current_balance, 2) : null;
        $previousBalance = $customer
            ? round($newBalance - $customer->saleBalanceDirection() * ($invoiceTotal - (float) $order->paid_amount), 2)
            : null;

        return [
            'total' => round($invoiceTotal + $discount, 2),
            'discount' => $discount,
            'payment' => $payment,
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
        ];
    }
}
