<?php

namespace App\Console\Commands;

use App\Models\VacancyInvestigation;
use App\Services\TelegramBotClient;
use App\Services\TelegramUpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class PollTelegramBot extends Command
{
    protected $signature = 'telegram:bot:poll {--once : Fetch one batch of updates and exit}';

    protected $description = 'Maintain the Telegram Bot watchdog; receive updates when polling transport is selected';

    private const CACHE_KEY = 'telegram_bot_poll:last_update_id';

    public function handle(TelegramBotClient $bot, TelegramUpdateHandler $handler): int
    {
        if (! $bot->isConfigured()) {
            $this->error('Telegram Bot token is not configured.');

            return self::FAILURE;
        }

        $transport = (string) config('services.telegram.transport', 'polling');
        if (! in_array($transport, ['polling', 'webhook'], true)) {
            $this->error('TELEGRAM_BOT_TRANSPORT must be polling or webhook.');

            return self::FAILURE;
        }

        $polling = $transport === 'polling';
        if ($polling && ! $bot->deleteWebhook(false)) {
            $this->warn('Could not disable Telegram webhook before polling. Will keep retrying getUpdates.');
        }

        $lastUpdateId = $this->lastUpdateId();
        $once = (bool) $this->option('once');

        $this->info($polling
            ? 'Telegram Bot long polling started.'
            : 'Telegram Bot webhook mode: watchdog only; polling is disabled.');

        while (true) {
            $this->recoverStaleInvestigations($bot);

            // Keep stale-job recovery running without deleting the webhook or
            // competing with Telegram's inbound delivery after a service restart.
            if (! $polling) {
                if ($once) {
                    return self::SUCCESS;
                }
                sleep(30);

                continue;
            }

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
                    $sent = $bot->sendMessage(
                        $reply['chat_id'], $reply['text'], $reply['reply_markup'] ?? null
                    );

                    if (! $sent && isset($reply['clear_inline_keyboard'])) {
                        // The SQLite write may already be committed. Do not
                        // acknowledge an undelivered confirmation update.
                        // Retrying is safe: replay returns the original revision.
                        $this->warn('Telegram research acknowledgement failed; retrying this update.');
                        if ($once) {
                            return self::FAILURE;
                        }
                        sleep(5);
                        break;
                    }

                    if ($sent && isset($reply['clear_inline_keyboard'])) {
                        $bot->removeInlineKeyboard($reply['chat_id'], (int) $reply['clear_inline_keyboard']);
                    }
                }

                Cache::forever(self::CACHE_KEY, $updateId);
                $lastUpdateId = $updateId;
            }

            if ($once) {
                return self::SUCCESS;
            }
        }
    }

    private function recoverStaleInvestigations(TelegramBotClient $bot): void
    {
        $stale = VacancyInvestigation::query()
            ->with('user.telegramAccount')
            ->where('status', 'running')
            ->whereNotNull('started_at')
            ->where('started_at', '<', now()->subMinutes(6))
            ->orderBy('id')
            ->limit(20)
            ->get();

        foreach ($stale as $investigation) {
            $updated = VacancyInvestigation::query()
                ->whereKey($investigation->id)
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'progress_stage' => 'failed',
                    'progress_text' => 'Проверка автоматически остановлена: превышено максимальное время выполнения.',
                    'finished_at' => now(),
                    'timed_out_at' => now(),
                ]);

            if ($updated !== 1) {
                continue;
            }

            $chatId = $investigation->user?->telegramAccount?->telegram_chat_id;

            if ($chatId) {
                $bot->sendMessage(
                    $chatId,
                    'Проверка #'.$investigation->id
                    .' автоматически остановлена: она выполнялась больше 6 минут. '
                    .'Можно сразу прислать вакансию ещё раз.',
                );
            }
        }
    }

    private function lastUpdateId(): ?int
    {
        $value = Cache::get(self::CACHE_KEY);

        return is_numeric($value) ? (int) $value : null;
    }
}
