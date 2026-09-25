<?php

namespace App\Console\Commands;

use App\Services\TelegramBotClient;
use App\Services\TelegramUpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PollTelegramBot extends Command
{
    protected $signature = 'telegram:bot:poll {--once : Fetch one batch of updates and exit}';

    protected $description = 'Receive Telegram Bot updates with long polling';

    private const CACHE_KEY = 'telegram_bot_poll:last_update_id';

    public function handle(TelegramBotClient $bot, TelegramUpdateHandler $handler): int
    {
        if (! $bot->isConfigured()) {
            $this->error('Telegram Bot token is not configured.');

            return self::FAILURE;
        }

        if (! $bot->deleteWebhook(false)) {
            $this->warn('Could not disable Telegram webhook before polling. Will keep retrying getUpdates.');
        }

        $lastUpdateId = $this->lastUpdateId();
        $once = (bool) $this->option('once');

        $this->info('Telegram Bot long polling started.');

        while (true) {
            $updates = $bot->getUpdates(
                $lastUpdateId === null ? null : $lastUpdateId + 1,
                $once ? 0 : 25,
            );

            if ($updates === null) {
                if ($once) {
                    return self::FAILURE;
                }

                sleep(5);

                continue;
            }

            foreach ($updates as $update) {
                if (! is_array($update)) {
                    continue;
                }

                $updateId = data_get($update, 'update_id');

                if (! is_numeric($updateId)) {
                    continue;
                }

                $updateId = (int) $updateId;

                if ($lastUpdateId !== null && $updateId <= $lastUpdateId) {
                    continue;
                }

                $reply = $handler->handle($update);

                if ($reply !== null) {
                    $bot->sendMessage($reply['chat_id'], $reply['text']);
                }

                Cache::forever(self::CACHE_KEY, $updateId);
                $lastUpdateId = $updateId;
            }

            if ($once) {
                return self::SUCCESS;
            }
        }
    }

    private function lastUpdateId(): ?int
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_numeric($value) ? (int) $value : null;
    }
}
