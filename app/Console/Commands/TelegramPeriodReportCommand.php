<?php

namespace App\Console\Commands;

use App\Models\TelegramLink;
use App\Services\TelegramPeriodReport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:period-report {period=week : week or month}')]
#[Description('Send the weekly or monthly store report PDF to every owner Telegram chat that asked for it.')]
class TelegramPeriodReportCommand extends Command
{
    public function handle(TelegramPeriodReport $report): int
    {
        $period = (string) $this->argument('period');
        if (! in_array($period, ['week', 'month'], true)) {
            $this->error('The period must be week or month.');

            return self::INVALID;
        }
        $sent = TelegramLink::query()->linked()->whereNotNull('user_id')->distinct()->pluck('tenant_id')
            ->sum(fn (int $tenantId): int => $report->send($tenantId, $period));
        $this->info("Report ({$period}) sent to {$sent} chat(s).");

        return self::SUCCESS;
    }
}
