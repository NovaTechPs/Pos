<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Gives sales invoices issued before the shared numbering (SAL-, W-, INV- and
 * random A{branch}- numbers) the next number of their branch sequence, oldest
 * first. The old number is kept in previous_invoice_number so it stays searchable.
 * Held and online-shop orders keep their own numbers.
 */
class RenumberLegacySalesInvoices
{
    /** @return int The number of invoices renumbered. */
    public function run(): int
    {
        $renumbered = 0;
        $candidates = Order::query()
            ->whereIn('type', ['pos', 'wholesale'])
            ->whereIn('status', ['completed', 'cancelled'])
            ->whereNotNull('branch_id')
            ->where('invoice_number', 'not like', 'HOLD-%')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'branch_id', 'invoice_number']);

        foreach ($candidates as $order) {
            if (preg_match('/^A'.$order->branch_id.'-\d{1,8}$/', (string) $order->invoice_number) === 1) {
                continue;
            }

            DB::transaction(function () use ($order): void {
                $number = SalesInvoiceNumber::next((int) $order->tenant_id, (int) $order->branch_id);
                Order::query()->whereKey($order->id)->update([
                    'invoice_number' => $number,
                    'previous_invoice_number' => $order->invoice_number,
                ]);
                // Receipts generated for the invoice quote its number in their notes.
                DB::table('payments')
                    ->where('order_id', $order->id)
                    ->where('notes', 'like', '%'.$order->invoice_number.'%')
                    ->update(['notes' => DB::raw('REPLACE(notes, '.DB::getPdo()->quote($order->invoice_number).', '.DB::getPdo()->quote($number).')')]);
            });
            $renumbered++;
        }

        return $renumbered;
    }
}
