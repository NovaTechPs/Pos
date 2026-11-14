<?php

namespace App\Console\Commands;

use App\Models\TelegramLink;
use App\Services\TelegramNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:daily-summary')]
#[Description("Send today's sales summary to every staff Telegram chat that asked for it.")]
class TelegramDailySummary extends Command
{
    public function handle(TelegramNotifier $notifier): int
    {
        $sent = TelegramLink::query()->linked()->whereNotNull('user_id')->distinct()->pluck('tenant_id')
            ->sum(fn (int $tenantId): int => $notifier->dailySummary($tenantId));
        $this->info("Daily summary sent to {$sent} chat(s).");

        return self::SUCCESS;
    }
}
