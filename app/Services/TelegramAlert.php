<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Talks to the shared WaslaPOS Telegram bot. Owner alerts go to TELEGRAM_CHAT_ID; store staff and
 * customers get messages in their own linked chats. Nothing here ever throws: a message must not
 * break the work that raised it.
 */
class TelegramAlert
{
    public function botConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    public function enabled(): bool
    {
        return $this->botConfigured() && filled(config('services.telegram.chat_id'));
    }

    /** Sends an alert to the system owner's chat. */
    public function send(string $text): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->sendTo((string) config('services.telegram.chat_id'), '['.config('app.name').'] '.$text);
    }

    public function sendTo(string $chatId, string $text): bool
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => Str::limit($text, 3900),
            'disable_web_page_preview' => true,
        ]);
    }

    public function sendDocument(string $chatId, string $filename, string $contents, string $caption = ''): bool
    {
        if (! $this->botConfigured()) {
            return false;
        }
        try {
            return Http::connectTimeout(3)->timeout(20)
                ->attach('document', $contents, $filename)
                ->post($this->endpoint('sendDocument'), ['chat_id' => $chatId, 'caption' => Str::limit($caption, 1000)])
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $payload */
    public function call(string $method, array $payload): bool
    {
        if (! $this->botConfigured()) {
            return false;
        }
        try {
            return Http::connectTimeout(2)->timeout(5)->post($this->endpoint($method), $payload)->successful();
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

    private function endpoint(string $method): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method;
    }
}
