<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramBotClient
{
    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    public function botUsername(): ?string
    {
        $username = trim((string) config('services.telegram.username'));

        return $username === '' ? null : ltrim($username, '@');
    }

    public function sendMessage(int|string $chatId, string $text): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::asJson()
                ->connectTimeout(4)
                ->timeout(8)
                ->post($this->apiUrl('sendMessage'), [
                    'chat_id' => $chatId,
                    'text' => Str::limit($text, 4000, '…'),
                    'disable_web_page_preview' => true,
                ]);
        } catch (ConnectionException) {
            Log::warning('Telegram Bot API connection failed while sending a message.');

            return false;
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('Telegram Bot API rejected sendMessage.', [
                'status' => $response->status(),
                'description' => $response->json('description'),
            ]);

            return false;
        }

        return true;
    }

    public function setWebhook(string $url, string $secretToken, bool $dropPendingUpdates = false): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::asJson()
                ->connectTimeout(5)
                ->timeout(12)
                ->post($this->apiUrl('setWebhook'), [
                    'url' => $url,
                    'secret_token' => $secretToken,
                    'allowed_updates' => ['message'],
                    'max_connections' => 5,
                    'drop_pending_updates' => $dropPendingUpdates,
                ]);
        } catch (ConnectionException) {
            return false;
        }

        return $response->successful() && $response->json('ok') === true;
    }

    private function apiUrl(string $method): string
    {
        return 'https://api.telegram.org/bot'.$this->token().'/'.$method;
    }

    private function token(): string
    {
        return trim((string) config('services.telegram.token'));
    }
}
