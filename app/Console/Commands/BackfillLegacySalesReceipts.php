<?php

namespace App\Console\Commands;

use App\Services\BackfillLegacySalesReceipts as LegacySalesReceipts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sales:backfill-legacy-receipts
    {--tenant= : Store ID to process; required with --apply unless --all-tenants is used}
    {--all-tenants : Explicitly process every store when applying}
    {--order=* : Restrict the preview or repair to specific sales invoice IDs}
    {--apply : Create the receipt vouchers after previewing them}')]
#[Description('Preview or safely create linked receipt vouchers for legacy paid sales invoices.')]
class BackfillLegacySalesReceipts extends Command
{
    public function handle(LegacySalesReceipts $legacySalesReceipts): int
    {
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;
        /** @var list<int> $orderIds */
        $orderIds = array_map('intval', (array) $this->option('order'));
        $apply = (bool) $this->option('apply');
        $allTenants = (bool) $this->option('all-tenants');

        if ($tenantId && $allTenants) {
            $this->error('استخدم --tenant أو --all-tenants، وليس كليهما.');

            return self::FAILURE;
        }

        if ($apply && ! $tenantId && ! $allTenants) {
            $this->error('حدد المتجر عبر --tenant أو استخدم --all-tenants عند استخدام --apply.');

            return self::FAILURE;
        }

        $count = 0;
        $amount = 0.0;
        foreach ($legacySalesReceipts->candidates($tenantId, $orderIds) as $order) {
            $unvoucheredAmount = $legacySalesReceipts->unvoucheredAmount($order, (float) ($order->linked_receipt_amount ?? 0));
            $count++;
            $amount += $unvoucheredAmount;

            $this->line("{$order->invoice_number}: ".number_format($unvoucheredAmount, 2));
        }

        if (! $apply) {
            $this->warn("معاينة فقط: {$count} فاتورة، بإجمالي ".number_format($amount, 2).'. لم يتم حفظ أي سند.');
            $this->line('بعد المراجعة شغّل الأمر نفسه مع --tenant=STORE_ID --apply.');

            return self::SUCCESS;
        }

        $created = 0;
        foreach ($legacySalesReceipts->candidates($tenantId, $orderIds) as $order) {
            if ($legacySalesReceipts->createFor($order->id)) {
                $created++;
            }
        }

        $this->info("تم إنشاء {$created} سند قبض تاريخي. لم يتم تعديل paid_amount أو رصيد أي زبون.");

        return self::SUCCESS;
    }
}
