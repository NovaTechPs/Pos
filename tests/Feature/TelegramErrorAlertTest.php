<?php

use App\Services\SystemErrorLog;
use App\Services\TelegramAlert;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.telegram.bot_token' => 'test-token', 'services.telegram.chat_id' => '42', 'services.telegram.error_quiet_minutes' => 30]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
});

test('a reported error is sent to Telegram without customer data', function (): void {
    app(SystemErrorLog::class)->record(new RuntimeException('Customer phone 0599123456 failed'));

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_contains($request['text'], 'خطأ في النظام')
        && str_contains($request['text'], 'RuntimeException')
        && ! str_contains($request['text'], '0599123456'));
});

test('the same error repeated is sent only once in the quiet period', function (): void {
    $log = app(SystemErrorLog::class);
    foreach (range(1, 5) as $attempt) {
        $log->record(new RuntimeException('same'));
    }
    $log->record(new LogicException('different'));

    Http::assertSentCount(2);
});

test('nothing is sent until Telegram is configured', function (): void {
    config(['services.telegram.bot_token' => null]);

    app(SystemErrorLog::class)->record(new RuntimeException('boom'));

    Http::assertNothingSent();
});

test('a Telegram outage never breaks the request that raised the error', function (): void {
    Http::fake(fn () => throw new ConnectionException('down'));

    expect(app(TelegramAlert::class)->send('test'))->toBeFalse();
});
