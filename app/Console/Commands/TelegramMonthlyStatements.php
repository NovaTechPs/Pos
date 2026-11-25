<?php

namespace App\Console\Commands;

use App\Models\TelegramLink;
use App\Services\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:monthly-statements')]
#[Description("Send every linked customer last month's account statement as a PDF on Telegram.")]
class TelegramMonthlyStatements extends Command
{
    public function handle(TelegramNotifier $notifier): int
    {
        $sent = TelegramLink::query()->linked()->whereNotNull('party_id')->distinct()->pluck('tenant_id')
            ->sum(fn (int $tenantId): int => $notifier->monthlyStatements($tenantId));
        $this->info("Monthly statements sent to {$sent} customer(s).");

        return self::SUCCESS;
    }
}
