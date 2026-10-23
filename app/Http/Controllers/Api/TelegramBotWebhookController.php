<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TelegramUpdateHandler;
use App\Services\TelegramBotClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramBotWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramUpdateHandler $handler, TelegramBotClient $bot): JsonResponse
    {
        if (! $this->hasValidSecret($request)) {
            abort(403);
        }

        $reply = $handler->handle($request->all());

        if ($reply === null) {
            return response()->json(['ok' => true]);
        }

        if (isset($reply['clear_inline_keyboard'])) {
            // Telegram's webhook single-method response cannot also edit a
            // message. Send directly first, then remove buttons if delivered.
            $sent = $bot->sendMessage(
                $reply['chat_id'], $reply['text'], $reply['reply_markup'] ?? null
            );
            if ($sent) {
                $bot->removeInlineKeyboard($reply['chat_id'], (int) $reply['clear_inline_keyboard']);
            }

            // Telegram retries non-2xx webhook deliveries; replay is idempotent.
            return response()->json(['ok' => $sent], $sent ? 200 : 503);
        }

        $payload = [
            'method' => 'sendMessage',
            'chat_id' => $reply['chat_id'],
            'text' => $reply['text'],
            'disable_web_page_preview' => true,
        ];
        if (isset($reply['reply_markup'])) {
            $payload['reply_markup'] = $reply['reply_markup'];
        }

        return response()->json($payload);
    }

    private function hasValidSecret(Request $request): bool
    {
        $configuredSecret = trim((string) config('services.telegram.webhook_secret'));
        $providedSecret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return $configuredSecret !== ''
            && $providedSecret !== ''
            && hash_equals($configuredSecret, $providedSecret);
    }
}
