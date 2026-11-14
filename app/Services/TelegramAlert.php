<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends short alerts to the owner's Telegram chat. Does nothing until TELEGRAM_BOT_TOKEN and
 * TELEGRAM_CHAT_ID are set, and never throws: an alert must not break the work that raised it.
 */
class TelegramAlert
{
    public function enabled(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.chat_id'));
    }

    public function send(string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        try {
            return Http::connectTimeout(2)->timeout(4)
                ->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage', [
                    'chat_id' => config('services.telegram.chat_id'),
                    'text' => Str::limit('['.config('app.name').'] '.$text, 3900),
                    'disable_web_page_preview' => true,
                ])->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Reports an application error, at most once per distinct error within the quiet period, so
     * an error repeated on every request sends one message instead of hundreds.
     */
    public function error(string $details): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        try {
            $quietMinutes = max(1, (int) config('services.telegram.error_quiet_minutes'));
            if (! Cache::add('telegram-error:'.md5(Str::before($details, "\n").'|'.Str::of($details)->explode("\n")->get(1)), true, now()->addMinutes($quietMinutes))) {
                return false;
            }
        } catch (Throwable) {
            // Without a cache the alert is still worth sending.
        }
        $where = app()->runningInConsole() ? 'أمر خلفي' : request()->method().' /'.ltrim(request()->path(), '/');

        return $this->send("🚨 خطأ في النظام\n{$where}\n".Str::limit($details, 1500));
    }
}
