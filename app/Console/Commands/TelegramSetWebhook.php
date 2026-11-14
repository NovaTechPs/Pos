<?php

namespace App\Console\Commands;

use App\Services\TelegramAlert;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('telegram:set-webhook')]
#[Description('Point the WaslaPOS Telegram bot at this server and set its command menu.')]
class TelegramSetWebhook extends Command
{
    public function handle(TelegramAlert $telegram): int
    {
        if (! $telegram->botConfigured() || blank(config('services.telegram.webhook_secret'))) {
            $this->error('Set TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET first.');

            return self::FAILURE;
        }

        $url = route('telegram.webhook');
        $registered = $telegram->call('setWebhook', [
            'url' => $url,
            'secret_token' => config('services.telegram.webhook_secret'),
            'allowed_updates' => ['message'],
            'drop_pending_updates' => true,
        ]);
        $telegram->call('setMyCommands', ['commands' => [
            ['command' => 'balance', 'description' => 'رصيدي'],
            ['command' => 'invoices', 'description' => 'آخر فواتيري'],
            ['command' => 'stop', 'description' => 'إيقاف الرسائل'],
        ]]);

        if (! $registered) {
            $this->error('Telegram refused the webhook. Check the bot token and that '.$url.' is public HTTPS.');

            return self::FAILURE;
        }
        $this->info('Bot webhook set to '.$url);

        return self::SUCCESS;
    }
}
