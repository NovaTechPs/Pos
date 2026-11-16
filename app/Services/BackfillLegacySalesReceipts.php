<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

final class BackfillLegacySalesReceipts
{
    /**
     * @param  list<int>  $orderIds
     * @return LazyCollection<int, Order>
     */
    public function candidates(?int $tenantId = null, array $orderIds = []): LazyCollection
    {
        return Order::query()
            ->select([
                'id',
                'tenant_id',
                'branch_id',
                'created_by',
                'customer_id',
                'invoice_number',
                'invoice_date',
                'total',
                'delivery_fee',
                'paid_amount',
                'payment_method',
                'status',
                'type',
                'created_at',
            ])
            ->where('status', 'completed')
            ->where('type', 'wholesale')
            ->whereNotNull('customer_id')
            ->where('paid_amount', '>', 0)
            ->when($tenantId, fn (Builder $query): Builder => $query->where('tenant_id', $tenantId))
            ->when($orderIds !== [], fn (Builder $query): Builder => $query->whereIn('id', $orderIds))
            ->withSum(['payments as linked_receipt_amount' => fn (Builder $query): Builder => $query->where('type', 'receipt')], 'amount')
            ->lazyById(200)
            ->filter(fn (Order $order): bool => $this->unvoucheredAmount($order, (float) ($order->linked_receipt_amount ?? 0)) > 0);
    }

    public function createFor(int $orderId, ?int $tenantId = null): ?Payment
    {
        return DB::transaction(function () use ($orderId, $tenantId): ?Payment {
            $order = Order::query()
                ->when($tenantId, fn (Builder $query): Builder => $query->where('tenant_id', $tenantId))
                ->lockForUpdate()
                ->find($orderId);
            if (! $this->isEligible($order)) {
                return null;
            }

            $linkedReceipts = (float) Payment::query()
                ->where('tenant_id', $order->tenant_id)
                ->where('order_id', $order->id)
                ->where('type', 'receipt')
                ->sum('amount');
            $amount = $this->unvoucheredAmount($order, $linkedReceipts);
            if ($amount <= 0) {
                return null;
            }

            return Payment::query()->create([
                'tenant_id' => $order->tenant_id,
                'branch_id' => $order->branch_id,
                'created_by' => $order->created_by,
                'party_id' => $order->customer_id,
                'type' => 'receipt',
                'voucher_number' => 'RCV-LEGACY-'.$order->id.'-'.Str::upper(Str::random(8)),
                'amount' => $amount,
                'payment_method' => $this->paymentMethod($order),
                'order_id' => $order->id,
                'notes' => 'دفعة على الفاتورة رقم: '.$order->invoice_number.' — تمت معالجته تلقائياً كسند قبض تاريخي.',
                'payment_date' => $order->invoice_date?->copy()->startOfDay() ?? $order->created_at,
            ]);
        });
    }

    public function unvoucheredAmount(Order $order, float $linkedReceipts): float
    {
        $invoiceNet = max(0, (float) $order->total - (float) $order->delivery_fee);
        $directPayment = max(0, (float) $order->paid_amount - (float) $order->delivery_fee - $linkedReceipts);

        return round(min($directPayment, $invoiceNet), 2);
    }

    private function isEligible(?Order $order): bool
    {
        return $order !== null
            && $order->status === 'completed'
            && $order->type === 'wholesale'
            && $order->customer_id !== null
            && (float) $order->paid_amount > 0;
    }

    private function paymentMethod(Order $order): string
    {
        $method = (string) $order->payment_method;

        return in_array($method, ['cash', 'card', 'bank_transfer', 'cheque'], true) ? $method : 'cash';
    }
}
