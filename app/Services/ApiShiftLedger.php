<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shift;

class ApiShiftLedger
{
    public static function activeForWrite(int $tenantId, int $branchId, int $userId): ?Shift
    {
        Branch::query()->where('tenant_id', $tenantId)->whereKey($branchId)->lockForUpdate()->firstOrFail();

        return Shift::query()->where('tenant_id', $tenantId)->where('branch_id', $branchId)
            ->where('opened_by', $userId)->where('status', 'open')->latest('opened_at')->lockForUpdate()->first();
    }

    /** @return array{sales: float, returns: float, cash_receipts: float, cash_payments: float, expected_cash: float} */
    public static function summary(Shift $shift): array
    {
        $orders = Order::query()->where('tenant_id', $shift->tenant_id)->where('branch_id', $shift->branch_id)
            ->where('shift_id', $shift->id)->where('status', 'completed');
        $payments = Payment::query()->where('tenant_id', $shift->tenant_id)->where('branch_id', $shift->branch_id)
            ->where('shift_id', $shift->id)->where('payment_method', 'cash');
        $receipts = (float) (clone $payments)->where('type', 'receipt')->sum('amount');
        $outgoing = (float) (clone $payments)->where('type', 'payment')->sum('amount');

        return [
            'sales' => (float) (clone $orders)->whereIn('type', ['pos', 'wholesale'])->sum('total'),
            'returns' => abs((float) (clone $orders)->where('type', 'return')->sum('total')),
            'cash_receipts' => $receipts, 'cash_payments' => $outgoing,
            'expected_cash' => round((float) $shift->opening_cash + $receipts - $outgoing, 2),
        ];
    }
}
