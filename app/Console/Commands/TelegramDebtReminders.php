<?php

namespace App\Console\Commands;

use App\Models\TelegramLink;
use App\Services\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:debt-reminders')]
#[Description('Remind linked customers of their unpaid balance, in stores that turned reminders on.')]
class TelegramDebtReminders extends Command
{
    public function handle(TelegramNotifier $notifier): int
    {
        $sent = TelegramLink::query()->linked()->whereNotNull('party_id')->distinct()->pluck('tenant_id')
            ->sum(fn (int $tenantId): int => $notifier->debtReminders($tenantId));
        $this->info("Debt reminders sent to {$sent} customer(s).");

        return self::SUCCESS;
    }
}
