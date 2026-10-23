<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
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

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => Str::limit($text, 4000, '…'),
                'disable_web_page_preview' => true,
            ];
            if ($replyMarkup !== null) {
                $payload['reply_markup'] = $replyMarkup;
            }
            $response = $this->request(12)->post($this->apiUrl('sendMessage'), $payload);
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

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getUpdates(?int $offset = null, int $timeout = 25): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $payload = [
            'timeout' => max(0, min($timeout, 50)),
            'allowed_updates' => ['message', 'callback_query'],
        ];

        if ($offset !== null) {
            $payload['offset'] = $offset;
        }

        try {
            $response = $this->request(max(12, $payload['timeout'] + 8))
                ->post($this->apiUrl('getUpdates'), $payload);
        } catch (ConnectionException) {
            Log::warning('Telegram Bot API connection failed while polling updates.');

            return null;
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            Log::warning('Telegram Bot API rejected getUpdates.', [
                'status' => $response->status(),
                'description' => $response->json('description'),
            ]);

            return null;
        }

        $updates = $response->json('result');

        return is_array($updates) ? $updates : [];
    }

    /**
     * Download a small JSON document through Telegram's getFile -> file API.
     * File paths are issued by Telegram; reject traversal and arbitrary URLs.
     */
    public function downloadJson(string $fileId, int $maxBytes = 262144): ?string
    {
        if (! $this->isConfigured() || $fileId === '' || strlen($fileId) > 256) {
            return null;
        }

        try {
            $meta = $this->request(15)->post($this->apiUrl('getFile'), ['file_id' => $fileId]);
            if (! $meta->successful() || $meta->json('ok') !== true) {
                return null;
            }

            $path = $meta->json('result.file_path');
            $size = $meta->json('result.file_size');
            if (! is_string($path) || strlen($path) > 400
                || ! preg_match('#^[a-zA-Z0-9_.\-/]+$#', $path)
                || str_starts_with($path, '/') || str_contains($path, '..')
                || ($size !== null && (! is_numeric($size) || $size > $maxBytes))) {
                return null;
            }

            $download = $this->request(20)
                ->get('https://api.telegram.org/file/bot'.$this->token().'/'.$path);
            if (! $download->successful() || strlen($download->body()) > $maxBytes) {
                return null;
            }

            return $download->body();
        } catch (ConnectionException) {
            Log::warning('Telegram file download connection failed.');

            return null;
        }
    }

    public function answerCallback(string $callbackId, string $text = ''): bool
    {
        if (! $this->isConfigured() || $callbackId === '') {
            return false;
        }
        try {
            $response = $this->request(12)->post($this->apiUrl('answerCallbackQuery'), [
                'callback_query_id' => $callbackId,
                'text' => Str::limit($text, 180, '…'),
            ]);

            return $response->successful() && $response->json('ok') === true;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function deleteWebhook(bool $dropPendingUpdates = false): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->request(12)
                ->post($this->apiUrl('deleteWebhook'), [
                    'drop_pending_updates' => $dropPendingUpdates,
                ]);
        } catch (ConnectionException) {
            Log::warning('Telegram Bot API connection failed while deleting webhook.');

            return false;
        }

        return $response->successful() && $response->json('ok') === true;
    }

    public function probe(): array
    {
        if (! $this->isConfigured()) {
            return [
                'ok' => false,
                'username' => null,
                'description' => 'Bot token is not configured.',
            ];
        }

        try {
            $response = $this->request(12)
                ->get($this->apiUrl('getMe'));
        } catch (ConnectionException) {
            return [
                'ok' => false,
                'username' => null,
                'description' => 'Connection to Telegram Bot API failed.',
            ];
        }

        return [
            'ok' => $response->successful() && $response->json('ok') === true,
            'username' => $response->json('result.username'),
            'description' => (string) ($response->json('description') ?: 'Telegram Bot API probe failed.'),
        ];
    }

    public function setWebhook(string $url, string $secretToken, bool $dropPendingUpdates = false): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->request(12)
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

    private function request(int $timeout): PendingRequest
    {
        return Http::asJson()
            ->withOptions($this->transportOptions())
            ->connectTimeout(5)
            ->timeout($timeout);
    }

    private function transportOptions(): array
    {
        $options = [
            'force_ip_resolve' => 'v4',
        ];

        $apiIp = trim((string) config('services.telegram.api_ip'));

        if (filter_var($apiIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $options['curl'] = [
                CURLOPT_RESOLVE => [
                    'api.telegram.org:443:'.$apiIp,
                ],
            ];
        }

        return $options;
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
