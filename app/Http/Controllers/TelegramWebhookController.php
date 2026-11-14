<?php

namespace App\Http\Controllers;

use App\Services\TelegramBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** Receives messages for the shared WaslaPOS bot from Telegram (registered by telegram:set-webhook). */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramBot $bot): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        try {
            $bot->handle($request->json()->all());
        } catch (Throwable $exception) {
            // Answer 200 anyway, or Telegram keeps resending the same message.
            report($exception);
        }

        return response()->json(['ok' => true]);
    }
}
