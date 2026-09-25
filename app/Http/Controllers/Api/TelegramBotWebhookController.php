<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TelegramUpdateHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TelegramBotWebhookController extends Controller
{
    public function __invoke(Request $request, TelegramUpdateHandler $handler): JsonResponse
    {
        if (! $this->hasValidSecret($request)) {
            abort(403);
        }

        $reply = $handler->handle($request->all());

        if ($reply === null) {
            return response()->json(['ok' => true]);
        }

        return response()->json([
            'method' => 'sendMessage',
            'chat_id' => $reply['chat_id'],
            'text' => $reply['text'],
            'disable_web_page_preview' => true,
        ]);
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
