<?php

namespace App\Services;

use App\Models\Order;

/**
 * One running number per branch for every sales invoice, whichever screen or app creates it
 * (POS, sales invoices, wholesale or the mobile/desktop API): A{branch}-{n}. Returns use R{branch}-{n}.
 */
class SalesInvoiceNumber
{
    public static function next(int $tenantId, int $branchId, bool $isReturn = false): string
    {
        $prefix = ($isReturn ? 'R' : 'A').$branchId.'-';

        // Older invoices carry nine random digits after the prefix, so only shorter numbers count towards the sequence.
        $latest = Order::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('invoice_number', 'like', $prefix.'%')
            ->whereRaw('LENGTH(invoice_number) < ?', [strlen($prefix) + 9])
            ->orderByRaw('LENGTH(invoice_number) DESC')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');
        $sequence = max(0, (int) substr((string) $latest, strlen($prefix)));

        do {
            $number = $prefix.(++$sequence);
        } while (Order::withTrashed()->where('tenant_id', $tenantId)->where('invoice_number', $number)->exists());

        return $number;
    }
}
