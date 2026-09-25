<?php

namespace App\Console\Commands;

use App\Services\TelegramBotClient;
use Illuminate\Console\Command;

class ConfigureTelegramBotWebhook extends Command
{
    protected $signature = 'telegram:bot:set-webhook {--drop-pending : Drop Telegram updates waiting before webhook setup}';

    protected $description = 'Configure the Telegram Bot API webhook for Vacancy Source';

    public function handle(TelegramBotClient $bot): int
    {
        $secret = trim((string) config('services.telegram.webhook_secret'));
        $appUrl = rtrim((string) config('app.url'), '/');

        if (! $bot->isConfigured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }

        if (! preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret)) {
            $this->error('TELEGRAM_BOT_WEBHOOK_SECRET must contain only A-Z, a-z, 0-9, _ or - and be 1-256 characters.');

            return self::FAILURE;
        }

        if (! str_starts_with($appUrl, 'https://')) {
            $this->error('APP_URL must be HTTPS before configuring the Telegram webhook.');

            return self::FAILURE;
        }

        $url = $appUrl.'/api/telegram/bot/webhook';
        $configured = $bot->setWebhook($url, $secret, (bool) $this->option('drop-pending'));

        if (! $configured) {
            $this->error('Telegram rejected the webhook setup or could not be reached.');

            return self::FAILURE;
        }

        $this->info('Telegram webhook configured: '.$url);

        return self::SUCCESS;
    }
}
