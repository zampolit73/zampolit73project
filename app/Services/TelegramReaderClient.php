<?php

namespace App\Services;

use RuntimeException;

class TelegramReaderClient
{
    public function status(): array
    {
        return $this->call('status');
    }

    public function requestCode(string $phone): array
    {
        return $this->call('request_code', ['phone' => $phone]);
    }

    public function submitCode(string $code): array
    {
        return $this->call('submit_code', ['code' => $code]);
    }

    public function submitPassword(string $password): array
    {
        return $this->call('submit_password', ['password' => $password]);
    }

    public function folders(): array
    {
        return $this->call('folders');
    }

    public function selectFolder(int $folderId): array
    {
        return $this->call('select_folder', ['folder_id' => $folderId]);
    }

    public function syncNow(): array
    {
        return $this->call('sync_now');
    }

    public function search(string $query, int $limit = 20): array
    {
        return $this->call('search', [
            'query' => $query,
            'limit' => $limit,
        ]);
    }

    private function call(string $method, array $params = []): array
    {
        $socketPath = trim((string) config(
            'services.telegram_reader.socket',
            '/run/zampolit73-telegram-reader/reader.sock',
        ));

        if ($socketPath === '') {
            throw new RuntimeException('Telegram Reader socket is not configured.');
        }

        $errno = 0;
        $error = '';

        $stream = @stream_socket_client(
            'unix://'.$socketPath,
            $errno,
            $error,
            4,
            STREAM_CLIENT_CONNECT,
        );

        if (! is_resource($stream)) {
            throw new RuntimeException('Telegram Reader service is unavailable.');
        }

        stream_set_timeout($stream, 20);

        $payload = json_encode([
            'method' => $method,
            'params' => $params,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($payload)) {
            fclose($stream);

            throw new RuntimeException('Could not encode Telegram Reader request.');
        }

        fwrite($stream, $payload."\n");
        $response = fgets($stream);
        $meta = stream_get_meta_data($stream);
        fclose($stream);

        if ($response === false || ($meta['timed_out'] ?? false)) {
            throw new RuntimeException('Telegram Reader did not answer in time.');
        }

        $decoded = json_decode($response, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Telegram Reader returned an invalid response.');
        }

        if (($decoded['ok'] ?? false) !== true) {
            throw new RuntimeException(
                trim((string) ($decoded['error'] ?? 'Telegram Reader request failed.')),
            );
        }

        return is_array($decoded['result'] ?? null)
            ? $decoded['result']
            : [];
    }
}
