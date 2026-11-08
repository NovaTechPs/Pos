<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;
use Illuminate\Support\Facades\DB;

class ApiShiftLedger
{
    public static function activeForWrite(int $tenantId, int $branchId, int $userId): ?Shift
    {
        Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->lockForUpdate()->firstOrFail();

        return Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
            ->where('opened_by', $userId)->where('status', 'open')->latest('opened_at')->lockForUpdate()->first();
    }

    /** @return array{sales: float, returns: float, cash_receipts: float, cash_payments: float, card_receipts: float, bank_transfer_receipts: float, bank_receipts: float, cheque_receipts: float, expected_cash: float} */
    public static function summary(Shift $shift): array
    {
        $orders = Order::query()->where('tenant_id', $shift->tenant_id)->where('branch_id', $shift->branch_id)
            ->where('shift_id', $shift->id)->where('status', 'completed');
        $payments = Payment::query()->where('tenant_id', $shift->tenant_id)->where('branch_id', $shift->branch_id)
            ->where('shift_id', $shift->id)->where('payment_method', 'cash')
            ->where(function ($query): void {
                $query->whereNull('order_id')
                    ->orWhereDoesntHave('order', fn ($orderQuery) => $orderQuery->where('type', 'pos')->whereNull('customer_id'));
            });
        $receipts = (float) (clone $payments)->where('type', 'receipt')->sum('amount');
        $outgoing = (float) (clone $payments)->where('type', 'payment')->sum('amount');
        $walkInOrders = (clone $orders)->where('type', 'pos')->whereNull('customer_id');
        $receipts += (float) (clone $walkInOrders)->where('payment_method', 'cash')
            ->sum(DB::raw("CASE WHEN source = 'api' THEN paid_amount WHEN paid_amount > delivery_fee THEN paid_amount - delivery_fee ELSE 0 END"));
        $bankReceipts = Payment::query()->where('tenant_id', $shift->tenant_id)->where('branch_id', $shift->branch_id)
            ->where('shift_id', $shift->id)->where('type', 'receipt')
            ->where(function ($query): void {
                $query->whereNull('order_id')
                    ->orWhereDoesntHave('order', fn ($orderQuery) => $orderQuery->where('type', 'pos')->whereNull('customer_id'));
            })
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'card' THEN amount ELSE 0 END), 0) as card_receipts")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'bank_transfer' THEN amount ELSE 0 END), 0) as bank_transfer_receipts")
            ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'cheque' THEN amount ELSE 0 END), 0) as cheque_receipts")
            ->toBase()
            ->first();
        $cardReceipts = (float) $bankReceipts->card_receipts + (float) (clone $walkInOrders)->where('payment_method', 'card')
            ->sum(DB::raw("CASE WHEN source = 'api' THEN paid_amount WHEN paid_amount > delivery_fee THEN paid_amount - delivery_fee ELSE 0 END"));
        $bankTransferReceipts = (float) $bankReceipts->bank_transfer_receipts + (float) (clone $walkInOrders)->where('payment_method', 'bank_transfer')
            ->sum(DB::raw("CASE WHEN source = 'api' THEN paid_amount WHEN paid_amount > delivery_fee THEN paid_amount - delivery_fee ELSE 0 END"));
        $chequeReceipts = (float) $bankReceipts->cheque_receipts + (float) (clone $walkInOrders)->where('payment_method', 'cheque')
            ->sum(DB::raw("CASE WHEN source = 'api' THEN paid_amount WHEN paid_amount > delivery_fee THEN paid_amount - delivery_fee ELSE 0 END"));

        return [
            'sales' => (float) (clone $orders)->whereIn('type', ['pos', 'wholesale'])->sum('total'),
            'returns' => abs((float) (clone $orders)->where('type', 'return')->sum('total')),
            'cash_receipts' => $receipts, 'cash_payments' => $outgoing,
            // Card and bank-transfer receipts are settled in the bank, never in the cash drawer.
            'card_receipts' => $cardReceipts,
            'bank_transfer_receipts' => $bankTransferReceipts,
            'bank_receipts' => $cardReceipts + $bankTransferReceipts,
            'cheque_receipts' => $chequeReceipts,
            'expected_cash' => round((float) $shift->opening_cash + $receipts - $outgoing, 2),
        ];
    }
}
